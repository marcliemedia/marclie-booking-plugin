<?php
/**
 * Nightly-price data port (12-month price table per unit).
 */

namespace Marclie\Booking\Pricing;

defined( 'ABSPATH' ) || exit;

interface RateSource {

	/**
	 * Price per person of each night, by the night's month.
	 *
	 * @param list<string> $dates Y-m-d nights.
	 * @return array<string, int> date => cents per person.
	 * @throws \Marclie\Booking\Domain\Exception\NoRateException When a night falls in a closed month (code 'no_rate').
	 */
	public function nightly( int $unitId, array $dates ): array;

	/** Minimum stay of the unit (nights, ≥ 1). */
	public function minStay( int $unitId ): int;

	/** Minimum number of paying guests charged per night (≥ 1). */
	public function minGuests( int $unitId ): int;
}
