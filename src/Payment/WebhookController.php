<?php
/**
 * POST /marclie-booking/v1/stripe/webhook — Stripe events. The raw body is
 * verified against the `Stripe-Signature` header with MBK_STRIPE_WEBHOOK_SECRET
 * (filter `mbk_stripe_webhook_secret`, 5-minute tolerance). The time of the
 * last valid event / last signature error goes to `mbk_stripe_status`.
 *
 * 200 processed/duplicate/ignored · 400 bad signature or payload · 503 no
 * secret configured · 500 processing error (Stripe retries).
 */

namespace Marclie\Booking\Payment;

use Marclie\Booking\Container;
use Marclie\Booking\Plugin;

defined( 'ABSPATH' ) || exit;

final class WebhookController {

	private const TOLERANCE = 300;

	/** rest_api_init. */
	public static function register(): void {
		register_rest_route(
			Plugin::REST_NAMESPACE,
			StripeConfig::WEBHOOK_ROUTE,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'receive' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function secret(): string {
		/**
		 * Webhook signing secret (default: MBK_STRIPE_WEBHOOK_SECRET). Tests sign
		 * fake events with their own secret through this filter.
		 *
		 * @param string $secret
		 */
		return (string) apply_filters( 'mbk_stripe_webhook_secret', StripeConfig::webhookSecret() );
	}

	public static function receive( \WP_REST_Request $request ): \WP_REST_Response {
		$secret = self::secret();
		if ( '' === $secret ) {
			return self::respond( 503, array( 'error' => 'webhook_not_configured' ) );
		}

		$payload = $request->get_body();
		try {
			\Stripe\WebhookSignature::verifyHeader( $payload, (string) $request->get_header( 'stripe_signature' ), $secret, self::TOLERANCE );
		} catch ( \Stripe\Exception\SignatureVerificationException $e ) {
			StripeStatus::recordWebhook( 'Invalid signature' );

			return self::respond( 400, array( 'error' => 'invalid_signature' ) );
		}

		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) ) {
			return self::respond( 400, array( 'error' => 'invalid_payload' ) );
		}
		StripeStatus::recordWebhook();

		try {
			$result = Container::webhookHandler()->handle( $event );
		} catch ( \Throwable $e ) {
			error_log( '[marclie-booking] Stripe webhook ' . ( $event['id'] ?? '?' ) . ': ' . get_class( $e ) . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			return self::respond( 500, array( 'error' => 'processing_failed' ) );
		}

		return self::respond( 200, array( 'received' => true, 'result' => $result ) );
	}

	/** @param array<string, mixed> $data */
	private static function respond( int $status, array $data ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
