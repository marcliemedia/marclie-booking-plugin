<?php
/**
 * GET /admin/bootstrap — everything the admin app needs on first load.
 */

namespace Marclie\Booking\Rest\Admin;

use Marclie\Booking\Container;
use Marclie\Booking\Domain\BookingStatus;
use Marclie\Booking\Install\Activator;
use Marclie\Booking\Payment\StripeConfig;
use Marclie\Booking\Settings;

defined( 'ABSPATH' ) || exit;

final class BootstrapController extends BaseController {

	public function register(): void {
		$this->route( '/bootstrap', $this->endpoint( \WP_REST_Server::READABLE, array( $this, 'show' ), Activator::CAP_BOOKINGS ) );
	}

	/** @return array<string, mixed> */
	public function show(): array {
		$user = wp_get_current_user();

		return array(
			'user'     => array(
				'id'     => (int) $user->ID,
				'name'   => (string) $user->display_name,
				'email'  => (string) $user->user_email,
				'locale' => get_user_locale( $user ),
			),
			'caps'     => array(
				'manage_bookings' => current_user_can( Activator::CAP_BOOKINGS ),
				'manage_rates'    => current_user_can( Activator::CAP_RATES ),
				'manage_settings' => current_user_can( Activator::CAP_SETTINGS ),
			),
			'version'  => MBK_VERSION,
			'brand'    => \Marclie\Booking\Email\BusinessInfo::get()['name'],
			'today'    => Container::clock()->today()->format( 'Y-m-d' ),
			'currency' => 'EUR',
			'units'    => array_map( array( Presenter::class, 'unit' ), Container::units()->all() ),
			'statuses' => array_map( static fn( BookingStatus $s ): string => $s->value, BookingStatus::cases() ),
			'policy'   => Settings::all()['policy'],
			'stripe'   => array(
				'mode'       => StripeConfig::mode(),
				'configured' => StripeConfig::configured(),
			),
		);
	}
}
