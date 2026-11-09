<?php
/**
 * Registers the public REST API (`marclie-booking/v1/availability`, `/search`,
 * `/quote`, `/coupons/validate`, `/bookings…`, `/account/…`) used by the
 * frontend widgets, and the iCal export (`/ical/{unit_code}.ics`) read by the
 * OTAs. The Stripe webhook route is registered by Payment\WebhookController.
 */

namespace Marclie\Booking\Rest\Public;

defined( 'ABSPATH' ) || exit;

final class Routes {

	/** @var list<class-string<BaseController>> */
	private const CONTROLLERS = array(
		AvailabilityController::class,
		SearchController::class,
		QuoteController::class,
		CouponController::class,
		BookingController::class,
		AccountController::class,
		IcalController::class,
	);

	/** rest_api_init. */
	public static function register(): void {
		foreach ( self::CONTROLLERS as $class ) {
			( new $class() )->register();
		}
	}
}
