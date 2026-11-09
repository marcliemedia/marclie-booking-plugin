<?php
/**
 * Quote calculation (architecture §5). Availability is checked by AvailabilityService.
 *
 * Each night costs, at the price per person of its month, the adults + half the
 * person price (half-up to the cent) per child aged 5–10, but at least the unit's
 * minimum paying guests × the person price. Children aged 0–4 are free and do not
 * count toward capacity or the minimum.
 */

namespace Marclie\Booking\Service;

defined( 'ABSPATH' ) || exit;

use Marclie\Booking\Domain\BookingHorizon;
use Marclie\Booking\Domain\Clock;
use Marclie\Booking\Domain\Exception\BeyondHorizonException;
use Marclie\Booking\Domain\Exception\CapacityException;
use Marclie\Booking\Domain\Exception\MinStayException;
use Marclie\Booking\Domain\Exception\PastDateException;
use Marclie\Booking\Domain\Exception\UnitNotFoundException;
use Marclie\Booking\Domain\Money;
use Marclie\Booking\Domain\Policy;
use Marclie\Booking\Domain\Quote;
use Marclie\Booking\Domain\QuoteLine;
use Marclie\Booking\Domain\Stay;
use Marclie\Booking\Pricing\RateSource;
use Marclie\Booking\Units\UnitProvider;

final class PricingService {

	/** Youngest age that pays: younger children (0–4) are free. */
	public const CHILD_MIN_AGE = 5;

	/** Oldest age that counts as a child (half a person); older guests are adults. */
	public const CHILD_MAX_AGE = 10;

	public function __construct(
		private RateSource $rates,
		private UnitProvider $units,
		private CouponService $coupons,
		private Policy $policy,
		private Clock $clock
	) {}

	/**
	 * @param int $children Children aged 5–10 (half a person each, counted in capacity).
	 * @param int $infants  Children aged 0–4 (free, not counted in capacity or the minimum).
	 */
	public function quote( Stay $stay, int $adults, int $children, ?string $couponCode = null, ?int $guestId = null, int $infants = 0 ): Quote {
		$unit = $this->units->get( $stay->unitId );
		if ( null === $unit ) {
			throw new UnitNotFoundException( 'Unit not found.', array( 'unit_id' => $stay->unitId ) );
		}
		if ( $unit->id !== $stay->unitId ) {
			// Translations resolve to the HR original: rates, coupons and the quote use the canonical ID.
			$stay = new Stay( $unit->id, $stay->arrival, $stay->departure );
		}

		$today = $this->clock->today();
		if ( $stay->arrivalString() < $today->format( 'Y-m-d' ) ) {
			throw new PastDateException( 'Arrival is in the past.', array( 'today' => $today->format( 'Y-m-d' ) ) );
		}
		$lastArrival = BookingHorizon::lastArrival( $today );
		if ( $stay->arrivalString() > $lastArrival ) {
			throw new BeyondHorizonException( 'Arrival is beyond the booking horizon.', array( 'last_arrival' => $lastArrival ) );
		}

		if ( $adults < 1 || $children < 0 || $infants < 0 || $adults + $children > $unit->capacity || $children > $unit->maxChildren ) {
			throw new CapacityException(
				'Guest count exceeds unit capacity.',
				array( 'max' => $unit->capacity, 'max_children' => $unit->maxChildren )
			);
		}

		$dates   = $stay->nightDates();
		$person  = $this->rates->nightly( $stay->unitId, $dates );
		$minStay = $this->rates->minStay( $stay->unitId );
		if ( count( $dates ) < $minStay ) {
			throw new MinStayException( 'Minimum stay not met.', array( 'required' => $minStay, 'nights' => count( $dates ) ) );
		}

		// The unit minimum applies when the party counts fewer persons (a child 5–10 is half a person).
		$minGuests = $this->rates->minGuests( $stay->unitId );
		$minimum   = 2 * $adults + $children < 2 * $minGuests ? $minGuests : 0;

		// Group nights by price per person, in order of first appearance.
		$groups = array();
		foreach ( $dates as $date ) {
			$groups[ $person[ $date ] ] = ( $groups[ $person[ $date ] ] ?? 0 ) + 1;
		}

		$lines    = array();
		$subtotal = Money::zero();
		foreach ( $groups as $cents => $nights ) {
			$child     = intdiv( $cents + 1, 2 ); // Half-up to the cent.
			$nightly   = $minimum > 0 ? $minimum * $cents : $adults * $cents + $children * $child;
			$lineTotal = new Money( $nightly * $nights );
			$subtotal  = $subtotal->add( $lineTotal );
			$lines[]   = $minimum > 0
				? new QuoteLine( QuoteLine::NIGHTS, $nights, $nightly, $lineTotal->cents(), $cents, minGuests: $minimum )
				: new QuoteLine( QuoteLine::NIGHTS, $nights, $nightly, $lineTotal->cents(), $cents, $adults, $children, $child );
		}
		$lines[] = new QuoteLine( QuoteLine::CLEANING, 0, 0, 0 );
		$lines[] = new QuoteLine( QuoteLine::TOURIST_TAX, 0, 0, 0 );

		$discount = Money::zero();
		$coupon   = null;
		if ( null !== $couponCode && '' !== trim( $couponCode ) ) {
			$coupon   = $this->coupons->validate( $couponCode, $stay, $subtotal, $guestId );
			$discount = $this->coupons->discount( $coupon, $subtotal );
			if ( ! $discount->isZero() ) {
				$lines[] = new QuoteLine( QuoteLine::COUPON, 0, -$discount->cents(), -$discount->cents() );
			}
		}

		$total         = $subtotal->sub( $discount );
		$daysToArrival = (int) $today->diff( $stay->arrival )->days;
		$payInFull     = $daysToArrival <= $this->policy->balanceDays;
		$deposit       = $payInFull ? $total : $total->percent( $this->policy->depositPct );
		$balance       = $total->sub( $deposit );

		$balanceDueOn    = $payInFull ? null : $stay->arrival->modify( '-' . $this->policy->balanceDays . ' days' );
		$freeCancelUntil = $stay->arrival->modify( '-' . $this->policy->freeCancelDays . ' days' );
		if ( $freeCancelUntil < $today ) {
			$freeCancelUntil = null;
		}

		$quote = new Quote(
			$stay,
			$adults,
			$children,
			$infants,
			$lines,
			$subtotal,
			$discount,
			$total,
			$deposit,
			$balance,
			$balanceDueOn,
			$freeCancelUntil,
			$payInFull,
			$coupon?->code,
			$coupon?->id,
			$minStay
		);

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Adjusts a computed quote. Must return a Quote; anything else is ignored.
			 *
			 * @param Quote  $quote
			 * @param \Marclie\Booking\Units\Unit $unit
			 * @param Policy $policy
			 */
			$filtered = apply_filters( 'mbk_quote', $quote, $unit, $this->policy );
			if ( $filtered instanceof Quote ) {
				$quote = $filtered;
			}
		}

		return $quote;
	}
}
