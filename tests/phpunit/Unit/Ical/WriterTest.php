<?php

namespace Marclie\Booking\Tests\Unit\Ical;

use Marclie\Booking\Ical\Parser;
use Marclie\Booking\Ical\Writer;
use PHPUnit\Framework\TestCase;

final class WriterTest extends TestCase {

	private function calendar( array $events ): string {
		return Writer::calendar( $events, 'Villa Example 101', new \DateTimeImmutable( '2026-10-29 10:15:00', new \DateTimeZone( 'Europe/Zagreb' ) ) );
	}

	public function testCrlfAndStructure(): void {
		$ics = $this->calendar(
			array(
				array(
					'uid'       => 'BK-101-280404@example.com',
					'date_from' => '2028-04-04',
					'date_to'   => '2028-04-08',
					'summary'   => 'Villa Example – busy',
				),
			)
		);

		$this->assertStringStartsWith( "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n", $ics );
		$this->assertStringEndsWith( "END:VCALENDAR\r\n", $ics );
		$this->assertSame( 0, preg_match( "/(?<!\r)\n/", $ics ), 'every line ends with CRLF' );
		$this->assertStringContainsString( "\r\nDTSTART;VALUE=DATE:20280404\r\nDTEND;VALUE=DATE:20280408\r\n", $ics );
		$this->assertStringContainsString( "\r\nDTSTAMP:20261029T091500Z\r\n", $ics, 'DTSTAMP in UTC (10:15 CET)' );
		$this->assertStringContainsString( "\r\nUID:BK-101-280404@example.com\r\n", $ics );
	}

	public function testEscaping(): void {
		$this->assertSame( 'a\\, b\\; c\\\\ d\\ne', Writer::escape( "a, b; c\\ d\ne" ) );
		$this->assertSame( 'x\\ny', Writer::escape( "x\r\ny" ) );
	}

	public function testFoldingAt75Octets(): void {
		$line   = 'SUMMARY:' . str_repeat( 'abcdefghij', 20 );
		$folded = Writer::fold( $line );

		foreach ( explode( "\r\n", $folded ) as $i => $part ) {
			$this->assertLessThanOrEqual( 75, strlen( $part ), "part {$i}" );
			if ( $i > 0 ) {
				$this->assertSame( ' ', $part[0] );
			}
		}
		$this->assertSame( 75, strlen( explode( "\r\n", $folded )[0] ) );
		$this->assertSame( $line, Parser::unfold( $folded )[0] );
	}

	public function testFoldingNeverSplitsMultibyteCharacters(): void {
		$line   = 'SUMMARY:' . str_repeat( 'ž', 80 ) . '–€';
		$folded = Writer::fold( $line );

		foreach ( explode( "\r\n", $folded ) as $part ) {
			$this->assertLessThanOrEqual( 75, strlen( $part ) );
			$this->assertTrue( mb_check_encoding( $part, 'UTF-8' ), 'each physical line is valid UTF-8' );
		}
		$this->assertSame( $line, Parser::unfold( $folded )[0] );
	}

	public function testShortLineUnchanged(): void {
		$this->assertSame( 'UID:x', Writer::fold( 'UID:x' ) );
	}

	public function testRoundTripThroughParser(): void {
		$events = array(
			array(
				'uid'       => 'block-7@example.com',
				'date_from' => '2028-05-01',
				'date_to'   => '2028-05-03',
				'summary'   => 'Villa Example – busy',
			),
			array(
				'uid'       => 'ext-9@example.com',
				'date_from' => '2028-06-01',
				'date_to'   => '2028-06-02',
				'summary'   => 'Comma, semicolon; backslash \\ ' . str_repeat( 'č', 50 ),
			),
		);

		$this->assertSame( $events, Parser::parse( $this->calendar( $events ), new \DateTimeZone( 'Europe/Zagreb' ) ) );
	}
}
