<?php
/**
 * Unit search for `/pretraga/` (REST `GET /search`, Bricks query type): every
 * unit with its availability, capacity fit and stay total. Occupancy is read
 * with one query per availability source for all units.
 */

namespace Marclie\Booking\Service;

use Marclie\Booking\Domain\Clock;
use Marclie\Booking\Domain\Exception\BeyondHorizonException;
use Marclie\Booking\Domain\Exception\BookingException;
use Marclie\Booking\Domain\Exception\InvalidStayException;
use Marclie\Booking\Domain\Exception\MinStayException;
use Marclie\Booking\Domain\Exception\NoRateException;
use Marclie\Booking\Domain\Exception\PastDateException;
use Marclie\Booking\Domain\Money;
use Marclie\Booking\Domain\Stay;
use Marclie\Booking\I18n\Formatter;
use Marclie\Booking\I18n\LanguageAdapter;
use Marclie\Booking\I18n\NativeDriver;
use Marclie\Booking\Repository\PriceRepository;
use Marclie\Booking\Units\Unit;
use Marclie\Booking\Units\UnitProvider;

defined( 'ABSPATH' ) || exit;

final class SearchService {

	public const REASON_UNAVAILABLE = 'unavailable';
	public const REASON_CAPACITY    = 'capacity';
	public const REASON_MIN_STAY    = 'min_stay';
	public const REASON_NO_RATE     = 'no_rate';

	public const REASON_BEYOND_HORIZON = 'beyond_horizon';

	public function __construct(
		private UnitProvider $units,
		private AvailabilityService $availability,
		private PricingService $pricing,
		private PriceRepository $prices,
		private Clock $clock
	) {}

	/**
	 * Strict parsing of the `dolazak` / `odlazak` URL params (unit ID 0).
	 * Null when both are empty.
	 *
	 * @throws InvalidStayException When only one is given, a date is malformed or departure ≤ arrival.
	 * @throws PastDateException    When the arrival is before today.
	 */
	public function parseStay( ?string $arrival, ?string $departure ): ?Stay {
		$arrival   = trim( (string) $arrival );
		$departure = trim( (string) $departure );
		if ( '' === $arrival && '' === $departure ) {
			return null;
		}

		$stay  = Stay::fromStrings( 0, $arrival, $departure, wp_timezone() );
		$today = $this->clock->today()->format( 'Y-m-d' );
		if ( $stay->arrivalString() < $today ) {
			throw new PastDateException( 'Arrival is in the past.', array( 'today' => $today ) );
		}

		return $stay;
	}

	/**
	 * Lenient variant for server rendering: null when missing or invalid.
	 */
	public function stayFromRequest( ?string $arrival, ?string $departure ): ?Stay {
		try {
			return $this->parseStay( $arrival, $departure );
		} catch ( BookingException $e ) {
			return null;
		}
	}

	/**
	 * @param Stay|null   $stay Dates only (unit ID ignored); null = no dates chosen.
	 * @param string|null $lang Formatting language; null = current visitor language.
	 * @return list<array{id:int, code:string, title:string, permalink:string, available:bool, fits:bool, reason:?string, total_cents:?int, total_formatted:?string, nightly_from_cents:int, person_from_cents:int, person_from_formatted:?string}>
	 * @throws PastDateException When the arrival is before today.
	 */
	public function search( ?Stay $stay, int $adults, int $children, ?string $lang = null ): array {
		$lang     = LanguageAdapter::normalize( $lang ) ?? LanguageAdapter::current();
		$adults   = max( 1, $adults );
		$children = max( 0, $children );

		$busy = array();
		if ( null !== $stay ) {
			$today = $this->clock->today()->format( 'Y-m-d' );
			if ( $stay->arrivalString() < $today ) {
				throw new PastDateException( 'Arrival is in the past.', array( 'today' => $today ) );
			}
			$busy = array_flip( $this->availability->busyUnitIds( $stay->arrivalString(), $stay->departureString() ) );
		}

		$table   = $this->prices->table();
		$results = array();
		foreach ( $this->units->all() as $unit ) {
			$fits      = $adults + $children <= $unit->capacity && $children <= $unit->maxChildren;
			$available = ! isset( $busy[ $unit->id ] );
			$reason    = null;
			$total     = null;

			if ( null !== $stay ) {
				try {
					// A unit that does not fit is priced for one adult (its minimum price).
					$quote = $this->pricing->quote( new Stay( $unit->id, $stay->arrival, $stay->departure ), $fits ? $adults : 1, $fits ? $children : 0 );
					$total = $quote->total->cents();
				} catch ( MinStayException $e ) {
					$reason = self::REASON_MIN_STAY;
				} catch ( NoRateException $e ) {
					$reason = self::REASON_NO_RATE;
				} catch ( BeyondHorizonException $e ) {
					$reason = self::REASON_BEYOND_HORIZON;
				}
			}
			if ( ! $fits ) {
				$reason = self::REASON_CAPACITY;
			}
			if ( ! $available ) {
				$reason = self::REASON_UNAVAILABLE;
			}

			$person    = $table->lowestPersonCents( $unit->id ) ?? 0;
			$results[] = array(
				'id'                    => $unit->id,
				'code'                  => $unit->code,
				'title'                 => $unit->title,
				'permalink'             => self::permalink( $unit, $lang ),
				'available'             => $available,
				'fits'                  => $fits,
				'reason'                => $reason,
				'total_cents'           => $total,
				'total_formatted'       => null === $total ? null : Formatter::price( $total, $lang ),
				'nightly_from_cents'    => $person * $table->minGuests( $unit->id ),
				'person_from_cents'     => $person,
				'person_from_formatted' => $person > 0 ? Formatter::price( $person, $lang ) : null,
			);
		}

		return $results;
	}

	/** Lowest apartment price per night of the 12 months (price per person × minimum paying guests); null without any price. */
	public function priceFrom( int $unitId ): ?Money {
		$unit  = $this->units->get( $unitId );
		$table = $this->prices->table();
		$cents = null === $unit ? null : $table->lowestPersonCents( $unit->id );

		return null === $cents ? null : new Money( $cents * $table->minGuests( $unit->id ) );
	}

	/** Lowest price per person per night of the 12 months; null without any price. */
	public function personPriceFrom( int $unitId ): ?Money {
		$unit  = $this->units->get( $unitId );
		$cents = null === $unit ? null : $this->prices->table()->lowestPersonCents( $unit->id );

		return null === $cents ? null : new Money( $cents );
	}

	/** The unit URL in `$lang`: HR URL + `lang=en` with the native driver, the translation's URL with WPML. */
	public static function permalink( Unit $unit, string $lang ): string {
		if ( 'en' !== $lang ) {
			return $unit->permalink;
		}

		return LanguageAdapter::driver() instanceof NativeDriver
			? add_query_arg( NativeDriver::QUERY_ARG, 'en', $unit->permalink )
			: (string) apply_filters( 'wpml_permalink', $unit->permalink, 'en' );
	}
}
