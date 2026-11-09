<?php

namespace Marclie\Booking\Tests\Unit\Ical;

use Marclie\Booking\Ical\IcalException;
use Marclie\Booking\Ical\Importer;
use Marclie\Booking\Ical\Parser;
use PHPUnit\Framework\TestCase;

/** RRULE / EXDATE expansion, duplicate stays, fetch address checks, stored errors. */
final class RecurrenceTest extends TestCase {

	private function parse( string $events, ?string $today = '2027-12-31', ?array &$warnings = null ): array {
		return Parser::parse( "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n" . $events . "END:VCALENDAR\r\n", new \DateTimeZone( 'Europe/Zagreb' ), $today, $warnings );
	}

	private static function event( string $uid, string $from, string $to, string $extra = '' ): string {
		return "BEGIN:VEVENT\r\nUID:{$uid}\r\nDTSTART;VALUE=DATE:{$from}\r\nDTEND;VALUE=DATE:{$to}\r\n{$extra}END:VEVENT\r\n";
	}

	/** @return list<array{0:string, 1:string}> */
	private static function ranges( array $events ): array {
		return array_map( static fn( array $e ): array => array( $e['date_from'], $e['date_to'] ), $events );
	}

	public function testDailyWithCount(): void {
		$events = $this->parse( self::event( 'cleaning', '20280103', '20280104', "RRULE:FREQ=DAILY;INTERVAL=2;COUNT=3\r\n" ) );

		$this->assertSame( array( array( '2028-01-03', '2028-01-04' ), array( '2028-01-05', '2028-01-06' ), array( '2028-01-07', '2028-01-08' ) ), self::ranges( $events ) );
		$this->assertSame( array( 'cleaning#2028-01-03', 'cleaning#2028-01-05', 'cleaning#2028-01-07' ), array_column( $events, 'uid' ) );
	}

	public function testWeeklyUntilWithExdate(): void {
		$events = $this->parse( self::event( 'owner', '20280105', '20280107', "RRULE:FREQ=WEEKLY;UNTIL=20280126T230000Z\r\nEXDATE;VALUE=DATE:20280112\r\nEXDATE;VALUE=DATE:20280119,20280120\r\n" ) );

		// 26 Jan 23:00Z = 27 Jan 00:00 in Zagreb: the 26th is still included; 12th and 19th excluded.
		$this->assertSame( array( array( '2028-01-05', '2028-01-07' ), array( '2028-01-26', '2028-01-28' ) ), self::ranges( $events ) );
	}

	public function testUnboundedRuleStopsAtHorizonAndSkipsThePast(): void {
		$events = $this->parse( self::event( 'weekly', '20271201', '20271202', "RRULE:FREQ=WEEKLY\r\n" ), '2027-12-31' );

		$this->assertSame( '2028-01-05', $events[0]['date_from'], 'occurrences before today are left out' );
		$this->assertLessThan( '2029-06-30', end( $events )['date_from'], '18 months after today' );
		$this->assertGreaterThan( '2029-06-20', end( $events )['date_from'] );
	}

	public function testUnsupportedRuleKeepsFirstOccurrenceWithWarning(): void {
		$warnings = array();
		$events   = $this->parse( self::event( 'monthly', '20280110', '20280112', "RRULE:FREQ=MONTHLY;BYDAY=2MO\r\n" ), '2027-12-31', $warnings );

		$this->assertSame( array( array( '2028-01-10', '2028-01-12' ) ), self::ranges( $events ) );
		$this->assertSame( 'monthly', $events[0]['uid'] );
		$this->assertSame( array( 'rrule_unsupported' ), $warnings );
	}

	public function testSameUidAndStartKeepsTheLongestStay(): void {
		$events = $this->parse( self::event( 'x', '20280301', '20280303' ) . self::event( 'x', '20280301', '20280306' ) . self::event( 'x', '20280301', '20280302' ) );

		$this->assertSame( array( array( '2028-03-01', '2028-03-06' ) ), self::ranges( $events ) );
		$this->assertSame( 'x', $events[0]['uid'] );
	}

	public function testPublicAddressCheck(): void {
		foreach ( array( '93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946' ) as $ip ) {
			$this->assertTrue( Importer::isPublicIp( $ip ), $ip );
		}
		foreach ( array( '127.0.0.1', '10.1.2.3', '192.168.1.1', '169.254.169.254', '100.64.0.1', '::1', 'fe80::1', 'fd00::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1', '0.0.0.0', 'not-an-ip' ) as $ip ) {
			$this->assertFalse( Importer::isPublicIp( $ip ), $ip );
		}
	}

	public function testStoredErrors(): void {
		$this->assertNull( IcalException::fromStored( null ) );
		$this->assertNull( IcalException::fromStored( '' ) );

		$error = IcalException::fromStored( '{"reason":"http_status","status":503}' );
		$this->assertSame( 'http_status', $error->reason );
		$this->assertSame( 'The calendar address answered with HTTP 503.', $error->describe() );

		$legacy = IcalException::fromStored( 'The calendar address answered with HTTP 500.' );
		$this->assertSame( 'legacy', $legacy->reason );
		$this->assertSame( 'The calendar address answered with HTTP 500.', $legacy->describe() );
	}
}
