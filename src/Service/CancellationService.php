<?php
/**
 * Cancellation refunds: until the booking's free_cancel_until date (inclusive;
 * fallback when the column is empty: arrival − free_cancel_days from the
 * policy_json snapshot) everything received (normally just the deposit) is
 * refunded, afterwards nothing. An admin may override the amount with a logged
 * reason. The refund is split over the payment methods, newest first: card
 * money goes back through Stripe (RefundService), money received outside
 * Stripe is refunded by hand and recorded with its original method.
 */

namespace Marclie\Booking\Service;

use Marclie\Booking\Domain\BookingStatus;
use Marclie\Booking\Domain\Clock;
use Marclie\Booking\Domain\Exception\PaymentException;
use Marclie\Booking\Domain\Money;
use Marclie\Booking\Domain\Policy;
use Marclie\Booking\Payment\RefundService;
use Marclie\Booking\Repository\BookingRepository;
use Marclie\Booking\Repository\Db;
use Marclie\Booking\Repository\EventRepository;
use Marclie\Booking\Repository\PaymentRepository;

defined( 'ABSPATH' ) || exit;

final class CancellationService {

	public function __construct(
		private BookingRepository $bookings,
		private PaymentRepository $payments,
		private BookingService $service,
		private EventRepository $events,
		private RefundService $refunds,
		private Clock $clock
	) {}

	/**
	 * @param int|null $overrideRefundCents Admin override (clamped to 0…paid); requires `$reason`.
	 */
	public function decide( int $bookingId, ?int $overrideRefundCents = null, string $reason = '' ): CancellationDecision {
		$booking = $this->bookings->find( $bookingId );
		if ( null === $booking ) {
			throw new \InvalidArgumentException( 'Booking not found.' );
		}

		$tz = $this->clock->today()->getTimezone();
		if ( ! empty( $booking['free_cancel_until'] ) ) {
			$deadline = new \DateTimeImmutable( (string) $booking['free_cancel_until'], $tz );
		} else {
			$snapshot = json_decode( (string) $booking['policy_json'], true );
			$policy   = Policy::fromArray( is_array( $snapshot ) ? $snapshot : array() );
			$deadline = ( new \DateTimeImmutable( (string) $booking['arrival'], $tz ) )->modify( sprintf( '-%d days', $policy->freeCancelDays ) );
		}
		$within = $this->clock->today() <= $deadline;

		$paid   = new Money( max( 0, $this->payments->netPaidCents( $bookingId ) ) );
		$refund = $within ? $paid : Money::zero();

		$overridden = false;
		if ( null !== $overrideRefundCents ) {
			if ( '' === trim( $reason ) ) {
				throw new \InvalidArgumentException( 'A reason is required to override the refund amount.' );
			}
			$refund     = new Money( max( 0, min( $overrideRefundCents, $paid->cents() ) ) );
			$overridden = true;
		}

		return new CancellationDecision( $bookingId, $paid, $refund, $within, $deadline->format( 'Y-m-d' ), $overridden, trim( $reason ) );
	}

	/**
	 * Cancels the booking (releases nights) and logs the refund decision.
	 */
	public function cancel( int $bookingId, string $actor, ?int $overrideRefundCents = null, string $reason = '' ): CancellationDecision {
		$decision = $this->decide( $bookingId, $overrideRefundCents, $reason );

		$this->service->transition( $bookingId, BookingStatus::Cancelled, $actor, array( 'refund' => $decision->toArray() ) );

		if ( $decision->overridden ) {
			$booking = $this->bookings->find( $bookingId );
			$this->events->log( 'refund_override', $actor, $bookingId, $booking['guest_id'] ?? null, $decision->toArray() );
		}

		return $decision;
	}

