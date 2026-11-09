<?php

namespace Marclie\Booking\Tests\Unit\Ical;

use Marclie\Booking\Ical\IcalException;
use Marclie\Booking\Ical\Parser;
use PHPUnit\Framework\TestCase;

final class ParserTest extends TestCase {

	private const TODAY = '2026-10-29';

	private function tz(): \DateTimeZone {
		return new \DateTimeZone( 'Europe/Zagreb' );
	}

	/** @return list<array{uid:string, date_from:string, date_to:string, summary:string}> */
	private function fixture( string $name, ?string $today = self::TODAY ): array {
		return Parser::parse( (string) file_get_contents( dirname( __DIR__, 3 ) . '/fixtures/ical/' . $name ), $this->tz(), $today );
	}

	/** @return array<string, array{uid:string, date_from:string, date_to:string, summary:string}> */
	private function byUid( array $events ): array {
		return array_column( $events, null, 'uid' );
	}

	public function testAirbnbFeed(): void {
		$events = $this->fixture( 'airbnb.ics' );

		$this->assertCount( 3, $events, 'the 2025 reservation is in the past' );
		$this->assertSame(
			array(
				'uid'       => '1418fb94e984-5d3b2a1c9e8f7d6c5b4a3928170f6e5d@airbnb.com',
				'date_from' => '2028-04-04',
				'date_to'   => '2028-04-08',
				'summary'   => 'Reserved',
			),
			$events[0]
		);
		$this->assertSame( 'Airbnb (Not available)', $events[1]['summary'] );
		$this->assertSame( array( '2028-04-18', '2028-04-20' ), array( $events[1]['date_from'], $events[1]['date_to'] ) );
	}

	public function testPastEventsKeptWithoutToday(): void {
		$this->assertCount( 4, $this->fixture( 'airbnb.ics', null ) );
	}

	public function testBookingComFeedWithLfLineEndings(): void {
		$events = $this->fixture( 'booking.ics' );

		$this->assertCount( 2, $events );
		$this->assertSame( 'CLOSED - Not available', $events[0]['summary'] );
		$this->assertSame( array( '2028-04-10', '2028-04-13' ), array( $events[0]['date_from'], $events[0]['date_to'] ) );
	}

	public function testUnfoldingAndEscapes(): void {
		$events = $this->byUid( $this->fixture( 'folded-escaped.ics' ) );

		$this->assertCount( 2, $events );
		$uid = 'folded-uid-0001-abcdefghijklmnopqrstuvwxyz-0123456789-abcdefghijklmnopqrstuvwxyz@example.com';
		$this->assertArrayHasKey( $uid, $events, 'a folded UID is joined without the leading space' );
		$this->assertSame( 'Gost, obitelj; dolazak kasno Druga linija \\ kosa crta – Žute ruže čekaju', $events[ $uid ]['summary'] );
		$this->assertSame( array( '2028-06-01', '2028-06-04' ), array( $events[ $uid ]['date_from'], $events[ $uid ]['date_to'] ), 'VALARM DTSTART is ignored' );

		$this->assertSame( 'Tabfolded', $events['tab-folded@example.com']['summary'] );
		$this->assertSame( '2028-06-10', $events['tab-folded@example.com']['date_from'] );
	}

	public function testUnescape(): void {
		$this->assertSame( "a,b;c\\d\ne\nf", Parser::unescape( 'a\\,b\\;c\\\\d\\ne\\Nf' ) );
		$this->assertSame( 'keep \\x', Parser::unescape( 'keep \\x' ) );
	}

	public function testPropertyWithQuotedParameters(): void {
		$prop = Parser::property( 'ATTENDEE;CN="Doe: John; Jr.";ROLE=REQ-PARTICIPANT:mailto:john@example.com' );

		$this->assertSame( 'ATTENDEE', $prop['name'] );
		$this->assertSame( 'Doe: John; Jr.', $prop['params']['CN'] );
		$this->assertSame( 'REQ-PARTICIPANT', $prop['params']['ROLE'] );
		$this->assertSame( 'mailto:john@example.com', $prop['value'] );
		$this->assertNull( Parser::property( 'no colon here' ) );
		$this->assertNull( Parser::property( ':value without name' ) );
	}

