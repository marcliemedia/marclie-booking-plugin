<?php

namespace Marclie\Booking\Tests\Unit;

use Marclie\Booking\Coupons\Coupon;
use Marclie\Booking\Domain\Exception\BeyondHorizonException;
use Marclie\Booking\Domain\Exception\CapacityException;
use Marclie\Booking\Domain\Exception\MinStayException;
use Marclie\Booking\Domain\Exception\NoRateException;
use Marclie\Booking\Domain\Exception\PastDateException;
use Marclie\Booking\Domain\Exception\UnitNotFoundException;
use Marclie\Booking\Domain\FixedClock;
use Marclie\Booking\Domain\Policy;
use Marclie\Booking\Domain\Quote;
use Marclie\Booking\Domain\QuoteLine;
use Marclie\Booking\Domain\Stay;
use Marclie\Booking\Pricing\PriceTable;
use Marclie\Booking\Service\CouponService;
use Marclie\Booking\Service\PricingService;
use Marclie\Booking\Tests\Fakes\ArrayCouponStore;
use Marclie\Booking\Tests\Fakes\ArrayUnitProvider;
use Marclie\Booking\Units\Unit;
use PHPUnit\Framework\TestCase;

final class PricingServiceTest extends TestCase {

	private static function tz(): \DateTimeZone {
		return new \DateTimeZone( 'Europe/Zagreb' );
	}

	/**
	 * Unit 1: sleeps 4, at least 2 paying, min 3 nights, January closed, May 50 €, June 70 €,
	 * September 49,99 €, other months 40 € per person. Unit 6: sleeps 2 (1 child max), at least 2.
	 * Unit 20: sleeps 8, at least 6 paying, min 5 nights, June 100 € per person.
	 */
	private static function service( string $today = '2026-10-28 09:00' ): PricingService {
		$months    = array_fill( 1, 12, 4000 );
		$months[1] = null;
		$months[5] = 5000;
		$months[6] = 7000;
		$months[9] = 4999;
		$suite     = array_fill( 1, 12, null );
		$suite[6]  = 10000;
		$table     = new PriceTable(
			array(
				1  => array( 'min_stay' => 3, 'min_guests' => 2, 'months' => $months ),
				6  => array( 'min_stay' => 3, 'min_guests' => 2, 'months' => $months ),
				20 => array( 'min_stay' => 5, 'min_guests' => 6, 'months' => $suite ),
			)
		);
		$units = array(
			new Unit( 1, 'A1', 'Apartman 1', 4, 3, '15:00', '22:00', '10:00' ),
			new Unit( 6, 'A6', 'Apartman 6', 2, 1, '15:00', '22:00', '10:00' ),
			new Unit( 20, 'PS', 'President Suite Luxury', 8, 7, '15:00', '22:00', '10:00' ),
		);
		$clock = new FixedClock( $today, self::tz() );

		return new PricingService(
			$table,
			new ArrayUnitProvider( $units, array( 1001 => 1 ) ),
			new CouponService( new ArrayCouponStore( array( new Coupon( 1, 'DIRECT5', 'pct', 5 ) ) ), $clock ),
			new Policy(),
			$clock
		);
	}

	private static function stay( string $a, string $d, int $unit = 1 ): Stay {
		return Stay::fromStrings( $unit, $a, $d, self::tz() );
	}

	/** @return list<QuoteLine> */
	private static function lines( Quote $q, string $type ): array {
		return array_values( array_filter( $q->lines, static fn( QuoteLine $l ): bool => $type === $l->type ) );
	}

	public function testFewerGuestsThanTheMinimumPayTheMinimum(): void {
		$q = self::service()->quote( self::stay( '2027-06-10', '2027-06-13' ), 1, 0 );

		$nights = self::lines( $q, QuoteLine::NIGHTS );
		$this->assertCount( 1, $nights );
		$this->assertSame( array( 3, 14000, 2, 7000 ), array( $nights[0]->nights, $nights[0]->unitCents, $nights[0]->minGuests, $nights[0]->personCents ) );
		$this->assertSame( 3 * 14000, $q->total->cents() );
		$this->assertSame( 5 * 6 * 10000, self::service()->quote( self::stay( '2027-06-10', '2027-06-15', 20 ), 2, 0 )->total->cents(), 'suite: at least 6 paying' );

		// 1 adult + 1 child = 1½ persons: below the minimum of 2.
		$this->assertSame( 2, self::lines( self::service()->quote( self::stay( '2027-06-10', '2027-06-13' ), 1, 1 ), QuoteLine::NIGHTS )[0]->minGuests );
		// 1 adult + 2 children = 2 persons: the party pays (1 × 70 € + 2 × 35 €), not the minimum.
		$line = self::lines( self::service()->quote( self::stay( '2027-06-10', '2027-06-13' ), 1, 2 ), QuoteLine::NIGHTS )[0];
		$this->assertSame( array( 0, 1, 2, 3500, 14000 ), array( $line->minGuests, $line->adults, $line->children, $line->childCents, $line->unitCents ) );
	}

