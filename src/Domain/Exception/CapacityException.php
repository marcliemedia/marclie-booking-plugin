<?php
/**
 * Domain error 'capacity'.
 */

namespace Marclie\Booking\Domain\Exception;

defined( 'ABSPATH' ) || exit;

final class CapacityException extends BookingException {

	public const CODE = 'capacity';

	public function errorCode(): string {
		return self::CODE;
	}
}
