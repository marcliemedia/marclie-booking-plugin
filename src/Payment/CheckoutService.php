<?php
/**
 * Online checkout (POST /bookings) and guest balance payments
 * (POST /bookings/{ref}/balance-intent).
 *
 * start(): re-quotes on the server, holds the nights (pending_payment, hold of
 * policy.hold_minutes), reuses or creates the guest's Stripe Customer and
 * creates the PaymentIntent the browser confirms with the Payment Element
 * (deferred intent: amount = deposit, or the total when paying in full;
 * setup_future_usage=off_session so the balance can be charged later). The
 * payment row is written before Stripe is called and its ID travels in the
 * intent metadata, so a webhook can never create a second row for it.
 * A guest's own earlier unpaid hold (changed party, coupon, corrected email…)
 * is superseded only when the request proves it with that booking's link token.
 */

namespace Marclie\Booking\Payment;

use Marclie\Booking\Domain\BookingStatus;
use Marclie\Booking\Domain\Clock;
use Marclie\Booking\Domain\Exception\InvalidTransitionException;
use Marclie\Booking\Domain\Exception\PaymentException;
use Marclie\Booking\Domain\Stay;
use Marclie\Booking\Repository\BookingRepository;
use Marclie\Booking\Repository\Db;
use Marclie\Booking\Repository\GuestRepository;
use Marclie\Booking\Repository\PaymentRepository;
use Marclie\Booking\Repository\TokenRepository;
use Marclie\Booking\Service\BookingService;
use Marclie\Booking\Service\PricingService;
use Marclie\Booking\Units\UnitProvider;

defined( 'ABSPATH' ) || exit;

final class CheckoutService {

	public const ACTOR = 'guest';

	/** Idempotency-key prefix of on-session payments from the payment page. */
	public const PAY_KEY_PREFIX = 'mbk-pay-';

	/** Intent statuses a payment page may (re)use. */
	private const REUSABLE = array( 'requires_payment_method', 'requires_confirmation', 'requires_action' );

	public function __construct(
		private BookingService $bookings,
		private BookingRepository $bookingRepo,
		private PricingService $pricing,
		private GuestRepository $guests,
		private PaymentRepository $payments,
		private UnitProvider $units,
		private ?PaymentGateway $gateway,
		private PaymentReconciler $reconciler,
		private Clock $clock,
		private string $site
	) {}

	public function available(): bool {
		return null !== $this->gateway;
	}

