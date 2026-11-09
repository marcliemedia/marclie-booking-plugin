<?php
/**
 * mbk_bookings persistence. Rows are returned as associative arrays with
 * integer columns cast to int.
 */

namespace Marclie\Booking\Repository;

defined( 'ABSPATH' ) || exit;

final class BookingRepository {

	private const INT_COLUMNS = array(
		'id',
		'unit_id',
		'guest_id',
		'nights',
		'adults',
		'children',
		'infants',
		'user_id',
		'subtotal_cents',
		'discount_cents',
		'total_cents',
		'deposit_cents',
		'balance_cents',
		'paid_cents',
	);

	/** Statuses that keep their nights (occupy the calendar). */
	public const ACTIVE_STATUSES = array( 'pending_payment', 'confirmed', 'paid', 'balance_failed', 'completed' );

	/** Statuses that count as sold (occupancy, revenue). */
	public const SOLD_STATUSES = array( 'confirmed', 'paid', 'balance_failed', 'completed' );

	/** Not yet arrived and still running ("upcoming" tab). */
	public const UPCOMING_STATUSES = array( 'pending_payment', 'confirmed', 'paid', 'balance_failed' );

	public const ORDERBY = array(
		'arrival'    => 'b.arrival',
		'created_at' => 'b.created_at',
		'total'      => 'b.total_cents',
		'departure'  => 'b.departure',
	);

	private function table(): string {
		return Db::table( 'mbk_bookings' );
	}

	/**
	 * Admin list: filtered, sorted, paginated in SQL. Each row gets `paid_cents`.
	 *
	 * Filters: status (string, `upcoming` or a list), unit_id, source, guest_id,
	 * search (ref/name/email/phone), date_field (arrival|departure|created) with
	 * from/to (Y-m-d, inclusive; `created` compares from_utc/to_utc), payment_state
	 * (unpaid|deposit|paid|failed), today (Y-m-d, for `upcoming`).
	 *
	 * @param array<string, mixed> $filters
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function search( array $filters, int $page, int $perPage, string $orderby = 'created_at', string $order = 'DESC' ): array {
		global $wpdb;

		[ $where, $args ] = $this->where( $filters );
		$column           = self::ORDERBY[ $orderby ] ?? self::ORDERBY['created_at'];
		$order            = 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC';
		$paid             = $this->paidSql();

		$total = (int) $wpdb->get_var( self::prepare( "SELECT COUNT(*) FROM {$this->table()} b WHERE {$where}", $args ) );
		$rows  = (array) $wpdb->get_results(
			self::prepare(
				"SELECT b.*, {$paid} AS paid_cents FROM {$this->table()} b WHERE {$where} ORDER BY {$column} {$order}, b.id {$order} LIMIT %d OFFSET %d",
				array_merge( $args, array( $perPage, ( $page - 1 ) * $perPage ) )
			),
			ARRAY_A
		);

		return array(
			'items' => array_map( array( self::class, 'hydrate' ), $rows ),
			'total' => $total,
		);
	}

	/**
	 * Rows matching the filters without pagination (bounded by `$limit`), with `paid_cents`.
	 *
	 * @param array<string, mixed> $filters
	 * @return list<array<string, mixed>>
	 */
	public function findAll( array $filters, string $orderby = 'arrival', string $order = 'ASC', int $limit = 500 ): array {
		return $this->search( $filters, 1, $limit, $orderby, $order )['items'];
	}

	/**
	 * Count per status for the filters (their status filter ignored), plus `all` and `upcoming`.
	 *
	 * @param array<string, mixed> $filters
	 * @return array<string, int>
	 */
	public function countsByStatus( array $filters ): array {
		global $wpdb;

		unset( $filters['status'] );
		[ $where, $args ] = $this->where( $filters );

		$counts = array( 'all' => 0 );
		foreach ( array( 'pending_payment', 'confirmed', 'paid', 'balance_failed', 'cancelled', 'expired', 'completed' ) as $status ) {
			$counts[ $status ] = 0;
		}

		$rows = (array) $wpdb->get_results( self::prepare( "SELECT b.status AS status, COUNT(*) AS n FROM {$this->table()} b WHERE {$where} GROUP BY b.status", $args ), ARRAY_A );
		foreach ( $rows as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['n'];
			$counts['all']                    += (int) $row['n'];
		}

		[ $where, $args ] = $this->where( array_merge( $filters, array( 'status' => 'upcoming' ) ) );
		$counts['upcoming'] = (int) $wpdb->get_var( self::prepare( "SELECT COUNT(*) FROM {$this->table()} b WHERE {$where}", $args ) );

		return $counts;
	}

