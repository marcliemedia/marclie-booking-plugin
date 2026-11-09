<?php
/**
 * Option `mbk_stripe_status` (autoload off): result of the last connection
 * check and webhook delivery bookkeeping (written by WebhookController).
 */

namespace Marclie\Booking\Payment;

defined( 'ABSPATH' ) || exit;

final class StripeStatus {

	public const OPTION = 'mbk_stripe_status';

	private const TIMEOUT         = 10;
	private const CONNECT_TIMEOUT = 5;

	private const ERROR_THROTTLE = 'mbk_stripe_webhook_error';

	/**
	 * @return array{last_verify: array<string, mixed>|null, webhook: array{last_received_at: ?string, last_error: ?string}}
	 */
	public static function get(): array {
		$stored  = get_option( self::OPTION, array() );
		$stored  = is_array( $stored ) ? $stored : array();
		$webhook = isset( $stored['webhook'] ) && is_array( $stored['webhook'] ) ? $stored['webhook'] : array();

		// A check made with another secret key (keys swapped in wp-config.php) no longer applies.
		$current = isset( $stored['last_verify_key'] ) && $stored['last_verify_key'] === self::keyFingerprint();

		return array(
			'last_verify' => $current && isset( $stored['last_verify'] ) && is_array( $stored['last_verify'] ) ? $stored['last_verify'] : null,
			'webhook'     => array(
				'last_received_at' => isset( $webhook['last_received_at'] ) ? (string) $webhook['last_received_at'] : null,
				'last_error'       => isset( $webhook['last_error'] ) ? (string) $webhook['last_error'] : null,
			),
		);
	}

	/**
	 * Calls Account::retrieve with the secret key and stores the outcome.
	 *
	 * @return array{ok: bool, at: string, account?: array{id: string, name: ?string, country: ?string, charges_enabled: bool, payouts_enabled: bool}, error?: string}
	 */
	public static function verify(): array {
		$at     = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( DATE_ATOM );
		$secret = StripeConfig::secretKey();

		if ( '' === $secret ) {
			$result = array(
				'ok'    => false,
				'at'    => $at,
				'error' => __( 'The secret key is not configured (MBK_STRIPE_SECRET_KEY).', 'marclie-booking' ),
			);
		} else {
			$result = self::retrieveAccount( $secret, $at );
		}

		self::save(
			array(
				'last_verify'     => $result,
				'last_verify_key' => self::keyFingerprint(),
			)
		);

		return $result;
	}

	/**
	 * Webhook bookkeeping: time of the last valid event / last signature error.
	 * Errors (unauthenticated requests) are written at most once a minute.
	 */
	public static function recordWebhook( ?string $error = null ): void {
		if ( null !== $error ) {
			if ( false !== get_transient( self::ERROR_THROTTLE ) ) {
				return;
			}
			set_transient( self::ERROR_THROTTLE, 1, MINUTE_IN_SECONDS );
		}
		$now     = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( DATE_ATOM );
		$webhook = self::get()['webhook'];
		if ( null === $error ) {
			$webhook['last_received_at'] = $now;
		} else {
			$webhook['last_error'] = $now . ' ' . $error;
		}

		self::save( array( 'webhook' => $webhook ) );
	}

	/** @return array<string, mixed> */
	private static function retrieveAccount( string $secret, string $at ): array {
		try {
			$http = \Stripe\HttpClient\CurlClient::instance();
			$http->setTimeout( self::TIMEOUT );
			$http->setConnectTimeout( self::CONNECT_TIMEOUT );
			\Stripe\ApiRequestor::setHttpClient( $http );

			$account = ( new \Stripe\StripeClient( $secret ) )->accounts->retrieve();
			$name    = $account->settings->dashboard->display_name ?? null;
			$name  ??= $account->business_profile->name ?? null;

			return array(
				'ok'      => true,
				'at'      => $at,
				'account' => array(
					'id'              => (string) $account->id,
					'name'            => null !== $name ? (string) $name : null,
					'country'         => isset( $account->country ) ? (string) $account->country : null,
					'charges_enabled' => (bool) ( $account->charges_enabled ?? false ),
					'payouts_enabled' => (bool) ( $account->payouts_enabled ?? false ),
				),
			);
		} catch ( \Stripe\Exception\AuthenticationException $e ) {
			$error = __( 'Stripe rejected the secret key.', 'marclie-booking' );
		} catch ( \Stripe\Exception\ApiConnectionException $e ) {
			$error = __( 'Could not reach Stripe (network error or timeout).', 'marclie-booking' );
		} catch ( \Throwable $e ) {
			$error = self::redact( $e->getMessage(), $secret );
		}

		return array(
			'ok'    => false,
			'at'    => $at,
			'error' => $error,
		);
	}

	/** Mode + last four characters of the secret key the last check used (never the key). */
	private static function keyFingerprint(): string {
		$secret = StripeConfig::secretKey();

		return '' === $secret ? '' : ( StripeConfig::modeOf( $secret ) ?? '?' ) . ':' . substr( $secret, -4 );
	}

	/** Never let a key end up in the stored error. */
	private static function redact( string $message, string $secret ): string {
		$message = str_replace( $secret, '[redacted]', $message );

		return (string) preg_replace( '/\b(sk|rk|pk|whsec)_[A-Za-z0-9_]+/', '$1_…', $message );
	}

	/** @param array<string, mixed> $patch */
	private static function save( array $patch ): void {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		update_option( self::OPTION, array_merge( $stored, $patch ), false );
	}
}
