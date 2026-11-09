<?php
/**
 * Domain error 'beyond_horizon': the arrival is after BookingHorizon::lastArrival().
 */

namespace Marclie\Booking\Domain\Exception;

defined( 'ABSPATH' ) || exit;

final class BeyondHorizonException extends BookingException {

	public const CODE = 'beyond_horizon';

	public function errorCode(): string {
		return self::CODE;
	}
}
