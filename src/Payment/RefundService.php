<?php
/**
 * Stripe refunds in two steps, so money is never refunded twice:
 *
 * 1. plan(): inside the caller's transaction (cancellation, late payment,
 *    overpayment) the refund is written as `refund` rows (method stripe, status
 *    pending, no refund ID yet) against the Stripe charges, newest first.
 *    Pending refunds already reduce the net amount received.
 * 2. send(): after the commit each row is sent with the idempotency key
 *    `mbk-refund-{row id}` and metadata.payment_id; the Stripe refund ID and
 *    status are stored. Rows left unsent (network error, timeout) are retried
 *    hourly by retryUnsent(): a retry first lists the intent's refunds and links
 *    the one created for the row; only when none exists is it sent again (with
 *    the same key while Stripe still remembers it, < 23 h, else a new key).
 *
 * refund.created / refund.updated / refund.failed and charge.refunded webhooks
 * update the rows (a failed refund no longer counts) and record refunds made
 * in the Stripe Dashboard. A charge row is `refunded` while its succeeded
 * refunds cover it.
 */

namespace Marclie\Booking\Payment;

use Marclie\Booking\Domain\Clock;
use Marclie\Booking\Repository\Db;
use Marclie\Booking\Repository\PaymentRepository;

defined( 'ABSPATH' ) || exit;

final class RefundService {

	/** Definite errors after which a retry may still succeed (the refund stays pending). */
	private const TRANSIENT_ERRORS = array( 'rate_limit', 'lock_timeout', 'authentication_error' );

	/** Stripe keeps idempotency keys 24 h; stay below. */
	private const KEY_TTL_HOURS = 23;

	public function __construct(
		private PaymentLedger $ledger,
		private ?PaymentGateway $gateway,
		private Clock $clock
	) {}

	public function available(): bool {
		return null !== $this->gateway;
	}

	/**
	 * Stripe charge rows with the amount still refundable, newest first.
	 *
	 * @param list<array<string, mixed>> $payments Rows of one booking.
	 * @return list<array{payment: array<string, mixed>, refundable: int}>
	 */
	public static function refundable( array $payments ): array {
		$refunded = array();
		foreach ( $payments as $row ) {
			if ( 'refund' === $row['kind'] && PaymentRepository::METHOD_STRIPE === $row['method'] && in_array( $row['status'], PaymentRepository::REFUND_COUNTED, true ) && ! empty( $row['stripe_intent_id'] ) ) {
				$refunded[ (string) $row['stripe_intent_id'] ] = ( $refunded[ (string) $row['stripe_intent_id'] ] ?? 0 ) + (int) $row['amount_cents'];
			}
		}

		$out = array();
		foreach ( array_reverse( $payments ) as $row ) {
			if ( 'refund' === $row['kind'] || PaymentRepository::METHOD_STRIPE !== $row['method'] || empty( $row['stripe_intent_id'] ) || ! in_array( $row['status'], array( 'succeeded', 'refunded' ), true ) ) {
				continue;
			}
			$left = (int) $row['amount_cents'] - ( $refunded[ (string) $row['stripe_intent_id'] ] ?? 0 );
			if ( $left > 0 ) {
				$out[] = array(
					'payment'    => $row,
					'refundable' => $left,
				);
			}
		}

		return $out;
	}

	/**
	 * Writes pending refund rows for up to `$amountCents` across the booking's
	 * Stripe charges (newest first). Call inside the transaction that decides
	 * the refund; then send() the returned IDs after the commit.
	 *
	 * @return list<int> Refund row IDs.
	 */
	public function plan( int $bookingId, int $amountCents, string $note ): array {
		$ids       = array();
		$remaining = $amountCents;
		foreach ( self::refundable( $this->ledger->payments( $bookingId ) ) as $item ) {
			if ( $remaining <= 0 ) {
				break;
			}
			$amount     = min( $item['refundable'], $remaining );
			$ids[]      = $this->ledger->insertPayment(
				array(
					'booking_id'       => $bookingId,
					'kind'             => 'refund',
					'method'           => PaymentRepository::METHOD_STRIPE,
					'amount_cents'     => $amount,
					'status'           => 'pending',
					'stripe_intent_id' => (string) $item['payment']['stripe_intent_id'],
					'card_brand'       => (string) ( $item['payment']['card_brand'] ?? '' ),
					'card_last4'       => (string) ( $item['payment']['card_last4'] ?? '' ),
					'note'             => '' !== $note ? $note : null,
					'created_at'       => Db::utc( $this->clock->now() ),
				)
			);
			$remaining -= $amount;
		}

		return $ids;
	}

