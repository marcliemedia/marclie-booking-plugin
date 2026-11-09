<?php
/**
 * mbk_prices: one row per unit (canonical ID) with its minimum stay, minimum
 * paying guests and the price per person per night of each month (m1…m12,
 * NULL = closed). Read once per request into a PriceTable (the RateSource of
 * PricingService).
 */

namespace Marclie\Booking\Repository;

use Marclie\Booking\Pricing\PriceTable;
use Marclie\Booking\Pricing\RateSource;

defined( 'ABSPATH' ) || exit;

final class PriceRepository implements RateSource {

	private ?PriceTable $table = null;

	private function tableName(): string {
		return Db::table( 'mbk_prices' );
	}

	/** Every stored row, memoized until save(). */
	public function table(): PriceTable {
		if ( null !== $this->table ) {
			return $this->table;
		}

		global $wpdb;
		$rows = array();
		foreach ( (array) $wpdb->get_results( "SELECT * FROM {$this->tableName()}", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows[ (int) $row['unit_id'] ] = self::hydrate( $row );
		}

		return $this->table = new PriceTable( $rows );
	}

	public function nightly( int $unitId, array $dates ): array {
		return $this->table()->nightly( $unitId, $dates );
	}

	public function minStay( int $unitId ): int {
		return $this->table()->minStay( $unitId );
	}

	public function minGuests( int $unitId ): int {
		return $this->table()->minGuests( $unitId );
	}

	/** @param array<int, ?int> $months 1–12 => cents per person or null. */
	public function save( int $unitId, int $minStay, int $minGuests, array $months ): bool {
		global $wpdb;

		$data = array(
			'unit_id'    => $unitId,
			'min_stay'   => $minStay,
			'min_guests' => $minGuests,
		);
		for ( $m = 1; $m <= 12; $m++ ) {
			$data[ 'm' . $m ] = $months[ $m ] ?? null;
		}
		$this->table = null;

		// REPLACE keeps NULL months (wpdb formats null as NULL).
		return false !== $wpdb->replace( $this->tableName(), $data );
	}

	/** Drops the memo (another request changed the table). */
	public function flush(): void {
		$this->table = null;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{min_stay:int, min_guests:int, months:array<int, ?int>}
	 */
	private static function hydrate( array $row ): array {
		$months = array();
		for ( $m = 1; $m <= 12; $m++ ) {
			$months[ $m ] = null === ( $row[ 'm' . $m ] ?? null ) ? null : (int) $row[ 'm' . $m ];
		}

		return array(
			'min_stay'   => (int) $row['min_stay'],
			'min_guests' => (int) ( $row['min_guests'] ?? PriceTable::DEFAULT_MIN_GUESTS ),
			'months'     => $months,
		);
	}
}
