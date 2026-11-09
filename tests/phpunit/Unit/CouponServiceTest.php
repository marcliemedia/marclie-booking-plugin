<?php

namespace Marclie\Booking\Tests\Unit;

use Marclie\Booking\Coupons\Coupon;
use Marclie\Booking\Domain\Exception\CouponException;
use Marclie\Booking\Domain\FixedClock;
use Marclie\Booking\Domain\Money;
use Marclie\Booking\Domain\Stay;
use Marclie\Booking\Service\CouponService;
use Marclie\Booking\Tests\Fakes\ArrayCouponStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CouponServiceTest extends TestCase {

	private function service(): CouponService {
		$coupons = array(
			new Coupon( 1, 'DIRECT5', 'pct', 5 ),
			new Coupon( 2, 'OFF', 'pct', 10, active: false ),
			new Coupon( 3, 'OLD', 'pct', 10, bookTo: '2026-10-01' ),
			new Coupon( 4, 'SOON', 'pct', 10, bookFrom: '2026-11-01' ),
			new Coupon( 5, 'LONG', 'pct', 10, minNights: 10 ),
			new Coupon( 6, 'U2', 'pct', 10, unitIds: array( 2 ) ),
			new Coupon( 7, 'FULL', 'pct', 10, usageLimit: 3, usedCount: 3 ),
			new Coupon( 8, 'ONCE', 'pct', 10, perGuestLimit: 1 ),
			new Coupon( 9, 'VIP', 'fixed', 5000, guestId: 42 ),
			new Coupon( 10, 'JUNE', 'pct', 10, stayFrom: '2027-06-01', stayTo: '2027-06-30' ),
			new Coupon( 11, 'AUG', 'pct', 10, stayFrom: '2027-08-01' ),
			new Coupon( 12, 'BIG', 'fixed', 999999 ),
		);
		return new CouponService( new ArrayCouponStore( $coupons, array( 8 => array( 7 => 1 ) ) ), new FixedClock( '2026-10-28 12:00' ) );
	}

	private function stay( string $a = '2027-07-12', string $d = '2027-07-19', int $unit = 1 ): Stay {
		return Stay::fromStrings( $unit, $a, $d, new \DateTimeZone( 'Europe/Zagreb' ) );
	}

	public static function failures(): array {
		return array(
			'not_found'   => array( 'NOPE', null, 'not_found' ),
			'empty'       => array( '  ', null, 'not_found' ),
			'inactive'    => array( 'OFF', null, 'inactive' ),
			'book_to'     => array( 'OLD', null, 'expired' ),
			'book_from'   => array( 'SOON', null, 'not_started' ),
			'stay_to'     => array( 'JUNE', null, 'expired' ),
			'stay_from'   => array( 'AUG', null, 'not_started' ),
			'min_nights'  => array( 'LONG', null, 'min_nights' ),
			'unit'        => array( 'U2', null, 'unit' ),
			'limit'       => array( 'FULL', null, 'limit' ),
			'guest_limit' => array( 'ONCE', 7, 'guest_limit' ),
			'guest_only'  => array( 'VIP', 41, 'guest_only' ),
			'guest_anon'  => array( 'VIP', null, 'guest_only' ),
		);
	}

	#[DataProvider( 'failures' )]
	public function testRejects( string $code, ?int $guest, string $reason ): void {
		try {
			$this->service()->validate( $code, $this->stay(), new Money( 136500 ), $guest );
			$this->fail( 'Expected CouponException' );
		} catch ( CouponException $e ) {
			$this->assertSame( 'coupon_invalid', $e->errorCode() );
			$this->assertSame( $reason, $e->context()['reason'] );
		}
	}

	public function testAccepts(): void {
		$svc = $this->service();
		$this->assertSame( 1, $svc->validate( 'direct5', $this->stay(), new Money( 136500 ), null )->id );
		$this->assertSame( 8, $svc->validate( 'ONCE', $this->stay(), new Money( 136500 ), 8 )->id );
		$this->assertSame( 9, $svc->validate( 'VIP', $this->stay(), new Money( 136500 ), 42 )->id );
		$this->assertSame( 6, $svc->validate( 'U2', $this->stay( unit: 2 ), new Money( 136500 ), null )->id );
		$this->assertSame( 10, $svc->validate( 'JUNE', $this->stay( '2027-06-23', '2027-07-01' ), new Money( 1 ), null )->id );
	}

	public function testDiscount(): void {
		$svc = $this->service();
		$this->assertSame( 6825, $svc->discount( new Coupon( 1, 'P', 'pct', 5 ), new Money( 136500 ) )->cents() );
		$this->assertSame( 5000, $svc->discount( new Coupon( 1, 'F', 'fixed', 5000 ), new Money( 136500 ) )->cents() );
		$this->assertSame( 136500, $svc->discount( new Coupon( 1, 'F', 'fixed', 999999 ), new Money( 136500 ) )->cents() );
		$this->assertSame( 1000, $svc->discount( new Coupon( 1, 'P', 'pct', 150 ), new Money( 1000 ) )->cents() );
		$this->assertSame( 0, $svc->discount( new Coupon( 1, 'F', 'fixed', -5 ), new Money( 1000 ) )->cents() );
	}
}
