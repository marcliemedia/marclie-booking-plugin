<?php
/**
 * Applies the state of a Stripe PaymentIntent to the booking — the single,
 * idempotent path used by webhooks, the confirmation page poll, the hold
 * reaper and the balance charger.
 *
 * Only the plugin's own intents are applied: a payment row must carry the
 * intent ID, or be named by `metadata.payment_id` for the same booking, and
 * the metadata `ref` / `site` and the currency must match. Anything else on
 * the Stripe account (other installs, Dashboard payments) is logged as
 * `foreign_intent` and ignored.
 *
 * - succeeded: the payment row becomes succeeded (card, charge, saved payment
 *   method stored), the booking moves by amount (pending → confirmed/paid,
 *   confirmed/balance_failed → paid); money above the total (two payments
 *   racing) is refunded (`overpayment`). A payment for an expired booking
 *   re-holds its nights when they are still free (→ confirmed/paid), otherwise
 *   the booking is cancelled (`hold_lost`) and the payment refunded in full;
 *   money arriving on a cancelled booking is refunded in full too;
 * - failed (declined, authentication required) / canceled: the row becomes
 *   failed; an off-session balance charge moves confirmed → balance_failed;
 * - processing / requires_action: the row stays (or becomes again) pending.
 *
 * Changes run under the booking row lock; hooks fire after the transaction.
 */

namespace Marclie\Booking\Payment;

use Marclie\Booking\Domain\BookingStatus;
use Marclie\Booking\Domain\Clock;
use Marclie\Booking\Repository\Db;
use Marclie\Booking\Repository\PaymentRepository;

defined( 'ABSPATH' ) || exit;

final class PaymentReconciler {

	public const ACTOR_WEBHOOK   = 'system:stripe_webhook';
	public const ACTOR_RECONCILE = 'system:stripe_reconcile';
	public const ACTOR_REAPER    = 'system:hold_reaper';

	/** Error code of a row whose intent was never created (lost request, not found at Stripe). */
	public const NOT_CREATED = 'not_created';

	/** When Stripe cannot be asked, an expired hold is kept at most this long. */
	private const UNREACHABLE_GRACE_MINUTES = 60;

	/** A row without intent ID is declared "not created" once Stripe search has had this long to index it. */
	private const NOT_CREATED_AFTER_MINUTES = 15;

	/** Failed rows whose intent will never change again. */
	private const CLOSED_ERRORS = array( Intent::CANCELED, 'api_error', self::NOT_CREATED );

	public function __construct(
		private PaymentLedger $ledger,
		private ?PaymentGateway $gateway,
		private RefundService $refunds,
		private Clock $clock,
		private string $site
	) {}

	/**
	 * @return array<string, mixed>|null The booking after the update; null when the intent is not one of the plugin's payments.
	 */
	public function apply( Intent $pi, string $actor ): ?array {
		if ( '' === $pi->id ) {
			return null;
		}

		$row     = $this->rowFor( $pi );
		$booking = null === $row ? null : $this->ledger->booking( (int) $row['booking_id'] );
		$reason  = match ( true ) {
			null === $row => 'no_payment_row',
			null === $booking => 'no_booking',
			( $pi->metadata['ref'] ?? '' ) !== (string) $booking['ref'] => 'ref_mismatch',
			( $pi->metadata['site'] ?? '' ) !== $this->site => 'site_mismatch',
			'eur' !== $pi->currency => 'currency',
			default => null,
		};
		if ( null !== $reason ) {
			$this->ledger->log(
				'foreign_intent',
				$actor,
				null,
				array(
					'intent'     => $pi->id,
					'status'     => $pi->status,
					'reason'     => $reason,
					'booking_id' => $pi->metadata['booking_id'] ?? null,
				)
			);

			return null;
		}
		$bookingId = (int) $booking['id'];
		$rowId     = (int) $row['id'];

		/** @var list<callable(): void> $after */
		$after     = array();
		$refundIds = array();
		$this->ledger->transaction(
			function () use ( $pi, $bookingId, $rowId, $actor, &$after, &$refundIds ): void {
				$booking = $this->ledger->lockBooking( $bookingId );
				$row     = $this->ledger->payment( $rowId );
				if ( null === $booking || null === $row ) {
					return;
				}
				if ( empty( $row['stripe_intent_id'] ) ) {
					// The webhook was faster than the response of the create call.
					$this->ledger->updatePayment( $rowId, array( 'stripe_intent_id' => $pi->id ) );
					$row['stripe_intent_id'] = $pi->id;
				}

				if ( $pi->succeeded() ) {
					$this->applySucceeded( $booking, $row, $pi, $actor, $after, $refundIds );
				} elseif ( $pi->failed() ) {
					$this->applyFailed( $booking, $row, $pi, $actor, $after );
				} elseif ( 'failed' === $row['status'] && ! in_array( $row['error_code'], self::CLOSED_ERRORS, true ) ) {
					// A new attempt on the same intent (other card, 3-D Secure) is under way.
					$this->ledger->updatePayment( $rowId, array( 'status' => 'pending', 'error_code' => null ) );
				}
			}
		);

		foreach ( $after as $callback ) {
			$callback();
		}
		if ( $refundIds ) {
			$this->refunds->send( $refundIds, $actor );
		}

		return $this->ledger->booking( $bookingId );
	}