	/**
	 * Sends planned refund rows to Stripe. Failures are logged (`refund_failed`);
	 * ambiguous and transient ones stay pending for retryUnsent().
	 *
	 * @param list<int> $refundIds
	 */
	public function send( array $refundIds, string $actor ): void {
		foreach ( $refundIds as $id ) {
			$row = $this->ledger->payment( (int) $id );
			if ( null === $row || 'refund' !== $row['kind'] || 'pending' !== $row['status'] || ! empty( $row['stripe_refund_id'] ) ) {
				continue;
			}
			$bookingId = (int) $row['booking_id'];

			if ( null === $this->gateway ) {
				$this->ledger->log( 'refund_failed', $actor, $bookingId, array( 'payment_id' => $row['id'], 'error' => 'payments_unavailable' ) );
				continue;
			}

			$attempts = (int) $row['attempts'];
			$booking  = $this->ledger->booking( $bookingId );
			try {
				$refund = null;
				if ( $attempts > 0 ) {
					// An earlier attempt may have reached Stripe: link that refund instead of sending another.
					foreach ( $this->gateway->listRefunds( (string) $row['stripe_intent_id'] ) as $candidate ) {
						$refund = $candidate->paymentId() === (int) $row['id'] ? $candidate : $refund;
					}
				}

				if ( null === $refund ) {
					$this->ledger->updatePayment( (int) $row['id'], array( 'attempts' => $attempts + 1 ) );
					$fresh  = (string) $row['created_at'] >= Db::utc( $this->clock->now()->modify( sprintf( '-%d hours', self::KEY_TTL_HOURS ) ) );
					$refund = $this->gateway->refund(
						(string) $row['stripe_intent_id'],
						(int) $row['amount_cents'],
						array(
							'booking_id' => (string) $bookingId,
							'ref'        => (string) ( $booking['ref'] ?? '' ),
							'payment_id' => (string) $row['id'],
							'kind'       => 'refund',
						),
						'mbk-refund-' . $row['id'] . ( $fresh ? '' : '-' . ( $attempts + 1 ) )
					);
				}
			} catch ( GatewayException $e ) {
				$retry = $e->ambiguous() || in_array( $e->stripeCode, self::TRANSIENT_ERRORS, true );
				if ( ! $retry ) {
					$this->ledger->updatePayment( (int) $row['id'], array( 'status' => 'failed', 'error_code' => substr( (string) $e->stripeCode, 0, 100 ) ) );
				}
				$this->ledger->log(
					'refund_failed',
					$actor,
					$bookingId,
					array(
						'payment_id'   => $row['id'],
						'amount_cents' => $row['amount_cents'],
						'error'        => $e->getMessage(),
						'code'         => $e->stripeCode,
						'retry'        => $retry,
					)
				);
				continue;
			}

			$this->applyRefund( $refund, $actor );
			$this->ledger->log(
				'refund_created',
				$actor,
				$bookingId,
				array(
					'payment_id'       => $row['id'],
					'amount_cents'     => $row['amount_cents'],
					'stripe_refund_id' => $refund->id,
					'status'           => $refund->status,
				)
			);

			/**
			 * Fires after a refund was sent to Stripe.
			 *
			 * @param array<string, mixed> $booking
			 * @param array<string, mixed> $payment The refund row.
			 */
			do_action( 'mbk_refund_created', $this->ledger->booking( $bookingId ), $this->ledger->payment( (int) $row['id'] ) );
		}
	}

	/**
	 * Sends refund rows that are still unsent (hourly cron).
	 *
	 * @return int Rows attempted.
	 */
	public function retryUnsent( string $actor ): int {
		if ( null === $this->gateway ) {
			return 0;
		}
		$ids = array_map( static fn( array $row ): int => (int) $row['id'], $this->ledger->unsentRefunds() );
		$this->send( $ids, $actor );

		return count( $ids );
	}

	/**
	 * State of a Stripe refund (API response, refund.* webhook, listing) → the
	 * plugin's refund row: matched by refund ID or metadata.payment_id; a refund
	 * the plugin did not create (Stripe Dashboard) on one of its charges is
	 * recorded. A refund that failed no longer reduces the amount received.
	 *
	 * @return int|null Booking ID, null when the refund is not on a plugin charge.
	 */
	public function applyRefund( Refund $refund, string $actor ): ?int {
		$row    = $this->ledger->paymentByRefund( $refund->id );
		$charge = null;
		if ( null === $row && $refund->paymentId() > 0 ) {
			$candidate = $this->ledger->payment( $refund->paymentId() );
			$row       = null !== $candidate && 'refund' === $candidate['kind'] && empty( $candidate['stripe_refund_id'] )
				&& ( null === $refund->paymentIntent || $refund->paymentIntent === $candidate['stripe_intent_id'] ) ? $candidate : null;
		}
		if ( null === $row ) {
			$charge = null === $refund->paymentIntent ? null : $this->ledger->paymentByIntent( $refund->paymentIntent );
			if ( null === $charge ) {
				return null;
			}
		}
		$bookingId = (int) ( $row['booking_id'] ?? $charge['booking_id'] );

		$this->ledger->transaction(
			function () use ( $refund, $row, $charge, $bookingId, $actor ): void {
				$this->ledger->lockBooking( $bookingId );

				if ( null === $row ) {
					if ( null === $this->ledger->paymentByRefund( $refund->id ) && 'failed' !== $refund->rowStatus() ) {
						$this->recordExternal( $bookingId, (array) $charge, $refund, $actor );
					}
				} else {
					$current = (array) $this->ledger->payment( (int) $row['id'] );
					$status  = $refund->rowStatus();
					if ( 'pending' === $status && 'pending' !== $current['status'] ) {
						$status = (string) $current['status']; // An older "pending" never undoes a final state.
					}
					if ( $current['status'] !== $status || $current['stripe_refund_id'] !== $refund->id ) {
						$this->ledger->updatePayment(
							(int) $row['id'],
							array(
								'status'           => $status,
								'stripe_refund_id' => $refund->id,
								'error_code'       => 'failed' === $status ? substr( 'refund_' . $refund->status, 0, 100 ) : null,
							)
						);
						if ( 'failed' === $status && 'failed' !== $current['status'] ) {
							$this->ledger->log(
								'refund_failed',
								$actor,
								$bookingId,
								array(
									'payment_id'       => $row['id'],
									'amount_cents'     => $row['amount_cents'],
									'stripe_refund_id' => $refund->id,
									'retry'            => false,
								)
							);
						}
					}
				}

				$this->syncChargeStatus( (string) ( $row['stripe_intent_id'] ?? $refund->paymentIntent ) );
			}
		);

		return $bookingId;
	}