	/**
	 * Quick search (command palette): ref, guest name, email or phone.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function quickSearch( string $query, int $limit ): array {
		return $this->search( array( 'search' => $query ), 1, $limit, 'arrival', 'DESC' )['items'];
	}

	/**
	 * Bookings in `$statuses` whose stay overlaps [from, to) (departure exclusive).
	 *
	 * @param list<string> $statuses
	 * @return list<array<string, mixed>>
	 */
	public function overlapping( string $from, string $to, array $statuses ): array {
		global $wpdb;

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE arrival < %s AND departure > %s AND status IN (" . Db::placeholders( $statuses, '%s' ) . ') ORDER BY unit_id, arrival', // phpcs:ignore WordPress.DB.PreparedSQL
				array_merge( array( $to, $from ), $statuses )
			),
			ARRAY_A
		);

		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/**
	 * Number of bookings created at or after `$sinceUtc`, excluding `$excludeStatuses`.
	 *
	 * @param list<string> $excludeStatuses
	 */
	public function countCreatedSince( string $sinceUtc, array $excludeStatuses = array() ): int {
		global $wpdb;

		$sql  = "SELECT COUNT(*) FROM {$this->table()} WHERE created_at >= %s";
		$args = array( $sinceUtc );
		if ( $excludeStatuses ) {
			$sql .= ' AND status NOT IN (' . Db::placeholders( $excludeStatuses, '%s' ) . ')';
			$args = array_merge( $args, $excludeStatuses );
		}

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Booked revenue (total_cents) per unit for sold bookings arriving in [from, to).
	 *
	 * @return array<int, int> unit_id => cents.
	 */
	public function revenueByUnit( string $from, string $to ): array {
		global $wpdb;

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT unit_id, SUM(total_cents) AS cents FROM {$this->table()} WHERE arrival >= %s AND arrival < %s AND status IN (" . Db::placeholders( self::SOLD_STATUSES, '%s' ) . ') GROUP BY unit_id', // phpcs:ignore WordPress.DB.PreparedSQL
				array_merge( array( $from, $to ), self::SOLD_STATUSES )
			),
			ARRAY_A
		);

		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['unit_id'] ] = (int) $row['cents'];
		}

		return $out;
	}

	/**
	 * SQL of the net amount received by booking `b` (see PaymentRepository::NET_SQL).
	 */
	private function paidSql(): string {
		return '(SELECT COALESCE(SUM(' . PaymentRepository::NET_SQL . '), 0) FROM ' . Db::table( 'mbk_payments' ) . ' p WHERE p.booking_id = b.id)';
	}

	/**
	 * @param array<string, mixed> $filters
	 * @return array{0: string, 1: list<mixed>}
	 */
	private function where( array $filters ): array {
		global $wpdb;

		$where = array( '1=1' );
		$args  = array();

		$status = $filters['status'] ?? null;
		if ( 'upcoming' === $status ) {
			$where[] = 'b.status IN (' . Db::placeholders( self::UPCOMING_STATUSES, '%s' ) . ') AND b.departure > %s';
			$args    = array_merge( $args, self::UPCOMING_STATUSES, array( (string) ( $filters['today'] ?? gmdate( 'Y-m-d' ) ) ) );
		} elseif ( is_array( $status ) && $status ) {
			$where[] = 'b.status IN (' . Db::placeholders( $status, '%s' ) . ')';
			$args    = array_merge( $args, array_values( array_map( 'strval', $status ) ) );
		} elseif ( is_string( $status ) && '' !== $status ) {
			$where[] = 'b.status = %s';
			$args[]  = $status;
		}

		foreach ( array( 'unit_id' => '%d', 'guest_id' => '%d', 'source' => '%s' ) as $column => $format ) {
			if ( isset( $filters[ $column ] ) && '' !== $filters[ $column ] && null !== $filters[ $column ] ) {
				$where[] = "b.{$column} = {$format}";
				$args[]  = $filters[ $column ];
			}
		}

		if ( ! empty( $filters['ids'] ) ) {
			$where[] = 'b.id IN (' . Db::placeholders( $filters['ids'] ) . ')';
			$args    = array_merge( $args, array_map( 'intval', $filters['ids'] ) );
		}

		$search = trim( (string) ( $filters['search'] ?? '' ) );
		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = "(b.ref LIKE %s OR b.first_name LIKE %s OR b.last_name LIKE %s OR CONCAT(b.first_name, ' ', b.last_name) LIKE %s OR b.email LIKE %s OR b.phone LIKE %s)";
			$args    = array_merge( $args, array_fill( 0, 6, $like ) );
		}

		$field = (string) ( $filters['date_field'] ?? 'arrival' );
		if ( 'created' === $field ) {
			if ( ! empty( $filters['from_utc'] ) ) {
				$where[] = 'b.created_at >= %s';
				$args[]  = (string) $filters['from_utc'];
			}
			if ( ! empty( $filters['to_utc'] ) ) {
				$where[] = 'b.created_at < %s';
				$args[]  = (string) $filters['to_utc'];
			}
		} else {
			$column = 'departure' === $field ? 'b.departure' : 'b.arrival';
			if ( ! empty( $filters['from'] ) ) {
				$where[] = "{$column} >= %s";
				$args[]  = (string) $filters['from'];
			}
			if ( ! empty( $filters['to'] ) ) {
				$where[] = "{$column} <= %s";
				$args[]  = (string) $filters['to'];
			}
		}

		if ( ! empty( $filters['balance_due_to'] ) ) {
			$where[] = 'b.balance_due_on IS NOT NULL AND b.balance_due_on <= %s';
			$args[]  = (string) $filters['balance_due_to'];
		}
		if ( ! empty( $filters['balance_due_from'] ) ) {
			$where[] = 'b.balance_due_on >= %s';
			$args[]  = (string) $filters['balance_due_from'];
		}

		$paid = $this->paidSql();
		switch ( $filters['payment_state'] ?? '' ) {
			case 'unpaid':
				$where[] = "{$paid} <= 0";
				break;
			case 'deposit':
				$where[] = "{$paid} > 0 AND {$paid} < b.total_cents";
				break;
			case 'paid':
				$where[] = "{$paid} >= b.total_cents";
				break;
			case 'outstanding':
				$where[] = "{$paid} < b.total_cents";
				break;
			case 'failed':
				$where[] = "(b.status = 'balance_failed' OR EXISTS (SELECT 1 FROM " . Db::table( 'mbk_payments' ) . " pf WHERE pf.booking_id = b.id AND pf.status = 'failed'))";
				break;
		}

		return array( implode( ' AND ', $where ), $args );
	}

	/** @param list<mixed> $args */
	private static function prepare( string $sql, array $args ): string {
		global $wpdb;

		return $args ? (string) $wpdb->prepare( $sql, $args ) : $sql; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Inserts a booking. Returns the new id, or 0 on error (classify with Db::errorKind()).
	 *
	 * @param array<string, mixed> $row Column => value; created_at/updated_at default to now (UTC).
	 */
	public function insert( array $row ): int {
		global $wpdb;

		$now                = Db::nowUtc();
		$row['created_at'] ??= $now;
		$row['updated_at'] ??= $now;

		$wpdb->last_error = '';
		$suppress         = $wpdb->suppress_errors( true );
		$ok               = $wpdb->insert( $this->table(), $row );
		$wpdb->suppress_errors( $suppress );

		return false === $ok ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public function update( int $id, array $row ): bool {
		global $wpdb;

		$row['updated_at'] ??= Db::nowUtc();

		return false !== $wpdb->update( $this->table(), $row, array( 'id' => $id ) );
	}

	/**
	 * Compare-and-set status change.
	 *
	 * @param array<string, mixed> $extra
	 * @return int|false 1 = changed, 0 = row no longer has `$from`, false = SQL error.
	 */
	public function updateStatus( int $id, string $from, string $to, array $extra = array() ) {
		global $wpdb;

		$extra['status']     = $to;
		$extra['updated_at'] = Db::nowUtc();

		return $wpdb->update(
			$this->table(),
			$extra,
			array(
				'id'     => $id,
				'status' => $from,
			)
		);
	}

	/**
	 * Sets ga_sent_at once. True only when this call set it.
	 */
	public function markGaSent( int $id, string $nowUtc ): bool {
		global $wpdb;

		return 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET ga_sent_at = %s WHERE id = %d AND ga_sent_at IS NULL", $nowUtc, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * pending_payment bookings with a live hold using a coupon code: not yet
	 * expired, or an admin hold without expiry.
	 */
	public function countLiveHoldsWithCoupon( string $code, string $nowUtc ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table()} WHERE status = %s AND UPPER(coupon_code) = %s AND (hold_expires_at IS NULL OR hold_expires_at > %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'pending_payment',
				strtoupper( trim( $code ) ),
				$nowUtc
			)
		);
	}

	/**
	 * Bookings in `$statuses` carrying a coupon code.
	 *
	 * @param list<string> $statuses
	 */
	public function countWithCoupon( string $code, array $statuses ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table()} WHERE UPPER(coupon_code) = %s AND status IN (" . Db::placeholders( $statuses, '%s' ) . ')', // phpcs:ignore WordPress.DB.PreparedSQL
				array_merge( array( strtoupper( trim( $code ) ) ), $statuses )
			)
		);
	}

	/**
	 * Locks the booking row for the rest of the current transaction and returns
	 * it fresh. MySQL: SELECT … FOR UPDATE. SQLite: a no-op write takes the
	 * database write lock now, so concurrent writers wait (or fail) before reading.
	 *
	 * @return array<string, mixed>|null Null only when the booking does not exist.
	 * @throws LockConflictException On a lock wait timeout / deadlock (MySQL).
	 * @throws \RuntimeException On any other SQL error.
	 */
	public function lockForUpdate( int $id ): ?array {
		global $wpdb;

		if ( Db::isMysql() ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d FOR UPDATE", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( null === $row && '' !== (string) $wpdb->last_error ) {
				// A failed lock must not read as "booking not found" (callers would skip or 404).
				if ( Db::ERROR_LOCK === Db::errorKind() ) {
					throw new LockConflictException( Db::message( 'Locking booking hit a lock conflict' ) );
				}
				throw new \RuntimeException( Db::message( 'Locking booking failed' ) );
			}

			return $row ? self::hydrate( $row ) : null;
		}

		Db::check( $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET updated_at = updated_at WHERE id = %d", $id ) ), 'Locking booking' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $this->find( $id );
	}

	public function delete( int $id ): bool {
		global $wpdb;

		return false !== $wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
	}

	/** @return array<string, mixed>|null */
	public function find( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? self::hydrate( $row ) : null;
	}

	/** @return array<string, mixed>|null */
	public function findByRef( string $ref ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE ref = %s", $ref ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Refs equal to `$base` or `$base-N`.
	 *
	 * @return list<string>
	 */
	public function refsWithBase( string $base ): array {
		global $wpdb;

		return array_map(
			'strval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ref FROM {$this->table()} WHERE ref = %s OR ref LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$base,
					$wpdb->esc_like( $base . '-' ) . '%'
				)
			)
		);
	}

	/**
	 * pending_payment bookings whose hold expired before `$nowUtc`.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function expiredHolds( string $nowUtc, int $limit = 100 ): array {
		global $wpdb;

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE status = %s AND hold_expires_at IS NOT NULL AND hold_expires_at < %s ORDER BY hold_expires_at ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'pending_payment',
				$nowUtc,
				$limit
			),
			ARRAY_A
		);

		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/** @return list<array<string, mixed>> */
	public function forGuest( int $guestId ): array {
		global $wpdb;

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE guest_id = %d ORDER BY arrival DESC", $guestId ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	public function reassignGuest( int $fromGuestId, int $toGuestId ): bool {
		global $wpdb;

		return false !== $wpdb->update( $this->table(), array( 'guest_id' => $toGuestId ), array( 'guest_id' => $fromGuestId ), array( '%d' ), array( '%d' ) );
	}

	/**
	 * Bookings of a WordPress user (guest account), newest arrival first.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function forUser( int $userId ): array {
		global $wpdb;

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE user_id = %d ORDER BY arrival DESC, id DESC", $userId ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/**
	 * Assigns `$userId` to the bookings of a guest (by guest id or booking email)
	 * that have no user yet. Returns the number of bookings linked, false on error.
	 */
	public function linkUser( int $userId, int $guestId, string $email ): int|false {
		global $wpdb;

		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table()} SET user_id = %d WHERE user_id IS NULL AND (guest_id = %d OR email = %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$userId,
				$guestId,
				strtolower( trim( $email ) )
			)
		);

		return false === $result ? false : (int) $result;
	}

	/** Clears `user_id` on every booking of the user (account deleted); false on error. */
	public function unlinkUser( int $userId ): bool {
		global $wpdb;

		return false !== $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET user_id = NULL WHERE user_id = %d", $userId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Completed stays of a guest: bookings marked `completed`, plus `paid`
	 * bookings whose departure is on or before `$todayYmd` (stay over, not yet
	 * closed by an admin).
	 */
	public function countCompletedStays( int $guestId, string $todayYmd ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table()} WHERE guest_id = %d AND (status = 'completed' OR (status = 'paid' AND departure <= %s))", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$guestId,
				$todayYmd
			)
		);
	}

	/** @return list<int> */
	public function idsForGuest( int $guestId ): array {
		global $wpdb;

		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$this->table()} WHERE guest_id = %d", $guestId ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Clears PII on every booking of a guest; amounts, dates and status are kept.
	 */
	public function anonymizeForGuest( int $guestId ): bool {
		global $wpdb;

		return false !== $wpdb->update(
			$this->table(),
			array(
				'first_name'               => '',
				'last_name'                => '',
				'email'                    => '',
				'phone'                    => '',
				'country'                  => '',
				'arrival_time'             => '',
				'message'                  => null,
				'internal_notes'           => null,
				'user_id'                  => null,
				'stripe_customer_id'       => null,
				'stripe_payment_method_id' => null,
				'updated_at'               => Db::nowUtc(),
			),
			array( 'guest_id' => $guestId )
		);
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public static function hydrate( array $row ): array {
		foreach ( self::INT_COLUMNS as $column ) {
			if ( array_key_exists( $column, $row ) && null !== $row[ $column ] ) {
				$row[ $column ] = (int) $row[ $column ];
			}
		}

		return $row;
	}
}
