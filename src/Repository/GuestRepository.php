<?php
/**
 * mbk_guests persistence (CRM). Email is stored lowercased and trimmed.
 */

namespace Marclie\Booking\Repository;

defined( 'ABSPATH' ) || exit;

final class GuestRepository {

	/** Booking statuses counted in guest statistics. */
	public const STAT_STATUSES = array( 'confirmed', 'paid', 'balance_failed', 'completed' );

	private const INT_COLUMNS = array( 'id', 'user_id', 'bookings_count', 'nights_total', 'revenue_cents' );

	private function table(): string {
		return Db::table( 'mbk_guests' );
	}

	public static function normalizeEmail( string $email ): string {
		return strtolower( trim( $email ) );
	}

	/** @return array<string, mixed>|null */
	public function find( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Admin list. Filters: search (name/email/phone), tag, country, has_consent (bool),
	 * has_account (bool: linked WordPress user), tier (clan|srebrni|zlatni: user meta mbk_tier).
	 *
	 * @param array<string, mixed> $filters
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function search( array $filters, int $page, int $perPage, string $orderby = 'last_stay_at', string $order = 'DESC' ): array {
		global $wpdb;

		$where = array( '1=1' );
		$args  = array();

		$search = trim( (string) ( $filters['search'] ?? '' ) );
		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = "(first_name LIKE %s OR last_name LIKE %s OR CONCAT(first_name, ' ', last_name) LIKE %s OR email LIKE %s OR phone LIKE %s)";
			$args    = array_merge( $args, array_fill( 0, 5, $like ) );
		}
		$tag = trim( (string) ( $filters['tag'] ?? '' ) );
		if ( '' !== $tag ) {
			$where[] = '(tags = %s OR tags LIKE %s OR tags LIKE %s OR tags LIKE %s)';
			$escaped = $wpdb->esc_like( $tag );
			$args    = array_merge( $args, array( $tag, $escaped . ',%', '%,' . $escaped, '%,' . $escaped . ',%' ) );
		}
		if ( ! empty( $filters['country'] ) ) {
			$where[] = 'country = %s';
			$args[]  = strtoupper( (string) $filters['country'] );
		}
		if ( isset( $filters['has_consent'] ) && null !== $filters['has_consent'] ) {
			$where[] = $filters['has_consent'] ? 'marketing_consent_at IS NOT NULL' : 'marketing_consent_at IS NULL';
		}
		if ( isset( $filters['has_account'] ) && null !== $filters['has_account'] ) {
			$where[] = $filters['has_account'] ? 'user_id IS NOT NULL' : 'user_id IS NULL';
		}
		$tier = (string) ( $filters['tier'] ?? '' );
		if ( '' !== $tier ) {
			$where[] = "user_id IN (SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'mbk_tier' AND meta_value = %s)";
			$args[]  = $tier;
		}

		$order   = 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC';
		$columns = array(
			'last_stay_at'   => "last_stay_at {$order}",
			'revenue'        => "revenue_cents {$order}",
			'bookings_count' => "bookings_count {$order}",
			'name'           => "last_name {$order}, first_name {$order}",
			'created_at'     => "created_at {$order}",
		);
		$sort    = $columns[ $orderby ] ?? $columns['last_stay_at'];
		$sql     = implode( ' AND ', $where );
		$prepare = static fn( string $q, array $a ): string => $a ? (string) $wpdb->prepare( $q, $a ) : $q; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$total = (int) $wpdb->get_var( $prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE {$sql}", $args ) );
		$rows  = (array) $wpdb->get_results(
			$prepare( "SELECT * FROM {$this->table()} WHERE {$sql} ORDER BY {$sort}, id {$order} LIMIT %d OFFSET %d", array_merge( $args, array( $perPage, ( $page - 1 ) * $perPage ) ) ),
			ARRAY_A
		);

		return array(
			'items' => array_map( array( self::class, 'hydrate' ), $rows ),
			'total' => $total,
		);
	}

	/**
	 * Quick search (command palette), anonymized guests excluded.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function quickSearch( string $query, int $limit ): array {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( trim( $query ) ) . '%';
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE anonymized_at IS NULL AND (first_name LIKE %s OR last_name LIKE %s OR CONCAT(first_name, ' ', last_name) LIKE %s OR email LIKE %s OR phone LIKE %s) ORDER BY last_stay_at DESC, id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$like,
				$like,
				$like,
				$like,
				$like,
				$limit
			),
			ARRAY_A
		);

		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/** @return array<string, mixed>|null */
	public function findByEmail( string $email ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE email = %s", self::normalizeEmail( $email ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? self::hydrate( $row ) : null;
	}

	/** Clears the user link of every guest of `$userId` (account deleted); false on error. */
	public function unlinkUser( int $userId ): bool {
		global $wpdb;

		return false !== $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET user_id = NULL, updated_at = %s WHERE user_id = %d", Db::nowUtc(), $userId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Guests linked to a user: [guest_id, user_id, email] (membership cron).
	 *
	 * @return list<array{guest_id:int, user_id:int, email:string}>
	 */
	public function linkedToUsers( int $afterId, int $limit ): array {
		global $wpdb;

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT id, user_id, email FROM {$this->table()} WHERE user_id IS NOT NULL AND anonymized_at IS NULL AND id > %d ORDER BY id ASC LIMIT %d", $afterId, $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return array_map(
			static fn( array $r ): array => array(
				'guest_id' => (int) $r['id'],
				'user_id'  => (int) $r['user_id'],
				'email'    => (string) $r['email'],
			),
			$rows
		);
	}

	/**
	 * @param array<string, mixed> $row
	 * @return int New id, 0 on error (e.g. duplicate email; classify with Db::errorKind()).
	 */
	public function insert( array $row ): int {
		global $wpdb;

		$now               = Db::nowUtc();
		$row['email']      = self::normalizeEmail( (string) ( $row['email'] ?? '' ) );
		$row['created_at'] ??= $now;
		$row['updated_at'] ??= $now;

		$wpdb->last_error = '';
		$suppress         = $wpdb->suppress_errors( true );
		$ok               = $wpdb->insert( $this->table(), $row );
		$wpdb->suppress_errors( $suppress );

		return false === $ok ? 0 : (int) $wpdb->insert_id;
	}

	/** @param array<string, mixed> $row */
	public function update( int $id, array $row ): bool {
		global $wpdb;

		if ( isset( $row['email'] ) ) {
			$row['email'] = self::normalizeEmail( (string) $row['email'] );
		}
		$row['updated_at'] ??= Db::nowUtc();

		return false !== $wpdb->update( $this->table(), $row, array( 'id' => $id ) );
	}

	public function delete( int $id ): bool {
		global $wpdb;

		return false !== $wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Recomputes bookings_count, nights_total, revenue_cents, first_booking_at and
	 * last_stay_at (latest arrival) from the guest's bookings in STAT_STATUSES.
	 */
	public function recomputeStats( int $guestId ): bool {
		global $wpdb;

		$bookings = Db::table( 'mbk_bookings' );
		$in       = Db::placeholders( self::STAT_STATUSES, '%s' );

		$stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS bookings_count, COALESCE(SUM(nights), 0) AS nights_total, COALESCE(SUM(total_cents), 0) AS revenue_cents, MIN(created_at) AS first_booking_at, MAX(arrival) AS last_stay_at FROM {$bookings} WHERE guest_id = %d AND status IN ({$in})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array( $guestId ), self::STAT_STATUSES )
			),
			ARRAY_A
		);

		if ( null === $stats && '' !== $wpdb->last_error ) {
			return false;
		}

		return $this->update(
			$guestId,
			array(
				'bookings_count'   => (int) ( $stats['bookings_count'] ?? 0 ),
				'nights_total'     => (int) ( $stats['nights_total'] ?? 0 ),
				'revenue_cents'    => (int) ( $stats['revenue_cents'] ?? 0 ),
				'first_booking_at' => $stats['first_booking_at'] ?? null,
				'last_stay_at'     => $stats['last_stay_at'] ?? null,
			)
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
