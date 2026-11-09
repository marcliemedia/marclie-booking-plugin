<?php
/**
 * Email verification of a registration. Nothing is created before the
 * address is confirmed: the registration waits in a transient keyed by the
 * SHA-256 of a random 64-hex token (48 h, single use) with the password
 * already hashed.
 *
 * Opening `/moj-racun/?verify=TOKEN` only shows a form (isPending(); link
 * scanners consume nothing). The account is created by verify() with the
 * token AND the password chosen at registration — the inbox alone does not
 * activate someone else's registration, and nobody is signed in by merely
 * opening a link. Five wrong passwords void the token. The claim is atomic
 * per address (option lock), so concurrent requests create one user.
 * On success: WordPress user (role mbk_guest, address verified), guest and
 * earlier bookings linked, membership started (welcome coupon + email), signed in.
 */

namespace Marclie\Booking\Account;

use Marclie\Booking\Repository\Db;
use Marclie\Booking\Repository\EventRepository;

defined( 'ABSPATH' ) || exit;

final class Verification {

	/** Pending registrations live 48 hours. */
	public const TTL = 172800;

	/** Wrong passwords before the token is void. */
	public const MAX_ATTEMPTS = 5;

	private const TRANSIENT = 'mbk_reg_';

	private const LOCK = 'mbk_reg_lock_';

	/** A lock older than this was left by a crashed request. */
	private const LOCK_STALE = 300;

	public function __construct( private AccountService $accounts, private EventRepository $events ) {}

	/* Pure token helpers ------------------------------------------------- */