	/**
	 * Asks Stripe for the booking's open card payments (pending, or failed but
	 * retryable on the same intent) and applies them — for when a webhook is
	 * late. Rows whose create call lost its response are looked up by
	 * metadata.payment_id.
	 *
	 * @param array<string, mixed> $booking
	 * @return Intent|null The newest intent asked, null without gateway/open payments or on error.
	 */
	public function reconcile( array $booking, string $actor = self::ACTOR_RECONCILE ): ?Intent {
		if ( null === $this->gateway ) {
			return null;
		}

		$latest = null;
		foreach ( $this->openPayments( (int) $booking['id'], true ) as $row ) {
			try {
				$pi = empty( $row['stripe_intent_id'] )
					? $this->gateway->findIntentByPaymentId( (int) $row['id'] )
					: $this->gateway->retrieveIntent( (string) $row['stripe_intent_id'] );
			} catch ( GatewayException $e ) {
				return $latest;
			}

			if ( null === $pi ) {
				$limit = Db::utc( $this->clock->now()->modify( sprintf( '-%d minutes', self::NOT_CREATED_AFTER_MINUTES ) ) );
				if ( (string) $row['created_at'] < $limit ) {
					$this->ledger->updatePayment( (int) $row['id'], array( 'status' => 'failed', 'error_code' => self::NOT_CREATED ) );
					$this->ledger->log( 'payment_not_created', $actor, (int) $row['booking_id'], array( 'payment_id' => $row['id'] ) );
				}
				continue;
			}

			$latest = $pi;
			$this->apply( $pi, $actor );
		}

		return $latest;
	}

	/**
	 * Cancels the open (not succeeded / processing) intents of the booking's
	 * pending card payments whose idempotency key starts with `$keyPrefix`, so
	 * they can no longer be paid, and applies their final state.
	 *
	 * @throws GatewayException
	 */
	public function cancelOpenIntents( int $bookingId, string $keyPrefix, string $actor ): void {
		if ( null === $this->gateway ) {
			return;
		}
		foreach ( $this->openPayments( $bookingId, false ) as $row ) {
			if ( 'pending' === $row['status'] && str_starts_with( (string) ( $row['idempotency_key'] ?? '' ), $keyPrefix ) ) {
				$this->settle( (string) $row['stripe_intent_id'], $actor );
			}
		}
	}

	/**
	 * Filter `mbk_hold_reaper_should_expire`: never expire a hold whose payment
	 * succeeded (it is applied now) or is processing. Other open intents are
	 * cancelled first, so no payment can arrive after the expiry.
	 *
	 * @param array<string, mixed> $booking
	 */
	public function shouldExpire( bool $expire, array $booking ): bool {
		if ( ! $expire || null === $this->gateway ) {
			return $expire;
		}

		try {
			foreach ( $this->openPayments( (int) $booking['id'], false ) as $row ) {
				$pi = $this->settle( (string) $row['stripe_intent_id'], self::ACTOR_REAPER );
				if ( $pi->succeeded() || Intent::PROCESSING === $pi->status ) {
					return false;
				}
			}
		} catch ( GatewayException $e ) {
			$limit = Db::utc( $this->clock->now()->modify( sprintf( '-%d minutes', self::UNREACHABLE_GRACE_MINUTES ) ) );

			return null !== $booking['hold_expires_at'] && (string) $booking['hold_expires_at'] < $limit;
		}

		return true;
	}

