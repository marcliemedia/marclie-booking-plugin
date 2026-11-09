<?php
/**
 * Pure occupancy maths (no WordPress): occupied nights / (units × days).
 * Stays are [arrival, departure) date ranges, Y-m-d.
 */

namespace Marclie\Booking\Service;

defined( 'ABSPATH' ) || exit;

final class Occupancy {

	/** Nights of [arrival, departure) that fall in [from, to). */
	public static function nightsInRange( string $arrival, string $departure, string $from, string $to ): int {
		$start = max( $arrival, $from );
		$end   = min( $departure, $to );
		if ( $end <= $start ) {
			return 0;
		}

		return (int) self::day( $start )->diff( self::day( $end ) )->days;
	}

	/** Percentage with one decimal; 0 when there is no capacity. */
	public static function pct( int $nights, int $units, int $days ): float {
		$capacity = $units * $days;

		return $capacity > 0 ? round( 100 * $nights / $capacity, 1 ) : 0.0;
	}

	/**
	 * Occupancy per calendar month.
	 *
	 * @param list<array{arrival:string, departure:string}> $stays
	 * @param string                                        $firstMonth Y-m.
	 * @return array<string, float> Y-m => percent, `$months` entries in order.
	 */
	public static function byMonth( array $stays, string $firstMonth, int $months, int $units ): array {
		$out   = array();
		$start = self::day( $firstMonth . '-01' );
		for ( $i = 0; $i < $months; $i++ ) {
			$from   = $start->modify( sprintf( '+%d months', $i ) );
			$to     = $from->modify( '+1 month' );
			$fromS  = $from->format( 'Y-m-d' );
			$toS    = $to->format( 'Y-m-d' );
			$nights = 0;
			foreach ( $stays as $stay ) {
				$nights += self::nightsInRange( (string) $stay['arrival'], (string) $stay['departure'], $fromS, $toS );
			}
			$out[ $from->format( 'Y-m' ) ] = self::pct( $nights, $units, (int) $from->format( 't' ) );
		}

		return $out;
	}

	private static function day( string $ymd ): \DateTimeImmutable {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, new \DateTimeZone( 'UTC' ) );
		if ( false === $date ) {
			throw new \InvalidArgumentException( sprintf( 'Invalid date "%s".', $ymd ) );
		}

		return $date;
	}
}