	/**
	 * charge.refunded: reads the intent's refunds from Stripe (the event's charge
	 * no longer embeds them) and applies each.
	 *
	 * @param array<string, mixed> $charge Charge object.
	 * @return int|null Booking ID, null when the charge is unknown.
	 * @throws GatewayException When the refunds cannot be listed (the webhook is retried).
	 */
	public function onChargeRefunded( array $charge, string $actor ): ?int {
		$intentId = is_array( $charge['payment_intent'] ?? null ) ? (string) ( $charge['payment_intent']['id'] ?? '' ) : (string) ( $charge['payment_intent'] ?? '' );
		$row      = '' === $intentId ? null : $this->ledger->paymentByIntent( $intentId );
		if ( null === $row ) {
			return null;
		}

		$refunds = null === $this->gateway
			? array_map( static fn( $r ): Refund => Refund::fromArray( (array) $r ), (array) ( $charge['refunds']['data'] ?? array() ) )
			: $this->gateway->listRefunds( $intentId );
		foreach ( $refunds as $refund ) {
			$this->applyRefund( null === $refund->paymentIntent ? new Refund( $refund->id, $refund->status, $refund->amount, $refund->metadata, $intentId ) : $refund, $actor );
		}

		return (int) $row['booking_id'];
	}

	/**
	 * A refund made outside the plugin (Stripe Dashboard).
	 *
	 * @param array<string, mixed> $charge The charge row it belongs to.
	 */
	private function recordExternal( int $bookingId, array $charge, Refund $refund, string $actor ): void {
		if ( $refund->amount <= 0 ) {
			return;
		}
		$id = $this->ledger->insertPayment(
			array(
				'booking_id'       => $bookingId,
				'kind'             => 'refund',
				'method'           => PaymentRepository::METHOD_STRIPE,
				'amount_cents'     => $refund->amount,
				'status'           => $refund->rowStatus(),
				'stripe_intent_id' => (string) $charge['stripe_intent_id'],
				'stripe_refund_id' => $refund->id,
				'card_brand'       => (string) ( $charge['card_brand'] ?? '' ),
				'card_last4'       => (string) ( $charge['card_last4'] ?? '' ),
				'note'             => 'Refunded in the Stripe Dashboard',
				'created_at'       => Db::utc( $this->clock->now() ),
			)
		);
		$this->ledger->log(
			'refund_external',
			$actor,
			$bookingId,
			array(
				'payment_id'       => $id,
				'amount_cents'     => $refund->amount,
				'stripe_refund_id' => $refund->id,
			)
		);
	}

	/** The charge row of `$intentId` is `refunded` while its succeeded refunds cover it, else `succeeded`. */
	private function syncChargeStatus( string $intentId ): void {
		$charge = '' === $intentId ? null : $this->ledger->paymentByIntent( $intentId );
		if ( null === $charge || ! in_array( $charge['status'], array( 'succeeded', 'refunded' ), true ) ) {
			return;
		}
		$refunded = 0;
		foreach ( $this->ledger->payments( (int) $charge['booking_id'] ) as $row ) {
			if ( 'refund' === $row['kind'] && 'succeeded' === $row['status'] && $intentId === (string) $row['stripe_intent_id'] ) {
				$refunded += (int) $row['amount_cents'];
			}
		}
		$status = $refunded >= (int) $charge['amount_cents'] ? 'refunded' : 'succeeded';
		if ( $status !== $charge['status'] ) {
			$this->ledger->updatePayment( (int) $charge['id'], array( 'status' => $status ) );
		}
	}
}
