<?php
/**
 * Forgotten password of a guest account, on WordPress reset keys
 * (get_password_reset_key / check_password_reset_key, valid 24 h):
 * `forgot()` always answers the same and only queues an AccountMail job (the
 * job emails account_password_reset to guest accounts only); `reset()` sets
 * the new password from the link (/moj-racun/zaboravljena-lozinka/?login=&key=).
 */

namespace Marclie\Booking\Account;

use Marclie\Booking\Domain\Exception\ValidationException;

defined( 'ABSPATH' ) || exit;

final class PasswordReset {

	public function __construct( private AccountService $accounts ) {}

	/** Queues the reset email job for any valid address (AccountMail decides; uniform timing). */
	public function forgot( string $email ): void {
		$email = strtolower( trim( $email ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return;
		}
		AccountMail::queue(
			array(
				'kind'  => 'reset',
				'email' => $email,
			)
		);
	}

	public static function link( \WP_User $user, string $key, string $lang ): string {
		return add_query_arg(
			array(
				'login' => rawurlencode( $user->user_login ),
				'key'   => $key,
			),
			AccountService::pageUrl( 'zaboravljena_lozinka', $lang )
		);
	}

	/**
	 * @return \WP_User|\WP_Error `invalid_key` (400) for an unknown, used or expired link.
	 * @throws ValidationException Password too short/long.
	 */
	public function reset( string $login, string $key, string $password ) {
		$user = check_password_reset_key( $key, $login );
		if ( ! $user instanceof \WP_User || ! Roles::hasGuestRole( $user ) ) {
			return new \WP_Error( 'invalid_key', __( 'This password reset link is invalid or has expired. Please ask for a new one.', 'marclie-booking' ), array( 'status' => 400 ) );
		}
		$weak = Registration::passwordError( $password );
		if ( null !== $weak ) {
			throw ValidationException::fields( array( 'password' => $weak ) );
		}

		reset_password( $user, $password );
		// The link reached the inbox: the address is proven (accounts created by staff get linked now).
		if ( ! AccountService::isVerified( $user ) ) {
			AccountService::markVerified( $user );
			$this->accounts->startMembership( $user, $this->accounts->link( $user, AccountService::userLang( $user->ID ) ) );
		}

		return $user;
	}
}
