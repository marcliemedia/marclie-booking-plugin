<?php
/**
 * Storage of the iCal sync: import feeds (mbk_ical_feeds), their imported
 * blocks (mbk_external_blocks, UNIQUE per feed + UID, [date_from, date_to)),
 * the per-unit export secrets (mbk_ical_exports) and when each OTA last read
 * our export (mbk_ical_export_fetches, per unit and `for=` channel).
 *
 * Export secrets live in their own table rather than an option: the admin app
 * must show the export URL, so the secret itself is stored (32 random bytes,
 * base64url), one row per canonical unit, with the time it was last rotated.
 *
 * Provenance: each imported block is `external` (a reservation/closure made
 * on the OTA: blocks our calendar, raises conflicts, is re-exported to the
 * other channels) or `echo` (our own export coming back: blocks our calendar
 * but is never re-exported and never a conflict), decided once on first sight.
 */

namespace Marclie\Booking\Ical;

use Marclie\Booking\Repository\BookingRepository;
use Marclie\Booking\Repository\Db;
use Marclie\Booking\Repository\LockConflictException;

defined( 'ABSPATH' ) || exit;

final class FeedRepository {

	public const CHANNELS = array( 'airbnb', 'booking', 'other' );

	public const ORIGIN_EXTERNAL = 'external';

	public const ORIGIN_ECHO = 'echo';

	private function feeds(): string {
		return Db::table( 'mbk_ical_feeds' );
	}

	private function blocks(): string {
		return Db::table( 'mbk_external_blocks' );
	}

	private function exports(): string {
		return Db::table( 'mbk_ical_exports' );
	}

	/** Display name of a channel (alerts, CLI). */
	public static function label( string $channel ): string {
		switch ( $channel ) {
			case 'airbnb':
				return 'Airbnb';
			case 'booking':
				return 'Booking.com';
			default:
				return __( 'another calendar', 'marclie-booking' );
		}
	}

	/* Feeds ------------------------------------------------------------------ */

	/** @return list<array<string, mixed>> */
	public function all( bool $activeOnly = false ): array {
		global $wpdb;

		$where = $activeOnly ? ' WHERE active = 1' : '';
		$rows  = (array) $wpdb->get_results( "SELECT * FROM {$this->feeds()}{$where} ORDER BY unit_id, channel, id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/** @return list<array<string, mixed>> */
	public function forUnit( int $unitId, bool $activeOnly = false ): array {
		global $wpdb;

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->feeds()} WHERE unit_id = %d" . ( $activeOnly ? ' AND active = 1' : '' ) . ' ORDER BY channel, id', $unitId ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/** @return array<string, mixed>|null */
	public function find( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->feeds()} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? self::hydrate( $row ) : null;
	}

	/** @return array<string, mixed>|null */
	public function findByUnitChannel( int $unitId, string $channel ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->feeds()} WHERE unit_id = %d AND channel = %s ORDER BY id LIMIT 1", $unitId, $channel ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? self::hydrate( $row ) : null;
	}

	public function insert( int $unitId, string $channel, string $url ): int {
		global $wpdb;

		$ok = $wpdb->insert(
			$this->feeds(),
			array(
				'unit_id'    => $unitId,
				'channel'    => $channel,
				'url'        => $url,
				'active'     => 1,
				'created_at' => Db::nowUtc(),
			)
		);

		return false === $ok ? 0 : (int) $wpdb->insert_id;
	}

	/** @param array<string, mixed> $fields Column => value. */
	public function update( int $id, array $fields ): bool {
		global $wpdb;

		return false !== $wpdb->update( $this->feeds(), $fields, array( 'id' => $id ) );
	}

	public function delete( int $id ): bool {
		global $wpdb;

		return false !== $wpdb->delete( $this->feeds(), array( 'id' => $id ), array( '%d' ) );
	}

