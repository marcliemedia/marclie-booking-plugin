<?php
/**
 * Domain error 'no_rate': a night falls in a month without a price for the unit (closed).
 */

namespace Marclie\Booking\Domain\Exception;

defined( 'ABSPATH' ) || exit;

final class NoRateException extends BookingException {

	public const CODE = 'no_rate';

	public function errorCode(): string {
		return self::CODE;
	}
}
