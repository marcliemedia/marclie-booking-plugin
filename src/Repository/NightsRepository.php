<?php
/**
 * mbk_nights: one row per occupied night, UNIQUE(unit_id, night) is the
 * double-booking guard.
 */

namespace Marclie\Booking\Repository;

defined( 'ABSPATH' ) || exit;

final class NightsRepository {

	private function table(): string {
		return Db::table( 'mbk_nights' );
	}

	/**
	 * Inserts all nights in one statement. Returns false on error (duplicate night
	 * or other; classify with Db::errorKind()). The caller owns the transaction.
	 *
	 * @param list<string> $nights Y-m-d.
	 */
	public function insert( int $unitId, array $nights, ?int $bookingId, ?int $blockId ): bool {
		global $wpdb;

		if ( ! $nights ) {
			return true;
		}

		$values = array();
		foreach ( $nights as $night ) {
			$values[] = $wpdb->prepare(
				'(%d, %s, ' . ( null === $bookingId ? 'NULL' : '%d' ) . ', ' . ( null === $blockId ? 'NULL' : '%d' ) . ')',
				array_values( array_filter( array( $unitId, $night, $bookingId, $blockId ), static fn( $v ): bool => null !== $v ) )
			);
		}

		$result = Db::quiet( "INSERT INTO {$this->table()} (unit_id, night, booking_id, block_id) VALUES " . implode( ', ', $values ) );

		return false !== $result;
	}

	/**
	 * Occupied nights of a unit in [from, to).
	 *
	 * @return list<string> Y-m-d, ascending.
	 */
	public function occupied( int $unitId, string $from, string $to ): array {
		global $wpdb;

		return array_map(
			'strval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT night FROM {$this->table()} WHERE unit_id = %d AND night >= %s AND night < %s ORDER BY night ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$unitId,
					$from,
					$to
				)
			)
		);
	}

	/**
	 * Occupied nights of a unit in [from, to) not held by `$bookingId`.
	 *
	 * @return list<string> Y-m-d, ascending.
	 */
	public function occupiedExcludingBooking( int $unitId, string $from, string $to, int $bookingId ): array {
		global $wpdb;

		return array_map(
			'strval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT night FROM {$this->table()} WHERE unit_id = %d AND night >= %s AND night < %s AND (booking_id IS NULL OR booking_id <> %d) ORDER BY night ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$unitId,
					$from,
					$to,
					$bookingId
				)
			)
		);
	}

	/**
	 * Units with at least one occupied night in [from, to) — one query for all units.
	 *
	 * @return list<int>
	 */
	public function busyUnitIds( string $from, string $to ): array {
		global $wpdb;

		return array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT unit_id FROM {$this->table()} WHERE night >= %s AND night < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$from,
					$to
				)
			)
		);
	}

	/** @return list<array{unit_id:int, night:string, booking_id:?int, block_id:?int}> */
	public function inRange( string $from, string $to ): array {
		global $wpdb;

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT unit_id, night, booking_id, block_id FROM {$this->table()} WHERE night >= %s AND night < %s ORDER BY unit_id, night", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$from,
				$to
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $r ): array => array(
				'unit_id'    => (int) $r['unit_id'],
				'night'      => (string) $r['night'],
				'booking_id' => null === $r['booking_id'] ? null : (int) $r['booking_id'],
				'block_id'   => null === $r['block_id'] ? null : (int) $r['block_id'],
			),
			$rows
		);
	}

	/**
	 * Pairs of (booking, `external` iCal block of an active feed) of the same
	 * unit whose nights in [from, to) overlap — one query; `date` = first
	 * overlapping night. Echoes of our own export (origin `echo`, see
	 * Ical\FeedRepository) are never conflicts; external blocks always are,
	 * whatever their summary.
	 *
	 * @return list<array{booking_id:int, ref:string, external_block_id:int, unit_id:int, feed_id:int, channel:string, date:string, date_from:string, date_to:string}>
	 */
	public function externalConflicts( string $from, string $to, ?int $unitId = null ): array {
		global $wpdb;

		$external = Db::table( 'mbk_external_blocks' );
		$bookings = Db::table( 'mbk_bookings' );
		$feeds    = Db::table( 'mbk_ical_feeds' );
		$sql      = "SELECT n.booking_id AS booking_id, e.id AS external_block_id, MIN(b.ref) AS ref, MIN(n.unit_id) AS unit_id, MIN(e.feed_id) AS feed_id, MIN(f.channel) AS channel, MIN(n.night) AS first_night, MIN(e.date_from) AS date_from, MIN(e.date_to) AS date_to FROM {$this->table()} n INNER JOIN {$external} e ON e.unit_id = n.unit_id AND n.night >= e.date_from AND n.night < e.date_to AND e.origin = 'external' INNER JOIN {$feeds} f ON f.id = e.feed_id AND f.active = 1 INNER JOIN {$bookings} b ON b.id = n.booking_id WHERE n.booking_id IS NOT NULL AND n.night >= %s AND n.night < %s";
		$args     = array( $from, $to );
		if ( null !== $unitId ) {
			$sql   .= ' AND n.unit_id = %d';
			$args[] = $unitId;
		}
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql . ' GROUP BY n.booking_id, e.id ORDER BY first_night, n.booking_id, e.id', $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map(
			static fn( array $r ): array => array(
				'booking_id'        => (int) $r['booking_id'],
				'ref'               => (string) $r['ref'],
				'external_block_id' => (int) $r['external_block_id'],
				'unit_id'           => (int) $r['unit_id'],
				'feed_id'           => (int) $r['feed_id'],
				'channel'           => (string) ( $r['channel'] ?? '' ),
				'date'              => (string) $r['first_night'],
				'date_from'         => (string) $r['date_from'],
				'date_to'           => (string) $r['date_to'],
			),
			$rows
		);
	}

	/**
	 * Units holding nights for a booking (`booking_id`) or block (`block_id`).
	 *
	 * @param 'booking_id'|'block_id' $column
	 * @return list<int>
	 */
	public function unitIdsHeldBy( string $column, int $id ): array {
		global $wpdb;

		$column = 'block_id' === $column ? 'block_id' : 'booking_id';

		return array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare( "SELECT DISTINCT unit_id FROM {$this->table()} WHERE {$column} = %d", $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			)
		);
	}

	/** @throws \RuntimeException When the delete fails. */
	public function deleteByBooking( int $bookingId ): int {
		global $wpdb;

		return (int) Db::check( $wpdb->delete( $this->table(), array( 'booking_id' => $bookingId ), array( '%d' ) ), 'Releasing booking nights' );
	}

	/** @throws \RuntimeException When the delete fails. */
	public function deleteByBlock( int $blockId ): int {
		global $wpdb;

		return (int) Db::check( $wpdb->delete( $this->table(), array( 'block_id' => $blockId ), array( '%d' ) ), 'Releasing block nights' );
	}
}
