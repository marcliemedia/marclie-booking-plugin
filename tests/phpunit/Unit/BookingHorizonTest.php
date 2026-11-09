<?php

namespace Marclie\Booking\Tests\Unit;

use Marclie\Booking\Domain\BookingHorizon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BookingHorizonTest extends TestCase {

	/** @return array<string, array{string, string, string}> today → last month, last arrival */
	public static function days(): array {
		return array(
			'mid month'                 => array( '2026-10-28', '2028-10', '2028-10-31' ),
			'first of the month'        => array( '2026-11-01', '2028-11', '2028-11-30' ),
			'31st does not overflow'    => array( '2026-01-31', '2028-01', '2028-01-31' ),
			'into a leap February'      => array( '2026-02-28', '2028-02', '2028-02-29' ),
			'December crosses the year' => array( '2026-12-31', '2028-12', '2028-12-31' ),
		);
	}

	#[DataProvider( 'days' )]
	public function testLastMonthAndArrival( string $today, string $month, string $arrival ): void {
		$day = new \DateTimeImmutable( $today . ' 23:30', new \DateTimeZone( 'Europe/Zagreb' ) );
		$this->assertSame( $month, BookingHorizon::lastMonth( $day ) );
		$this->assertSame( $arrival, BookingHorizon::lastArrival( $day ) );
	}
}
