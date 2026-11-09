<?php

namespace Marclie\Booking\Tests\Unit;

use Marclie\Booking\Service\Occupancy;
use PHPUnit\Framework\TestCase;

final class OccupancyTest extends TestCase {

	public function testNightsInRangeClipsToTheRange(): void {
		// Departure is not a night: 2027-07-12 → 2027-07-19 = 7 nights.
		$this->assertSame( 7, Occupancy::nightsInRange( '2027-07-12', '2027-07-19', '2027-07-01', '2027-08-01' ) );
		// Spans the month end: only July nights (29, 30, 31) count for July.
		$this->assertSame( 3, Occupancy::nightsInRange( '2027-07-29', '2027-08-03', '2027-07-01', '2027-08-01' ) );
		$this->assertSame( 2, Occupancy::nightsInRange( '2027-07-29', '2027-08-03', '2027-08-01', '2027-09-01' ) );
		// Departing on the 1st occupies nothing in that month.
		$this->assertSame( 0, Occupancy::nightsInRange( '2027-06-25', '2027-07-01', '2027-07-01', '2027-08-01' ) );
		$this->assertSame( 0, Occupancy::nightsInRange( '2027-09-01', '2027-09-05', '2027-07-01', '2027-08-01' ) );
	}

	public function testPct(): void {
		// 17 units × 31 days = 527 unit-nights; 7 nights = 1.3 %.
		$this->assertSame( 1.3, Occupancy::pct( 7, 17, 31 ) );
		$this->assertSame( 100.0, Occupancy::pct( 527, 17, 31 ) );
		$this->assertSame( 0.0, Occupancy::pct( 5, 0, 31 ) );
	}

	public function testByMonth(): void {
		$stays = array(
			array( 'arrival' => '2027-07-29', 'departure' => '2027-08-03' ),
			array( 'arrival' => '2027-08-10', 'departure' => '2027-08-20' ),
			array( 'arrival' => '2028-02-27', 'departure' => '2028-03-02' ),
		);

		$by = Occupancy::byMonth( $stays, '2027-07', 12, 2 );

		$this->assertSame( array( '2027-07', '2027-08', '2027-09', '2027-10', '2027-11', '2027-12', '2028-01', '2028-02', '2028-03', '2028-04', '2028-05', '2028-06' ), array_keys( $by ) );
		$this->assertSame( Occupancy::pct( 3, 2, 31 ), $by['2027-07'] );
		$this->assertSame( Occupancy::pct( 12, 2, 31 ), $by['2027-08'] );
		$this->assertSame( 0.0, $by['2027-09'] );
		// Leap year: February 2028 has 29 days; nights 27, 28, 29 Feb.
		$this->assertSame( Occupancy::pct( 3, 2, 29 ), $by['2028-02'] );
		$this->assertSame( Occupancy::pct( 1, 2, 31 ), $by['2028-03'] );
	}
}
