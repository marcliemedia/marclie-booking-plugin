<?php
/**
 * mbk_events audit log.
 */

namespace Marclie\Booking\Repository;

use Marclie\Booking\Payment\WpPaymentLedger;

defined( 'ABSPATH' ) || exit;

final class EventRepository {

	/** Days an event attached to nothing (no booking, guest or external ID) is kept. */
	public const KEEP_DAYS = 365;

	/** Most events attached to nothing kept, newest first (caps a burst). */
	public const KEEP_MAX = 2000;

	/** Days a Stripe webhook dedupe marker is kept (Stripe retries a delivery for up to 3 days). */
	public const WEBHOOK_KEEP_DAYS = 90;

	/** Events belonging to no booking, guest or one-time claim (settings, prices, coupons, test emails …). */
	private const LOOSE = 'booking_id IS NULL AND guest_id IS NULL AND external_id IS NULL';

	private function table(): string {
		return Db::table( 'mbk_events' );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public function log( string $type, string $actor, ?int $bookingId = null, ?int $guestId = null, array $payload = array() ): int {
		global $wpdb;

		$ok = $wpdb->insert(
			$this->table(),
			array(
				'booking_id'   => $bookingId,
				'guest_id'     => $guestId,
				'type'         => $type,
				'actor'        => $actor,
				'payload_json' => $payload ? wp_json_encode( $payload ) : null,
				'created_at'   => Db::nowUtc(),
			)
		);

		return false === $ok ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Logs an event keyed by an external ID (UNIQUE): false when it exists already.
	 *
	 * @param array<string, mixed> $payload
	 */
	public function logExternal( string $externalId, string $type, string $actor, ?int $bookingId = null, ?int $guestId = null, array $payload = array() ): bool {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );
		$ok       = $wpdb->insert(
			$this->table(),
			array(
				'booking_id'   => $bookingId,
				'guest_id'     => $guestId,
				'type'         => $type,
				'actor'        => $actor,
				'payload_json' => $payload ? wp_json_encode( $payload ) : null,
				'external_id'  => $externalId,
				'created_at'   => Db::nowUtc(),
			)
		);
		$wpdb->suppress_errors( $suppress );

		return false !== $ok;
	}

	/**
	 * Inserts an event keyed by a UNIQUE external ID as an atomic claim:
	 * its ID, or 0 when another process holds the key.
	 *
	 * @param array<string, mixed> $payload
	 */
	public function claim( string $externalId, string $type, string $actor, ?int $bookingId, ?int $guestId, array $payload ): int {
		global $wpdb;

		return $this->logExternal( $externalId, $type, $actor, $bookingId, $guestId, $payload ) ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Rewrites type and payload of an event; `$releaseExternal` clears its external ID (claim given up).
	 *
	 * @param array<string, mixed> $payload
	 */
	public function updateEvent( int $id, string $type, array $payload, bool $releaseExternal = false ): bool {
		global $wpdb;

		$row = array(
			'type'         => $type,
			'payload_json' => $payload ? wp_json_encode( $payload ) : null,
		);
		if ( $releaseExternal ) {
			$row['external_id'] = null;
		}

		return false !== $wpdb->update( $this->table(), $row, array( 'id' => $id ) );
	}

	public function existsExternal( string $externalId ): bool {
		global $wpdb;

		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->table()} WHERE external_id = %s LIMIT 1", $externalId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Whether an `email_sent` event of template `$code` exists for the booking. */
	public function hasEmailSent( int $bookingId, string $code ): bool {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->table()} WHERE booking_id = %d AND type = 'email_sent' AND payload_json LIKE %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$bookingId,
				'%' . $wpdb->esc_like( '"code":' . wp_json_encode( $code ) ) . '%'
			)
		);
	}

	/**
	 * Events of `$type` created at or after `$sinceUtc`, newest first, with the booking ref.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function ofTypeSince( string $type, string $sinceUtc, int $limit = 50 ): array {
		global $wpdb;

		$bookings = Db::table( 'mbk_bookings' );
		$rows     = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT e.*, b.ref AS booking_ref FROM {$this->table()} e LEFT JOIN {$bookings} b ON b.id = e.booking_id WHERE e.type = %s AND e.created_at >= %s ORDER BY e.id DESC LIMIT %d", $type, $sinceUtc, $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/** @return list<array<string, mixed>> */
	public function forBooking( int $bookingId ): array {
		global $wpdb;

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE booking_id = %d ORDER BY id ASC", $bookingId ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/**
	 * One page of the activity log (newest first) with the booking ref (`booking_ref`).
	 *
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function page( int $page, int $perPage ): array {
		global $wpdb;

		$bookings = Db::table( 'mbk_bookings' );
		$rows     = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT e.*, b.ref AS booking_ref FROM {$this->table()} e LEFT JOIN {$bookings} b ON b.id = e.booking_id ORDER BY e.id DESC LIMIT %d OFFSET %d", $perPage, ( $page - 1 ) * $perPage ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return array(
			'items' => array_map( array( self::class, 'hydrate' ), $rows ),
			'total' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()}" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Retention (cron `mbk_daily`): deletes events attached to nothing older than
	 * KEEP_DAYS or beyond the newest KEEP_MAX, and Stripe webhook markers older
	 * than WEBHOOK_KEEP_DAYS. Booking and guest timelines and the other
	 * external-ID claims (once-only emails, member coupons, cancel requests,
	 * iCal alerts) are kept: they stop an action from repeating.
	 *
	 * @return int Rows deleted.
	 */
	public function prune( \DateTimeImmutable $now ): int {
		global $wpdb;

		$loose = self::LOOSE;
		// The newest loose event beyond the cap: it and every older loose event go.
		$firstDropped = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->table()} WHERE {$loose} ORDER BY id DESC LIMIT 1 OFFSET %d", self::KEEP_MAX ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$events   = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table()} WHERE {$loose} AND ( created_at < %s OR id <= %d )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Db::utc( $now->modify( sprintf( '-%d days', self::KEEP_DAYS ) ) ),
				$firstDropped
			)
		);
		$webhooks = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table()} WHERE type = %s AND created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				WpPaymentLedger::WEBHOOK_EVENT,
				Db::utc( $now->modify( sprintf( '-%d days', self::WEBHOOK_KEEP_DAYS ) ) )
			)
		);
		Db::check( $events, 'Pruning events' );
		Db::check( $webhooks, 'Pruning webhook markers' );

		return (int) $events + (int) $webhooks;
	}

	/** @return list<array<string, mixed>> */
	public function forGuest( int $guestId ): array {
		global $wpdb;

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE guest_id = %d ORDER BY id ASC", $guestId ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	public function reassignGuest( int $fromGuestId, int $toGuestId ): bool {
		global $wpdb;

		return false !== $wpdb->update( $this->table(), array( 'guest_id' => $toGuestId ), array( 'guest_id' => $fromGuestId ), array( '%d' ), array( '%d' ) );
	}

	/**
	 * Blanks PII keys (at any depth) in the payloads of a guest's events and of
	 * the events of the given bookings; `$keysByType` adds keys for one event
	 * type only (e.g. `to` of email events, while `to` of status changes stays).
	 *
	 * @param list<int>                   $bookingIds
	 * @param list<string>                $keys
	 * @param array<string, list<string>> $keysByType
	 */
	public function scrubPayloads( int $guestId, array $bookingIds, array $keys, array $keysByType = array() ): bool {
		global $wpdb;

		$sql  = "SELECT id, type, payload_json FROM {$this->table()} WHERE payload_json IS NOT NULL AND (guest_id = %d";
		$args = array( $guestId );
		if ( $bookingIds ) {
			$sql .= ' OR booking_id IN (' . Db::placeholders( $bookingIds ) . ')';
			$args = array_merge( $args, $bookingIds );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( $sql . ')', $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( null === $rows && '' !== $wpdb->last_error ) {
			return false;
		}

		$keys = array_flip( $keys );
		foreach ( (array) $rows as $row ) {
			$payload = json_decode( (string) $row['payload_json'], true );
			if ( ! is_array( $payload ) ) {
				continue;
			}
			$scrubbed = self::scrub( $payload, isset( $keysByType[ $row['type'] ] ) ? $keys + array_flip( $keysByType[ $row['type'] ] ) : $keys );
			if ( $scrubbed === $payload ) {
				continue;
			}
			if ( false === $wpdb->update( $this->table(), array( 'payload_json' => wp_json_encode( $scrubbed ) ), array( 'id' => (int) $row['id'] ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array<mixed>      $data
	 * @param array<string,int> $keys Flipped key list.
	 * @return array<mixed>
	 */
	private static function scrub( array $data, array $keys ): array {
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && isset( $keys[ $key ] ) ) {
				$data[ $key ] = '';
			} elseif ( is_array( $value ) ) {
				$data[ $key ] = self::scrub( $value, $keys );
			}
		}

		return $data;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		$row['id']         = (int) $row['id'];
		$row['booking_id'] = null === $row['booking_id'] ? null : (int) $row['booking_id'];
		$row['guest_id']   = null === $row['guest_id'] ? null : (int) $row['guest_id'];
		$row['payload']    = null === $row['payload_json'] ? array() : (array) json_decode( (string) $row['payload_json'], true );

		return $row;
	}
}
