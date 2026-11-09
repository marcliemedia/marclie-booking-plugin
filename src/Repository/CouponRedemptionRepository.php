<?php
/**
 * mbk_coupon_redemptions: written when a booking becomes confirmed/paid
 * (UNIQUE booking_id — at most one redemption per booking).
 */

namespace Marclie\Booking\Repository;

defined( 'ABSPATH' ) || exit;

final class CouponRedemptionRepository {

	private function table(): string {
		return Db::table( 'mbk_coupon_redemptions' );
	}

	/** Returns the new id, 0 on error (e.g. duplicate booking_id). */
	public function insert( int $couponId, int $bookingId, ?int $guestId, int $discountCents ): int {
		global $wpdb;

		$wpdb->last_error = '';
		$suppress         = $wpdb->suppress_errors( true );
		$ok               = $wpdb->insert(
			$this->table(),
			array(
				'coupon_id'      => $couponId,
				'booking_id'     => $bookingId,
				'guest_id'       => $guestId,
				'discount_cents' => $discountCents,
				'created_at'     => Db::nowUtc(),
			)
		);
		$wpdb->suppress_errors( $suppress );

		return false === $ok ? 0 : (int) $wpdb->insert_id;
	}

	public function existsForBooking( int $bookingId ): bool {
		global $wpdb;

		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->table()} WHERE booking_id = %d", $bookingId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @return list<array<string, mixed>> */
	public function forCoupon( int $couponId ): array {
		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE coupon_id = %d ORDER BY created_at DESC", $couponId ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/**
	 * Paginated redemptions of a coupon, newest first.
	 *
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function pageForCoupon( int $couponId, int $page, int $perPage ): array {
		global $wpdb;

		return array(
			'items' => (array) $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE coupon_id = %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $couponId, $perPage, ( $page - 1 ) * $perPage ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			),
			'total' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE coupon_id = %d", $couponId ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/** @return list<array<string, mixed>> */
	public function forGuest( int $guestId ): array {
		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE guest_id = %d ORDER BY created_at DESC", $guestId ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	public function reassignGuest( int $fromGuestId, int $toGuestId ): bool {
		global $wpdb;

		return false !== $wpdb->update( $this->table(), array( 'guest_id' => $toGuestId ), array( 'guest_id' => $fromGuestId ), array( '%d' ), array( '%d' ) );
	}

	public function deleteForBooking( int $bookingId ): void {
		global $wpdb;

		$wpdb->delete( $this->table(), array( 'booking_id' => $bookingId ), array( '%d' ) );
	}
}
