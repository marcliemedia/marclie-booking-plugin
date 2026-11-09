<?php
/**
 * A source of occupied nights (bookings/blocks, imported iCal, …).
 * Registered through the `mbk_availability_sources` filter.
 */

namespace Marclie\Booking\Service;

defined( 'ABSPATH' ) || exit;

interface AvailabilitySource {

	/**
	 * Occupied nights of a unit in [from, to).
	 *
	 * @return list<string> Y-m-d.
	 */
	public function occupied( int $unitId, string $from, string $to ): array;

	/**
	 * Units with at least one occupied night in [from, to), in a single query.
	 *
	 * @return list<int>
	 */
	public function busyUnitIds( string $from, string $to ): array;
}
