<?php
/**
 * Domain error 'not_found': a referenced record (booking, guest, block…) does not exist.
 */

namespace Marclie\Booking\Domain\Exception;

defined( 'ABSPATH' ) || exit;

final class NotFoundException extends BookingException {

	public const CODE = 'not_found';

	public function errorCode(): string {
		return self::CODE;
	}
}
