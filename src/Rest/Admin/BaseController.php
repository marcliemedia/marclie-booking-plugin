<?php
/**
 * Shared plumbing of the admin REST controllers (`marclie-booking/v1/admin/*`):
 * route registration with a capability check, exception → WP_Error mapping
 * (`{ code, message, data: { status, ...context } }`) and common arguments.
 * `Cache-Control: no-store, private` and the `validation` shape of WordPress
 * argument errors are applied for every admin route in Routes.
 */

namespace Marclie\Booking\Rest\Admin;

use Marclie\Booking\Domain\BookingHorizon;
use Marclie\Booking\Domain\Exception\BookingException;
use Marclie\Booking\Domain\Exception\PaymentException;
use Marclie\Booking\Domain\Exception\ValidationException;
use Marclie\Booking\Plugin;

defined( 'ABSPATH' ) || exit;

abstract class BaseController {

	public const PREFIX = '/admin';

	protected const DATE_PATTERN = '^\d{4}-\d{2}-\d{2}$';

	/** HTTP status per domain error code. */
	private const STATUS = array(
		'invalid_stay'              => 400,
		'past_date'                 => 422,
		'beyond_horizon'            => 422,
		'min_stay'                  => 422,
		'capacity'                  => 422,
		'unavailable'               => 409,
		'coupon_invalid'            => 422,
		'invalid_transition'        => 409,
		'unit_not_found'            => 404,
		'no_rate'                   => 422,
		'not_found'                 => 404,
		'validation'                => 422,
		'duplicate_payment'         => 409,
		'guest_has_active_bookings' => 409,
	);

	abstract public function register(): void;

	/**
	 * @param array<int|string, mixed> $endpoints One endpoint or a list (register_rest_route format).
	 */
	protected function route( string $path, array $endpoints ): void {
		register_rest_route( Plugin::REST_NAMESPACE, self::PREFIX . $path, $endpoints );
	}

	/**
	 * Endpoint definition whose callback runs through handle().
	 *
	 * @param callable(\WP_REST_Request): mixed $callback
	 * @param array<string, array<string, mixed>> $args
	 * @return array<string, mixed>
	 */
	protected function endpoint( string $methods, callable $callback, string $capability, array $args = array() ): array {
		return array(
			'methods'             => $methods,
			'callback'            => fn( \WP_REST_Request $request ) => $this->handle( static fn() => $callback( $request ) ),
			'permission_callback' => static fn(): bool => current_user_can( $capability ),
			'args'                => $args,
		);
	}

	/**
	 * Runs a callback; arrays become 200 responses, exceptions become WP_Error.
	 *
	 * @param callable(): mixed $callback
	 * @return \WP_REST_Response|\WP_Error
	 */
	protected function handle( callable $callback ) {
		try {
			$result = $callback();
		} catch ( BookingException $e ) {
			return self::domainError( $e );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'invalid_request', $e->getMessage(), array( 'status' => 400 ) );
		} catch ( \Throwable $e ) {
			error_log( '[marclie-booking] admin REST: ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			return new \WP_Error( 'server_error', __( 'Something went wrong. Please try again.', 'marclie-booking' ), array( 'status' => 500 ) );
		}

		if ( $result instanceof \WP_REST_Response || $result instanceof \WP_Error ) {
			return $result;
		}

		return new \WP_REST_Response( $result );
	}

	public static function domainError( BookingException $e ): \WP_Error {
		$code    = $e->errorCode();
		$context = $e->context();

		return new \WP_Error( $code, self::message( $code, $context ), array_merge( $context, array( 'status' => self::STATUS[ $code ] ?? PaymentException::STATUS[ $code ] ?? 400 ) ) );
	}

