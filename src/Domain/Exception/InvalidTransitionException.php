<?php
/**
 * Domain error 'invalid_transition'.
 */

namespace Marclie\Booking\Domain\Exception;

defined( 'ABSPATH' ) || exit;

final class InvalidTransitionException extends BookingException {

	public const CODE = 'invalid_transition';

	public function errorCode(): string {
		return self::CODE;
	}
}
