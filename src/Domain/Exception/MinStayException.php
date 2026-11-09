<?php
/**
 * Domain error 'min_stay'.
 */

namespace Marclie\Booking\Domain\Exception;

defined( 'ABSPATH' ) || exit;

final class MinStayException extends BookingException {

	public const CODE = 'min_stay';

	public function errorCode(): string {
		return self::CODE;
	}
}
