<?php
/**
 * Shared plumbing of the public REST controllers (`marclie-booking/v1/*`,
 * no authentication, no nonce; guest booking routes use link tokens):
 *
 * - language: `X-MBK-Lang: hr|en` header, else the visitor language; the whole
 *   callback runs with the plugin text domain switched to it;
 * - errors: `{ code, message (translated), data: { status, ...context } }`;
 * - `Cache-Control` per route (errors: `no-store`) and `Vary: X-MBK-Lang`. A
 *   translated payload whose language came from the cookie/query (no header)
 *   is `no-store`, so shared caches never mix languages;
 * - per-client fixed-window rate limits (transient keyed by a salted hash of
 *   the client IP, IPv6 per /64; X-Forwarded-For only via `mbk_trusted_proxies`).
 */

namespace Marclie\Booking\Rest\Public;

use Marclie\Booking\Domain\BookingHorizon;
use Marclie\Booking\Domain\Exception\BookingException;
use Marclie\Booking\Domain\Exception\PaymentException;
use Marclie\Booking\Domain\Exception\InvalidStayException;
use Marclie\Booking\Domain\Stay;
use Marclie\Booking\I18n\LanguageAdapter;
use Marclie\Booking\Plugin;

defined( 'ABSPATH' ) || exit;

abstract class BaseController {

	public const LANG_HEADER = 'X-MBK-Lang';

	public const CACHE_PUBLIC = 'public, max-age=60';

	public const CACHE_NONE = 'no-store';

	protected const DATE_PATTERN = '^\d{4}-\d{2}-\d{2}$';

	private const RATE_WINDOW = MINUTE_IN_SECONDS;

	/** HTTP status per error code. */
	private const STATUS = array(
		'invalid_stay'       => 400,
		'past_date'          => 422,
		'beyond_horizon'     => 422,
		'min_stay'           => 422,
		'capacity'           => 422,
		'unavailable'        => 409,
		'coupon_invalid'     => 422,
		'unit_not_found'     => 404,
		'no_rate'            => 422,
		'invalid_range'      => 400,
		'rate_limited'       => 429,
		'validation'         => 422,
		'not_found'          => 404,
		'invalid_request'    => 400,
		'invalid_transition' => 409,
	);

	abstract public function register(): void;

	/**
	 * @param callable(\WP_REST_Request, string): mixed $callback        Receives the request and its language (hr|en).
	 * @param array<string, array<string, mixed>>       $args
	 * @param bool                                      $languageNeutral The success payload has no translated or formatted strings.
	 */
	protected function route( string $path, string $methods, callable $callback, array $args, string $cacheControl, bool $languageNeutral = false ): void {
		register_rest_route(
			Plugin::REST_NAMESPACE,
			$path,
			array(
				'methods'             => $methods,
				'callback'            => fn( \WP_REST_Request $request ) => $this->handle( $request, $callback, $cacheControl, $languageNeutral ),
				'permission_callback' => '__return_true',
				'args'                => $args,
			)
		);
	}

