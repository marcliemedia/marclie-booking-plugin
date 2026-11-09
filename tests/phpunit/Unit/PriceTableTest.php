<?php

namespace Marclie\Booking\Tests\Unit;

use Marclie\Booking\Pricing\PriceTable;
use PHPUnit\Framework\TestCase;

final class PriceTableTest extends TestCase {

	public function testUnitWithoutRowIsClosedWithDefaultMinimumStay(): void {
		$table = new PriceTable( array() );

		$this->assertSame( 3, $table->minStay( 7 ) );
		$this->assertSame( 1, $table->minGuests( 7 ) );
		$this->assertNull( $table->personCents( 7, '2027-06-10' ) );
		$this->assertNull( $table->lowestPersonCents( 7 ) );
		$this->assertSame( array(), $table->priced( 7, array( '2027-06-10' ) ) );
	}

	public function testPricesFollowTheMonthOfEveryYear(): void {
		$months    = array_fill( 1, 12, null );
		$months[5] = 5000;
		$months[6] = 7000;
		$table     = new PriceTable( array( 1 => array( 'min_stay' => 4, 'min_guests' => 3, 'months' => $months ) ) );

		$this->assertSame( 5000, $table->personCents( 1, '2027-05-31' ) );
		$this->assertSame( 7000, $table->personCents( 1, '2031-06-01' ) );
		$this->assertNull( $table->personCents( 1, '2027-07-01' ) );
		$this->assertSame( array( '2027-05-31' => 5000, '2027-06-01' => 7000 ), $table->priced( 1, array( '2027-04-30', '2027-05-31', '2027-06-01', '2027-07-01' ) ) );
		$this->assertSame( 5000, $table->lowestPersonCents( 1 ) );
		$this->assertSame( 4, $table->minStay( 1 ) );
		$this->assertSame( 3, $table->minGuests( 1 ) );
	}
}
