<?php
/**
 * Domain error 'unavailable'.
 */

namespace Marclie\Booking\Domain\Exception;

defined( 'ABSPATH' ) || exit;

final class UnavailableException extends BookingException {

	public const CODE = 'unavailable';

	public function errorCode(): string {
		return self::CODE;
	}
}
