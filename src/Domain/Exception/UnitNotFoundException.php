<?php
/**
 * Domain error 'unit_not_found'.
 */

namespace Marclie\Booking\Domain\Exception;

defined( 'ABSPATH' ) || exit;

final class UnitNotFoundException extends BookingException {

	public const CODE = 'unit_not_found';

	public function errorCode(): string {
		return self::CODE;
	}
}
