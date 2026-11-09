<?php
/**
 * Time source (site timezone).
 */

namespace Marclie\Booking\Domain;

defined( 'ABSPATH' ) || exit;

interface Clock {

	/** Today at 00:00 in the site timezone. */
	public function today(): \DateTimeImmutable;

	public function now(): \DateTimeImmutable;
}
