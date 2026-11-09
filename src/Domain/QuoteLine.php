<?php
/**
 * One line of a quote.
 *
 * nights: `nights` × `unitCents`, the apartment price per night. Either the party
 *         (`adults` × `personCents` + `children` 5–10 × `childCents`, half the person price)
 *         or, when the party is smaller, the unit minimum (`minGuests` × `personCents`;
 *         `minGuests` is 0 when the party pays).
 * coupon: `totalCents` < 0.
 */

namespace Marclie\Booking\Domain;

defined( 'ABSPATH' ) || exit;

final class QuoteLine {

	public const NIGHTS      = 'nights';
	public const CLEANING    = 'cleaning';
	public const TOURIST_TAX = 'tourist_tax';
	public const COUPON      = 'coupon';

	public function __construct(
		public readonly string $type,
		public readonly int $nights,
		public readonly int $unitCents,
		public readonly int $totalCents,
		public readonly int $personCents = 0,
		public readonly int $adults = 0,
		public readonly int $children = 0,
		public readonly int $childCents = 0,
		public readonly int $minGuests = 0
	) {}

	/** @return array<string, int|string> */
	public function toArray(): array {
		return array(
			'type'         => $this->type,
			'nights'       => $this->nights,
			'unit_cents'   => $this->unitCents,
			'total_cents'  => $this->totalCents,
			'person_cents' => $this->personCents,
			'adults'       => $this->adults,
			'children'     => $this->children,
			'child_cents'  => $this->childCents,
			'min_guests'   => $this->minGuests,
		);
	}

	/** @param array<string, mixed> $a */
	public static function fromArray( array $a ): self {
		return new self(
			(string) $a['type'],
			(int) $a['nights'],
			(int) $a['unit_cents'],
			(int) $a['total_cents'],
			(int) ( $a['person_cents'] ?? 0 ),
			(int) ( $a['adults'] ?? 0 ),
			(int) ( $a['children'] ?? 0 ),
			(int) ( $a['child_cents'] ?? 0 ),
			(int) ( $a['min_guests'] ?? 0 )
		);
	}
}
