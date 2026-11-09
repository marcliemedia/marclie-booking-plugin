<?php
/**
 * mbk_coupons persistence and the CouponStore used by CouponService.
 * Codes are stored uppercase; unit_ids is a CSV of post IDs (NULL = all units).
 */

namespace Marclie\Booking\Repository;

use Marclie\Booking\Coupons\Coupon;
use Marclie\Booking\Coupons\CouponStore;

defined( 'ABSPATH' ) || exit;

final class CouponRepository implements CouponStore {

	private function table(): string {
		return Db::table( 'mbk_coupons' );
	}

	public static function normalizeCode( string $code ): string {
		return strtoupper( trim( $code ) );
	}

	public function findActiveByCode( string $code ): ?Coupon {
		$row = $this->findRowByCode( $code );

		return $row ? self::toCoupon( $row ) : null;
	}

	public function countRedemptions( int $couponId, ?int $guestId = null ): int {
		global $wpdb;

		$table = Db::table( 'mbk_coupon_redemptions' );

		return null === $guestId
			? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE coupon_id = %d", $couponId ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			: (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE coupon_id = %d AND guest_id = %d", $couponId, $guestId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function find( int $id ): ?Coupon {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? self::toCoupon( $row ) : null;
	}

	/** @return array<string, mixed>|null Raw row (any active state). */
	public function findRowByCode( string $code ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE UPPER(code) = %s", self::normalizeCode( $code ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ?: null;
	}

	/** @return array<string, mixed>|null Raw row. */
	public function findRow( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ?: null;
	}

	/**
	 * Admin list of raw rows. Filters: active (bool|null), search (code).
	 *
	 * @param array{active?:bool|null, search?:string} $filters
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function search( array $filters, int $page, int $perPage ): array {
		global $wpdb;

		$where = array( '1=1' );
		$args  = array();
		if ( isset( $filters['active'] ) && null !== $filters['active'] ) {
			$where[] = 'active = %d';
			$args[]  = $filters['active'] ? 1 : 0;
		}
		$search = trim( (string) ( $filters['search'] ?? '' ) );
		if ( '' !== $search ) {
			$where[] = 'code LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( strtoupper( $search ) ) . '%';
		}

		$sql     = implode( ' AND ', $where );
		$prepare = static fn( string $q, array $a ): string => $a ? (string) $wpdb->prepare( $q, $a ) : $q; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'items' => (array) $wpdb->get_results(
				$prepare( "SELECT * FROM {$this->table()} WHERE {$sql} ORDER BY active DESC, created_at DESC, id DESC LIMIT %d OFFSET %d", array_merge( $args, array( $perPage, ( $page - 1 ) * $perPage ) ) ),
				ARRAY_A
			),
			'total' => (int) $wpdb->get_var( $prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE {$sql}", $args ) ),
		);
	}

	/**
	 * Raw rows of coupons assigned to or redeemed by a guest.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function rowsForGuest( int $guestId ): array {
		global $wpdb;

		$redemptions = Db::table( 'mbk_coupon_redemptions' );

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE guest_id = %d OR id IN (SELECT coupon_id FROM {$redemptions} WHERE guest_id = %d) ORDER BY created_at DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$guestId,
				$guestId
			),
			ARRAY_A
		);
	}

	/**
	 * Redemption count and discount total per coupon.
	 *
	 * @param list<int> $couponIds
	 * @return array<int, array{redemptions:int, discount_total_cents:int}>
	 */
	public function stats( array $couponIds ): array {
		global $wpdb;

		if ( ! $couponIds ) {
			return array();
		}

		$redemptions = Db::table( 'mbk_coupon_redemptions' );
		$rows        = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT coupon_id, COUNT(*) AS n, COALESCE(SUM(discount_cents), 0) AS cents FROM {$redemptions} WHERE coupon_id IN (" . Db::placeholders( $couponIds ) . ') GROUP BY coupon_id', // phpcs:ignore WordPress.DB.PreparedSQL
				$couponIds
			),
			ARRAY_A
		);

		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['coupon_id'] ] = array(
				'redemptions'          => (int) $row['n'],
				'discount_total_cents' => (int) $row['cents'],
			);
		}

		return $out;
	}

	/** @return list<Coupon> */
	public function all(): array {
		global $wpdb;

		$rows = (array) $wpdb->get_results( "SELECT * FROM {$this->table()} ORDER BY code ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( array( self::class, 'toCoupon' ), $rows );
	}

	/**
	 * @param array<string, mixed> $row Columns; `unit_ids` may be a list<int> or null.
	 */
	public function insert( array $row ): int {
		global $wpdb;

		$row               = self::prepareRow( $row );
		$row['created_at'] ??= Db::nowUtc();

		$ok = $wpdb->insert( $this->table(), $row );

		return false === $ok ? 0 : (int) $wpdb->insert_id;
	}

	/** @param array<string, mixed> $row */
	public function update( int $id, array $row ): bool {
		global $wpdb;

		return false !== $wpdb->update( $this->table(), self::prepareRow( $row ), array( 'id' => $id ) );
	}

	public function delete( int $id ): bool {
		global $wpdb;

		return false !== $wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
	}

	public function incrementUsed( int $id ): bool {
		global $wpdb;

		return false !== $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET used_count = used_count + 1 WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function reassignGuest( int $fromGuestId, int $toGuestId ): bool {
		global $wpdb;

		return false !== $wpdb->update( $this->table(), array( 'guest_id' => $toGuestId ), array( 'guest_id' => $fromGuestId ), array( '%d' ), array( '%d' ) );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function prepareRow( array $row ): array {
		if ( isset( $row['code'] ) ) {
			$row['code'] = self::normalizeCode( (string) $row['code'] );
		}
		if ( array_key_exists( 'unit_ids', $row ) && is_array( $row['unit_ids'] ) ) {
			$ids             = array_values( array_unique( array_filter( array_map( 'intval', $row['unit_ids'] ) ) ) );
			$row['unit_ids'] = $ids ? implode( ',', $ids ) : null;
		}
		if ( array_key_exists( 'active', $row ) ) {
			$row['active'] = $row['active'] ? 1 : 0;
		}

		return $row;
	}

	/** @param array<string, mixed> $row */
	public static function toCoupon( array $row ): Coupon {
		$nullableInt = static fn( $v ): ?int => null === $v || '' === $v ? null : (int) $v;
		$nullableStr = static fn( $v ): ?string => null === $v || '' === $v ? null : (string) $v;

		$unitIds = null;
		if ( null !== $row['unit_ids'] && '' !== trim( (string) $row['unit_ids'] ) ) {
			$unitIds = array_values( array_filter( array_map( 'intval', explode( ',', (string) $row['unit_ids'] ) ) ) );
		}

		return new Coupon(
			(int) $row['id'],
			(string) $row['code'],
			(string) $row['type'],
			(int) $row['amount'],
			(int) $row['min_nights'],
			$nullableStr( $row['book_from'] ),
			$nullableStr( $row['book_to'] ),
			$nullableStr( $row['stay_from'] ),
			$nullableStr( $row['stay_to'] ),
			$unitIds,
			$nullableInt( $row['usage_limit'] ),
			$nullableInt( $row['per_guest_limit'] ),
			(int) $row['used_count'],
			$nullableInt( $row['guest_id'] ),
			(bool) (int) $row['active']
		);
	}
}
