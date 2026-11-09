<?php
/**
 * GET /admin/dashboard?month=YYYY-MM — KPIs, today, alerts, charts.
 */

namespace Marclie\Booking\Rest\Admin;

use Marclie\Booking\Container;
use Marclie\Booking\Install\Activator;

defined( 'ABSPATH' ) || exit;

final class DashboardController extends BaseController {

	public function register(): void {
		$this->route(
			'/dashboard',
			$this->endpoint(
				\WP_REST_Server::READABLE,
				array( $this, 'show' ),
				Activator::CAP_BOOKINGS,
				array(
					'month' => array(
						'type'    => 'string',
						'pattern' => '^\d{4}-(0[1-9]|1[0-2])$',
					),
				)
			)
		);
	}

	/** @return array<string, mixed> */
	public function show( \WP_REST_Request $request ): array {
		$month = (string) $request['month'];
		if ( '' === $month ) {
			$month = Container::clock()->today()->format( 'Y-m' );
		}

		$data = Container::dashboard()->build( $month );

		// BookingSummary plus the guest's expected arrival time and message.
		$today = static function ( array $rows ): array {
			$summaries = Presenter::bookingSummaries( $rows );
			foreach ( $rows as $i => $row ) {
				$summaries[ $i ]['arrival_time'] = Presenter::nullable( $row['arrival_time'] );
				$summaries[ $i ]['message']      = Presenter::nullable( $row['message'] );
			}

			return $summaries;
		};

		$data['today'] = array(
			'arrivals'   => $today( $data['today']['arrivals'] ),
			'departures' => $today( $data['today']['departures'] ),
		);

		return $data;
	}
}
