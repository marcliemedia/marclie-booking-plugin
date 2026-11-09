<?php
/**
 * Coupon persistence port.
 */

namespace Marclie\Booking\Coupons;

defined( 'ABSPATH' ) || exit;

interface CouponStore {

	/** Case-insensitive lookup; returns the coupon even when inactive (CouponService reports `inactive`). */
	public function findActiveByCode( string $code ): ?Coupon;

	public function countRedemptions( int $couponId, ?int $guestId = null ): int;
}