	/**
	 * @param array{email:string, first_name:string, last_name:string, phone:string, country:string, arrival_time?:string, message?:string, terms_accepted:bool, terms_version?:string} $guest
	 * @param array{ref?:string, t?:string}|null $supersede The guest's own earlier unpaid hold (ref + link token) this checkout replaces.
	 * @return array{booking: array<string, mixed>, token: string, intent: Intent, amount_cents: int, pay_in_full: bool}
	 * @throws PaymentException payments_unavailable (nothing is kept on a Stripe error).
	 * @throws \Marclie\Booking\Domain\Exception\BookingException unavailable, min_stay, capacity, coupon_invalid…
	 */
	public function start( Stay $stay, int $adults, int $children, ?string $coupon, array $guest, string $lang, ?array $supersede = null, int $infants = 0 ): array {
		$gateway = $this->gateway;
		if ( null === $gateway ) {
			throw PaymentException::unavailable();
		}

		$email    = GuestRepository::normalizeEmail( $guest['email'] );
		$existing = $this->guests->findByEmail( $email );
		if ( null !== $supersede ) {
			$this->supersede( $stay, (string) ( $supersede['ref'] ?? '' ), (string) ( $supersede['t'] ?? '' ) );
		}

		$quote = $this->pricing->quote( $stay, $adults, $children, $coupon, $existing ? (int) $existing['id'] : null, $infants );

		/**
		 * Fires right before a checkout holds the stay's nights: the iCal sync
		 * refreshes the unit's stale import feeds here (Ical\SyncService).
		 *
		 * @param Stay $stay
		 */
		do_action( 'mbk_checkout_before_hold', $stay );

		$created = $this->bookings->create( $stay, $quote, $guest, $lang, 'direct' );
		$booking = $created['booking'];
		$id      = (int) $booking['id'];
		$kind    = $quote->payInFull ? 'full' : 'deposit';
		$amount  = $quote->deposit->cents();

		try {
			$key       = 'mbk-deposit-' . $id;
			$paymentId = (int) Db::check(
				$this->payments->insert(
					array(
						'booking_id'      => $id,
						'kind'            => $kind,
						'method'          => PaymentRepository::METHOD_STRIPE,
						'amount_cents'    => $amount,
						'status'          => 'pending',
						'idempotency_key' => $key,
						'created_at'      => Db::utc( $this->clock->now() ),
					)
				) ?: false,
				'Recording payment'
			);
			$intent    = $this->createIntent( $booking, $gateway, $amount, $kind, $paymentId, $key );
			$this->payments->update( $paymentId, array( 'stripe_intent_id' => $intent->id ) );
		} catch ( GatewayException $e ) {
			error_log( '[marclie-booking] checkout ' . $booking['ref'] . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			if ( isset( $paymentId ) ) {
				// The guest never got a client secret: an intent created anyway can never be confirmed.
				$this->payments->update( $paymentId, array( 'status' => 'failed', 'error_code' => PaymentReconciler::NOT_CREATED ) );
			}
			$this->bookings->transition( $id, BookingStatus::Expired, self::ACTOR, array( 'reason' => 'payment_init_failed' ) );
			throw PaymentException::unavailable( $e );
		}

		return array(
			'booking'      => (array) $this->bookings->find( $id ),
			'token'        => $created['token'],
			'intent'       => $intent,
			'amount_cents' => $amount,
			'pay_in_full'  => $quote->payInFull,
		);
	}

	/**
	 * Why the guest cannot pay the booking online now, or null:
	 * `not_payable` (final status, or a checkout hold), `in_progress` (another
	 * card payment — e.g. the off-session balance charge — is open),
	 * `nothing_due`.
	 *
	 * @param array<string, mixed>       $booking
	 * @param list<array<string, mixed>> $payments
	 */
	public static function notPayableReason( array $booking, array $payments ): ?string {
		$status  = BookingStatus::tryFrom( (string) $booking['status'] );
		$payable = in_array( $status, array( BookingStatus::Confirmed, BookingStatus::BalanceFailed ), true )
			|| ( BookingStatus::PendingPayment === $status && null === $booking['hold_expires_at'] ); // Admin hold paid through a payment link.

		return match ( true ) {
			! $payable => PaymentException::NOT_PAYABLE,
			BalanceCharger::hasPendingCardPayment( $payments, self::PAY_KEY_PREFIX . $booking['id'] . '-' ) => PaymentException::IN_PROGRESS,
			PaymentRepository::net( $payments ) >= (int) $booking['total_cents'] => PaymentException::NOTHING_DUE,
			default => null,
		};
	}

	/**
	 * On-session payment of everything outstanding (payment page): reuses the
	 * open intent of an earlier visit for the same amount, else cancels the
	 * earlier visits' intents and creates a new one. Refused while an
	 * off-session charge (or any other card payment) is open.
	 *
	 * @param array<string, mixed> $booking
	 * @return array{intent: Intent, amount_cents: int}
	 * @throws PaymentException not_payable, in_progress, nothing_due, payments_unavailable.
	 */
	public function balanceIntent( array $booking ): array {
		$gateway = $this->gateway;
		if ( null === $gateway ) {
			throw PaymentException::unavailable();
		}

		$id     = (int) $booking['id'];
		$prefix = self::PAY_KEY_PREFIX . $id . '-';
		$this->reconciler->reconcile( $booking );
		$booking = (array) $this->bookings->find( $id );
		$rows    = $this->payments->forBooking( $id );
		$this->assertPayable( $booking, $rows );
		$net    = PaymentRepository::net( $rows );
		$amount = (int) $booking['total_cents'] - $net;

		$tries = array_values( array_filter( $rows, static fn( array $r ): bool => str_starts_with( (string) ( $r['idempotency_key'] ?? '' ), $prefix ) ) );
		$last  = end( $tries );
		try {
			if ( false !== $last && 'pending' === $last['status'] && (int) $last['amount_cents'] === $amount && ! empty( $last['stripe_intent_id'] ) ) {
				$open = $gateway->retrieveIntent( (string) $last['stripe_intent_id'] );
				if ( in_array( $open->status, self::REUSABLE, true ) ) {
					return array(
						'intent'       => $open,
						'amount_cents' => $amount,
					);
				}
			}

			// Earlier visits' intents (other amount) must not stay payable next to the new one.
			$this->reconciler->cancelOpenIntents( $id, $prefix, self::ACTOR );
			$booking = (array) $this->bookings->find( $id );
			$rows    = $this->payments->forBooking( $id );
			$this->assertPayable( $booking, $rows );
			$net    = PaymentRepository::net( $rows );
			$amount = (int) $booking['total_cents'] - $net;
			if ( BalanceCharger::hasPendingCardPayment( $rows ) ) {
				throw new PaymentException( PaymentException::IN_PROGRESS, 'A card payment is being processed.' ); // One of them is processing.
			}

			$kind      = $net > 0 ? 'balance' : 'full';
			$key       = $prefix . ( count( $tries ) + 1 );
			$paymentId = (int) Db::check(
				$this->payments->insert(
					array(
						'booking_id'      => $id,
						'kind'            => $kind,
						'method'          => PaymentRepository::METHOD_STRIPE,
						'amount_cents'    => $amount,
						'status'          => 'pending',
						'idempotency_key' => $key,
						'created_at'      => Db::utc( $this->clock->now() ),
					)
				) ?: false,
				'Recording payment'
			);
			$intent    = $this->createIntent( $booking, $gateway, $amount, $kind, $paymentId, $key );
			$this->payments->update( $paymentId, array( 'stripe_intent_id' => $intent->id ) );
		} catch ( GatewayException $e ) {
			error_log( '[marclie-booking] payment page ' . $booking['ref'] . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			if ( isset( $paymentId ) ) {
				// The page never got the client secret: an intent created anyway can never be confirmed.
				$this->payments->update( $paymentId, array( 'status' => 'failed', 'error_code' => PaymentReconciler::NOT_CREATED ) );
			}
			throw PaymentException::unavailable( $e );
		}

		return array(
			'intent'       => $intent,
			'amount_cents' => $amount,
		);
	}

	/**
	 * @param array<string, mixed>       $booking
	 * @param list<array<string, mixed>> $rows
	 * @throws PaymentException
	 */
	private function assertPayable( array $booking, array $rows ): void {
		$reason = self::notPayableReason( $booking, $rows );
		if ( null !== $reason ) {
			throw new PaymentException( $reason, 'The booking cannot be paid online now.', array( 'reason' => $reason ) );
		}
	}

	/**
	 * Creates the intent for the guest's customer. A stored customer unknown to
	 * this account/mode (e.g. created in test mode before going live) is replaced once.
	 *
	 * @param array<string, mixed> $booking
	 * @throws GatewayException
	 */
	private function createIntent( array $booking, PaymentGateway $gateway, int $amount, string $kind, int $paymentId, string $key ): Intent {
		$customer = $this->customer( $booking, $gateway );
		try {
			return $gateway->createIntent( $this->intentParams( $booking, $customer, $amount, $kind, $paymentId ), $key );
		} catch ( GatewayException $e ) {
			if ( 'resource_missing' !== $e->stripeCode ) {
				throw $e;
			}
			if ( ! empty( $booking['guest_id'] ) ) {
				$this->guests->update( (int) $booking['guest_id'], array( 'stripe_customer_id' => null ) );
			}
			$booking['stripe_customer_id'] = null;
			$customer                      = $this->customer( $booking, $gateway );

			return $gateway->createIntent( $this->intentParams( $booking, $customer, $amount, $kind, $paymentId ), $key . '-c' );
		}
	}

	/**
	 * @param array<string, mixed> $booking
	 * @return array<string, mixed>
	 */
	private function intentParams( array $booking, string $customer, int $amount, string $kind, int $paymentId ): array {
		$unit   = $this->units->get( (int) $booking['unit_id'] );
		$params = array(
			'amount'               => $amount,
			'currency'             => 'eur',
			'customer'             => $customer,
			'setup_future_usage'   => 'off_session',
			// API 2026-09-30.endive: `payment_method_types` was removed; cards only (off-session balance needs a saved card).
			'allowed_payment_method_types' => array( 'card' ),
			'description'          => BalanceCharger::description( (string) $booking['ref'], $unit?->title ?? '', $kind ),
			'metadata'             => array(
				'booking_id' => (string) $booking['id'],
				'ref'        => (string) $booking['ref'],
				'kind'       => $kind,
				'payment_id' => (string) $paymentId,
				'site'       => $this->site,
			),
		);

		/**
		 * Card statement descriptor suffix (Stripe: up to 22 characters together
		 * with the account prefix; empty = none). Default "KP {unit code}".
		 *
		 * @param string               $suffix
		 * @param array<string, mixed> $booking
		 */
		$suffix = (string) apply_filters( 'mbk_statement_descriptor_suffix', null === $unit ? '' : 'KP ' . strtoupper( $unit->code ), $booking );
		if ( '' !== $suffix ) {
			$params['statement_descriptor_suffix'] = substr( $suffix, 0, 22 );
		}

		return $params;
	}

	/**
	 * Stripe Customer of the booking's guest: reused from the guest (or booking),
	 * else created and stored on both.
	 *
	 * @param array<string, mixed> $booking
	 * @throws GatewayException
	 */
	private function customer( array $booking, PaymentGateway $gateway ): string {
		$guest = empty( $booking['guest_id'] ) ? null : $this->guests->find( (int) $booking['guest_id'] );
		$id    = (string) ( $guest['stripe_customer_id'] ?? '' );
		if ( '' === $id ) {
			$id = (string) ( $booking['stripe_customer_id'] ?? '' );
		}

		if ( '' === $id ) {
			$params = array(
				'email'    => (string) $booking['email'],
				'name'     => trim( $booking['first_name'] . ' ' . $booking['last_name'] ),
				'metadata' => array( 'guest_id' => (string) ( $booking['guest_id'] ?? '' ) ),
			);
			if ( '' !== (string) $booking['phone'] ) {
				$params['phone'] = (string) $booking['phone'];
			}
			// Per booking: a key reused within 24 h with other parameters would be an idempotency_error.
			$id = $gateway->createCustomer( $params, 'mbk-customer-' . $booking['id'] . '-' . ( $booking['guest_id'] ?? 0 ) . '-' . $gateway->mode() );
			if ( null !== $guest ) {
				$this->guests->update( (int) $guest['id'], array( 'stripe_customer_id' => $id ) );
			}
		}

		if ( (string) ( $booking['stripe_customer_id'] ?? '' ) !== $id ) {
			$this->bookingRepo->update( (int) $booking['id'], array( 'stripe_customer_id' => $id ) );
		}

		return $id;
	}

	/**
	 * Expires the guest's own earlier unpaid checkout hold — proven by its link
	 * token, so the email may differ (corrected typo) — after cancelling its
	 * intent, when it is on the same unit and overlaps the new stay. A hold
	 * whose payment succeeded or is processing is kept (the new booking then
	 * gets `unavailable`). Anything else is ignored.
	 */
	private function supersede( Stay $stay, string $ref, string $token ): void {
		$hold = $this->bookings->verifyToken( $ref, $token, array( TokenRepository::ACCESS ) );
		if ( null === $hold || BookingStatus::PendingPayment->value !== $hold['status'] || 'direct' !== $hold['source'] ) {
			return;
		}
		$unitId = $this->units->get( $stay->unitId )?->id ?? $stay->unitId;
		if ( (int) $hold['unit_id'] !== $unitId || (string) $hold['arrival'] >= $stay->departureString() || (string) $hold['departure'] <= $stay->arrivalString() ) {
			return;
		}
		if ( ! $this->reconciler->shouldExpire( true, $hold ) ) {
			return;
		}
		try {
			$this->bookings->transition( (int) $hold['id'], BookingStatus::Expired, self::ACTOR, array( 'reason' => 'superseded' ) );
		} catch ( InvalidTransitionException ) {
			return; // Paid meanwhile.
		}
	}
}