	/** New plain token (64 hex characters). */
	public static function newToken(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	public static function hashToken( string $token ): string {
		return hash( 'sha256', $token );
	}

	/** Shape check before any lookup (links may arrive mangled by mail clients). */
	public static function isWellFormed( string $token ): bool {
		return 1 === preg_match( '/^[a-f0-9]{64}$/D', $token );
	}

	/* Pending registrations ---------------------------------------------- */

	/**
	 * Stores a validated registration; returns the plain token for the email link.
	 *
	 * @param array{email: string, first_name: string, last_name: string, password: string, newsletter: bool, lang: string} $data
	 */
	public function createPending( array $data ): string {
		$token = self::newToken();
		set_transient(
			self::TRANSIENT . self::hashToken( $token ),
			array(
				'email'      => $data['email'],
				'first_name' => $data['first_name'],
				'last_name'  => $data['last_name'],
				'pass_hash'  => wp_hash_password( $data['password'] ),
				'newsletter' => $data['newsletter'],
				'lang'       => $data['lang'],
				'terms_at'   => Db::nowUtc(),
				'expires'    => time() + self::TTL,
				'attempts'   => 0,
			),
			self::TTL
		);

		return $token;
	}

	/** Whether `$token` belongs to a registration still waiting (read-only: safe on GET). */
	public function isPending( string $token ): bool {
		return null !== $this->pending( $token );
	}

	/** Link of the verification email. */
	public static function link( string $token, string $lang ): string {
		return add_query_arg( 'verify', $token, AccountService::pageUrl( 'moj_racun', $lang ) );
	}

	/**
	 * Creates, links and signs in the account of a pending registration when
	 * `$password` is the one chosen at registration.
	 * Errors: `validation` 422 (`fields.password`, `attempts_left`; the 5th wrong
	 * password voids the token), `invalid_token` 400 (unknown, used, expired or
	 * voided link), `already_verified` 409 (an account exists for the email).
	 *
	 * @return \WP_User|\WP_Error
	 */
	public function verify( string $token, string $password ) {
		$pending = $this->pending( $token );
		if ( null === $pending ) {
			return self::error( 'invalid_token' );
		}
		$key = self::TRANSIENT . self::hashToken( strtolower( trim( $token ) ) );

		if ( '' === $password || ! wp_check_password( $password, (string) $pending['pass_hash'] ) ) {
			$pending['attempts'] = (int) ( $pending['attempts'] ?? 0 ) + 1;
			$left                = max( 0, self::MAX_ATTEMPTS - $pending['attempts'] );
			if ( 0 === $left ) {
				delete_transient( $key );
			} else {
				set_transient( $key, $pending, max( 1, (int) ( $pending['expires'] ?? time() + self::TTL ) - time() ) );
			}
			$message = __( 'This is not the password you chose when signing up.', 'marclie-booking' );

			return new \WP_Error(
				'validation',
				$message,
				array(
					'status'        => 422,
					'fields'        => array( 'password' => $message ),
					'attempts_left' => $left,
				)
			);
		}

		$email = (string) $pending['email'];
		$lock  = self::LOCK . hash( 'sha256', $email );
		$claim = Db::claimLock( $lock, self::LOCK_STALE );
		if ( null === $claim ) {
			return self::error( 'invalid_token' );
		}
		try {
			// Claimed: the token is used whatever happens next.
			delete_transient( $key );
			if ( false !== email_exists( $email ) ) {
				return self::error( 'already_verified' );
			}

			return $this->create( $pending );
		} finally {
			Db::releaseLock( $lock, $claim );
		}
	}

	/**
	 * @param array<string, mixed> $pending
	 * @return \WP_User|\WP_Error
	 */
	private function create( array $pending ) {
		$email  = (string) $pending['email'];
		$userId = wp_insert_user(
			array(
				'user_login'   => self::login( $email ),
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'first_name'   => (string) $pending['first_name'],
				'last_name'    => (string) $pending['last_name'],
				'display_name' => trim( $pending['first_name'] . ' ' . $pending['last_name'] ),
				'role'         => Roles::GUEST,
			)
		);
		if ( is_wp_error( $userId ) ) {
			return $userId;
		}

		// The password chosen at registration, hashed when it was submitted.
		global $wpdb;
		$wpdb->update( $wpdb->users, array( 'user_pass' => (string) $pending['pass_hash'] ), array( 'ID' => $userId ) );
		clean_user_cache( $userId );

		$lang = in_array( $pending['lang'] ?? null, array( 'hr', 'en' ), true ) ? (string) $pending['lang'] : 'hr';
		$now  = Db::nowUtc();
		$user = new \WP_User( $userId );
		AccountService::markVerified( $user );
		update_user_meta( $userId, AccountService::META_LANG, $lang );
		update_user_meta( $userId, 'mbk_terms_accepted_at', (string) ( $pending['terms_at'] ?? $now ) );
		if ( ! empty( $pending['newsletter'] ) ) {
			update_user_meta( $userId, AccountService::META_CONSENT, $now );
		}

		$guestId = $this->accounts->link( $user, $lang, ! empty( $pending['newsletter'] ) );
		$this->events->log( 'account_created', AccountService::actor( $userId ), null, $guestId, array( 'lang' => $lang ) );
		$this->accounts->startMembership( $user, $guestId );
		Auth::signIn( $user, true );

		return $user;
	}

	/**
	 * Live pending registration of a token, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	private function pending( string $token ): ?array {
		$token = strtolower( trim( $token ) );
		if ( ! self::isWellFormed( $token ) ) {
			return null;
		}
		$pending = get_transient( self::TRANSIENT . self::hashToken( $token ) );

		return is_array( $pending ) && ! empty( $pending['email'] ) && ! empty( $pending['pass_hash'] ) ? $pending : null;
	}

	/** user_login for an email: the address itself, else a unique `guest-…` login. */
	private static function login( string $email ): string {
		$login = sanitize_user( $email, true );
		if ( '' !== $login && strlen( $login ) <= 60 && ! username_exists( $login ) ) {
			return $login;
		}
		do {
			$login = 'guest-' . strtolower( wp_generate_password( 10, false ) );
		} while ( username_exists( $login ) );

		return $login;
	}

	private static function error( string $code ): \WP_Error {
		return match ( $code ) {
			'already_verified' => new \WP_Error( $code, __( 'This email address is already confirmed. Please sign in.', 'marclie-booking' ), array( 'status' => 409 ) ),
			default            => new \WP_Error( $code, __( 'This confirmation link is invalid or has expired. Please register again.', 'marclie-booking' ), array( 'status' => 400 ) ),
		};
	}
}