	/** A successful fetch: errors cleared, the failure streak ends. */
	public function markOk( int $id, string $nowUtc, string $status, ?string $etag, ?string $lastModified, ?int $eventsCount ): bool {
		$fields = array(
			'last_synced_at' => $nowUtc,
			'last_ok_at'     => $nowUtc,
			'last_status'    => $status,
			'last_error'     => null,
			'fail_since'     => null,
		);
		if ( null !== $etag ) {
			$fields['etag'] = '' !== $etag ? substr( $etag, 0, 255 ) : null;
		}
		if ( null !== $lastModified ) {
			$fields['last_modified'] = '' !== $lastModified ? substr( $lastModified, 0, 100 ) : null;
		}
		if ( null !== $eventsCount ) {
			$fields['events_count'] = $eventsCount;
		}

		return $this->update( $id, $fields );
	}

	/** A failed fetch: `fail_since` keeps the start of the failure streak. */
	public function markFailed( int $id, string $nowUtc, string $error ): bool {
		global $wpdb;

		$error = function_exists( 'mb_strcut' ) ? mb_strcut( $error, 0, 255, 'UTF-8' ) : substr( $error, 0, 255 );

		return false !== $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->feeds()} SET last_synced_at = %s, last_status = 'error', last_error = %s, fail_since = COALESCE(fail_since, %s) WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$nowUtc,
				$error,
				$nowUtc,
				$id
			)
		);
	}

	/* Imported blocks -------------------------------------------------------- */

	/** @return list<array{id:int, uid:string, date_from:string, date_to:string, summary:string, origin:string, first_seen_at:?string, orphaned_at:?string, orphan_fetch_at:?string}> */
	public function blocksOfFeed( int $feedId ): array {
		global $wpdb;

		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, uid, date_from, date_to, summary, origin, first_seen_at, orphaned_at, orphan_fetch_at FROM {$this->blocks()} WHERE feed_id = %d ORDER BY date_from, id", $feedId ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map(
			static fn( array $r ): array => array(
				'id'              => (int) $r['id'],
				'uid'             => (string) $r['uid'],
				'date_from'       => (string) $r['date_from'],
				'date_to'         => (string) $r['date_to'],
				'summary'         => (string) $r['summary'],
				'origin'          => (string) $r['origin'],
				'first_seen_at'   => $r['first_seen_at'] ?? null,
				'orphaned_at'     => $r['orphaned_at'] ?? null,
				'orphan_fetch_at' => $r['orphan_fetch_at'] ?? null,
			),
			$rows
		);
	}

	/** @return array<string, mixed>|null */
	public function findBlock( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->blocks()} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ?: null;
	}

	/**
	 * Makes the feed's blocks equal to `$events`: upsert by (feed, UID), delete
	 * vanished UIDs; one transaction that first re-reads the feed (row lock on
	 * MySQL) and does nothing — null — when it was deleted or switched off
	 * meanwhile. A new UID's origin is decided once (classify()) and kept.
	 *
	 * @param list<array{uid:string, date_from:string, date_to:string, summary:string}> $events
	 * @return array{added:int, updated:int, removed:int}|null
	 */
	public function replaceBlocks( int $feedId, int $unitId, string $channel, array $events, string $nowUtc ): ?array {
		return Db::transaction(
			function () use ( $feedId, $unitId, $channel, $events, $nowUtc ): ?array {
				global $wpdb;

				$active = $wpdb->get_var( $wpdb->prepare( "SELECT active FROM {$this->feeds()} WHERE id = %d" . ( Db::isMysql() ? ' FOR UPDATE' : '' ), $feedId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( null === $active && '' !== (string) $wpdb->last_error ) {
					// A failed lock must not read as "feed switched off".
					if ( Db::ERROR_LOCK === Db::errorKind() ) {
						throw new LockConflictException( Db::message( 'Locking iCal feed hit a lock conflict' ) );
					}
					throw new \RuntimeException( Db::message( 'Locking iCal feed failed' ) );
				}
				if ( null === $active || 1 !== (int) $active ) {
					return null;
				}

				$existing = array_column( $this->blocksOfFeed( $feedId ), null, 'uid' );
				$fetched  = $this->lastFetch( $unitId, $channel );
				$counts   = array(
					'added'   => 0,
					'updated' => 0,
					'removed' => 0,
				);
				foreach ( $events as $event ) {
					$row = $existing[ $event['uid'] ] ?? null;
					unset( $existing[ $event['uid'] ] );
					$fields = array(
						'unit_id'   => $unitId,
						'date_from' => $event['date_from'],
						'date_to'   => $event['date_to'],
						'summary'   => $event['summary'],
						'seen_at'   => $nowUtc,
					);
					if ( null !== $row ) {
						Db::check( $wpdb->update( $this->blocks(), $fields, array( 'id' => $row['id'] ) ), 'Updating external block' );
						if ( $row['date_from'] !== $event['date_from'] || $row['date_to'] !== $event['date_to'] || $row['summary'] !== $event['summary'] ) {
							++$counts['updated'];
						}
						continue;
					}

					$suppress = $wpdb->suppress_errors( true );
					$ok       = $wpdb->insert(
						$this->blocks(),
						$fields + array(
							'feed_id'       => $feedId,
							'uid'           => $event['uid'],
							'first_seen_at' => $nowUtc,
							'origin'        => $this->classify( $unitId, $channel, $event, $fetched ),
						)
					);
					$wpdb->suppress_errors( $suppress );
					if ( false === $ok ) {
						if ( Db::ERROR_DUPLICATE !== Db::errorKind() ) {
							throw new \RuntimeException( Db::message( 'Inserting external block failed' ) );
						}
						// UNIQUE (feed_id, uid): the row exists after all — update it, keep its origin.
						Db::check(
							$wpdb->update(
								$this->blocks(),
								$fields,
								array(
									'feed_id' => $feedId,
									'uid'     => $event['uid'],
								)
							),
							'Updating external block'
						);
						continue;
					}
					++$counts['added'];
				}

				foreach ( $existing as $row ) {
					Db::check( $wpdb->delete( $this->blocks(), array( 'id' => $row['id'] ), array( '%d' ) ), 'Deleting vanished external block' );
					++$counts['removed'];
				}

				return $counts;
			}
		);
	}

	/**
	 * Origin of a block seen for the first time: `echo` (our own export coming
	 * back) only when an own event with exactly these dates — a booking holding
	 * nights, a manual block, or an external block of another channel that we
	 * export to this one — existed before this channel last fetched our export
	 * (that fetch is, by definition, before now = first sight). An Airbnb
	 * "Reserved" event is a real reservation, never an echo. Else `external`.
	 *
	 * @param array{uid:string, date_from:string, date_to:string, summary:string} $event
	 */
	public function classify( int $unitId, string $channel, array $event, ?string $lastFetchUtc ): string {
		if ( null === $lastFetchUtc || ( 'airbnb' === $channel && false !== stripos( $event['summary'], 'reserved' ) ) ) {
			return self::ORIGIN_EXTERNAL;
		}

		return $this->ownEventExists( $unitId, $channel, $event['date_from'], $event['date_to'], $lastFetchUtc ) ? self::ORIGIN_ECHO : self::ORIGIN_EXTERNAL;
	}

	/**
	 * Whether we export an event with exactly [from, to) for the unit to `$channel`
	 * (optionally: one that existed before `$beforeUtc`).
	 */
	public function ownEventExists( int $unitId, string $channel, string $from, string $to, ?string $beforeUtc = null ): bool {
		global $wpdb;

		$bookings = Db::table( 'mbk_bookings' );
		$statuses = BookingRepository::ACTIVE_STATUSES;
		$sql      = "SELECT id FROM {$bookings} WHERE unit_id = %d AND arrival = %s AND departure = %s AND status IN (" . Db::placeholders( $statuses, '%s' ) . ')';
		$args     = array_merge( array( $unitId, $from, $to ), $statuses );
		if ( null !== $beforeUtc ) {
			$sql   .= ' AND created_at < %s';
			$args[] = $beforeUtc;
		}
		if ( $wpdb->get_var( $wpdb->prepare( $sql . ' LIMIT 1', $args ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return true;
		}

		$blocks = Db::table( 'mbk_blocks' );
		$sql    = "SELECT id FROM {$blocks} WHERE unit_id = %d AND date_from = %s AND date_to = %s";
		$args   = array( $unitId, $from, $to );
		if ( null !== $beforeUtc ) {
			$sql   .= ' AND created_at < %s';
			$args[] = $beforeUtc;
		}
		if ( $wpdb->get_var( $wpdb->prepare( $sql . ' LIMIT 1', $args ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return true;
		}

		$sql  = "SELECT e.id FROM {$this->blocks()} e INNER JOIN {$this->feeds()} f ON f.id = e.feed_id AND f.active = 1 WHERE e.unit_id = %d AND e.date_from = %s AND e.date_to = %s AND e.origin = %s AND f.channel <> %s";
		$args = array( $unitId, $from, $to, self::ORIGIN_EXTERNAL, $channel );
		if ( null !== $beforeUtc ) {
			$sql   .= ' AND e.first_seen_at < %s';
			$args[] = $beforeUtc;
		}

		return (bool) $wpdb->get_var( $wpdb->prepare( $sql . ' LIMIT 1', $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Tracks echo blocks whose own event is gone: `orphaned_at` when first noticed
	 * (cleared if the event is back), `orphan_fetch_at` = the channel's first
	 * export fetch seen after that (from then on the OTA should drop the echo).
	 */
	public function refreshEchoes( int $feedId, int $unitId, string $channel, string $nowUtc ): void {
		global $wpdb;

		$fetched = $this->lastFetch( $unitId, $channel );
		foreach ( $this->blocksOfFeed( $feedId ) as $row ) {
			if ( self::ORIGIN_ECHO !== $row['origin'] ) {
				continue;
			}
			if ( $this->ownEventExists( $unitId, $channel, $row['date_from'], $row['date_to'] ) ) {
				if ( null !== $row['orphaned_at'] ) {
					$wpdb->update(
						$this->blocks(),
						array(
							'orphaned_at'     => null,
							'orphan_fetch_at' => null,
						),
						array( 'id' => $row['id'] )
					);
				}
				continue;
			}
			$fields     = array();
			$orphanedAt = $row['orphaned_at'] ?? $nowUtc;
			if ( null === $row['orphaned_at'] ) {
				$fields['orphaned_at'] = $nowUtc;
			}
			if ( null === $row['orphan_fetch_at'] && null !== $fetched && $fetched >= $orphanedAt ) {
				$fields['orphan_fetch_at'] = $fetched;
			}
			if ( $fields ) {
				$wpdb->update( $this->blocks(), $fields, array( 'id' => $row['id'] ) );
			}
		}
	}

	/**
	 * Echo blocks of active feeds still imported although our own event is gone
	 * and the OTA fetched our export at or before `$limitUtc` since.
	 *
	 * @return list<array{id:int, feed_id:int, unit_id:int, channel:string, date_from:string, date_to:string, orphan_fetch_at:string}>
	 */
	public function staleEchoes( string $limitUtc ): array {
		global $wpdb;

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.id, e.feed_id, e.unit_id, f.channel, e.date_from, e.date_to, e.orphan_fetch_at FROM {$this->blocks()} e INNER JOIN {$this->feeds()} f ON f.id = e.feed_id AND f.active = 1 WHERE e.origin = %s AND e.orphan_fetch_at IS NOT NULL AND e.orphan_fetch_at <= %s ORDER BY e.date_from, e.id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::ORIGIN_ECHO,
				$limitUtc
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $r ): array => array(
				'id'              => (int) $r['id'],
				'feed_id'         => (int) $r['feed_id'],
				'unit_id'         => (int) $r['unit_id'],
				'channel'         => (string) $r['channel'],
				'date_from'       => (string) $r['date_from'],
				'date_to'         => (string) $r['date_to'],
				'orphan_fetch_at' => (string) $r['orphan_fetch_at'],
			),
			$rows
		);
	}

	public function deleteBlocksOfFeed( int $feedId ): int {
		global $wpdb;

		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->blocks()} WHERE feed_id = %d", $feedId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Blocks of the unit's active feeds overlapping [from, to), with channel and origin (export).
	 *
	 * @return list<array{id:int, date_from:string, date_to:string, channel:string, origin:string}>
	 */
	public function blocksOfUnit( int $unitId, string $from, string $to ): array {
		global $wpdb;

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.id, e.date_from, e.date_to, e.origin, f.channel FROM {$this->blocks()} e INNER JOIN {$this->feeds()} f ON f.id = e.feed_id AND f.active = 1 WHERE e.unit_id = %d AND e.date_from < %s AND e.date_to > %s ORDER BY e.date_from, e.id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$unitId,
				$to,
				$from
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $r ): array => array(
				'id'        => (int) $r['id'],
				'date_from' => (string) $r['date_from'],
				'date_to'   => (string) $r['date_to'],
				'channel'   => (string) $r['channel'],
				'origin'    => (string) $r['origin'],
			),
			$rows
		);
	}

	/* Export fetches --------------------------------------------------------- */

	/** An OTA read the unit's export for `$channel` (the `for=` of the URL). */
	public function recordFetch( int $unitId, string $channel, string $nowUtc ): void {
		global $wpdb;

		$table    = Db::table( 'mbk_ical_export_fetches' );
		$suppress = $wpdb->suppress_errors( true );
		$ok       = $wpdb->insert(
			$table,
			array(
				'unit_id'         => $unitId,
				'channel'         => $channel,
				'last_fetched_at' => $nowUtc,
			)
		);
		$wpdb->suppress_errors( $suppress );
		if ( false === $ok ) {
			$wpdb->update(
				$table,
				array( 'last_fetched_at' => $nowUtc ),
				array(
					'unit_id' => $unitId,
					'channel' => $channel,
				)
			);
		}
	}

	public function lastFetch( int $unitId, string $channel ): ?string {
		global $wpdb;

		$at = $wpdb->get_var( $wpdb->prepare( 'SELECT last_fetched_at FROM ' . Db::table( 'mbk_ical_export_fetches' ) . ' WHERE unit_id = %d AND channel = %s', $unitId, $channel ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return is_string( $at ) && '' !== $at ? $at : null;
	}

	/* Export secrets --------------------------------------------------------- */

	public function secret( int $unitId ): ?string {
		global $wpdb;

		$secret = $wpdb->get_var( $wpdb->prepare( "SELECT secret FROM {$this->exports()} WHERE unit_id = %d", $unitId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_string( $secret ) && '' !== $secret ? $secret : null;
	}

	/** Stores (replaces) the unit's export secret. */
	public function saveSecret( int $unitId, string $secret, string $nowUtc ): bool {
		global $wpdb;

		if ( null === $this->secret( $unitId ) ) {
			$suppress = $wpdb->suppress_errors( true );
			$ok       = $wpdb->insert(
				$this->exports(),
				array(
					'unit_id'    => $unitId,
					'secret'     => $secret,
					'rotated_at' => $nowUtc,
				)
			);
			$wpdb->suppress_errors( $suppress );
			if ( false !== $ok ) {
				return true;
			}
		}

		return false !== $wpdb->update(
			$this->exports(),
			array(
				'secret'     => $secret,
				'rotated_at' => $nowUtc,
			),
			array( 'unit_id' => $unitId )
		);
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		return array(
			'id'             => (int) $row['id'],
			'unit_id'        => (int) $row['unit_id'],
			'channel'        => (string) $row['channel'],
			'url'            => (string) $row['url'],
			'active'         => ! isset( $row['active'] ) || 1 === (int) $row['active'],
			'last_synced_at' => $row['last_synced_at'] ?? null,
			'last_ok_at'     => $row['last_ok_at'] ?? null,
			'last_status'    => $row['last_status'] ?? null,
			'last_error'     => $row['last_error'] ?? null,
			'fail_since'     => $row['fail_since'] ?? null,
			'events_count'   => (int) ( $row['events_count'] ?? 0 ),
			'etag'           => $row['etag'] ?? null,
			'last_modified'  => $row['last_modified'] ?? null,
			'created_at'     => $row['created_at'] ?? null,
		);
	}
}
