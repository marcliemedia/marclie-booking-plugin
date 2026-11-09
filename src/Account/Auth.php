<?php
/**
 * Sign-in / sign-out of guest accounts through the plugin forms (wp-login.php
 * is untouched). Only users with the mbk_guest role sign in here; every
 * failure is the same generic error and costs one password hash check, also
 * for unknown or staff addresses (no enumeration by answer or timing). Rate
 * limits live in the REST controller.
 *
 * After a sign-in the new logged-in cookie is also placed in `$_COOKIE`, so a
 * `wp_rest` nonce created in the same request belongs to the new session.
 */

namespace Marclie\Booking\Account;

defined( 'ABSPATH' ) || exit;

final class Auth {

	/**
	 * @return \WP_User|\WP_Error `invalid_login` (401).
	 */
	public function login( string $email, string $password, bool $remember ) {
		$email = strtolower( trim( $email ) );
		if ( '' === $email || '' === $password || ! is_email( $email ) ) {
			return self::invalid();
		}

		$known = get_user_by( 'email', $email );
		if ( ! $known instanceof \WP_User || ! Roles::hasGuestRole( $known ) ) {
			wp_check_password( $password, self::dummyHash() );

			return self::invalid();
		}

		// Full `authenticate` filter chain (email + password, lockout/2FA plugins).
		$user = wp_authenticate( $email, $password );
		if ( ! $user instanceof \WP_User || ! Roles::hasGuestRole( $user ) ) {
			return self::invalid();
		}

		self::signIn( $user, $remember );
		/** Core action of a completed sign-in (other plugins rely on it). */
		do_action( 'wp_login', $user->user_login, $user );

		return $user;
	}

	public function logout(): void {
		wp_logout();
		wp_set_current_user( 0 );
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
	}

	/** A hash of a random secret in the site's current format, checked when no account matches. */
	private static function dummyHash(): string {
		$hash = (string) get_option( 'mbk_dummy_password_hash', '' );
		if ( '' === $hash ) {
			$hash = wp_hash_password( wp_generate_password( 32, true, true ) );
			add_option( 'mbk_dummy_password_hash', $hash, '', false );
		}

		return $hash;
	}

	/** Auth cookies for `$user` and the current user switched to them. */
	public static function signIn( \WP_User $user, bool $remember ): void {
		$capture = static function ( $cookie ): void {
			$_COOKIE[ LOGGED_IN_COOKIE ] = (string) $cookie;
		};
		add_action( 'set_logged_in_cookie', $capture );
		try {
			wp_set_auth_cookie( $user->ID, $remember, is_ssl() );
		} finally {
			remove_action( 'set_logged_in_cookie', $capture );
		}
		// wp_set_current_user() keeps the cached object when the ID is unchanged (stale after a password change).
		if ( get_current_user_id() === $user->ID ) {
			wp_set_current_user( 0 );
		}
		wp_set_current_user( $user->ID );
	}

	/** `wp_rest` nonce of the current session (send it as X-WP-Nonce). */
	public static function nonce(): string {
		return wp_create_nonce( 'wp_rest' );
	}

	private static function invalid(): \WP_Error {
		return new \WP_Error( 'invalid_login', __( 'The email address or password is not correct.', 'marclie-booking' ), array( 'status' => 401 ) );
	}
}
