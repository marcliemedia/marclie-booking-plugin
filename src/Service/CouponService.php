<?php
/**
 * Coupon validation + discount (one coupon per booking).
 */

namespace Marclie\Booking\Service;

defined( 'ABSPATH' ) || exit;

use Marclie\Booking\Coupons\Coupon;
use Marclie\Booking\Coupons\CouponStore;
use Marclie\Booking\Domain\Clock;
use Marclie\Booking\Domain\Exception\CouponException;
use Marclie\Booking\Domain\Money;
use Marclie\Booking\Domain\Stay;

final class CouponService {

	public function __construct( private CouponStore $store, private Clock $clock ) {}

	/**
	 * @throws CouponException reason: not_found|inactive|expired|not_started|min_nights|unit|limit|guest_limit|guest_only.
	 */
	public function validate( string $code, Stay $stay, Money $subtotal, ?int $guestId ): Coupon {
		$code   = strtoupper( trim( $code ) );
		$coupon = '' === $code ? null : $this->store->findActiveByCode( $code );
		if ( null === $coupon ) {
			self::fail( 'not_found', $code );
		}
		if ( ! $coupon->active ) {
			self::fail( 'inactive', $code );
		}
		$today    = $this->clock->today()->format( 'Y-m-d' );
		$lastNight = $stay->departure->modify( '-1 day' )->format( 'Y-m-d' );
		if ( ( $coupon->bookFrom && $today < $coupon->bookFrom ) || ( $coupon->stayFrom && $stay->arrivalString() < $coupon->stayFrom ) ) {
			self::fail( 'not_started', $code );
		}
		if ( ( $coupon->bookTo && $today > $coupon->bookTo ) || ( $coupon->stayTo && $lastNight > $coupon->stayTo ) ) {
			self::fail( 'expired', $code );
		}
		if ( $coupon->minNights > 0 && $stay->nights() < $coupon->minNights ) {
			self::fail( 'min_nights', $code, array( 'min_nights' => $coupon->minNights ) );
		}
		if ( null !== $coupon->unitIds && ! in_array( $stay->unitId, $coupon->unitIds, true ) ) {
			self::fail( 'unit', $code );
		}
		if ( null !== $coupon->usageLimit && $coupon->usedCount >= $coupon->usageLimit ) {
			self::fail( 'limit', $code );
		}
		if ( null !== $coupon->guestId && $coupon->guestId !== $guestId ) {
			self::fail( 'guest_only', $code );
		}
		if ( null !== $coupon->perGuestLimit && null !== $guestId
			&& $this->store->countRedemptions( $coupon->id, $guestId ) >= $coupon->perGuestLimit ) {
			self::fail( 'guest_limit', $code );
		}
		return $coupon;
	}

	/** Discount amount, never above the subtotal and never negative. */
	public function discount( Coupon $coupon, Money $subtotal ): Money {
		$discount = Coupon::PCT === $coupon->type ? $subtotal->percent( $coupon->amount ) : new Money( $coupon->amount );
		if ( $discount->isNegative() || $subtotal->isNegative() ) {
			return Money::zero();
		}
		return $discount->min( $subtotal );
	}

	/** @param array<string, mixed> $extra */
	private static function fail( string $reason, string $code, array $extra = array() ): never {
		throw new CouponException( 'coupon_' . $reason, array( 'reason' => $reason, 'code' => $code ) + $extra );
	}
}
