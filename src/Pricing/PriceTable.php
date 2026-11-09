<?php
/**
 * The 12-month price table (pure, no WordPress): per unit one price per person
 * per night for each calendar month (the same every year; null = month closed),
 * one minimum stay and the minimum number of paying guests. A night is priced
 * by its own month.
 */

namespace Marclie\Booking\Pricing;

use Marclie\Booking\Domain\Exception\NoRateException;

defined( 'ABSPATH' ) || exit;

final class PriceTable implements RateSource {

	/** Minimum stay of a unit without a stored row. */
	public const DEFAULT_MIN_STAY = 3;

	/** Minimum paying guests of a unit without a stored row. */
	public const DEFAULT_MIN_GUESTS = 1;

	/**
	 * @param array<int, array{min_stay:int, min_guests:int, months:array<int, ?int>}> $rows unit_id => row; months keyed 1–12, person cents.
	 */
	public function __construct( private array $rows ) {}

	/** @return array{min_stay:int, min_guests:int, months:array<int, ?int>} A unit without prices: defaults, every month closed. */
	public static function emptyRow(): array {
		return array(
			'min_stay'   => self::DEFAULT_MIN_STAY,
			'min_guests' => self::DEFAULT_MIN_GUESTS,
			'months'     => array_fill( 1, 12, null ),
		);
	}

	/** @return array{min_stay:int, min_guests:int, months:array<int, ?int>} */
	public function row( int $unitId ): array {
		return $this->rows[ $unitId ] ?? self::emptyRow();
	}

	public function minStay( int $unitId ): int {
		return max( 1, $this->row( $unitId )['min_stay'] );
	}

	public function minGuests( int $unitId ): int {
		return max( 1, $this->row( $unitId )['min_guests'] );
	}

	/** Price per person of the night `$date` (Y-m-d); null when its month is closed. */
	public function personCents( int $unitId, string $date ): ?int {
		$cents = $this->row( $unitId )['months'][ (int) substr( $date, 5, 2 ) ] ?? null;

		return null !== $cents && $cents > 0 ? $cents : null;
	}

	/**
	 * @param list<string> $dates Y-m-d nights.
	 * @return array<string, int> date => price per person (cents).
	 * @throws NoRateException When a night falls in a closed month.
	 */
	public function nightly( int $unitId, array $dates ): array {
		$out = array();
		foreach ( $dates as $date ) {
			$cents = $this->personCents( $unitId, $date );
			if ( null === $cents ) {
				throw new NoRateException( 'No rate for ' . $date . '.', array( 'date' => $date ) );
			}
			$out[ $date ] = $cents;
		}

		return $out;
	}

	/**
	 * Like nightly(), nights of closed months left out.
	 *
	 * @param list<string> $dates
	 * @return array<string, int>
	 */
	public function priced( int $unitId, array $dates ): array {
		$out = array();
		foreach ( $dates as $date ) {
			$cents = $this->personCents( $unitId, $date );
			if ( null !== $cents ) {
				$out[ $date ] = $cents;
			}
		}

		return $out;
	}

	/** Lowest price per person over the 12 months; null when every month is closed. */
	public function lowestPersonCents( int $unitId ): ?int {
		$prices = array_filter( $this->row( $unitId )['months'], static fn( ?int $c ): bool => null !== $c && $c > 0 );

		return $prices ? min( $prices ) : null;
	}
}
