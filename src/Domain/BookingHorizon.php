<?php
/**
 * Booking horizon: arrivals up to the last day of the month MONTHS months after
 * the current one (site timezone). One window for quotes (/quote, /search,
 * POST /bookings, admin bookings) and the public calendar (/availability).
 */

namespace Marclie\Booking\Domain;

defined( 'ABSPATH' ) || exit;

final class BookingHorizon {

	public const MONTHS = 24;

	/** Last month (Y-m) in which a stay may begin. */
	public static function lastMonth( \DateTimeImmutable $today ): string {
		return self::lastMonthStart( $today )->format( 'Y-m' );
	}

	/** Last bookable arrival date (Y-m-d). */
	public static function lastArrival( \DateTimeImmutable $today ): string {
		return self::lastMonthStart( $today )->format( 'Y-m-t' );
	}

	private static function lastMonthStart( \DateTimeImmutable $today ): \DateTimeImmutable {
		// From the 1st: "+24 month" on the 31st would overflow into the following month.
		return $today->modify( 'first day of this month' )->modify( '+' . self::MONTHS . ' month' );
	}
}
