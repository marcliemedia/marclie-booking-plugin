<?php
/**
 * Availability source backed by mbk_nights (bookings, holds, manual blocks).
 */

namespace Marclie\Booking\Service;

use Marclie\Booking\Repository\NightsRepository;

defined( 'ABSPATH' ) || exit;

final class NightsSource implements AvailabilitySource {

	public function __construct( private NightsRepository $nights ) {}

	public function occupied( int $unitId, string $from, string $to ): array {
		return $this->nights->occupied( $unitId, $from, $to );
	}

	public function busyUnitIds( string $from, string $to ): array {
		return $this->nights->busyUnitIds( $from, $to );
	}
}
