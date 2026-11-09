<?php
/**
 * GET marclie-booking/v1/admin/ping — health check for the admin app.
 */

namespace Marclie\Booking\Rest\Admin;

use Marclie\Booking\Install\Activator;
use Marclie\Booking\Plugin;

defined( 'ABSPATH' ) || exit;

final class PingController {

	public const ROUTE = '/admin/ping';

	public static function register(): void {
		register_rest_route(
			Plugin::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'ping' ),
				'permission_callback' => static fn(): bool => current_user_can( Activator::CAP_BOOKINGS ),
			)
		);
	}

	/** Cache-Control: no-store is added for every admin route (Routes::noStore). */
	public static function ping(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'ok'      => true,
				'version' => MBK_VERSION,
				'time'    => ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( DATE_ATOM ),
			)
		);
	}
}
