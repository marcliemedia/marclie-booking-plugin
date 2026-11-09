<?php
/**
 * Domain error 'coupon_invalid'.
 */

namespace Marclie\Booking\Domain\Exception;

defined( 'ABSPATH' ) || exit;

final class CouponException extends BookingException {

	public const CODE = 'coupon_invalid';

	public function errorCode(): string {
		return self::CODE;
	}
}
