<?php
/**
 * Busy/free export per unit (`GET /ical/{unit_code}.ics?k=&for=`).
 *
 * Events: bookings that occupy nights (pending_payment with a live or admin
 * hold, confirmed, paid, balance_failed, completed), manual blocks, and
 * `external` blocks imported from OTHER channels than `for` (a channel never
 * gets its own blocks back, and echoes of our own export are never
 * re-exported, so two OTAs cannot keep each other's echoes alive; without
 * `for` no imported block is exported at all). Each read with a valid key and
 * `for` is recorded (FeedRepository::recordFetch) to tell echoes from real
 * reservations. Window: today −30 days to +18 months. No PII: every event is
 * "{brand} – busy" (brand = BusinessInfo name), UIDs end in "@{site host}".
 */

namespace Marclie\Booking\Ical;

use Marclie\Booking\Domain\Clock;
use Marclie\Booking\Email\BusinessInfo;
use Marclie\Booking\Plugin;
use Marclie\Booking\Repository\BlockRepository;
use Marclie\Booking\Repository\BookingRepository;
use Marclie\Booking\Repository\Db;
use Marclie\Booking\Units\Unit;
use Marclie\Booking\Units\UnitProvider;

defined( 'ABSPATH' ) || exit;

final class Exporter {

	public const PAST_DAYS = 30;

	public const FUTURE_MONTHS = 18;

	public function __construct(
		private BookingRepository $bookings,
		private BlockRepository $blocks,
		private FeedRepository $feeds,
		private UnitProvider $units,
		private Clock $clock
	) {}

	/** The unit's export secret, created on first use. */
	public function secretFor( int $unitId ): string {
		$secret = $this->feeds->secret( $unitId );
		if ( null !== $secret ) {
			return $secret;
		}
		$this->feeds->saveSecret( $unitId, self::newSecret(), Db::utc( $this->clock->now() ) );

		return (string) $this->feeds->secret( $unitId );
	}

	/** Replaces the unit's secret: every previously shared export URL stops working. */
	public function rotate( int $unitId ): string {
		$secret = self::newSecret();
		$this->feeds->saveSecret( $unitId, $secret, Db::utc( $this->clock->now() ) );

		return $secret;
	}

	/** @return array{airbnb:string, booking:string, other:string} */
	public function urls( Unit $unit ): array {
		$secret = $this->secretFor( $unit->id );
		$urls   = array();
		foreach ( FeedRepository::CHANNELS as $channel ) {
			$urls[ $channel ] = self::url( $unit, $secret, $channel );
		}

		return $urls;
	}

	public static function url( Unit $unit, string $secret, string $for ): string {
		return add_query_arg(
			array(
				'k'   => $secret,
				'for' => $for,
			),
			rest_url( Plugin::REST_NAMESPACE . '/ical/' . rawurlencode( $unit->code ) . '.ics' )
		);
	}

	/** The OTA of `$for` read the unit's export now. */
	public function recordFetch( Unit $unit, string $for ): void {
		$this->feeds->recordFetch( $unit->id, $for, Db::utc( $this->clock->now() ) );
	}

	/** The unit of `$code` when `$key` is its secret (constant-time), else null. */
	public function verify( string $code, string $key ): ?Unit {
		$unit = '' !== $code ? $this->units->byCode( $code ) : null;
		if ( null === $unit || '' === $key ) {
			return null;
		}
		$secret = $this->feeds->secret( $unit->id );

		return null !== $secret && hash_equals( $secret, $key ) ? $unit : null;
	}

