<?php
/**
 * Calendar availability: occupied nights from every AvailabilitySource and
 * atomic night holds (UNIQUE(unit_id, night) in mbk_nights). Every change of
 * held nights fires `mbk_availability_changed` (unit ID) after the commit.
 */

namespace Marclie\Booking\Service;

use Marclie\Booking\Domain\Exception\UnavailableException;
use Marclie\Booking\Domain\Stay;
use Marclie\Booking\Repository\BlockRepository;
use Marclie\Booking\Repository\Db;
use Marclie\Booking\Repository\LockConflictException;
use Marclie\Booking\Repository\NightsRepository;
use Marclie\Booking\Units\UnitProvider;

defined( 'ABSPATH' ) || exit;

final class AvailabilityService {

	/** @var list<AvailabilitySource>|null */
	private ?array $sources = null;

	public function __construct(
		private NightsRepository $nights,
		private BlockRepository $blocks,
		private UnitProvider $units
	) {}

	/**
	 * Occupied nights of a unit in [from, to): mbk_nights ∪ external blocks ∪ any
	 * source added via `mbk_availability_sources`.
	 *
	 * @return list<string> Y-m-d, ascending, unique.
	 */
	public function occupiedNights( int $unitId, string $from, string $to ): array {
		$unitId = $this->canonicalId( $unitId );
		$nights = array();
		foreach ( $this->sources() as $source ) {
			foreach ( $source->occupied( $unitId, $from, $to ) as $night ) {
				$nights[ $night ] = true;
			}
		}

		$nights = array_keys( $nights );
		sort( $nights );

		return $nights;
	}

	public function isAvailable( Stay $stay ): bool {
		return array() === $this->occupiedNights( $stay->unitId, $stay->arrivalString(), $stay->departureString() );
	}

