<?php
/**
 * Cloudflare Turnstile bot check of the online checkout (POST /bookings):
 * keeps scripts from holding dates and from testing stolen cards against
 * real PaymentIntents. On when wp-config.php defines both keys:
 *
 *     define( 'MBK_TURNSTILE_SITE_KEY', '0x…' );
 *     define( 'MBK_TURNSTILE_SECRET_KEY', '0x…' );
 *
 * The widget runs with action `booking`; a token is single-use and valid 300 s.
 */

namespace Marclie\Booking\Service;

defined( 'ABSPATH' ) || exit;

final class Turnstile {

	public const SITE_KEY = 'MBK_TURNSTILE_SITE_KEY';
	public const SECRET   = 'MBK_TURNSTILE_SECRET_KEY';

	public const ACTION = 'booking';

	private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

	private const TIMEOUT = 5;

	/** Cloudflare's maximum token length. */
	private const TOKEN_MAX = 2048;

	/** Error codes of a wrong/missing secret: our configuration, not the visitor. */
	private const CONFIG_ERRORS = array( 'missing-input-secret', 'invalid-input-secret' );

	public static function enabled(): bool {
		return '' !== self::constant( self::SITE_KEY ) && '' !== self::constant( self::SECRET );
	}

	/** Site key for the widget, null when the check is off. */
	public static function siteKey(): ?string {
		return self::enabled() ? self::constant( self::SITE_KEY ) : null;
	}

	/**
	 * Asks Cloudflare whether `$token` is a valid, unused `booking` token.
	 * Off, Cloudflare unreachable or our secret refused: passes (logged) — an
	 * outage or a key mistake must not stop real bookings.
	 */
	public static function verify( string $token, string $ip ): bool {
		if ( ! self::enabled() ) {
			return true;
		}
		$token = trim( $token );
		if ( '' === $token || strlen( $token ) > self::TOKEN_MAX ) {
			return false;
		}

		$body = array(
			'secret'   => self::constant( self::SECRET ),
			'response' => $token,
		);
		if ( '' !== $ip ) {
			$body['remoteip'] = $ip;
		}
		$response = wp_remote_post(
			self::VERIFY_URL,
			array(
				'timeout' => self::TIMEOUT,
				'body'    => $body,
			)
		);
		$data = is_wp_error( $response ) ? null : json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['success'] ) ) {
			error_log( '[marclie-booking] Turnstile siteverify unreachable: ' . ( is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $response ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			return true;
		}

		return self::accepts( $data );
	}

	/**
	 * Decision on a siteverify answer (separate for tests).
	 *
	 * @param array<string, mixed> $data
	 */
	public static function accepts( array $data ): bool {
		if ( true === $data['success'] ) {
			// Real tokens name the widget's action; Cloudflare's testing keys answer without one.
			$action = (string) ( $data['action'] ?? '' );

			return '' === $action || self::ACTION === $action;
		}
		$codes = array_map( 'strval', (array) ( $data['error-codes'] ?? array() ) );
		if ( array_intersect( $codes, self::CONFIG_ERRORS ) ) {
			error_log( '[marclie-booking] Turnstile secret refused (' . implode( ', ', $codes ) . '): check MBK_TURNSTILE_SECRET_KEY.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			return true;
		}

		return false;
	}

	private static function constant( string $name ): string {
		return defined( $name ) ? trim( (string) constant( $name ) ) : '';
	}
}