	/**
	 * @param string|null $for airbnb|booking|other (null: no imported blocks).
	 * @return array{body:string, etag:string, count:int}
	 */
	public function calendar( Unit $unit, ?string $for ): array {
		$today = $this->clock->today();
		$from  = $today->modify( sprintf( '-%d days', self::PAST_DAYS ) )->format( 'Y-m-d' );
		$to    = $today->modify( sprintf( '+%d months', self::FUTURE_MONTHS ) )->format( 'Y-m-d' );

		$bookings = array_values( array_filter( $this->bookings->overlapping( $from, $to, BookingRepository::ACTIVE_STATUSES ), static fn( array $b ): bool => (int) $b['unit_id'] === $unit->id ) );
		$brand  = BusinessInfo::get()['name'];
		$events = self::select(
			$bookings,
			$this->blocks->overlapping( $from, $to, $unit->id ),
			$this->feeds->blocksOfUnit( $unit->id, $from, $to ),
			$for,
			Db::utc( $this->clock->now() ),
			/* translators: %s: business name. Summary of every event in the iCal export (no guest data). */
			sprintf( __( '%s – busy', 'marclie-booking' ), $brand ),
			self::uidDomain()
		);

		$name = $brand . ' ' . $unit->code;

		return array(
			'body'  => Writer::calendar( $events, $name, $this->clock->now() ),
			// DTSTAMP changes on every request; the ETag only covers the events.
			'etag'  => 'W/"' . md5( Writer::PRODID . '|' . $name . '|' . (string) wp_json_encode( $events ) ) . '"',
			'count' => count( $events ),
		);
	}

	/**
	 * Events of the export (pure): see the class doc.
	 *
	 * @param list<array<string, mixed>> $bookings  Booking rows of the unit (ref, arrival, departure, status, hold_expires_at).
	 * @param list<array<string, mixed>> $blocks    Manual blocks (id, date_from, date_to).
	 * @param list<array<string, mixed>> $externals Imported blocks (id, date_from, date_to, channel, origin); echoes are never exported.
	 * @param string                     $summary   SUMMARY of every event (no guest data).
	 * @param string                     $uidDomain UID suffix, e.g. "@example.com".
	 * @return list<array{uid:string, date_from:string, date_to:string, summary:string}>
	 */
	public static function select( array $bookings, array $blocks, array $externals, ?string $for, string $nowUtc, string $summary, string $uidDomain ): array {
		$events = array();
		foreach ( $bookings as $booking ) {
			$status = (string) $booking['status'];
			if ( ! in_array( $status, BookingRepository::ACTIVE_STATUSES, true ) ) {
				continue;
			}
			$expires = $booking['hold_expires_at'] ?? null;
			if ( 'pending_payment' === $status && null !== $expires && '' !== $expires && (string) $expires <= $nowUtc ) {
				continue; // Checkout hold already expired (the reaper has not run yet).
			}
			$events[] = self::event( (string) $booking['ref'] . $uidDomain, (string) $booking['arrival'], (string) $booking['departure'], $summary );
		}
		foreach ( $blocks as $block ) {
			$events[] = self::event( 'block-' . (int) $block['id'] . $uidDomain, (string) $block['date_from'], (string) $block['date_to'], $summary );
		}
		if ( null !== $for ) {
			foreach ( $externals as $external ) {
				if ( (string) $external['channel'] === $for || FeedRepository::ORIGIN_ECHO === ( $external['origin'] ?? FeedRepository::ORIGIN_EXTERNAL ) ) {
					continue;
				}
				$events[] = self::event( 'ext-' . (int) $external['id'] . $uidDomain, (string) $external['date_from'], (string) $external['date_to'], $summary );
			}
		}

		usort( $events, static fn( array $a, array $b ): int => array( $a['date_from'], $a['uid'] ) <=> array( $b['date_from'], $b['uid'] ) );

		return $events;
	}

	/** UID suffix of exported events: "@" + the site host (stable per site). */
	public static function uidDomain(): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		return '@' . ( is_string( $host ) && '' !== $host ? $host : 'localhost' );
	}

	/** @return array{uid:string, date_from:string, date_to:string, summary:string} */
	private static function event( string $uid, string $from, string $to, string $summary ): array {
		return array(
			'uid'       => $uid,
			'date_from' => $from,
			'date_to'   => $to,
			'summary'   => $summary,
		);
	}

	/** 32 random bytes, base64url without padding (43 characters). */
	private static function newSecret(): string {
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
	}
}
