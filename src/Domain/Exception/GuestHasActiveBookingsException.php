<?php
/**
 * Domain error 'guest_has_active_bookings': anonymizing would wipe the contact
 * data of upcoming bookings.
 */

namespace Marclie\Booking\Domain\Exception;

defined( 'ABSPATH' ) || exit;

final class GuestHasActiveBookingsException extends BookingException {

	public const CODE = 'guest_has_active_bookings';

	public function errorCode(): string {
		return self::CODE;
	}
}
