<?php
/**
 * Off-session balance charges with the card saved at checkout (cron
 * `mbk_daily`, 09:00 site time; admin "charge now"; `wp mbk charge-balances`).
 *
 * Confirmed bookings whose balance_due_on is today or earlier are charged the
 * outstanding amount (total − net received). A decline or required
 * authentication moves the booking to balance_failed (PaymentReconciler) and
 * fires `mbk_payment_failed` (guest gets the /rezervacija/placanje/ link). One
 * automatic retry follows RETRY_AFTER_HOURS later; after that the admin acts.
 *
 * Every attempt is a payment row with the idempotency key
 * `mbk-balance-{booking}-{attempt}` (UNIQUE), written under the booking lock
 * before Stripe is called with the same key: two runs can never charge twice.
 */

namespace Marclie\Booking\Payment;

use Marclie\Booking\Domain\BookingStatus;
use Marclie\Booking\Domain\Clock;
use Marclie\Booking\Domain\Exception\InvalidTransitionException;
use Marclie\Booking\Domain\Exception\NotFoundException;
use Marclie\Booking\Domain\Exception\PaymentException;
use Marclie\Booking\Repository\Db;
use Marclie\Booking\Repository\PaymentRepository;

defined( 'ABSPATH' ) || exit;

final class BalanceCharger {

	public const ACTOR = 'system:balance_charger';

	public const RETRY_AFTER_HOURS = 48;

	/** Automatic attempts per booking (first charge + one retry). */
	public const MAX_AUTO_ATTEMPTS = 2;

	public const KEY_PREFIX = 'mbk-balance-';

	public function __construct(
		private PaymentLedger $ledger,
		private ?PaymentGateway $gateway,
		private PaymentReconciler $reconciler,
		private Clock $clock,
		private string $site
	) {}

	/**
	 * Charges every due balance (or only lists them with `$dryRun`).
	 *
	 * @param string|null $todayYmd Defaults to today (site timezone).
	 * @return list<array{booking_id:int, ref:string, amount_cents:int, attempt:int, action:string, result:string}>
	 *         action: charge | skip; result: the intent status, an error code or the skip reason.
	 */
	public function run( ?string $todayYmd = null, bool $dryRun = false ): array {
		$today = $todayYmd ?? $this->clock->today()->format( 'Y-m-d' );
		$out   = array();

		foreach ( $this->ledger->balanceCandidates( $today ) as $booking ) {
			$plan = $this->plan( $booking, $this->ledger->payments( (int) $booking['id'] ) );
			$item = array(
				'booking_id'   => (int) $booking['id'],
				'ref'          => (string) $booking['ref'],
				'amount_cents' => $plan['amount'],
				'attempt'      => $plan['attempt'],
				'action'       => null === $plan['skip'] ? 'charge' : 'skip',
				'result'       => $plan['skip'] ?? ( $dryRun ? 'dry_run' : '' ),
			);

			// in_progress may be an unattempted payment-page intent that charge() cancels first.
			if ( ( null === $plan['skip'] || PaymentException::IN_PROGRESS === $plan['skip'] ) && ! $dryRun ) {
				$item['action'] = 'charge';
				try {
					$pi             = $this->charge( (int) $booking['id'], self::ACTOR, false );
					$item['result'] = $pi->succeeded() ? Intent::SUCCEEDED : ( $pi->errorCode ?? $pi->status );
				} catch ( PaymentException | InvalidTransitionException | NotFoundException $e ) {
					$item['result'] = $e->errorCode();
				}
			}

			$out[] = $item;
		}

		return $out;
	}