	public function testChildrenPayHalfAndYoungChildrenAreFree(): void {
		// 2 adults × 70 € + 2 children × 35 € = 210 € per night, 7 nights.
		$q    = self::service()->quote( self::stay( '2027-06-10', '2027-06-17' ), 2, 2 );
		$line = self::lines( $q, QuoteLine::NIGHTS )[0];
		$this->assertSame( array( 0, 2, 7000, 2, 3500, 21000 ), array( $line->minGuests, $line->adults, $line->personCents, $line->children, $line->childCents, $line->unitCents ) );
		$this->assertSame( 147000, $q->total->cents() );
		$this->assertSame( 44100, $q->deposit->cents() );

		$withInfants = self::service()->quote( self::stay( '2027-06-10', '2027-06-17' ), 2, 2, null, null, 3 );
		$this->assertSame( 147000, $withInfants->total->cents() );
		$this->assertSame( 3, $withInfants->infants );
	}

	public function testAddingAGuestNeverLowersThePrice(): void {
		$parties = array( array( 1, 0 ), array( 2, 0 ), array( 2, 1 ), array( 2, 2 ), array( 3, 1 ), array( 4, 0 ) );
		$totals  = array_map( static fn( array $p ): int => self::service()->quote( self::stay( '2027-06-10', '2027-06-13' ), $p[0], $p[1] )->total->cents(), $parties );

		// 140 · 140 · 175 · 210 · 245 · 280 € per night.
		$this->assertSame( array( 42000, 42000, 52500, 63000, 73500, 84000 ), $totals );
	}

	public function testChildPriceRoundsHalfUpToTheCent(): void {
		// Half of 49,99 € = 24,995 € → 25,00 € per child: 2 × 49,99 € + 1 × 25,00 € = 124,98 €.
		$q = self::service()->quote( self::stay( '2027-09-10', '2027-09-13' ), 2, 1 );
		$this->assertSame( array( 2500, 12498 ), array( self::lines( $q, QuoteLine::NIGHTS )[0]->childCents, self::lines( $q, QuoteLine::NIGHTS )[0]->unitCents ) );
		$this->assertSame( 3 * 12498, $q->total->cents() );
		// Each child is rounded, so the line adds up as shown: 99,98 € + 2 × 25,00 €.
		$this->assertSame( 14998, self::lines( self::service()->quote( self::stay( '2027-09-10', '2027-09-13' ), 2, 2 ), QuoteLine::NIGHTS )[0]->unitCents );
	}

	public function testEachNightIsPricedByItsMonth(): void {
		$q = self::service()->quote( self::stay( '2027-05-29', '2027-06-02' ), 2, 1 );

		$nights = self::lines( $q, QuoteLine::NIGHTS );
		$this->assertSame( array( array( 3, 5000, 12500 ), array( 1, 7000, 17500 ) ), array_map( static fn( QuoteLine $l ): array => array( $l->nights, $l->personCents, $l->unitCents ), $nights ) );
		$this->assertSame( 3 * 12500 + 17500, $q->total->cents() );
	}

	public function testCouponAppliesToTheStay(): void {
		$q = self::service()->quote( self::stay( '2027-06-10', '2027-06-17' ), 2, 2, 'DIRECT5' );

		$this->assertSame( 147000, $q->subtotal->cents() );
		$this->assertSame( 7350, $q->discount->cents() );
		$this->assertSame( 139650, $q->total->cents() );
		$this->assertSame( array( QuoteLine::NIGHTS, QuoteLine::CLEANING, QuoteLine::TOURIST_TAX, QuoteLine::COUPON ), array_map( static fn( QuoteLine $l ): string => $l->type, $q->lines ) );
	}

	public function testClosedMonthCannotBeBooked(): void {
		foreach ( array( array( '2027-01-10', '2027-01-14' ), array( '2026-12-30', '2027-01-02' ) ) as [ $a, $d ] ) {
			try {
				self::service()->quote( self::stay( $a, $d ), 2, 0 );
				$this->fail( 'Expected NoRateException for ' . $a );
			} catch ( NoRateException $e ) {
				$this->assertSame( 'no_rate', $e->errorCode() );
			}
		}
		// The stay may end on the first closed day (3 December nights, 2 persons).
		$this->assertSame( 3 * 4000 * 2, self::service()->quote( self::stay( '2026-12-29', '2027-01-01' ), 2, 0 )->total->cents() );
	}

