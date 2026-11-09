<?php

namespace Marclie\Booking\Tests\Unit;

use Marclie\Booking\Domain\Exception\InvalidStayException;
use Marclie\Booking\Domain\Stay;
use PHPUnit\Framework\TestCase;

final class StayTest extends TestCase {

	private function tz(): \DateTimeZone {
		return new \DateTimeZone( 'Europe/Zagreb' );
	}

	public function testNights(): void {
		$s = Stay::fromStrings( 7, '2027-06-29', '2027-07-02', $this->tz() );
		$this->assertSame( 3, $s->nights() );
		$this->assertSame( array( '2027-06-29', '2027-06-30', '2027-07-01' ), $s->nightDates() );
		$this->assertSame( '2027-06-29', $s->arrivalString() );
		$this->assertSame( '2027-07-02', $s->departureString() );
		$this->assertSame( 7, $s->unitId );
	}

	public function testAcrossDstChange(): void {
		$s = Stay::fromStrings( 1, '2027-03-27', '2027-03-30', $this->tz() );
		$this->assertSame( 3, $s->nights() );
		$this->assertCount( 3, $s->nightDates() );
	}

	public function testDepartureMustBeAfterArrival(): void {
		$this->expectException( InvalidStayException::class );
		Stay::fromStrings( 1, '2027-07-12', '2027-07-12', $this->tz() );
	}

	public function testBadFormat(): void {
		try {
			Stay::fromStrings( 1, '2027-02-30', '2027-03-02', $this->tz() );
			$this->fail( 'Expected exception' );
		} catch ( InvalidStayException $e ) {
			$this->assertSame( 'invalid_stay', $e->errorCode() );
		}
	}
}
