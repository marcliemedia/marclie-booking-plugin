<?php
/**
 * Availability source backed by mbk_external_blocks (iCal imports, R3).
 * Ranges are [date_from, date_to).
 */

namespace Marclie\Booking\Service;

use Marclie\Booking\Repository\BlockRepository;

defined( 'ABSPATH' ) || exit;

final class ExternalBlocksSource implements AvailabilitySource {

	public function __construct( private BlockRepository $blocks ) {}

	public function occupied( int $unitId, string $from, string $to ): array {
		$nights = array();
		foreach ( $this->blocks->externalOverlapping( $from, $to, $unitId ) as $block ) {
			$start = max( $from, $block['date_from'] );
			$end   = min( $to, $block['date_to'] );
			for ( $day = new \DateTimeImmutable( $start ); $day->format( 'Y-m-d' ) < $end; $day = $day->modify( '+1 day' ) ) {
				$nights[] = $day->format( 'Y-m-d' );
			}
		}

		return $nights;
	}

	public function busyUnitIds( string $from, string $to ): array {
		return array_values( array_unique( array_column( $this->blocks->externalOverlapping( $from, $to ), 'unit_id' ) ) );
	}
}