	/**
	 * Retrieves an intent, cancels it unless it succeeded / is processing /
	 * is cancelled already, and applies the result.
	 *
	 * @throws GatewayException
	 */
	private function settle( string $intentId, string $actor ): Intent {
		$gateway = $this->gateway;
		if ( null === $gateway ) {
			throw new GatewayException( 'No payment gateway.', 'payments_unavailable' );
		}
		$pi = $gateway->retrieveIntent( $intentId );
		if ( ! $pi->succeeded() && Intent::PROCESSING !== $pi->status && Intent::CANCELED !== $pi->status ) {
			try {
				$pi = $gateway->cancelIntent( $intentId );
			} catch ( GatewayException $e ) {
				// It may have succeeded meanwhile: look again.
				$pi = $gateway->retrieveIntent( $intentId );
			}
		}
		$this->apply( $pi, $actor );

		return $pi;
	}

	/**
	 * Stripe charge rows whose intent may still change. `$withoutIntent` adds
	 * pending rows whose create call never returned an intent ID.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function openPayments( int $bookingId, bool $withoutIntent ): array {
		return array_values(
			array_filter(
				$this->ledger->payments( $bookingId ),
				static fn( array $row ): bool => 'refund' !== $row['kind'] && PaymentRepository::METHOD_STRIPE === $row['method']
					&& ( ! empty( $row['stripe_intent_id'] ) ? ( 'pending' === $row['status'] || ( 'failed' === $row['status'] && ! in_array( $row['error_code'], self::CLOSED_ERRORS, true ) ) ) : ( $withoutIntent && 'pending' === $row['status'] ) )
			)
		);
	}

	/**
	 * The charge row of the intent: found by intent ID, else the row the intent
	 * was created for (metadata.payment_id of the same booking, intent ID not stored yet).
	 *
	 * @return array<string, mixed>|null
	 */
	private function rowFor( Intent $pi ): ?array {
		$row = $this->ledger->paymentByIntent( $pi->id );
		if ( null !== $row ) {
			return $row;
		}

		$paymentId = (int) ( $pi->metadata['payment_id'] ?? 0 );
		$row       = $paymentId > 0 ? $this->ledger->payment( $paymentId ) : null;

		return null !== $row && 'refund' !== $row['kind'] && PaymentRepository::METHOD_STRIPE === $row['method']
			&& (int) $row['booking_id'] === $pi->bookingId() && empty( $row['stripe_intent_id'] ) ? $row : null;
	}

