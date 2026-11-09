<?php
/**
 * Domain error 'duplicate_payment': an identical outside payment was just recorded (double submit).
 */

namespace Marclie\Booking\Domain\Exception;

defined( 'ABSPATH' ) || exit;

final class DuplicatePaymentException extends BookingException {

	public const CODE = 'duplicate_payment';

	public function errorCode(): string {
		return self::CODE;
	}
}
