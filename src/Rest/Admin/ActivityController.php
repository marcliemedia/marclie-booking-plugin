<?php
/**
 * GET /admin/activity?page=&per_page= — the mbk_events log, newest first
 * (retention: EventRepository::prune).
 */

namespace Marclie\Booking\Rest\Admin;

use Marclie\Booking\Container;
use Marclie\Booking\Install\Activator;

defined( 'ABSPATH' ) || exit;

final class ActivityController extends BaseController {

	public function register(): void {
		$this->route(
			'/activity',
			$this->endpoint(
				\WP_REST_Server::READABLE,
				array( $this, 'index' ),
				Activator::CAP_BOOKINGS,
				self::paginationArgs()
			)
		);
	}

	/** @return array<string, mixed> */
	public function index( \WP_REST_Request $request ): array {
		$result = Container::eventRepository()->page( (int) $request['page'], (int) $request['per_page'] );

		return self::listResponse( array_map( array( Presenter::class, 'event' ), $result['items'] ), $result['total'], $request );
	}
}
