<?php
/**
 * Frozen clock (tests, CLI simulations).
 */

namespace Marclie\Booking\Domain;

defined( 'ABSPATH' ) || exit;

final class FixedClock implements Clock {

	private \DateTimeImmutable $now;

	public function __construct( \DateTimeImmutable|string $now, ?\DateTimeZone $tz = null ) {
		$tz        = $tz ?? new \DateTimeZone( 'Europe/Zagreb' );
		$this->now = is_string( $now ) ? new \DateTimeImmutable( $now, $tz ) : $now;
	}

	public function now(): \DateTimeImmutable {
		return $this->now;
	}

	public function today(): \DateTimeImmutable {
		return $this->now->setTime( 0, 0 );
	}
}
