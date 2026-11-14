<?php
/**
 * Guest booking endpoints (no nonce; never cached):
 *
 * - POST /bookings — online checkout: validates the guest, re-quotes on the
 *   server, holds the nights and returns the PaymentIntent client secret
 *   (201). Honeypot `website`, 5 bookings per hour per client, Turnstile token
 *   `captcha` when MBK_TURNSTILE_* are set (403 captcha_failed).
 *   409 unavailable · 422 validation (data.fields)/min_stay/capacity/coupon ·
 *   503 payments_unavailable · 429 rate_limited. Signed in to the verified
 *   guest account of the booking email (cookie + X-WP-Nonce), the booking and
 *   guest are linked to that user right away.
 * - GET /bookings/{ref}?t= — status for the confirmation/payment pages; asks
 *   Stripe while a card payment is open (webhook late). `payable` /
 *   `not_payable_reason` tell the payment page whether it can take money,
 *   `cancel_reason` why a booking ended (hold_lost…), `account` whether the
 *   booking is in a guest account or the email may still register.
 * - POST /bookings/{ref}/balance-intent?t= — on-session payment of everything
 *   outstanding (/rezervacija/placanje/); 409 not_payable / nothing_due / in_progress.
 * - POST /bookings/{ref}/ga?t= — `{send:true}` exactly once per booking, when
 *   the GA purchase event should be pushed.
 *
 * `t` is a guest link token (purposes access, manage, pay); a wrong ref/token
 * answers 404 not_found.
 */

namespace Marclie\Booking\Rest\Public;

use Marclie\Booking\Container;
use Marclie\Booking\Domain\BookingStatus;
use Marclie\Booking\Domain\Exception\ValidationException;
use Marclie\Booking\Domain\Policy;
use Marclie\Booking\Domain\Quote;
use Marclie\Booking\Frontend\Renderer;
use Marclie\Booking\I18n\Countries;
use Marclie\Booking\I18n\Formatter;
use Marclie\Booking\Payment\CheckoutService;
use Marclie\Booking\Payment\Intent;
use Marclie\Booking\Repository\PaymentRepository;
use Marclie\Booking\Service\QuoteDisplay;
use Marclie\Booking\Service\SearchService;
use Marclie\Booking\Service\Turnstile;
use Marclie\Booking\Settings;

defined( 'ABSPATH' ) || exit;

final class BookingController extends BaseController {

	/** Bookings per client and hour. */
	public const RATE_LIMIT = 5;

	public const STATUS_RATE_LIMIT = 60;

	public const PAY_RATE_LIMIT = 10;

	private const REF = '/bookings/(?P<ref>[A-Za-z0-9][A-Za-z0-9-]{2,39})';

	private const MESSAGE_MAX = 2000;

