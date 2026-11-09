<?php
/**
 * /admin/stripe — read-only Stripe status (keys live in wp-config.php; only
 * their last four characters are exposed) and the connection check.
 */

namespace Marclie\Booking\Rest\Admin;

use Marclie\Booking\Install\Activator;
use Marclie\Booking\Payment\StripeConfig;
use Marclie\Booking\Payment\StripeStatus;

defined( 'ABSPATH' ) || exit;

final class StripeController extends BaseController {

	public function register(): void {
		$this->route( '/stripe/status', $this->endpoint( \WP_REST_Server::READABLE, array( $this, 'status' ), Activator::CAP_SETTINGS ) );
		$this->route( '/stripe/verify', $this->endpoint( \WP_REST_Server::CREATABLE, array( $this, 'verify' ), Activator::CAP_SETTINGS ) );
	}

	/** @return array<string, mixed> */
	public function status(): array {
		$status = StripeStatus::get();

		return array(
			'mode'          => StripeConfig::mode(),
			'keys'          => StripeConfig::keys(),
			'mismatch'      => StripeConfig::mismatch(),
			'last_verify'   => $status['last_verify'],
			'webhook'       => array(
				'url'              => StripeConfig::webhookUrl(),
				'events'           => StripeConfig::WEBHOOK_EVENTS,
				'last_received_at' => $status['webhook']['last_received_at'],
				'last_error'       => $status['webhook']['last_error'],
			),
			'dashboard_url' => StripeConfig::dashboardUrl(),
		);
	}

	/** @return array<string, mixed> */
	public function verify(): array {
		return StripeStatus::verify();
	}
}
