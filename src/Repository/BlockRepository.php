<?php
/**
 * Manual calendar blocks (mbk_blocks) and read access to imported iCal blocks
 * (mbk_external_blocks). Block date ranges are [date_from, date_to): date_to is
 * the first free night, like a booking departure.
 */

namespace Marclie\Booking\Repository;

defined( 'ABSPATH' ) || exit;

final class BlockRepository {

	private function table(): string {
		return Db::table( 'mbk_blocks' );
	}

	private function externalTable(): string {
		return Db::table( 'mbk_external_blocks' );
	}

	public function insert( int $unitId, string $from, string $to, string $reason = '', ?int $createdBy = null ): int {
		global $wpdb;

		$ok = $wpdb->insert(
			$this->table(),
			array(
				'unit_id'    => $unitId,
				'date_from'  => $from,
				'date_to'    => $to,
				'reason'     => $reason,
				'created_by' => $createdBy,
				'created_at' => Db::nowUtc(),
			)
		);

		return false === $ok ? 0 : (int) $wpdb->insert_id;
	}

	/** @return array<string, mixed>|null */
	public function find( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? self::hydrate( $row ) : null;
	}

	public function delete( int $id ): bool {
		global $wpdb;

		return false !== $wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Manual blocks overlapping [from, to), optionally for one unit.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function overlapping( string $from, string $to, ?int $unitId = null ): array {
		global $wpdb;

		$sql  = "SELECT * FROM {$this->table()} WHERE date_from < %s AND date_to > %s";
		$args = array( $to, $from );
		if ( null !== $unitId ) {
			$sql   .= ' AND unit_id = %d';
			$args[] = $unitId;
		}

		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY unit_id, date_from', $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/**
	 * Imported (iCal) blocks of active feeds overlapping [from, to), optionally for one unit.
	 *
	 * @return list<array{unit_id:int, date_from:string, date_to:string}>
	 */
	public function externalOverlapping( string $from, string $to, ?int $unitId = null ): array {
		global $wpdb;

		$feeds = Db::table( 'mbk_ical_feeds' );
		$sql   = "SELECT e.unit_id, e.date_from, e.date_to FROM {$this->externalTable()} e INNER JOIN {$feeds} f ON f.id = e.feed_id AND f.active = 1 WHERE e.date_from < %s AND e.date_to > %s";
		$args  = array( $to, $from );
		if ( null !== $unitId ) {
			$sql   .= ' AND e.unit_id = %d';
			$args[] = $unitId;
		}

		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map(
			static fn( array $r ): array => array(
				'unit_id'   => (int) $r['unit_id'],
				'date_from' => (string) $r['date_from'],
				'date_to'   => (string) $r['date_to'],
			),
			$rows
		);
	}

	/**
	 * Imported blocks of active feeds overlapping [from, to) with their feed
	 * channel and origin (`external` / `echo` of our own export) for the admin
	 * calendar; `conflict`: an external block over nights a booking holds.
	 *
	 * @return list<array{id:int, unit_id:int, date_from:string, date_to:string, channel:string, summary:string, origin:string, conflict:bool}>
	 */
	public function externalDetailed( string $from, string $to ): array {
		global $wpdb;

		$feeds    = Db::table( 'mbk_ical_feeds' );
		$nights   = Db::table( 'mbk_nights' );
		$conflict = "CASE WHEN e.origin = 'external' AND EXISTS (SELECT 1 FROM {$nights} n WHERE n.unit_id = e.unit_id AND n.night >= e.date_from AND n.night < e.date_to AND n.booking_id IS NOT NULL) THEN 1 ELSE 0 END";
		$rows     = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.id, e.unit_id, e.date_from, e.date_to, e.summary, e.origin, f.channel, {$conflict} AS conflict FROM {$this->externalTable()} e INNER JOIN {$feeds} f ON f.id = e.feed_id AND f.active = 1 WHERE e.date_from < %s AND e.date_to > %s ORDER BY e.unit_id, e.date_from", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$to,
				$from
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $r ): array => array(
				'id'        => (int) $r['id'],
				'unit_id'   => (int) $r['unit_id'],
				'date_from' => (string) $r['date_from'],
				'date_to'   => (string) $r['date_to'],
				'channel'   => (string) $r['channel'],
				'summary'   => (string) $r['summary'],
				'origin'    => (string) $r['origin'],
				'conflict'  => 1 === (int) $r['conflict'],
			),
			$rows
		);
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		$row['id']         = (int) $row['id'];
		$row['unit_id']    = (int) $row['unit_id'];
		$row['created_by'] = null === $row['created_by'] ? null : (int) $row['created_by'];

		return $row;
	}
}