	public function register(): void {
		$this->route(
			'/bookings',
			\WP_REST_Server::CREATABLE,
			array( $this, 'create' ),
			QuoteController::stayArgs() + array(
				'coupon'       => array(
					'type'      => array( 'string', 'null' ),
					'maxLength' => 40,
				),
				'guest'        => array(
					'type'     => 'object',
					'required' => true,
				),
				'arrival_time' => array( 'type' => array( 'string', 'null' ) ),
				'message'      => array( 'type' => array( 'string', 'null' ) ),
				'terms'        => array( 'type' => array( 'boolean', 'null' ) ),
				'website'      => array( 'type' => array( 'string', 'null' ) ),
				'captcha'      => array(
					'type'      => array( 'string', 'null' ),
					'maxLength' => 2048,
				),
				'supersede'    => array( 'type' => array( 'object', 'null' ) ),
			),
			self::CACHE_NONE
		);
		$token = array(
			't' => array(
				'type'     => 'string',
				'required' => true,
			),
		);
		$this->route( self::REF, \WP_REST_Server::READABLE, array( $this, 'show' ), $token, self::CACHE_NONE );
		$this->route( self::REF . '/balance-intent', \WP_REST_Server::CREATABLE, array( $this, 'balanceIntent' ), $token, self::CACHE_NONE );
		$this->route( self::REF . '/ga', \WP_REST_Server::CREATABLE, array( $this, 'gaSent' ), $token, self::CACHE_NONE, true );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function create( \WP_REST_Request $request, string $lang ) {
		if ( '' !== trim( (string) $request['website'] ) ) {
			return self::error( 'invalid_request' );
		}

		$stay = QuoteController::requestStay( $request );
		if ( $stay instanceof \WP_Error ) {
			return $stay;
		}
		$unit  = Container::units()->get( $stay->unitId );
		$guest = self::validGuest( $request, $unit?->checkinFrom ?? Settings::checkinFrom(), $unit?->checkinTo ?? Settings::checkinTo() );
		// Signed in with the verified account of this email (X-WP-Nonce sent): the booking belongs to it right away.
		$userId = Container::accounts()->checkoutUserId( $guest['email'] );
		if ( null !== $userId ) {
			$guest['user_id'] = $userId;
		}

		$limited = self::throttle( 'booking', self::RATE_LIMIT, HOUR_IN_SECONDS );
		if ( $limited ) {
			return $limited;
		}
		if ( ! Turnstile::verify( (string) $request['captcha'], self::clientIp() ) ) {
			return self::error( 'captcha_failed' );
		}

		$coupon    = trim( (string) $request['coupon'] );
		$supersede = is_array( $request['supersede'] ) ? $request['supersede'] : null;
		$result    = Container::checkout()->start( $stay, (int) $request['adults'], (int) $request['children'], '' !== $coupon ? $coupon : null, $guest, $lang, $supersede, (int) $request['infants'] );

		$booking = $result['booking'];
		$query   = array(
			'ref' => rawurlencode( (string) $booking['ref'] ),
			't'   => $result['token'],
		);

		return new \WP_REST_Response(
			array(
				'ref'             => (string) $booking['ref'],
				'token'           => $result['token'],
				'status'          => (string) $booking['status'],
				'amount_cents'    => $result['amount_cents'],
				'pay_in_full'     => $result['pay_in_full'],
				'client_secret'   => (string) $result['intent']->clientSecret,
				'return_url'      => add_query_arg( $query, Renderer::pageUrl( 'hvala', $lang ) ),
				'hold_expires_at' => self::iso( $booking['hold_expires_at'] ),
			),
			201
		);
	}

	/** @return array<string, mixed>|\WP_Error */
	public function show( \WP_REST_Request $request, string $lang ) {
		$limited = self::throttle( 'booking_status', self::STATUS_RATE_LIMIT );
		if ( $limited ) {
			return $limited;
		}
		$booking = self::booking( $request );
		if ( $booking instanceof \WP_Error ) {
			return $booking;
		}

		$intent = Container::reconciler()->reconcile( $booking );
		if ( null !== $intent ) {
			$booking = (array) Container::bookings()->find( (int) $booking['id'] );
		}

		return self::present( $booking, $intent, $lang );
	}

	/** @return array<string, mixed>|\WP_Error */
	public function balanceIntent( \WP_REST_Request $request, string $lang ) {
		$limited = self::throttle( 'balance_intent', self::PAY_RATE_LIMIT );
		if ( $limited ) {
			return $limited;
		}
		$booking = self::booking( $request );
		if ( $booking instanceof \WP_Error ) {
			return $booking;
		}

		$result = Container::checkout()->balanceIntent( $booking );

		return array(
			'client_secret' => (string) $result['intent']->clientSecret,
			'amount_cents'  => $result['amount_cents'],
			'amount'        => Formatter::price( $result['amount_cents'], $lang ),
		);
	}

	/** @return array{send: bool}|\WP_Error */
	public function gaSent( \WP_REST_Request $request ) {
		$limited = self::throttle( 'booking_status', self::STATUS_RATE_LIMIT );
		if ( $limited ) {
			return $limited;
		}
		$booking = self::booking( $request );
		if ( $booking instanceof \WP_Error ) {
			return $booking;
		}

		$sold = in_array( $booking['status'], array( BookingStatus::Confirmed->value, BookingStatus::Paid->value, BookingStatus::BalanceFailed->value, BookingStatus::Completed->value ), true );

		return array( 'send' => $sold && Container::bookings()->markGaSent( (int) $booking['id'] ) );
	}

	/**
	 * @param array<string, mixed> $booking
	 * @return array<string, mixed>
	 */
	private static function present( array $booking, ?Intent $intent, string $lang ): array {
		$tz       = wp_timezone();
		$snapshot = json_decode( (string) $booking['quote_json'], true );
		$quote    = Quote::fromArray( is_array( $snapshot ) ? $snapshot : array(), $tz );
		$policy   = json_decode( (string) $booking['policy_json'], true );
		$policy   = Policy::fromArray( is_array( $policy ) ? $policy : array() );
		$payments = Container::paymentRepository()->forBooking( (int) $booking['id'] );
		$total    = (int) $booking['total_cents'];
		$paid     = PaymentRepository::net( $payments );
		$unit     = Container::units()->get( (int) $booking['unit_id'] );
		$money    = static fn( int $cents ): array => array(
			'cents'     => $cents,
			'formatted' => Formatter::price( $cents, $lang ),
		);

		$display = QuoteDisplay::build( $quote, $lang, $policy->depositPct ) + array(
			'deposit_pct'    => Formatter::percent( $policy->depositPct, $lang ),
			'checkin_window' => null === $unit ? '' : $unit->checkinFrom . '–' . $unit->checkinTo,
			/* translators: %s: latest check-out time, e.g. "10:00". */
			'checkout_until' => null === $unit || '' === $unit->checkoutUntil ? '' : sprintf( __( 'by %s', 'marclie-booking' ), $unit->checkoutUntil ),
			'arrival'        => Formatter::date( (string) $booking['arrival'], $lang ),
			'departure'      => Formatter::date( (string) $booking['departure'], $lang ),
		);
		$thumb   = null !== $unit && $unit->thumbnailId ? wp_get_attachment_image_url( $unit->thumbnailId, 'medium_large' ) : false;
		$house   = null !== $unit && null !== $unit->houseSlug ? get_term_by( 'slug', $unit->houseSlug, 'kuca' ) : false;

		$notPayable = CheckoutService::notPayableReason( $booking, $payments );

		return array(
			'ref'                => (string) $booking['ref'],
			'status'             => (string) $booking['status'],
			'payment_state'      => self::paymentState( $payments, $intent, (string) $booking['status'] ),
			'cancel_reason'      => self::endReason( $booking ),
			'payable'            => null === $notPayable,
			'not_payable_reason' => $notPayable,
			'unit'              => array(
				'id'        => (int) $booking['unit_id'],
				'title'     => null === $unit ? '' : $unit->title,
				'house'     => $house instanceof \WP_Term ? $house->name : '',
				'place'     => null === $unit ? '' : Renderer::unitCard( $unit, $lang )['place'],
				'permalink' => null === $unit ? '' : SearchService::permalink( $unit, $lang ),
				'thumb'     => $thumb ? (string) $thumb : null,
			),
			'arrival'           => (string) $booking['arrival'],
			'departure'         => (string) $booking['departure'],
			'nights'            => (int) $booking['nights'],
			'adults'            => (int) $booking['adults'],
			'children'          => (int) $booking['children'],
			'infants'           => (int) $booking['infants'],
			'guest_first_name'  => (string) $booking['first_name'],
			'totals'            => array(
				'total'   => $money( $total ),
				'paid'    => $money( $paid ),
				'balance' => $money( max( 0, $total - $paid ) ),
			),
			'balance_due_on'    => self::nullable( $booking['balance_due_on'] ),
			'free_cancel_until' => self::nullable( $booking['free_cancel_until'] ),
			'pay_in_full'       => $quote->payInFull,
			// How the outstanding balance is collected: 'card' (saved card, charged automatically; website bookings
			// always pay by card, even before the webhook stores the method), 'manual' (admin booking paid outside
			// Stripe: staff get in touch) or null when nothing is due.
			'balance_method'    => $total - $paid <= 0 ? null : ( empty( $booking['stripe_payment_method_id'] ) && 'direct' !== $booking['source'] ? 'manual' : 'card' ),
			'hold_expires_at'   => self::iso( $booking['hold_expires_at'] ),
			'display'           => $display,
			'ga_sent'           => ! empty( $booking['ga_sent_at'] ),
			'purchase'          => array(
				'transaction_id' => (string) $booking['ref'],
				'value'          => round( $total / 100, 2 ),
				'deposit'        => round( (int) $booking['deposit_cents'] / 100, 2 ),
				'currency'       => (string) $booking['currency'],
				'item_id'        => null === $unit || '' === $unit->code ? (string) $booking['unit_id'] : $unit->code,
				'item_name'      => null === $unit ? '' : $unit->title,
				'quantity'       => max( 1, (int) $booking['nights'] ),
				'coupon'         => self::nullable( $booking['coupon_code'] ),
			),
			// Hvala page: link to the account, or an invitation to register with the booking email.
			'account'           => array(
				'linked'       => ! empty( $booking['user_id'] ),
				'invite_email' => empty( $booking['user_id'] ) && false === email_exists( (string) $booking['email'] ) ? (string) $booking['email'] : null,
			),
		);
	}

	/**
	 * Why a cancelled/expired booking ended: the `reason` of the status change
	 * (hold_lost, superseded, payment_init_failed…), `admin` for a staff
	 * cancellation, else null.
	 *
	 * @param array<string, mixed> $booking
	 */
	private static function endReason( array $booking ): ?string {
		if ( ! in_array( $booking['status'], array( BookingStatus::Cancelled->value, BookingStatus::Expired->value ), true ) ) {
			return null;
		}
		foreach ( array_reverse( Container::eventRepository()->forBooking( (int) $booking['id'] ) ) as $event ) {
			if ( 'status_changed' === $event['type'] && ( $event['payload']['to'] ?? '' ) === $booking['status'] ) {
				$reason = $event['payload']['context']['reason'] ?? null;
				if ( is_string( $reason ) && '' !== $reason && ! str_contains( $reason, ' ' ) ) {
					return $reason;
				}

				return str_starts_with( (string) $event['actor'], 'user:' ) ? 'admin' : null;
			}
		}

		return null;
	}

	/**
	 * `refunded` once card money of a cancelled/expired booking was (or is
	 * being) refunded; else the live intent state when Stripe was asked, else
	 * derived from the newest card payment row.
	 *
	 * @param list<array<string, mixed>> $payments
	 */
	private static function paymentState( array $payments, ?Intent $intent, string $status ): string {
		$ended = in_array( $status, array( BookingStatus::Cancelled->value, BookingStatus::Expired->value ), true );
		foreach ( $ended ? $payments : array() as $row ) {
			if ( 'refund' === $row['kind'] && PaymentRepository::METHOD_STRIPE === $row['method'] && in_array( $row['status'], PaymentRepository::REFUND_COUNTED, true ) ) {
				return 'refunded';
			}
		}
		if ( null !== $intent ) {
			return $intent->paymentState();
		}
		foreach ( array_reverse( $payments ) as $row ) {
			if ( 'refund' === $row['kind'] || PaymentRepository::METHOD_STRIPE !== $row['method'] ) {
				continue;
			}

			return match ( $row['status'] ) {
				'succeeded', 'refunded' => Intent::SUCCEEDED,
				'failed' => 'failed',
				default => Intent::STATE_NONE,
			};
		}

		return Intent::STATE_NONE;
	}

	/** @return array<string, mixed>|\WP_Error */
	private static function booking( \WP_REST_Request $request ) {
		$booking = Container::bookings()->verifyToken( (string) $request['ref'], (string) $request['t'] );

		return null === $booking ? self::error( 'not_found' ) : $booking;
	}

	/**
	 * Guest data for BookingService::create(), or a 422 with every invalid field.
	 *
	 * @return array<string, mixed>
	 * @throws ValidationException
	 */
	private static function validGuest( \WP_REST_Request $request, string $checkinFrom, string $checkinTo ): array {
		$input  = (array) $request['guest'];
		$text   = static fn( string $key ): string => trim( sanitize_text_field( (string) ( $input[ $key ] ?? '' ) ) );
		$guest  = array(
			'first_name' => $text( 'first_name' ),
			'last_name'  => $text( 'last_name' ),
			'email'      => strtolower( $text( 'email' ) ),
			'phone'      => $text( 'phone' ),
			'country'    => strtoupper( $text( 'country' ) ),
		);
		$errors = array();

		if ( '' === $guest['first_name'] || mb_strlen( $guest['first_name'] ) > 100 ) {
			$errors['guest.first_name'] = __( 'Enter your first name.', 'marclie-booking' );
		}
		if ( '' === $guest['last_name'] || mb_strlen( $guest['last_name'] ) > 100 ) {
			$errors['guest.last_name'] = __( 'Enter your last name.', 'marclie-booking' );
		}
		if ( '' === $guest['email'] || strlen( $guest['email'] ) > 190 || ! is_email( $guest['email'] ) ) {
			$errors['guest.email'] = __( 'Enter a valid email address.', 'marclie-booking' );
		}
		if ( ! preg_match( '/^\+?[0-9][0-9 ()\/.-]{5,38}$/', $guest['phone'] ) ) {
			$errors['guest.phone'] = __( 'Enter a valid phone number with the country code.', 'marclie-booking' );
		}
		// The checkout no longer asks for a country (Stripe's card form has its own); other clients may send one.
		if ( '' !== $guest['country'] && ! Countries::isValid( $guest['country'] ) ) {
			$errors['guest.country'] = __( 'Choose your country.', 'marclie-booking' );
		}

		$arrivalTime = trim( (string) $request['arrival_time'] );
		if ( '' !== $arrivalTime && ( ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $arrivalTime ) || $arrivalTime < $checkinFrom || $arrivalTime > $checkinTo ) ) {
			/* translators: 1: check-in from (15:00), 2: check-in until (22:00) */
			$errors['arrival_time'] = sprintf( __( 'Choose an arrival time between %1$s and %2$s.', 'marclie-booking' ), $checkinFrom, $checkinTo );
		}
		$message = trim( sanitize_textarea_field( (string) $request['message'] ) );
		if ( mb_strlen( $message ) > self::MESSAGE_MAX ) {
			/* translators: %d: maximum number of characters */
			$errors['message'] = sprintf( __( 'The message can be at most %d characters long.', 'marclie-booking' ), self::MESSAGE_MAX );
		}
		if ( true !== $request['terms'] ) {
			$errors['terms'] = __( 'Please accept the booking terms.', 'marclie-booking' );
		}

		if ( $errors ) {
			throw ValidationException::fields( $errors );
		}

		$termsPage = Settings::pageId( 'uvjeti' );

		return $guest + array(
			'arrival_time'   => $arrivalTime,
			'message'        => $message,
			'terms_accepted' => true,
			// Terms version = last change of the terms page.
			'terms_version'  => $termsPage ? (string) get_post_modified_time( 'Y-m-d', true, $termsPage ) : null,
		);
	}

	/** UTC DATETIME → ISO 8601 in the site timezone. */
	private static function iso( mixed $utc ): ?string {
		if ( null === $utc || '' === $utc ) {
			return null;
		}

		return ( new \DateTimeImmutable( (string) $utc, new \DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() )->format( DATE_ATOM );
	}

	private static function nullable( mixed $value ): ?string {
		return null === $value || '' === $value ? null : (string) $value;
	}
}