	/**
	 * @param callable(\WP_REST_Request, string): mixed $callback
	 */
	private function handle( \WP_REST_Request $request, callable $callback, string $cacheControl, bool $languageNeutral ): \WP_REST_Response {
		$lang = self::language( $request );

		$response = LanguageAdapter::withLanguage(
			$lang,
			static function () use ( $request, $callback, $lang ): \WP_REST_Response {
				try {
					$result = $callback( $request, $lang );
				} catch ( BookingException $e ) {
					$result = self::error( $e->errorCode(), $e->context() );
				} catch ( \Throwable $e ) {
					error_log( '[marclie-booking] public REST: ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					$result = new \WP_Error( 'server_error', __( 'Something went wrong. Please try again.', 'marclie-booking' ), array( 'status' => 500 ) );
				}

				return $result instanceof \WP_Error ? rest_convert_error_to_response( $result ) : rest_ensure_response( $result );
			}
		);

		$data = $response->get_data();
		if ( $response->is_error() && isset( $data['data']['retry_after'] ) ) {
			$response->header( 'Retry-After', (string) (int) $data['data']['retry_after'] );
		}
		$langFromHeader = null !== LanguageAdapter::normalize( $request->get_header( self::LANG_HEADER ) );
		$cacheable      = ! $response->is_error() && ( $languageNeutral || $langFromHeader );
		$response->header( 'Cache-Control', self::CACHE_PUBLIC === $cacheControl && ! $cacheable ? self::CACHE_NONE : $cacheControl );
		$response->header( 'Vary', self::LANG_HEADER );

		return $response;
	}

	/** `X-MBK-Lang` header, else the visitor language (`lang` param / cookie). */
	public static function language( \WP_REST_Request $request ): string {
		return LanguageAdapter::normalize( $request->get_header( self::LANG_HEADER ) ) ?? LanguageAdapter::current();
	}

	/**
	 * Contract error with a translated message.
	 *
	 * @param array<string, mixed> $context
	 */
	public static function error( string $code, array $context = array() ): \WP_Error {
		if ( 'coupon_invalid' === $code ) {
			$context['reason'] = self::publicCouponReason( (string) ( $context['reason'] ?? '' ) );
		}

		return new \WP_Error( $code, self::message( $code, $context ), array_merge( $context, array( 'status' => self::STATUS[ $code ] ?? PaymentException::STATUS[ $code ] ?? 400 ) ) );
	}

	/**
	 * Coupon refusal reason shown to visitors: someone else's personal coupon
	 * reads as an unknown code, so codes cannot be probed for existence
	 * (the admin API keeps the real reason).
	 */
	public static function publicCouponReason( string $reason ): string {
		return '' === $reason || 'guest_only' === $reason ? 'not_found' : $reason;
	}

	/**
	 * Counts a request of the client IP in `$bucket`; an error once `$limit`
	 * requests were made in the current minute.
	 */
	protected static function throttle( string $bucket, int $limit, int $window = self::RATE_WINDOW ): ?\WP_Error {
		$key    = 'mbk_rl_' . $bucket . '_' . self::clientHash();
		$stored = get_transient( $key );
		$hit    = RateLimit::hit( is_array( $stored ) ? $stored : null, time(), $limit, $window );
		if ( ! $hit['allowed'] ) {
			return self::error( 'rate_limited', array( 'retry_after' => $hit['retry_after'] ) );
		}

		set_transient( $key, $hit['state'], $hit['ttl'] );

		return null;
	}

	/** Salted hash of the client bucket (IPv4 address / IPv6 /64): no IP is stored. */
	protected static function clientHash(): string {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed by filter_var in RateLimit.
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) : '';
		$xff    = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? wp_unslash( (string) $_SERVER['HTTP_X_FORWARDED_FOR'] ) : null;
		// phpcs:enable

		/**
		 * Reverse proxies / CDN edges (IPs or CIDRs) whose X-Forwarded-For is trusted
		 * for the public rate limits. Empty by default: REMOTE_ADDR is the client.
		 *
		 * @param list<string> $proxies
		 */
		$trusted = array_values( array_filter( array_map( 'strval', (array) apply_filters( 'mbk_trusted_proxies', array() ) ) ) );
		$bucket  = RateLimit::bucket( RateLimit::clientIp( $remote, $xff, $trusted ) );

		return substr( hash_hmac( 'sha256', $bucket, wp_salt( 'nonce' ) ), 0, 20 );
	}

	/**
	 * @throws InvalidStayException
	 */
	protected static function stay( int $unitId, string $arrival, string $departure ): Stay {
		return Stay::fromStrings( $unitId, $arrival, $departure, wp_timezone() );
	}

	/** @return array<string, mixed> */
	protected static function dateArg( bool $required ): array {
		return array(
			'type'     => 'string',
			'pattern'  => self::DATE_PATTERN,
			'required' => $required,
		);
	}

	/** @return array<string, mixed> */
	protected static function intArg( int $min, int $max, ?int $default = null, bool $required = false ): array {
		$arg = array(
			'type'     => 'integer',
			'minimum'  => $min,
			'maximum'  => $max,
			'required' => $required,
		);
		if ( null !== $default ) {
			$arg['default'] = $default;
		}

		return $arg;
	}

