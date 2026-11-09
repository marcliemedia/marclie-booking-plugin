<?php
/**
 * Domain error 'past_date'.
 */

namespace Marclie\Booking\Domain\Exception;

defined( 'ABSPATH' ) || exit;

final class PastDateException extends BookingException {

	public const CODE = 'past_date';

	public function errorCode(): string {
		return self::CODE;
	}
}