	public function testDateTimeValuesBecomeLocalDates(): void {
		$events = $this->byUid( $this->fixture( 'datetime-tz.ics' ) );
		$range  = static fn( array $e ): array => array( $e['date_from'], $e['date_to'] );

		$this->assertSame( array( '2028-05-02', '2028-05-05' ), $range( $events['tzid-zagreb@example.com'] ), 'check-out at 10:00 does not occupy the night' );
		$this->assertSame( array( '2028-05-10', '2028-05-12' ), $range( $events['utc@example.com'] ), '23:00Z is 01:00 in Zagreb (CEST)' );
		$this->assertSame( array( '2028-05-16', '2028-05-18' ), $range( $events['new-york@example.com'] ), '20:00 New York is 02:00 next day in Zagreb' );
		$this->assertSame( array( '2028-05-20', '2028-05-22' ), $range( $events['floating@example.com'] ), 'floating time uses X-WR-TIMEZONE' );
		$this->assertSame( array( '2028-05-25', '2028-05-28' ), $range( $events['duration@example.com'] ), 'DURATION:P3D' );
		$this->assertSame( array( '2028-05-30', '2028-05-31' ), $range( $events['no-end@example.com'] ), 'no DTEND: one night' );
		$this->assertSame( array( '2028-06-03', '2028-06-06' ), $range( $events['mozilla-tzid@example.com'] ), 'vendor-prefixed TZID' );
	}

	public function testUtcConvertedToSiteZoneNotUtcDate(): void {
		$ics    = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:x\r\nDTSTART:20280110T233000Z\r\nDTEND:20280112T233000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
		$events = Parser::parse( $ics, $this->tz() );

		// 23:30 UTC in winter = 00:30 CET of the next day.
		$this->assertSame( array( '2028-01-11', '2028-01-13' ), array( $events[0]['date_from'], $events[0]['date_to'] ) );
	}

	public function testAllDayEndIsExclusive(): void {
		$ics    = "BEGIN:VCALENDAR\nBEGIN:VEVENT\nUID:one\nDTSTART;VALUE=DATE:20280301\nDTEND;VALUE=DATE:20280302\nEND:VEVENT\nEND:VCALENDAR\n";
		$events = Parser::parse( $ics, $this->tz() );

		$this->assertSame( array( '2028-03-01', '2028-03-02' ), array( $events[0]['date_from'], $events[0]['date_to'] ) );
	}

	public function testCancelledTransparentPastAndMalformedSkipped(): void {
		$events = $this->fixture( 'cancelled.ics' );
		$uids   = array_column( $events, 'uid' );

		$this->assertCount( 2, $events, implode( ', ', $uids ) );
		$this->assertSame( 'confirmed@example.com', $events[0]['uid'] );
		$this->assertSame( array( '2028-07-10', '2028-07-14' ), array( $events[0]['date_from'], $events[0]['date_to'] ) );
		$this->assertStringStartsWith( 'nouid-', $events[1]['uid'], 'an event without UID gets a stable synthetic one' );
		$this->assertSame( $events[1]['uid'], $this->fixture( 'cancelled.ics' )[1]['uid'] );
		$this->assertNotContains( 'unterminated@example.com', $uids );
	}

	public function testDuplicateUidsKeptOncePerStay(): void {
		$event  = static fn( string $from, string $to ): string => "BEGIN:VEVENT\nUID:dup\nDTSTART;VALUE=DATE:{$from}\nDTEND;VALUE=DATE:{$to}\nEND:VEVENT\n";
		$events = Parser::parse( "BEGIN:VCALENDAR\n" . $event( '20280301', '20280303' ) . $event( '20280310', '20280312' ) . $event( '20280301', '20280303' ) . 'END:VCALENDAR', $this->tz() );

		$this->assertSame( array( 'dup', 'dup#2028-03-10' ), array_column( $events, 'uid' ) );
	}

	public function testBomAndEmptyCalendar(): void {
		$this->assertSame( array(), Parser::parse( "\xEF\xBB\xBFBEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n", $this->tz() ) );
	}

	public function testNotACalendar(): void {
		$this->expectException( IcalException::class );
		Parser::parse( '<!DOCTYPE html><html><body>Login</body></html>', $this->tz() );
	}

	public function testLongUidIsShortenedDeterministically(): void {
		$uid    = str_repeat( 'u', 400 );
		$events = Parser::parse( "BEGIN:VCALENDAR\nBEGIN:VEVENT\nUID:{$uid}\nDTSTART;VALUE=DATE:20280301\nEND:VEVENT\nEND:VCALENDAR", $this->tz() );

		$this->assertSame( Parser::UID_MAX, strlen( $events[0]['uid'] ) );
		$this->assertStringEndsWith( md5( $uid ), $events[0]['uid'] );
	}
}