	/**
	 * Translated message per domain error code.
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
				/* translators: %d: minimum number of nights */
				return sprintf( _n( 'The minimum stay is %d night.', 'The minimum stay is %d nights.', (int) ( $context['required'] ?? 1 ), 'marclie-booking' ), (int) ( $context['required'] ?? 1 ) );
			case 'capacity':
				/* translators: %d: maximum number of guests */
				return sprintf( __( 'This unit sleeps at most %d guests.', 'marclie-booking' ), (int) ( $context['max'] ?? 0 ) );
			case 'unavailable':
				return __( 'These dates are not available.', 'marclie-booking' );
			case 'coupon_invalid':
				return self::couponMessage( (string) ( $context['reason'] ?? '' ) );
			case 'invalid_transition':
				return __( 'This action is not possible in the booking’s current status.', 'marclie-booking' );
			case 'unit_not_found':
				return __( 'Unit not found.', 'marclie-booking' );
			case 'no_rate':
				return __( 'No price is set for these dates.', 'marclie-booking' );
			case 'not_found':
				return __( 'The requested record does not exist.', 'marclie-booking' );
			case 'validation':
				return __( 'Please correct the highlighted fields.', 'marclie-booking' );
			case PaymentException::UNAVAILABLE:
				return __( 'Stripe is not available (check the keys in wp-config.php). Nothing was changed.', 'marclie-booking' );
			case PaymentException::REQUIRES_ACTION:
				return __( 'The card requires authentication by the guest. Send them a payment link.', 'marclie-booking' );
			case PaymentException::DECLINED:
				return __( 'The card was declined. Send the guest a payment link.', 'marclie-booking' );
			case PaymentException::NOTHING_DUE:
				return __( 'Nothing is outstanding on this booking.', 'marclie-booking' );
			case PaymentException::NO_SAVED_CARD:
				return __( 'There is no saved card for this booking. Send the guest a payment link.', 'marclie-booking' );
			case PaymentException::IN_PROGRESS:
				return __( 'A card payment of this booking is still being processed.', 'marclie-booking' );
			case PaymentException::CODE:
				return __( 'Stripe could not process the payment. Try again later.', 'marclie-booking' );
			case 'duplicate_payment':
				return __( 'An identical payment was recorded a moment ago. Refresh before recording it again.', 'marclie-booking' );
			case 'guest_has_active_bookings':
				return __( 'This guest has upcoming bookings. Anonymize after their stays, or force it as an administrator.', 'marclie-booking' );
			default:
				return __( 'The request could not be completed.', 'marclie-booking' );
		}
	}

	private static function couponMessage( string $reason ): string {
		switch ( $reason ) {
			case 'not_found':
				return __( 'This coupon does not exist.', 'marclie-booking' );
			case 'inactive':
				return __( 'This coupon is not active.', 'marclie-booking' );
			case 'expired':
				return __( 'This coupon has expired for these dates.', 'marclie-booking' );
			case 'not_started':
				return __( 'This coupon is not valid yet for these dates.', 'marclie-booking' );
			case 'min_nights':
				return __( 'The stay is too short for this coupon.', 'marclie-booking' );
			case 'unit':
				return __( 'This coupon does not apply to this unit.', 'marclie-booking' );
			case 'limit':
				return __( 'This coupon has been used up.', 'marclie-booking' );
			case 'guest_limit':
				return __( 'This guest has already used this coupon.', 'marclie-booking' );
			case 'guest_only':
				return __( 'This coupon belongs to another guest.', 'marclie-booking' );
			default:
				return __( 'The coupon cannot be applied.', 'marclie-booking' );
		}
	}

	/* Arguments -------------------------------------------------------------- */

	/** @return array<string, array<string, mixed>> page, per_page, order. */
	protected static function paginationArgs(): array {
		return array(
			'page'     => array(
				'type'    => 'integer',
				'minimum' => 1,
				'default' => 1,
			),
			'per_page' => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 100,
				'default' => 25,
			),
			'order'    => array(
				'type'    => 'string',
				'enum'    => array( 'asc', 'desc' ),
				'default' => 'desc',
			),
		);
	}

	/** @return array<string, mixed> */
	protected static function dateArg( bool $required = false ): array {
		return array(
			'type'     => 'string',
			'pattern'  => self::DATE_PATTERN,
			'required' => $required,
		);
	}

	/**
	 * Nullable string limited to the DB column length (longer → 422 validation).
	 *
	 * @return array<string, mixed>
	 */
	protected static function text( int $maxLength ): array {
		return array(
			'type'      => array( 'string', 'null' ),
			'maxLength' => $maxLength,
		);
	}

	/** @return array<string, mixed> */
	protected static function idArg(): array {
		return array(
			'type'     => 'integer',
			'minimum'  => 1,
			'required' => true,
		);
	}

	/**
	 * @param list<array<string, mixed>> $items
	 * @return array<string, mixed>
	 */
	protected static function listResponse( array $items, int $total, \WP_REST_Request $request, array $extra = array() ): array {
		return array_merge(
			array(
				'items'    => $items,
				'total'    => $total,
				'page'     => (int) $request['page'],
				'per_page' => (int) $request['per_page'],
			),
			$extra
		);
	}

	/** A Y-m-d date in the site timezone, or a validation error for `$field`. */
	protected static function date( string $value, string $field ): \DateTimeImmutable {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );
		if ( false === $date || $date->format( 'Y-m-d' ) !== $value ) {
			throw ValidationException::fields( array( $field => __( 'Enter a valid date.', 'marclie-booking' ) ) );
		}

		return $date;
	}

	/**
	 * Start (inclusive) of a local Y-m-d day as a UTC DATETIME.
	 */
	protected static function utcStartOf( string $ymd, string $field ): string {
		return gmdate( 'Y-m-d H:i:s', self::date( $ymd, $field )->getTimestamp() );
	}

	/**
	 * Start of the local day after `$ymd` as a UTC DATETIME (exclusive end of an inclusive range).
	 */
	protected static function utcEndOf( string $ymd, string $field ): string {
		return gmdate( 'Y-m-d H:i:s', self::date( $ymd, $field )->modify( '+1 day' )->getTimestamp() );
	}

	/** Whether the request body/query contains `$key` (also when null). */
	protected static function has( \WP_REST_Request $request, string $key ): bool {
		$params = $request->get_params();

		return array_key_exists( $key, $params );
	}

	protected static function userId(): int {
		return get_current_user_id();
	}

	protected static function actor(): string {
		return 'user:' . get_current_user_id();
	}
}
