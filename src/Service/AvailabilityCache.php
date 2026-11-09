<?php
/**
 * Public calendar data (`GET /availability`): occupied nights, closed nights
 * (months without a price) and the minimum stay per arrival date, cached in
 * transients per unit + month.
 *
 * Invalidation bumps a version token instead of deleting keys (the months of
 * a unit are unknown): per unit on `mbk_availability_changed` (nights, blocks,
 * prices), `mbk_booking_created` and `mbk_booking_status_changed`; globally on
 * `mbk_availability_changed` with unit 0. Orphaned month entries simply expire.
 */

namespace Marclie\Booking\Service;

use Marclie\Booking\Domain\BookingHorizon;
use Marclie\Booking\Domain\Clock;
use Marclie\Booking\Repository\PriceRepository;

defined( 'ABSPATH' ) || exit;

final class AvailabilityCache {

	private const PREFIX = 'mbk_av_';

	/** Lifetime of a cached month (a safety net; invalidation is event driven). */
	private const MONTH_TTL = 6 * HOUR_IN_SECONDS;

	/** Lifetime of a version token; an expired token only causes cache misses. */
	private const VERSION_TTL = 30 * DAY_IN_SECONDS;

	public const MAX_MONTHS = 12;

	/** Calendar window: months back from the current month (ahead: BookingHorizon::MONTHS). */
	public const WINDOW_PAST = 1;

	public function __construct(
		private AvailabilityService $availability,
		private PriceRepository $prices,
		private Clock $clock
	) {}

	public static function hooks(): void {
		add_action( 'mbk_availability_changed', array( self::class, 'invalidate' ) );
		add_action( 'mbk_booking_created', array( self::class, 'invalidateBooking' ) );
		add_action( 'mbk_booking_status_changed', array( self::class, 'invalidateBooking' ) );
	}

	/**
	 * @param int|mixed $unitId Canonical unit ID; 0 = every unit.
	 */
	public static function invalidate( $unitId = 0 ): void {
		set_transient( self::versionKey( (int) $unitId ), self::token(), self::VERSION_TTL );
	}

	/**
	 * @param array<string, mixed>|mixed $booking
	 */
	public static function invalidateBooking( $booking ): void {
		if ( is_array( $booking ) && ! empty( $booking['unit_id'] ) ) {
			self::invalidate( (int) $booking['unit_id'] );
		}
	}

	/**
	 * First and last month (Y-m) of the public calendar window: one month back
	 * to the last month of the booking horizon. Only these months are cached.
	 *
	 * @return array{0:string, 1:string}
	 */
	public function window(): array {
		$today = $this->clock->today();

		return array(
			$today->modify( 'first day of this month' )->modify( '-' . self::WINDOW_PAST . ' month' )->format( 'Y-m' ),
			BookingHorizon::lastMonth( $today ),
		);
	}