	/**
	 * Translated message per error code (current text-domain language).
	 *
	 * @param array<string, mixed> $context
	 */
	public static function message( string $code, array $context = array() ): string {
		switch ( $code ) {
			case 'invalid_stay':
				return __( 'Check the dates: departure must be after arrival.', 'marclie-booking' );
			case 'past_date':
				return __( 'The arrival date is in the past.', 'marclie-booking' );
			case 'beyond_horizon':
				/* translators: %d: how many months ahead bookings are possible (24) */
				return sprintf( _n( 'Bookings are possible up to %d month ahead.', 'Bookings are possible up to %d months ahead.', BookingHorizon::MONTHS, 'marclie-booking' ), BookingHorizon::MONTHS );
			case 'min_stay':
				$nights = (int) ( $context['required'] ?? 1 );
				/* translators: %d: minimum number of nights */
				return sprintf( _n( 'Minimum stay is %d night.', 'Minimum stay is %d nights.', $nights, 'marclie-booking' ), $nights );
			case 'capacity':
				$max = (int) ( $context['max'] ?? 0 );
				/* translators: %d: maximum number of guests */
				return sprintf( _n( 'Sleeps up to %d guest.', 'Sleeps up to %d guests.', $max, 'marclie-booking' ), $max );
			case 'unavailable':
				return __( 'These dates are already booked.', 'marclie-booking' );
			case 'coupon_invalid':
				return self::couponMessage( (string) ( $context['reason'] ?? '' ), $context );
			case 'unit_not_found':
				return __( 'Apartment not found.', 'marclie-booking' );
			case 'no_rate':
				return __( 'There is no price for these dates yet. Send us an enquiry.', 'marclie-booking' );
			case 'rate_limited':
				return __( 'Too many requests. Please try again in a minute.', 'marclie-booking' );
			case 'invalid_range':
				return __( 'These months are outside the booking calendar.', 'marclie-booking' );
			case 'validation':
				return __( 'Please correct the highlighted fields.', 'marclie-booking' );
			case 'not_found':
				return __( 'Booking not found. Check the link from your email.', 'marclie-booking' );
			case 'invalid_transition':
			case PaymentException::NOT_PAYABLE:
				return __( 'This booking cannot be paid online. Please contact us.', 'marclie-booking' );
			case PaymentException::UNAVAILABLE:
				return __( 'Online payment is currently unavailable. Please call us to book.', 'marclie-booking' );
			case PaymentException::NOTHING_DUE:
				return __( 'There is nothing left to pay for this booking.', 'marclie-booking' );
			case PaymentException::IN_PROGRESS:
				return __( 'A payment for this booking is being processed. Please wait a moment.', 'marclie-booking' );
			default:
				return __( 'The request could not be completed.', 'marclie-booking' );
		}
	}

	/** @param array<string, mixed> $context */
	public static function couponMessage( string $reason, array $context = array() ): string {
		switch ( $reason ) {
			case 'not_found':
				return __( 'This coupon code does not exist.', 'marclie-booking' );
			case 'inactive':
			case 'limit':
				return __( 'This coupon is no longer valid.', 'marclie-booking' );
			case 'expired':
			case 'not_started':
				return __( 'This coupon is not valid for these dates.', 'marclie-booking' );
			case 'min_nights':
				$nights = (int) ( $context['min_nights'] ?? 1 );
				/* translators: %d: minimum number of nights for the coupon */
				return sprintf( _n( 'This coupon requires a stay of at least %d night.', 'This coupon requires a stay of at least %d nights.', $nights, 'marclie-booking' ), $nights );
			case 'unit':
				return __( 'This coupon does not apply to this apartment.', 'marclie-booking' );
			case 'guest_limit':
			case 'guest_only':
				return __( 'This coupon cannot be used for this booking.', 'marclie-booking' );
			default:
				return __( 'The coupon cannot be applied.', 'marclie-booking' );
		}
	}
}
