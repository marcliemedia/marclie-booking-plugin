<?php
/**
 * A unit + arrival/departure date range (nights = arrival … departure-1).
 */

namespace Marclie\Booking\Domain;

defined( 'ABSPATH' ) || exit;

use Marclie\Booking\Domain\Exception\InvalidStayException;

final class Stay {

	public function __construct(
		public readonly int $unitId,
		public readonly \DateTimeImmutable $arrival,
		public readonly \DateTimeImmutable $departure
	) {
		if ( $departure <= $arrival ) {
			throw new InvalidStayException( 'Departure must be after arrival.' );
		}
	}

	public static function fromStrings( int $unitId, string $arrival, string $departure, \DateTimeZone $tz ): self {
		return new self( $unitId, self::parse( $arrival, $tz ), self::parse( $departure, $tz ) );
	}

	private static function parse( string $date, \DateTimeZone $tz ): \DateTimeImmutable {
		$d = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $tz );
		if ( false === $d || $d->format( 'Y-m-d' ) !== $date ) {
			throw new InvalidStayException( sprintf( 'Invalid date "%s".', $date ) );
		}
		return $d;
	}

	public function nights(): int {
		return (int) $this->arrival->diff( $this->departure )->days;
	}

	/** @return list<string> Y-m-d of every night. */
	public function nightDates(): array {
		$dates = array();
		for ( $d = $this->arrival; $d < $this->departure; $d = $d->modify( '+1 day' ) ) {
			$dates[] = $d->format( 'Y-m-d' );
		}
		return $dates;
	}

	public function arrivalString(): string {
		return $this->arrival->format( 'Y-m-d' );
	}

	public function departureString(): string {
		return $this->departure->format( 'Y-m-d' );
	}
}
