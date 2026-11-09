<?php
/**
 * GET /availability?unit=<id>&from=YYYY-MM&months=1..12 — occupied nights, closed
 * nights (months without a price) and the minimum stay per arrival date for the calendars. No booking details and
 * no translated strings (publicly cacheable in any language). `from` must lie
 * in AvailabilityCache::window() (last month … +24 months), else 400 invalid_range.
 */

namespace Marclie\Booking\Rest\Public;

use Marclie\Booking\Container;
use Marclie\Booking\Service\AvailabilityCache;

defined( 'ABSPATH' ) || exit;

final class AvailabilityController extends BaseController {

	public function register(): void {
		$this->route(
			'/availability',
			\WP_REST_Server::READABLE,
			array( $this, 'show' ),
			array(
				'unit'   => self::intArg( 1, PHP_INT_MAX, null, true ),
				'from'   => array(
					'type'    => 'string',
					'pattern' => '^\d{4}-(0[1-9]|1[0-2])$',
				),
				'months' => self::intArg( 1, AvailabilityCache::MAX_MONTHS, 2 ),
			),
			self::CACHE_PUBLIC,
			true
		);
	}

	/** @return array<string, mixed>|\WP_Error */
	public function show( \WP_REST_Request $request ) {
		$unit = Container::units()->get( (int) $request['unit'] );
		if ( null === $unit ) {
			return self::error( 'unit_not_found', array( 'unit_id' => (int) $request['unit'] ) );
		}

		$cache            = Container::availabilityCache();
		[ $first, $last ] = $cache->window();
		$from             = (string) $request['from'];
		if ( '' === $from ) {
			$from = Container::clock()->today()->format( 'Y-m' );
		}
		if ( $from < $first || $from > $last ) {
			return self::error(
				'invalid_range',
				array(
					'min' => $first,
					'max' => $last,
				)
			);
		}

		$data = $cache->range( $unit->id, $from, (int) $request['months'] );
		// A JSON object even when empty (no prices).
		$data['min_stay'] = (object) $data['min_stay'];

		return $data;
	}
}
