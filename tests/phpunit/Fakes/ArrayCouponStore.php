<?php

namespace Marclie\Booking\Tests\Fakes;

use Marclie\Booking\Coupons\Coupon;
use Marclie\Booking\Coupons\CouponStore;

final class ArrayCouponStore implements CouponStore {

	/** @var array<string, Coupon> */
	private array $coupons = array();

	/**
	 * @param list<Coupon>                     $coupons
	 * @param array<int, array<int, int>>      $redemptions couponId => guestId => count
	 */
	public function __construct( array $coupons = array(), private array $redemptions = array() ) {
		foreach ( $coupons as $c ) {
			$this->coupons[ strtoupper( $c->code ) ] = $c;
		}
	}

	public function findActiveByCode( string $code ): ?Coupon {
		return $this->coupons[ strtoupper( $code ) ] ?? null;
	}

	public function countRedemptions( int $couponId, ?int $guestId = null ): int {
		$rows = $this->redemptions[ $couponId ] ?? array();
		return null === $guestId ? array_sum( $rows ) : ( $rows[ $guestId ] ?? 0 );
	}
}