	/**
	 * Calendar data of `$months` months starting with `$fromMonth` (Y-m).
	 * Cached months are read from transients; the missing ones are computed
	 * together (one occupancy read per source, the price table once) and
	 * stored only when inside window().
	 *
	 * @param int $unitId Canonical unit ID.
	 * @return array{unit_id:int, from:string, to:string, today:string, occupied:list<string>, closed:list<string>, min_stay:array<string, int>}
	 */
	public function range( int $unitId, string $fromMonth, int $months ): array {
		$months  = max( 1, min( self::MAX_MONTHS, $months ) );
		$first   = new \DateTimeImmutable( $fromMonth . '-01', wp_timezone() );
		$version = substr( md5( self::version( 0 ) . self::version( $unitId ) ), 0, 10 );

		$data    = array();
		$missing = array();
		for ( $i = 0; $i < $months; $i++ ) {
			$month  = $first->modify( "+{$i} month" );
			$cached = get_transient( self::monthKey( $unitId, $month, $version ) );
			if ( is_array( $cached ) && isset( $cached['occupied'], $cached['closed'], $cached['min_stay'] ) ) {
				$data[ $i ] = $cached;
			} else {
				$missing[ $i ] = $month;
			}
		}

		if ( $missing ) {
			[ $windowFrom, $windowTo ] = $this->window();
			foreach ( $this->compute( $unitId, $missing ) as $i => $month ) {
				$data[ $i ] = $month;
				$ym         = $missing[ $i ]->format( 'Y-m' );
				if ( $ym >= $windowFrom && $ym <= $windowTo ) {
					set_transient( self::monthKey( $unitId, $missing[ $i ], $version ), $month, self::MONTH_TTL );
				}
			}
			ksort( $data );
		}

		// Arrivals after the booking horizon have no minimum stay = no check-in (a range may reach past it).
		$lastArrival = BookingHorizon::lastArrival( $this->clock->today() );
		$occupied    = array();
		$closed      = array();
		$minStay     = array();
		foreach ( $data as $month ) {
			$occupied = array_merge( $occupied, $month['occupied'] );
			$closed   = array_merge( $closed, $month['closed'] );
			$minStay += array_filter( $month['min_stay'], static fn( string $date ): bool => $date <= $lastArrival, ARRAY_FILTER_USE_KEY );
		}

		return array(
			'unit_id'  => $unitId,
			'from'     => $first->format( 'Y-m-d' ),
			'to'       => $first->modify( "+{$months} month" )->format( 'Y-m-d' ),
			'today'    => $this->clock->today()->format( 'Y-m-d' ),
			'occupied' => $occupied,
			'closed'   => $closed,
			'min_stay' => $minStay,
		);
	}

	/**
	 * @param array<int, \DateTimeImmutable> $months Index => first day of the month, ascending.
	 * @return array<int, array{occupied:list<string>, closed:list<string>, min_stay:array<string, int>}>
	 */
	private function compute( int $unitId, array $months ): array {
		$from     = reset( $months )->format( 'Y-m-d' );
		$to       = end( $months )->modify( '+1 month' )->format( 'Y-m-d' );
		$occupied = $this->availability->occupiedNights( $unitId, $from, $to );
		$prices   = $this->prices->table();
		$minNts   = $prices->minStay( $unitId );

		$out = array();
		foreach ( $months as $i => $first ) {
			$start = $first->format( 'Y-m-d' );
			$end   = $first->modify( '+1 month' )->format( 'Y-m-d' );
			$dates = array();
			for ( $d = $first; $d->format( 'Y-m-d' ) < $end; $d = $d->modify( '+1 day' ) ) {
				$dates[] = $d->format( 'Y-m-d' );
			}

			// A closed month (no price) has no arrival and no night of a stay; a stay may end on its first day.
			$priced = $prices->priced( $unitId, $dates );

			$out[ $i ] = array(
				'occupied' => array_values( array_filter( $occupied, static fn( string $night ): bool => $night >= $start && $night < $end ) ),
				'closed'   => array_values( array_diff( $dates, array_keys( $priced ) ) ),
				'min_stay' => array_fill_keys( array_keys( $priced ), $minNts ),
			);
		}

		return $out;
	}

	private static function monthKey( int $unitId, \DateTimeImmutable $first, string $version ): string {
		return self::PREFIX . $unitId . '_' . $first->format( 'Ym' ) . '_' . $version;
	}

	private static function version( int $unitId ): string {
		$version = get_transient( self::versionKey( $unitId ) );
		if ( is_string( $version ) && '' !== $version ) {
			return $version;
		}

		$version = self::token();
		set_transient( self::versionKey( $unitId ), $version, self::VERSION_TTL );

		return $version;
	}

	private static function versionKey( int $unitId ): string {
		return self::PREFIX . 'v_' . ( $unitId > 0 ? $unitId : 'all' );
	}

	private static function token(): string {
		return bin2hex( random_bytes( 6 ) );
	}
}
