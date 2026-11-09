<?php
/**
 * Domain error 'invalid_stay'.
 */

namespace Marclie\Booking\Domain\Exception;

defined( 'ABSPATH' ) || exit;

final class InvalidStayException extends BookingException {

	public const CODE = 'invalid_stay';

	public function errorCode(): string {
		return self::CODE;
	}
}
