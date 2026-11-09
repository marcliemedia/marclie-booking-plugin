<?php
/**
 * Registers the admin app REST API (`marclie-booking/v1/admin/*`) and the
 * response rules shared by all its routes.
 */

namespace Marclie\Booking\Rest\Admin;

use Marclie\Booking\Plugin;

defined( 'ABSPATH' ) || exit;

final class Routes {

	/** @var list<class-string<BaseController>> */
	private const CONTROLLERS = array(
		BootstrapController::class,
		DashboardController::class,
		ActivityController::class,
		CalendarController::class,
		BookingsController::class,
		BlocksController::class,
		GuestsController::class,
		PaymentsController::class,
		PricesController::class,
		CouponsController::class,
		SettingsController::class,
		StripeController::class,
		SearchController::class,
		RolesController::class,
		EmailTemplatesController::class,
		IcalController::class,
	);

	/** rest_api_init. */
	public static function register(): void {
		PingController::register();
		foreach ( self::CONTROLLERS as $class ) {
			( new $class() )->register();
		}

		add_filter( 'rest_request_after_callbacks', array( self::class, 'normalizeArgumentErrors' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( self::class, 'noStore' ), 10, 3 );
	}

	public static function isAdminRoute( string $route ): bool {
		return str_starts_with( $route, '/' . Plugin::REST_NAMESPACE . BaseController::PREFIX . '/' );
	}

	/**
	 * WordPress argument errors (rest_missing_callback_param / rest_invalid_param)
	 * become the contract's `validation` error with `fields`.
	 *
	 * @param mixed            $response
	 * @param array<mixed>     $handler
	 * @return mixed
	 */
	public static function normalizeArgumentErrors( $response, $handler, \WP_REST_Request $request ) {
		if ( ! $response instanceof \WP_Error || ! self::isAdminRoute( $request->get_route() ) ) {
			return $response;
		}

		$code = $response->get_error_code();
		if ( 'rest_missing_callback_param' !== $code && 'rest_invalid_param' !== $code ) {
			return $response;
		}

		$data   = (array) $response->get_error_data();
		$fields = array();
		if ( 'rest_missing_callback_param' === $code ) {
			foreach ( (array) ( $data['params'] ?? array() ) as $param ) {
				$fields[ (string) $param ] = __( 'This field is required.', 'marclie-booking' );
			}
		} else {
			foreach ( (array) ( $data['params'] ?? array() ) as $param => $message ) {
				$fields[ (string) $param ] = (string) $message;
			}
		}

		return new \WP_Error( 'validation', BaseController::message( 'validation' ), array( 'status' => 422, 'fields' => $fields ) );
	}

	/**
	 * Admin responses (including errors) must never be cached by page caches or CDNs.
	 *
	 * @param \WP_HTTP_Response $response
	 */
	public static function noStore( $response, \WP_REST_Server $server, \WP_REST_Request $request ): \WP_HTTP_Response {
		if ( self::isAdminRoute( $request->get_route() ) ) {
			$response->header( 'Cache-Control', 'no-store, private' );
		}

		return $response;
	}
}
