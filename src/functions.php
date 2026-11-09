<?php
/**
 * Public PHP helpers for other code (the site's Schema JSON-LD snippet, Bricks templates).
 */

use Marclie\Booking\Container;
use Marclie\Booking\Domain\Exception\InvalidStayException;
use Marclie\Booking\Domain\Money;
use Marclie\Booking\Domain\Stay;
use Marclie\Booking\Settings;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'mbk_get_price_from' ) ) {
	/**
	 * Lowest apartment price per night of a unit over the 12-month price table
	 * (price per person × minimum paying guests; schema offers "from … / night").
	 *
	 * @param int $unit_id `property` post ID (a translation resolves to the HR original).
	 * @return Money|null Null for an unknown unit or one without prices.
	 */
	function mbk_get_price_from( int $unit_id ): ?Money {
		return Container::search()->priceFrom( $unit_id );
	}
}

if ( ! function_exists( 'mbk_is_available' ) ) {
	/**
	 * Whether every night in [from, to) is free.
	 *
	 * @param int    $unit_id `property` post ID.
	 * @param string $from    Arrival, Y-m-d.
	 * @param string $to      Departure, Y-m-d.
	 * @return bool False for an unknown unit or invalid dates.
	 */
	function mbk_is_available( int $unit_id, string $from, string $to ): bool {
		$unit = Container::units()->get( $unit_id );
		if ( null === $unit ) {
			return false;
		}

		try {
			return Container::availability()->isAvailable( Stay::fromStrings( $unit->id, $from, $to, wp_timezone() ) );
		} catch ( InvalidStayException $e ) {
			return false;
		}
	}
}

if ( ! function_exists( 'mbk_get_stay_times' ) ) {
	/**
	 * Check-in window and check-out time (HH:MM, site time): the unit's own times, or the
	 * policy defaults of the plugin settings when `$unit_id` is 0 or unknown (schema checkinTime/checkoutTime).
	 *
	 * @param int $unit_id `property` post ID, 0 = site-wide policy.
	 * @return array{checkin_from: string, checkin_to: string, checkout_until: string}
	 */
	function mbk_get_stay_times( int $unit_id = 0 ): array {
		$unit = $unit_id ? Container::units()->get( $unit_id ) : null;
		if ( null !== $unit ) {
			return array(
				'checkin_from'   => $unit->checkinFrom,
				'checkin_to'     => $unit->checkinTo,
				'checkout_until' => $unit->checkoutUntil,
			);
		}

		return array(
			'checkin_from'   => Settings::checkinFrom(),
			'checkin_to'     => Settings::checkinTo(),
			'checkout_until' => Settings::checkoutUntil(),
		);
	}
}
