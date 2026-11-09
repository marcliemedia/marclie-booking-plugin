<?php
/**
 * Account emails of anonymous requests (registration, forgotten password)
 * are sent from a single cron event, not in the request: every answer then
 * takes the same time whether the address has an account or not (no timing
 * enumeration). The request always queues one job for a valid address; the
 * job decides what — if anything — is sent:
 *
 * - `verify`  (new address): account_verify with the link (the plain token
 *   sits in the cron entry until the job runs, seconds later);
 * - `exists`  (address with an account): account_exists, once per 15 minutes;
 * - `reset`   (any address): account_password_reset with a fresh WordPress
 *   reset key, only for guest accounts.
 *
 * The event runs on the next WP-Cron spawn (triggered right away outside WP-CLI).
 */

namespace Marclie\Booking\Account;

use Marclie\Booking\Container;
use Marclie\Booking\Email\Context;

defined( 'ABSPATH' ) || exit;

final class AccountMail {

	public const HOOK = 'mbk_account_mail';

	private const EXISTS_COOLDOWN = 900;

	/**
	 * @param array<string, mixed> $job `kind` + data (see class doc).
	 */
	public static function queue( array $job ): void {
		$job['queued'] = microtime( true ); // Distinct args: WP drops identical events within 10 minutes.
		wp_schedule_single_event( time(), self::HOOK, array( $job ) );
		if ( ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			spawn_cron();
		}
	}

	/**
	 * Cron callback.
	 *
	 * @param mixed $job
	 */
	public static function handle( $job ): void {
		if ( ! is_array( $job ) ) {
			return;
		}
		try {
			match ( (string) ( $job['kind'] ?? '' ) ) {
				'verify' => self::verify( $job ),
				'exists' => self::exists( (string) ( $job['email'] ?? '' ), (string) ( $job['lang'] ?? 'hr' ) ),
				'reset'  => self::reset( (string) ( $job['email'] ?? '' ) ),
				default  => null,
			};
		} catch ( \Throwable $e ) {
			error_log( '[marclie-booking] account mail ' . ( $job['kind'] ?? '?' ) . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/** @param array<string, mixed> $job */
	private static function verify( array $job ): void {
		$email = (string) ( $job['email'] ?? '' );
		$lang  = (string) ( $job['lang'] ?? 'hr' );
		Container::mailer()->sendAccount(
			'account_verify',
			$email,
			$lang,
			Context::accountFacts(
				array(
					'first_name' => (string) ( $job['first_name'] ?? '' ),
					'last_name'  => (string) ( $job['last_name'] ?? '' ),
					'email'      => $email,
				),
				array(),
				array( 'verify_link' => Verification::link( (string) ( $job['token'] ?? '' ), $lang ) )
			),
			null
		);
	}

	private static function exists( string $email, string $lang ): void {
		$user = get_user_by( 'email', $email );
		if ( ! $user instanceof \WP_User ) {
			return;
		}
		$key = 'mbk_acct_exists_' . $user->ID;
		if ( false !== get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, self::EXISTS_COOLDOWN );

		$lang = Roles::hasGuestRole( $user ) ? AccountService::userLang( $user->ID ) : $lang;
		Container::mailer()->sendAccount(
			'account_exists',
			(string) $user->user_email,
			$lang,
			Context::accountFacts(
				self::person( $user ),
				array(),
				array(
					'login_link' => AccountService::pageUrl( 'prijava', $lang ),
					'reset_link' => AccountService::pageUrl( 'zaboravljena_lozinka', $lang ),
				)
			),
			null
		);
	}

	private static function reset( string $email ): void {
		$user = '' !== $email ? get_user_by( 'email', $email ) : false;
		if ( ! $user instanceof \WP_User || ! Roles::hasGuestRole( $user ) ) {
			return;
		}
		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			return;
		}

		$lang = AccountService::userLang( $user->ID );
		Container::mailer()->sendAccount(
			'account_password_reset',
			(string) $user->user_email,
			$lang,
			Context::accountFacts( self::person( $user ), array(), array( 'reset_link' => PasswordReset::link( $user, $key, $lang ) ) ),
			null
		);
	}

	/** @return array{first_name: string, last_name: string, email: string} */
	private static function person( \WP_User $user ): array {
		return array(
			'first_name' => (string) $user->first_name,
			'last_name'  => (string) $user->last_name,
			'email'      => (string) $user->user_email,
		);
	}
}