	/**
	 * Whether the stay is free when the nights currently held by `$bookingId` are
	 * ignored (moving a booking onto dates that overlap its own).
	 */
	public function isAvailableExcept( Stay $stay, int $bookingId ): bool {
		$stay = $this->canonicalStay( $stay );
		$from = $stay->arrivalString();
		$to   = $stay->departureString();

		foreach ( $this->sources() as $source ) {
			$taken = $source instanceof NightsSource
				? $this->nights->occupiedExcludingBooking( $stay->unitId, $from, $to, $bookingId )
				: $source->occupied( $stay->unitId, $from, $to );
			if ( $taken ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The stay with its unit mapped to the canonical (HR original) post ID. Nights
	 * are always stored per canonical unit so HR and EN share one calendar.
	 */
	public function canonicalStay( Stay $stay ): Stay {
		$id = $this->canonicalId( $stay->unitId );

		return $id === $stay->unitId ? $stay : new Stay( $id, $stay->arrival, $stay->departure );
	}

	/**
	 * Dates in [from, to) on which a stay cannot start: every occupied night. A
	 * departure may still fall on the first occupied night (it is not slept).
	 *
	 * @return list<string> Y-m-d.
	 */
	public function noArrivalDates( int $unitId, string $from, string $to ): array {
		return $this->occupiedNights( $unitId, $from, $to );
	}

	/**
	 * Units free for the stay's dates (the stay's unitId is ignored) that sleep
	 * `$guests`. One query per source, not per unit.
	 *
	 * @return list<int>
	 */
	public function availableUnitIds( Stay $stay, int $guests ): array {
		$busy = array_flip( $this->busyUnitIds( $stay->arrivalString(), $stay->departureString() ) );

		$ids = array();
		foreach ( $this->units->all() as $unit ) {
			if ( $unit->capacity >= $guests && ! isset( $busy[ $unit->id ] ) ) {
				$ids[] = $unit->id;
			}
		}

		return $ids;
	}

	/**
	 * Units with at least one occupied night in [from, to). One query per source.
	 *
	 * @return list<int>
	 */
	public function busyUnitIds( string $from, string $to ): array {
		$busy = array();
		foreach ( $this->sources() as $source ) {
			foreach ( $source->busyUnitIds( $from, $to ) as $unitId ) {
				$busy[ $unitId ] = true;
			}
		}

		return array_keys( $busy );
	}

	/**
	 * Inserts one mbk_nights row per night of the stay for the booking, atomically.
	 * Inside an outer Db::transaction() it joins it (lock conflicts propagate as
	 * LockConflictException so the caller can retry the whole transaction).
	 *
	 * @throws UnavailableException When any night is already taken.
	 */
	public function hold( int $bookingId, Stay $stay ): void {
		$this->occupy( $stay, $bookingId, null );
	}

	/**
	 * Occupies the stay's nights for a manual block (mbk_blocks row).
	 *
	 * @throws UnavailableException When any night is already taken.
	 */
	public function block( int $blockId, Stay $stay ): void {
		$this->occupy( $stay, null, $blockId );
	}

	/** Frees every night held by a booking. */
	public function release( int $bookingId ): int {
		$unitIds = $this->nights->unitIdsHeldBy( 'booking_id', $bookingId );
		$deleted = $this->nights->deleteByBooking( $bookingId );
		array_map( array( self::class, 'changed' ), $unitIds );

		return $deleted;
	}

	/** Frees every night held by a manual block. */
	public function releaseBlock( int $blockId ): int {
		$unitIds = $this->nights->unitIdsHeldBy( 'block_id', $blockId );
		$deleted = $this->nights->deleteByBlock( $blockId );
		array_map( array( self::class, 'changed' ), $unitIds );

		return $deleted;
	}

	/**
	 * Announces changed availability of a unit (0 = every unit) once the current
	 * transaction commits (immediately outside a transaction).
	 */
	public static function changed( int $unitId ): void {
		Db::afterCommit(
			static function () use ( $unitId ): void {
				/**
				 * Fires after occupied nights, blocks or rates of a unit changed.
				 *
				 * @param int $unitId Canonical unit ID; 0 = every unit.
				 */
				do_action( 'mbk_availability_changed', $unitId );
			}
		);
	}

	/** @return list<AvailabilitySource> */
	private function sources(): array {
		if ( null === $this->sources ) {
			$defaults = array(
				new NightsSource( $this->nights ),
				new ExternalBlocksSource( $this->blocks ),
			);

			/**
			 * Filters the availability sources consulted for occupied nights.
			 *
			 * @param list<AvailabilitySource> $sources
			 */
			$sources       = (array) apply_filters( 'mbk_availability_sources', $defaults );
			$this->sources = array_values( array_filter( $sources, static fn( $s ): bool => $s instanceof AvailabilitySource ) );
		}

		return $this->sources;
	}

	private function canonicalId( int $unitId ): int {
		$unit = $this->units->get( $unitId );

		return null === $unit ? $unitId : $unit->id;
	}

	private function occupy( Stay $stay, ?int $bookingId, ?int $blockId ): void {
		$stay    = $this->canonicalStay( $stay );
		$attempt = function () use ( $stay, $bookingId, $blockId ): void {
			Db::transaction(
				function () use ( $stay, $bookingId, $blockId ): void {
					$this->insertNights( $stay, $bookingId, $blockId );
				}
			);
		};

		if ( Db::inTransaction() ) {
			$attempt();
			return;
		}

		try {
			Db::retryOnLock( $attempt );
		} catch ( LockConflictException $e ) {
			throw self::unavailable( $stay, $e );
		}
	}

	private function insertNights( Stay $stay, ?int $bookingId, ?int $blockId ): void {
		if ( $this->nights->insert( $stay->unitId, $stay->nightDates(), $bookingId, $blockId ) ) {
			self::changed( $stay->unitId );
			return;
		}

		switch ( Db::errorKind() ) {
			case Db::ERROR_DUPLICATE:
				throw self::unavailable( $stay );
			case Db::ERROR_LOCK:
				throw new LockConflictException( Db::message( 'Holding nights hit a lock conflict' ) );
			default:
				throw new \RuntimeException( Db::message( 'Holding nights failed' ) );
		}
	}

	public static function unavailable( Stay $stay, ?\Throwable $previous = null ): UnavailableException {
		return new UnavailableException(
			'One or more nights are already taken.',
			array(
				'unit_id'   => $stay->unitId,
				'arrival'   => $stay->arrivalString(),
				'departure' => $stay->departureString(),
			),
			$previous
		);
	}
}
