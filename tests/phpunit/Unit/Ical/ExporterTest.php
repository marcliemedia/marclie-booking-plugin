<?php

namespace Marclie\Booking\Tests\Unit\Ical;

use Marclie\Booking\Ical\Exporter;
use Marclie\Booking\Ical\Writer;
use PHPUnit\Framework\TestCase;

final class ExporterTest extends TestCase {

	private const NOW = '2026-10-29 08:00:00';
	private const SUMMARY = 'Villa Example – busy';
	private const UID_DOMAIN = '@example.com';

	/** @return list<array<string, mixed>> */
	private function bookings(): array {
		$booking = static fn( string $ref, string $status, ?string $expires = null ): array => array(
			'ref'             => $ref,
			'arrival'         => '2028-04-04',
			'departure'       => '2028-04-08',
			'status'          => $status,
			'hold_expires_at' => $expires,
			'first_name'      => 'Ana',
			'last_name'       => 'Gost',
			'email'           => 'ana@example.com',
			'phone'           => '+385 91 123 4567',
			'message'         => 'Late arrival',
		);

		return array(
			$booking( 'BK-P1', 'pending_payment', '2026-10-29 08:10:00' ),
			$booking( 'BK-P2', 'pending_payment', '2026-10-29 07:59:00' ),
			$booking( 'BK-P3', 'pending_payment' ),
			$booking( 'BK-C', 'confirmed' ),
			$booking( 'BK-PD', 'paid' ),
			$booking( 'BK-BF', 'balance_failed' ),
			$booking( 'BK-CO', 'completed' ),
			$booking( 'BK-X', 'cancelled' ),
			$booking( 'BK-E', 'expired' ),
		);
	}

	/** @return list<array<string, mixed>> */
	private function externals(): array {
		return array(
			array(
				'id'        => 11,
				'date_from' => '2028-05-01',
				'date_to'   => '2028-05-04',
				'channel'   => 'airbnb',
			),
			array(
				'id'        => 12,
				'date_from' => '2028-05-10',
				'date_to'   => '2028-05-12',
				'channel'   => 'booking',
			),
			array(
				'id'        => 13,
				'date_from' => '2028-05-20',
				'date_to'   => '2028-05-21',
				'channel'   => 'other',
			),
		);
	}

	private function blocks(): array {
		return array(
			array(
				'id'        => 5,
				'date_from' => '2028-06-01',
				'date_to'   => '2028-06-03',
				'reason'    => 'Painting (owner)',
			),
		);
	}

	public function testBookingsThatOccupyNights(): void {
		$uids = array_column( Exporter::select( $this->bookings(), array(), array(), null, self::NOW, self::SUMMARY, self::UID_DOMAIN ), 'uid' );

		sort( $uids );
		$this->assertSame(
			array( 'BK-BF@example.com', 'BK-C@example.com', 'BK-CO@example.com', 'BK-P1@example.com', 'BK-P3@example.com', 'BK-PD@example.com' ),
			$uids,
			'live and admin holds, confirmed, paid, balance_failed, completed; not an expired hold, cancelled or expired'
		);
	}

	public function testBlocksAndOtherChannelsOnly(): void {
		$uids = fn( ?string $for ): array => array_column( Exporter::select( array(), $this->blocks(), $this->externals(), $for, self::NOW, self::SUMMARY, self::UID_DOMAIN ), 'uid' );

		$this->assertSame( array( 'ext-12@example.com', 'ext-13@example.com', 'block-5@example.com' ), $uids( 'airbnb' ), 'Airbnb never gets its own blocks back' );
		$this->assertSame( array( 'ext-11@example.com', 'ext-13@example.com', 'block-5@example.com' ), $uids( 'booking' ) );
		$this->assertSame( array( 'ext-11@example.com', 'ext-12@example.com', 'block-5@example.com' ), $uids( 'other' ) );
		$this->assertSame( array( 'block-5@example.com' ), $uids( null ), 'no `for`: no imported block at all' );
	}

	public function testEchoesAreNeverReExported(): void {
		$externals   = $this->externals();
		$externals[] = array(
			'id'        => 14,
			'date_from' => '2028-05-25',
			'date_to'   => '2028-05-27',
			'channel'   => 'booking',
			'origin'    => 'echo',
		);
		$externals[] = array(
			'id'        => 15,
			'date_from' => '2028-05-28',
			'date_to'   => '2028-05-30',
			'channel'   => 'airbnb',
			'origin'    => 'external',
		);

		foreach ( array( 'airbnb', 'booking', 'other' ) as $for ) {
			$uids = array_column( Exporter::select( array(), array(), $externals, $for, self::NOW, self::SUMMARY, self::UID_DOMAIN ), 'uid' );
			$this->assertNotContains( 'ext-14@example.com', $uids, "echo not exported to {$for}" );
		}
		$this->assertContains( 'ext-15@example.com', array_column( Exporter::select( array(), array(), $externals, 'booking', self::NOW, self::SUMMARY, self::UID_DOMAIN ), 'uid' ) );
	}

	public function testNoPii(): void {
		$events = Exporter::select( $this->bookings(), $this->blocks(), $this->externals(), 'other', self::NOW, self::SUMMARY, self::UID_DOMAIN );
		$ics    = Writer::calendar( $events, 'Villa Example 101', new \DateTimeImmutable( self::NOW, new \DateTimeZone( 'UTC' ) ) );

		foreach ( $events as $event ) {
			$this->assertSame( array( 'uid', 'date_from', 'date_to', 'summary' ), array_keys( $event ) );
			$this->assertSame( self::SUMMARY, $event['summary'] );
		}
		foreach ( array( 'Ana', 'Gost', 'ana@example.com', '123 4567', 'Late arrival', 'Painting' ) as $pii ) {
			$this->assertStringNotContainsString( $pii, $ics );
		}
	}

	public function testSortedByDate(): void {
		$dates = array_column( Exporter::select( $this->bookings(), $this->blocks(), $this->externals(), 'other', self::NOW, self::SUMMARY, self::UID_DOMAIN ), 'date_from' );
		$sorted = $dates;
		sort( $sorted );

		$this->assertSame( $sorted, $dates );
	}
}