	/**
	 * How a refund of `$refundCents` is split: per payment method, newest method
	 * first; card money per Stripe charge, newest first (payment_id null: not recorded).
	 *
	 * @return list<array{payment_id:?int, method:string, amount_cents:int, card_brand:?string, card_last4:?string}>
	 */
	public function allocate( int $bookingId, int $refundCents ): array {
		$payments  = $this->payments->forBooking( $bookingId );
		$remaining = $refundCents;
		$out       = array();
		foreach ( array_reverse( $this->payments->netPaidByMethod( $bookingId ), true ) as $method => $net ) {
			if ( $remaining <= 0 ) {
				break;
			}
			if ( $net <= 0 ) {
				continue;
			}
			$left = min( $net, $remaining );
			if ( PaymentRepository::METHOD_STRIPE === $method ) {
				foreach ( RefundService::refundable( $payments ) as $item ) {
					$amount = min( $item['refundable'], $left );
					if ( $amount <= 0 ) {
						break;
					}
					$out[]      = array(
						'payment_id'   => null,
						'method'       => $method,
						'amount_cents' => $amount,
						'card_brand'   => '' !== (string) $item['payment']['card_brand'] ? (string) $item['payment']['card_brand'] : null,
						'card_last4'   => '' !== (string) $item['payment']['card_last4'] ? (string) $item['payment']['card_last4'] : null,
					);
					$left      -= $amount;
					$remaining -= $amount;
				}
				continue;
			}
			$out[]      = array(
				'payment_id'   => null,
				'method'       => (string) $method,
				'amount_cents' => $left,
				'card_brand'   => null,
				'card_last4'   => null,
			);
			$remaining -= $left;
		}

		return $out;
	}

	/**
	 * Admin cancellation with refunds: cancels the booking and, in the same
	 * transaction, records the refund — `refund` rows (status succeeded, original
	 * method) for money received outside Stripe, which the admin pays back by
	 * hand, and pending Stripe refund rows that are sent to Stripe right after
	 * the commit (status → succeeded by response or refund/charge webhooks).
	 * The decision and its split are made under the booking row lock, so a
	 * payment applied concurrently is never missed.
	 *
	 * @throws PaymentException payments_unavailable When card money must be refunded but Stripe is not available (nothing changes).
	 */
	public function cancelWithRefunds( int $bookingId, string $actor, ?int $overrideRefundCents = null, string $reason = '' ): CancellationDecision {
		[ $decision, $rows, $stripeIds ] = Db::transaction(
			function () use ( $bookingId, $actor, $overrideRefundCents, $reason ): array {
				$this->bookings->lockForUpdate( $bookingId );
				$decision = $this->decide( $bookingId, $overrideRefundCents, $reason );
				$plan     = $this->allocate( $bookingId, $decision->refund->cents() );
				$byStripe = array_sum( array_map( static fn( array $r ): int => PaymentRepository::METHOD_STRIPE === $r['method'] ? $r['amount_cents'] : 0, $plan ) );
				if ( $byStripe > 0 && ! $this->refunds->available() ) {
					throw PaymentException::unavailable();
				}
				$note = '' !== $decision->reason ? $decision->reason : 'Refund on cancellation';

				$this->cancel( $bookingId, $actor, $overrideRefundCents, $reason );
				$now  = Db::utc( $this->clock->now() );
				$rows = array();
				foreach ( $plan as $item ) {
					if ( PaymentRepository::METHOD_STRIPE === $item['method'] ) {
						continue;
					}
					$id = $this->payments->insert(
						array(
							'booking_id'   => $bookingId,
							'kind'         => 'refund',
							'method'       => $item['method'],
							'amount_cents' => $item['amount_cents'],
							'status'       => 'succeeded',
							'note'         => $note,
							'created_at'   => $now,
						)
					);
					Db::check( $id ?: false, 'Recording refund' );
					$rows[] = array( 'payment_id' => $id ) + $item;
				}

				$stripeIds = $byStripe > 0 ? $this->refunds->plan( $bookingId, $byStripe, $note ) : array();
				foreach ( $stripeIds as $id ) {
					$row    = (array) $this->payments->find( $id );
					$rows[] = array(
						'payment_id'   => $id,
						'method'       => PaymentRepository::METHOD_STRIPE,
						'amount_cents' => (int) $row['amount_cents'],
						'card_brand'   => '' !== (string) $row['card_brand'] ? (string) $row['card_brand'] : null,
						'card_last4'   => '' !== (string) $row['card_last4'] ? (string) $row['card_last4'] : null,
					);
				}

				if ( $rows ) {
					$booking = $this->bookings->find( $bookingId );
					Db::check(
						$this->events->log(
							'refund_recorded',
							$actor,
							$bookingId,
							$booking['guest_id'] ?? null,
							array(
								'refunds' => array_map(
									static fn( array $r ): array => array(
										'payment_id'   => $r['payment_id'],
										'method'       => $r['method'],
										'amount_cents' => $r['amount_cents'],
									),
									$rows
								),
							)
						) ?: false,
						'Logging refund'
					);
				}

				return array( $decision, $rows, $stripeIds );
			}
		);

		if ( $stripeIds ) {
			$this->refunds->send( $stripeIds, $actor );
		}

		return $decision->withRefunds( $rows );
	}
}
