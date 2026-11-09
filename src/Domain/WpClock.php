<?php
/**
 * Clock backed by the WordPress site timezone.
 */

namespace Marclie\Booking\Domain;

defined( 'ABSPATH' ) || exit;

final class WpClock implements Clock {

	public function now(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', wp_timezone() );
	}

	public function today(): \DateTimeImmutable {
		return $this->now()->setTime( 0, 0 );
	}
}
