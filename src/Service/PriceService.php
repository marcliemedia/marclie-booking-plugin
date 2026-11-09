<?php
/**
 * The 12-month price table edited from the admin "Cijene" module: per unit the
 * price per person per night of every month (empty = closed) and the minimum
 * stay. Every change is logged in mbk_events (history).
 */

namespace Marclie\Booking\Service;

use Marclie\Booking\Domain\Exception\ValidationException;
use Marclie\Booking\Repository\Db;
use Marclie\Booking\Repository\EventRepository;
use Marclie\Booking\Repository\PriceRepository;
use Marclie\Booking\Units\UnitProvider;

defined( 'ABSPATH' ) || exit;

final class PriceService {

	public const MAX_MIN_STAY = 365;

	/** Upper bound of a price (1 000 000 €), well inside the int unsigned column. */
	public const MAX_CENTS = 100000000;

	public function __construct(
		private PriceRepository $prices,
		private UnitProvider $units,
		private EventRepository $events
	) {}

	/**
	 * Rows of every published unit, in unit order; months indexed 0 (January) – 11.
	 *
	 * @return list<array{unit_id:int, min_stay:int, min_guests:int, months:list<?int>}>
	 */
	public function rows(): array {
		$table = $this->prices->table();
		$out   = array();
		foreach ( $this->units->all() as $unit ) {
			$row   = $table->row( $unit->id );
			$out[] = array(
				'unit_id'    => $unit->id,
				'min_stay'   => $row['min_stay'],
				'min_guests' => $row['min_guests'],
				'months'     => array_values( $row['months'] ),
			);
		}

		return $out;
	}

	/**
	 * Saves full rows. Each change: unit_id, min_stay (1–365), min_guests (1–capacity),
	 * months (12 values, index 0 = January: cents per person 1–MAX_CENTS, or null = closed).
	 *
	 * @param list<array<string, mixed>> $changes
	 * @throws ValidationException With keys changes.{i}.unit_id|min_stay|min_guests|months|months.{m}.
	 */
	public function save( array $changes, string $actor ): void {
		$errors = array();
		$rows   = array();
		foreach ( array_values( $changes ) as $i => $change ) {
			$unit = $this->units->get( (int) ( $change['unit_id'] ?? 0 ) );
			if ( null === $unit ) {
				$errors[ "changes.{$i}.unit_id" ] = __( 'Unknown unit.', 'marclie-booking' );
			}
			$minStay = $change['min_stay'] ?? null;
			if ( ! is_int( $minStay ) || $minStay < 1 || $minStay > self::MAX_MIN_STAY ) {
				$errors[ "changes.{$i}.min_stay" ] = __( 'Minimum stay must be between 1 and 365 nights.', 'marclie-booking' );
			}
			$minGuests = $change['min_guests'] ?? null;
			if ( ! is_int( $minGuests ) || $minGuests < 1 || ( null !== $unit && $minGuests > $unit->capacity ) ) {
				$errors[ "changes.{$i}.min_guests" ] = sprintf(
					/* translators: %d: apartment capacity. */
					__( 'Minimum guests must be between 1 and %d.', 'marclie-booking' ),
					null === $unit ? 1 : $unit->capacity
				);
			}
			$months = $change['months'] ?? null;
			if ( ! is_array( $months ) || 12 !== count( $months ) || array_keys( $months ) !== range( 0, 11 ) ) {
				$errors[ "changes.{$i}.months" ] = __( 'Send a price or an empty value for each of the 12 months.', 'marclie-booking' );
				continue;
			}
			$byMonth = array();
			foreach ( $months as $m => $cents ) {
				if ( null !== $cents && ( ! is_int( $cents ) || $cents < 1 || $cents > self::MAX_CENTS ) ) {
					$errors[ "changes.{$i}.months.{$m}" ] = __( 'Enter a price between 0,01 € and 1.000.000 €, or leave the month empty.', 'marclie-booking' );
				}
				$byMonth[ $m + 1 ] = $cents;
			}
			if ( null !== $unit && is_int( $minStay ) && is_int( $minGuests ) ) {
				$rows[ $unit->id ] = array(
					'min_stay'   => $minStay,
					'min_guests' => $minGuests,
					'months'     => $byMonth,
				);
			}
		}
		if ( $errors ) {
			throw ValidationException::fields( $errors );
		}

		Db::transaction(
			function () use ( $rows, $actor ): void {
				$before = $this->prices->table();
				$log    = array();
				foreach ( $rows as $unitId => $row ) {
					$old    = $before->row( $unitId );
					$months = array();
					foreach ( $row['months'] as $m => $cents ) {
						if ( $old['months'][ $m ] !== $cents ) {
							$months[ $m ] = array( $old['months'][ $m ], $cents );
						}
					}
					if ( ! $months && $old['min_stay'] === $row['min_stay'] && $old['min_guests'] === $row['min_guests'] ) {
						continue;
					}
					Db::check( $this->prices->save( $unitId, $row['min_stay'], $row['min_guests'], $row['months'] ) ?: false, 'Saving prices' );
					$log[] = array(
						'unit_id'         => $unitId,
						'min_stay_from'   => $old['min_stay'],
						'min_stay_to'     => $row['min_stay'],
						'min_guests_from' => $old['min_guests'],
						'min_guests_to'   => $row['min_guests'],
						'months'          => $months,
					);
				}
				if ( $log ) {
					Db::check( $this->events->log( 'prices_changed', $actor, null, null, array( 'changes' => $log ) ) ?: false, 'Logging prices_changed' );
					foreach ( $log as $entry ) {
						AvailabilityService::changed( $entry['unit_id'] );
					}
				}
			}
		);
	}
}