	public function testMinimumStayOfTheUnit(): void {
		try {
			self::service()->quote( self::stay( '2027-06-10', '2027-06-14', 20 ), 2, 0 );
			$this->fail( 'Expected MinStayException' );
		} catch ( MinStayException $e ) {
			$this->assertSame( 5, $e->context()['required'] );
		}
		$q = self::service()->quote( self::stay( '2027-06-10', '2027-06-15', 20 ), 7, 0 );
		$this->assertSame( 5 * 10000 * 7, $q->total->cents() );
		$this->assertSame( 5, $q->minStay );
		$this->assertSame( 3, self::service()->quote( self::stay( '2027-06-10', '2027-06-13' ), 2, 0 )->minStay );
	}

	public function testChildrenCountTowardCapacity(): void {
		try {
			self::service()->quote( self::stay( '2027-06-10', '2027-06-13' ), 2, 3 );
			$this->fail( 'Expected CapacityException' );
		} catch ( CapacityException $e ) {
			$this->assertSame( 4, $e->context()['max'] );
		}
	}

	public function testYoungChildrenDoNotCountTowardCapacity(): void {
		$q = self::service()->quote( self::stay( '2027-06-10', '2027-06-13' ), 4, 0, null, null, 3 );
		$this->assertSame( 3 * 4 * 7000, $q->total->cents() );
		$this->assertSame( 3, $q->infants );
	}

	public function testNoAdults(): void {
		$this->expectException( CapacityException::class );
		self::service()->quote( self::stay( '2027-06-10', '2027-06-13' ), 0, 2 );
	}

	public function testTooManyChildren(): void {
		$this->expectException( CapacityException::class );
		self::service()->quote( self::stay( '2027-06-10', '2027-06-13', 6 ), 1, 2 ); // kids_max 1.
	}

	public function testUnknownUnit(): void {
		$this->expectException( UnitNotFoundException::class );
		self::service()->quote( self::stay( '2027-06-10', '2027-06-13', 999 ), 2, 0 );
	}

	public function testPastArrival(): void {
		$this->expectException( PastDateException::class );
		self::service()->quote( self::stay( '2026-10-27', '2026-11-01' ), 2, 0 );
	}

	public function testArrivalTodayAllowed(): void {
		$q = self::service()->quote( self::stay( '2026-10-28', '2026-10-31' ), 2, 0 );
		$this->assertTrue( $q->payInFull );
	}

	public function testArrivalOnLastHorizonDayAllowed(): void {
		// today 2026-10-28 → the horizon month is 2028-10; a stay may run past it
		$q = self::service()->quote( self::stay( '2028-10-31', '2028-11-04' ), 2, 0 );
		$this->assertSame( 4, $q->lines[0]->nights );
	}

	public function testArrivalAfterHorizon(): void {
		try {
			self::service()->quote( self::stay( '2028-11-01', '2028-11-05' ), 2, 0 );
			$this->fail( 'Expected BeyondHorizonException' );
		} catch ( BeyondHorizonException $e ) {
			$this->assertSame( '2028-10-31', $e->context()['last_arrival'] );
		}
	}

	public function testPayInFullWithinBalanceDays(): void {
		$q = self::service()->quote( self::stay( '2026-11-11', '2026-11-14' ), 2, 0 ); // 14 days ahead.
		$this->assertTrue( $q->payInFull );
		$this->assertSame( $q->total->cents(), $q->deposit->cents() );
		$this->assertSame( 0, $q->balance->cents() );
		$this->assertNull( $q->balanceDueOn );
		$this->assertNull( $q->freeCancelUntil );

		$q = self::service()->quote( self::stay( '2026-11-12', '2026-11-15' ), 2, 0 ); // 15 days ahead: 3 × 80 €, 30 %.
		$this->assertFalse( $q->payInFull );
		$this->assertSame( 7200, $q->deposit->cents() );
	}

	public function testFreeCancelNullWithin30Days(): void {
		$q = self::service()->quote( self::stay( '2026-11-20', '2026-11-23' ), 2, 0 ); // 23 days ahead.
		$this->assertNull( $q->freeCancelUntil );
		$q = self::service()->quote( self::stay( '2026-11-27', '2026-11-30' ), 2, 0 ); // exactly 30 days.
		$this->assertSame( '2026-10-28', $q->freeCancelUntil?->format( 'Y-m-d' ) );
	}

	public function testTranslationIdResolvesToCanonicalUnit(): void {
		$q = self::service()->quote( self::stay( '2027-06-10', '2027-06-17', 1001 ), 2, 2, 'DIRECT5' );
		$this->assertSame( 1, $q->stay->unitId );
		$this->assertSame( 139650, $q->total->cents() );
	}

	public function testQuoteRoundTrip(): void {
		$q    = self::service()->quote( self::stay( '2027-06-10', '2027-06-17' ), 2, 2, 'DIRECT5' );
		$copy = Quote::fromArray( json_decode( (string) json_encode( $q->toArray() ), true ), self::tz() );
		$this->assertSame( $q->toArray(), $copy->toArray() );
		$this->assertSame( 1, $copy->couponId );
	}
}