	/**
	 * Charges the outstanding amount of a confirmed/balance_failed booking now.
	 * `$manual` (admin) skips the automatic retry rules.
	 *
	 * Before charging, the booking's open card payments are reconciled with
	 * Stripe and open payment-page intents are cancelled (the guest cannot pay
	 * the same balance twice); any card payment still in flight → in_progress.
	 * A network error / timeout leaves the attempt pending (outcome unknown);
	 * PaymentReconciler::reconcile() resolves it through metadata.payment_id.
	 *
	 * @throws PaymentException payments_unavailable, nothing_due, no_saved_card, in_progress, payment_error, retry rules (`retry_later`).
	 * @throws InvalidTransitionException|NotFoundException
	 */
	public function charge( int $bookingId, string $actor, bool $manual = true ): Intent {
		if ( null === $this->gateway ) {
			throw PaymentException::unavailable();
		}
		$current = $this->ledger->booking( $bookingId );
		if ( null === $current ) {
			throw new NotFoundException( 'Booking not found.', array( 'booking_id' => $bookingId ) );
		}
		try {
			$this->reconciler->reconcile( $current, $actor );
			$this->reconciler->cancelOpenIntents( $bookingId, CheckoutService::PAY_KEY_PREFIX . $bookingId . '-', $actor );
		} catch ( GatewayException $e ) {
			throw new PaymentException( PaymentException::CODE, 'Stripe could not be asked about open payments.', array( 'stripe_code' => $e->stripeCode ), $e );
		}
		$prepared = $this->ledger->transaction(
			function () use ( $bookingId, $manual ): array {
				$booking = $this->ledger->lockBooking( $bookingId );
				if ( null === $booking ) {
					throw new NotFoundException( 'Booking not found.', array( 'booking_id' => $bookingId ) );
				}
				if ( ! in_array( $booking['status'], array( BookingStatus::Confirmed->value, BookingStatus::BalanceFailed->value ), true ) ) {
					throw new InvalidTransitionException( 'Only confirmed bookings with a balance can be charged.', array( 'from' => $booking['status'] ) );
				}

				$plan = $this->plan( $booking, $this->ledger->payments( $bookingId ), $manual );
				if ( null !== $plan['skip'] ) {
					throw new PaymentException( $plan['skip'], 'The balance cannot be charged now.', array( 'reason' => $plan['skip'] ) );
				}

				$key       = self::KEY_PREFIX . $bookingId . '-' . $plan['attempt'];
				$paymentId = $this->ledger->insertPayment(
					array(
						'booking_id'      => $bookingId,
						'kind'            => 'balance',
						'method'          => PaymentRepository::METHOD_STRIPE,
						'amount_cents'    => $plan['amount'],
						'status'          => 'pending',
						'attempts'        => $plan['attempt'],
						'idempotency_key' => $key,
						'created_at'      => Db::utc( $this->clock->now() ),
					)
				);

				return array( $booking, $paymentId, $plan['amount'], $key );
			}
		);
		[ $booking, $paymentId, $amount, $key ] = $prepared;

		try {
			$pi = $this->gateway->chargeOffSession(
				array(
					'amount'               => $amount,
					'currency'             => 'eur',
					'customer'             => (string) $booking['stripe_customer_id'],
					'payment_method'       => (string) $booking['stripe_payment_method_id'],
					'allowed_payment_method_types' => array( 'card' ),
					'description'          => self::description( (string) $booking['ref'], $this->ledger->unitTitle( (int) $booking['unit_id'] ), 'balance' ),
					'metadata'             => array(
						'booking_id'  => (string) $bookingId,
						'ref'         => (string) $booking['ref'],
						'kind'        => 'balance',
						'payment_id'  => (string) $paymentId,
						'off_session' => '1',
						'site'        => $this->site,
					),
				),
				$key
			);
		} catch ( GatewayException $e ) {
			// Ambiguous (timeout, network, Stripe 5xx): Stripe may have charged. The row stays pending
			// (charges wait: in_progress) until reconcile() finds or rules out the intent.
			$unknown = $e->ambiguous();
			if ( ! $unknown ) {
				$this->ledger->updatePayment( $paymentId, array( 'status' => 'failed', 'error_code' => substr( (string) ( $e->stripeCode ?? 'request_failed' ), 0, 100 ) ) );
			}
			$this->ledger->log(
				'payment_error',
				$actor,
				$bookingId,
				array(
					'payment_id' => $paymentId,
					'error'      => $e->getMessage(),
					'code'       => $e->stripeCode,
					'unknown'    => $unknown,
				)
			);
			throw new PaymentException( PaymentException::CODE, 'The card payment could not be processed.', array( 'stripe_code' => $e->stripeCode, 'outcome_unknown' => $unknown ), $e );
		}

		$this->ledger->updatePayment( $paymentId, array( 'stripe_intent_id' => $pi->id ) );
		$this->reconciler->apply( $pi, $actor );

		return $pi;
	}

	/**
	 * PaymentIntent description shown in the Stripe Dashboard and on receipts:
	 * "{ref} · {unit} · akontacija|ostatak|ukupno".
	 */
	public static function description( string $ref, string $unitTitle, string $kind ): string {
		$label = array(
			'deposit' => 'akontacija',
			'balance' => 'ostatak',
			'full'    => 'ukupno',
		)[ $kind ] ?? $kind;

		return $ref . ' · ' . $unitTitle . ' · ' . $label;
	}

	/**
	 * Amount, attempt number and skip reason (null = charge) for a booking.
	 *
	 * @param array<string, mixed>       $booking
	 * @param list<array<string, mixed>> $payments
	 * @return array{amount:int, attempt:int, skip:?string}
	 */
	private function plan( array $booking, array $payments, bool $manual = false ): array {
		$amount   = (int) $booking['total_cents'] - PaymentRepository::net( $payments );
		$attempts = array_values( array_filter( $payments, static fn( array $p ): bool => str_starts_with( (string) ( $p['idempotency_key'] ?? '' ), self::KEY_PREFIX . $booking['id'] . '-' ) ) );
		$attempt  = count( $attempts ) + 1;
		$skip     = null;

		if ( $amount <= 0 ) {
			$skip = PaymentException::NOTHING_DUE;
		} elseif ( empty( $booking['stripe_customer_id'] ) || empty( $booking['stripe_payment_method_id'] ) ) {
			$skip = PaymentException::NO_SAVED_CARD;
		} elseif ( self::hasPendingCardPayment( $payments ) ) {
			$skip = PaymentException::IN_PROGRESS;
		} elseif ( ! $manual && BookingStatus::BalanceFailed->value === $booking['status'] ) {
			$last  = end( $attempts );
			$limit = Db::utc( $this->clock->now()->modify( sprintf( '-%d hours', self::RETRY_AFTER_HOURS ) ) );
			if ( count( $attempts ) >= self::MAX_AUTO_ATTEMPTS ) {
				$skip = 'retries_exhausted';
			} elseif ( false !== $last && (string) $last['created_at'] > $limit ) {
				$skip = 'retry_later';
			}
		}

		return array(
			'amount'  => max( 0, $amount ),
			'attempt' => $attempt,
			'skip'    => $skip,
		);
	}

	/**
	 * Whether a card payment of the booking is still open (pending): an
	 * off-session charge, a payment-page intent, or a request with unknown outcome.
	 *
	 * @param list<array<string, mixed>> $payments
	 */
	public static function hasPendingCardPayment( array $payments, string $exceptKeyPrefix = '' ): bool {
		foreach ( $payments as $p ) {
			if ( 'refund' !== $p['kind'] && PaymentRepository::METHOD_STRIPE === $p['method'] && 'pending' === $p['status']
				&& ( '' === $exceptKeyPrefix || ! str_starts_with( (string) ( $p['idempotency_key'] ?? '' ), $exceptKeyPrefix ) ) ) {
				return true;
			}
		}

		return false;
	}
}