	/**
	 * @param array<string, mixed>  $booking
	 * @param array<string, mixed>  $row
	 * @param list<callable(): void> $after
	 * @param list<int>             $refundIds
	 */
	private function applySucceeded( array $booking, array $row, Intent $pi, string $actor, array &$after, array &$refundIds ): void {
		if ( in_array( $row['status'], array( 'succeeded', 'refunded' ), true ) ) {
			return; // Already applied.
		}

		$bookingId = (int) $booking['id'];
		$this->ledger->updatePayment(
			(int) $row['id'],
			array(
				'status'           => 'succeeded',
				'amount_cents'     => $pi->amountReceived > 0 ? $pi->amountReceived : $pi->amount,
				'stripe_charge_id' => $pi->latestCharge,
				'card_brand'       => (string) $pi->cardBrand,
				'card_last4'       => (string) $pi->cardLast4,
				'error_code'       => null,
			)
		);
		$patch = array_filter(
			array(
				'stripe_customer_id'       => $pi->customer,
				'stripe_payment_method_id' => $pi->paymentMethod,
			)
		);
		if ( $patch ) {
			$this->ledger->updateBooking( $bookingId, $patch );
		}
		$payment = (array) $this->ledger->payment( (int) $row['id'] );
		$this->ledger->log(
			'payment_succeeded',
			$actor,
			$bookingId,
			array(
				'payment_id'   => $payment['id'],
				'kind'         => $payment['kind'],
				'amount_cents' => $payment['amount_cents'],
				'card_brand'   => $payment['card_brand'],
				'card_last4'   => $payment['card_last4'],
				'intent'       => $pi->id,
			)
		);

		$status = BookingStatus::from( (string) $booking['status'] );
		$net    = PaymentRepository::net( $this->ledger->payments( $bookingId ) );
		$total  = (int) $booking['total_cents'];
		$lost   = false;

		if ( $status->occupiesNights() ) {
			$target = $status->afterPayment( $net, $total, (int) $booking['deposit_cents'] );
			if ( null !== $target ) {
				$this->ledger->transition( $bookingId, $target, $actor, array( 'payment_id' => $payment['id'] ) );
			}

			// Two payments raced (payment page + off-session charge): give back what exceeds the total.
			$excess = min( $net - $total, (int) $payment['amount_cents'] );
			if ( $excess > 0 ) {
				$refundIds = array_merge( $refundIds, $this->refunds->plan( $bookingId, $excess, 'overpayment' ) );
				$this->ledger->log(
					'overpayment',
					$actor,
					$bookingId,
					array(
						'payment_id'   => $payment['id'],
						'amount_cents' => $excess,
					)
				);
			}
		} else {
			$target  = $net >= $total ? BookingStatus::Paid : BookingStatus::Confirmed;
			$revived = BookingStatus::Expired === $status && $this->ledger->reviveExpired( $bookingId, $target, $actor, array( 'payment_id' => $payment['id'] ) );
			if ( ! $revived ) {
				$lost = BookingStatus::Expired === $status;
				if ( $lost ) {
					$this->ledger->transition(
						$bookingId,
						BookingStatus::Cancelled,
						$actor,
						array(
							'reason'     => 'hold_lost',
							'payment_id' => $payment['id'],
						),
						true
					);
				}
				$refundIds = array_merge( $refundIds, $this->refunds->plan( $bookingId, (int) $payment['amount_cents'], $lost ? 'hold_lost' : 'Payment after cancellation' ) );
				$this->ledger->log(
					$lost ? 'hold_lost' : 'late_payment_refunded',
					$actor,
					$bookingId,
					array(
						'payment_id'   => $payment['id'],
						'amount_cents' => $payment['amount_cents'],
					)
				);
			}
		}

		$after[] = function () use ( $bookingId, $payment, $lost ): void {
			$booking = $this->ledger->booking( $bookingId );

			/**
			 * Fires after a card payment succeeded (deposit, balance or full).
			 *
			 * @param array<string, mixed> $booking
			 * @param array<string, mixed> $payment
			 */
			do_action( 'mbk_payment_succeeded', $booking, $payment );

			if ( $lost ) {
				/**
				 * Fires when a payment arrived after the hold expired and the nights
				 * were taken meanwhile: the booking is cancelled and the payment refunded.
				 *
				 * @param array<string, mixed> $booking
				 */
				do_action( 'mbk_hold_lost', $booking );
			}
		};
	}

	/**
	 * @param array<string, mixed>   $booking
	 * @param array<string, mixed>   $row
	 * @param list<callable(): void> $after
	 */
	private function applyFailed( array $booking, array $row, Intent $pi, string $actor, array &$after ): void {
		if ( 'pending' !== $row['status'] ) {
			return; // Already failed (or succeeded: never downgrade).
		}

		$bookingId = (int) $booking['id'];
		$canceled  = Intent::CANCELED === $pi->status;
		$code      = $canceled ? Intent::CANCELED : (string) $pi->errorCode;
		$this->ledger->updatePayment( (int) $row['id'], array( 'status' => 'failed', 'error_code' => substr( $code, 0, 100 ) ) );
		$this->ledger->log(
			'payment_failed',
			$actor,
			$bookingId,
			array(
				'payment_id' => $row['id'],
				'kind'       => $row['kind'],
				'code'       => $code,
				'intent'     => $pi->id,
			)
		);
		if ( $canceled ) {
			return;
		}

		$offSession = '1' === ( $pi->metadata['off_session'] ?? '' );
		if ( $offSession && 'balance' === $row['kind'] && BookingStatus::Confirmed->value === $booking['status'] ) {
			$this->ledger->transition( $bookingId, BookingStatus::BalanceFailed, $actor, array( 'payment_id' => $row['id'], 'code' => $code ) );
		}

		$error   = array(
			'code'            => $code,
			'message'         => $pi->errorMessage,
			'requires_action' => $pi->needsAction(),
			'off_session'     => $offSession,
		);
		$after[] = function () use ( $bookingId, $row, $error ): void {
			/**
			 * Fires after a card payment failed (declined, authentication required).
			 *
			 * @param array<string, mixed> $booking
			 * @param array<string, mixed> $payment
			 * @param array{code:string, message:?string, requires_action:bool, off_session:bool} $error
			 */
			do_action( 'mbk_payment_failed', $this->ledger->booking( $bookingId ), $this->ledger->payment( (int) $row['id'] ), $error );
		};
	}
}
