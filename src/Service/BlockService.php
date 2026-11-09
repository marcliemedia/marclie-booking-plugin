<?php
/**
 * Manual calendar blocks (maintenance, owner stays…): the mbk_blocks row and
 * its mbk_nights rows are created or removed together.
 */

namespace Marclie\Booking\Service;

use Marclie\Booking\Domain\Exception\NotFoundException;
use Marclie\Booking\Domain\Exception\UnavailableException;
use Marclie\Booking\Domain\Exception\UnitNotFoundException;
use Marclie\Booking\Domain\Stay;
use Marclie\Booking\Repository\BlockRepository;
use Marclie\Booking\Repository\Db;
use Marclie\Booking\Repository\EventRepository;
use Marclie\Booking\Repository\LockConflictException;
use Marclie\Booking\Units\UnitProvider;

defined( 'ABSPATH' ) || exit;

final class BlockService {

	public function __construct(
		private BlockRepository $blocks,
		private AvailabilityService $availability,
		private UnitProvider $units,
		private EventRepository $events
	) {}

	/**
	 * Blocks the stay's nights ([arrival, departure), like a booking).
	 *
	 * @return array<string, mixed> The block row.
	 * @throws UnitNotFoundException|UnavailableException
	 */
	public function create( Stay $stay, string $reason, int $actorUserId ): array {
		return $this->createMany( array( $stay ), $reason, $actorUserId )[0];
	}

	/**
	 * Blocks the nights of several stays (e.g. closing days in many units) in one
	 * transaction: either every block is created or none.
	 *
	 * @param list<Stay> $stays One per unit (a repeated unit counts once).
	 * @return list<array<string, mixed>> The block rows, in the order of `$stays`.
	 * @throws UnitNotFoundException|UnavailableException The first unknown or taken unit.
	 */
	public function createMany( array $stays, string $reason, int $actorUserId ): array {
		$canonical = array();
		foreach ( $stays as $stay ) {
			$unit = $this->units->get( $stay->unitId );
			if ( null === $unit ) {
				throw new UnitNotFoundException( 'Unit not found.', array( 'unit_id' => $stay->unitId ) );
			}
			$canonical[ $unit->id ] ??= new Stay( $unit->id, $stay->arrival, $stay->departure );
		}
		$canonical = array_values( $canonical );

		foreach ( $canonical as $stay ) {
			if ( ! $this->availability->isAvailable( $stay ) ) {
				throw AvailabilityService::unavailable( $stay );
			}
		}

		$attempt = function () use ( $canonical, $reason, $actorUserId ): array {
			return Db::transaction(
				function () use ( $canonical, $reason, $actorUserId ): array {
					$ids = array();
					foreach ( $canonical as $stay ) {
						$id = $this->blocks->insert( $stay->unitId, $stay->arrivalString(), $stay->departureString(), $reason, $actorUserId );
						Db::check( $id ?: false, 'Creating block' );
						$this->availability->block( $id, $stay );
						Db::check(
							$this->events->log(
								'block_created',
								'user:' . $actorUserId,
								null,
								null,
								array(
									'block_id'  => $id,
									'unit_id'   => $stay->unitId,
									'date_from' => $stay->arrivalString(),
									'date_to'   => $stay->departureString(),
									'reason'    => $reason,
								)
							) ?: false,
							'Logging block'
						);
						$ids[] = $id;
					}

					return $ids;
				}
			);
		};

		try {
			$ids = Db::retryOnLock( $attempt );
		} catch ( LockConflictException $e ) {
			// Which unit hit the lock is unknown: name it only when there is just one.
			throw 1 === count( $canonical ) ? AvailabilityService::unavailable( $canonical[0], $e ) : new UnavailableException( 'Lock conflict while blocking several units.', array(), $e );
		}

		return array_map( fn( int $id ): array => (array) $this->blocks->find( $id ), $ids );
	}

	/**
	 * Removes the block and frees its nights.
	 *
	 * @throws NotFoundException
	 */
	public function delete( int $blockId, int $actorUserId ): void {
		$block = $this->blocks->find( $blockId );
		if ( null === $block ) {
			throw new NotFoundException( 'Block not found.', array( 'block_id' => $blockId ) );
		}

		Db::transaction(
			function () use ( $block, $actorUserId ): void {
				$this->availability->releaseBlock( (int) $block['id'] );
				Db::check( $this->blocks->delete( (int) $block['id'] ) ?: false, 'Deleting block' );
				Db::check(
					$this->events->log(
						'block_deleted',
						'user:' . $actorUserId,
						null,
						null,
						array(
							'block_id'  => (int) $block['id'],
							'unit_id'   => (int) $block['unit_id'],
							'date_from' => (string) $block['date_from'],
							'date_to'   => (string) $block['date_to'],
						)
					) ?: false,
					'Logging block removal'
				);
			}
		);
	}
}
