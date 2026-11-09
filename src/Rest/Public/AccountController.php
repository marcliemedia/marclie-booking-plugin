<?php
/**
 * Guest account endpoints `marclie-booking/v1/account/*` (never cached).
 *
 * Anonymous (honeypot `website`, per-client rate limits, no account
 * enumeration):
 * - POST register {first_name, last_name, email, password, terms:true, newsletter?, lang?} → 201 {status:'pending'}
 * - POST verify {token, password} → {user, nonce, redirect} (signs in) · 422 validation
 *   (fields.password, attempts_left; 5 wrong passwords void the token) · 400 invalid_token ·
 *   409 already_verified · 429 (10 tries / 15 min per client + token)
 * - POST login {email, password, remember?, redirect?} → {user, nonce, redirect} · 401 invalid_login
 *   (5 failures / 15 min per client + email, 10 failures / 15 min per email from any
 *   client, 20 attempts / 15 min per client → 429)
 * - POST password/forgot {email} → always {sent:true} (email sent by a cron job)
 * - POST password/reset {login, key, password} → {reset:true} · 400 invalid_key
 *
 * Signed in (WordPress cookie + `X-WP-Nonce: wp_rest` — mbkFront.account.nonce;
 * role mbk_guest, else 401 not_logged_in):
 * - POST logout → {loggedIn:false, nonce}
 * - GET overview | bookings | payments | coupons | membership | profile
 * - PATCH profile {first_name?, last_name?, phone?, country?, lang?, newsletter?}
 * - POST password {current, new} → {changed:true, nonce} (session renewed)
 * - POST delete {password, confirm:'DELETE'} → {deleted:true, nonce}
 * - POST card/setup-intent → {client_secret, setup_intent} · 503 payments_unavailable
 * - POST card/confirm {setup_intent} → {card, bookings}
 * - POST bookings/{ref}/cancel-request {reason?} → {requested_at}
 *
 * Validation errors: 422 `validation` with `data.fields` (field => message).
 */

namespace Marclie\Booking\Rest\Public;

use Marclie\Booking\Account\AccountService;
use Marclie\Booking\Account\Auth;
use Marclie\Booking\Account\Roles;
use Marclie\Booking\Container;
use Marclie\Booking\Domain\Exception\PaymentException;
use Marclie\Booking\Domain\Exception\ValidationException;

defined( 'ABSPATH' ) || exit;

final class AccountController extends BaseController {

	public const LOGIN_FAILURES = 5;

	/** Failures per email from any client (credential stuffing over many IPs). */
	public const LOGIN_EMAIL_FAILURES = 10;

	public const LOGIN_ATTEMPTS = 20;

	public const LOGIN_WINDOW = 900;

	private const BASE = '/account';

	public function register(): void {
		$none = self::CACHE_NONE;
		$text = array(
			'type'      => 'string',
			'maxLength' => 200,
		);
		$post = \WP_REST_Server::CREATABLE;
		$get  = \WP_REST_Server::READABLE;

		$this->route(
			self::BASE . '/register',
			$post,
			array( $this, 'registerAccount' ),
			array(
				'first_name' => $text,
				'last_name'  => $text,
				'email'      => $text,
				'password'   => array( 'type' => 'string' ),
				'terms'      => array( 'type' => array( 'boolean', 'null' ) ),
				'newsletter' => array( 'type' => array( 'boolean', 'null' ) ),
				'lang'       => array( 'type' => array( 'string', 'null' ) ),
				'website'    => array( 'type' => array( 'string', 'null' ) ),
			),
			$none
		);
		$this->route(
			self::BASE . '/verify',
			$post,
			array( $this, 'verify' ),
			array(
				'token'    => $text + array( 'required' => true ),
				'password' => array(
					'type'     => 'string',
					'required' => true,
				),
			),
			$none
		);
		$this->route(
			self::BASE . '/login',
			$post,
			array( $this, 'login' ),
			array(
				'email'    => $text,
				'password' => array( 'type' => 'string' ),
				'remember' => array( 'type' => array( 'boolean', 'null' ) ),
				'redirect' => array( 'type' => array( 'string', 'null' ) ),
				'website'  => array( 'type' => array( 'string', 'null' ) ),
			),
			$none
		);
		$this->route( self::BASE . '/logout', $post, array( $this, 'logout' ), array(), $none );
		$this->route(
			self::BASE . '/password/forgot',
			$post,
			array( $this, 'forgot' ),
			array(
				'email'   => $text,
				'website' => array( 'type' => array( 'string', 'null' ) ),
			),
			$none
		);
		$this->route(
			self::BASE . '/password/reset',
			$post,
			array( $this, 'reset' ),
			array(
				'login'    => $text + array( 'required' => true ),
				'key'      => $text + array( 'required' => true ),
				'password' => array(
					'type'     => 'string',
					'required' => true,
				),
			),
			$none
		);

		foreach ( array( 'overview', 'bookings', 'payments', 'coupons', 'membership' ) as $view ) {
			$this->route( self::BASE . '/' . $view, $get, array( $this, $view ), array(), $none );
		}
		$this->route( self::BASE . '/profile', $get, array( $this, 'profile' ), array(), $none );
		$this->route(
			self::BASE . '/profile',
			'PATCH',
			array( $this, 'updateProfile' ),
			array(
				'first_name' => $text,
				'last_name'  => $text,
				'phone'      => $text,
				'country'    => $text,
				'lang'       => array( 'type' => 'string' ),
				'newsletter' => array( 'type' => 'boolean' ),
			),
			$none
		);
		$this->route(
			self::BASE . '/password',
			$post,
			array( $this, 'changePassword' ),
			array(
				'current' => array(
					'type'     => 'string',
					'required' => true,
				),
				'new'     => array(
					'type'     => 'string',
					'required' => true,
				),
			),
			$none
		);
		$this->route(
			self::BASE . '/delete',
			$post,
			array( $this, 'delete' ),
			array(
				'password' => array(
					'type'     => 'string',
					'required' => true,
				),
				'confirm'  => array(
					'type'     => 'string',
					'required' => true,
				),
			),
			$none
		);
		$this->route( self::BASE . '/card/setup-intent', $post, array( $this, 'cardSetupIntent' ), array(), $none );
		$this->route( self::BASE . '/card/confirm', $post, array( $this, 'cardConfirm' ), array( 'setup_intent' => $text + array( 'required' => true ) ), $none );
		$this->route(
			self::BASE . '/bookings/(?P<ref>[A-Za-z0-9][A-Za-z0-9-]{2,39})/cancel-request',
			$post,
			array( $this, 'cancelRequest' ),
			array(
				'reason' => array(
					'type'      => array( 'string', 'null' ),
					'maxLength' => 5000,
				),
			),
			$none
		);
	}

	/* Anonymous ------------------------------------------------------------ */

	/** @return \WP_REST_Response|\WP_Error */
	public function registerAccount( \WP_REST_Request $request, string $lang ) {
		$accepted = new \WP_REST_Response( array( 'status' => 'pending' ), 201 );
		if ( '' !== trim( (string) $request['website'] ) ) {
			return $accepted;
		}
		$limited = self::throttle( 'account_register', 5, HOUR_IN_SECONDS );
		if ( $limited ) {
			return $limited;
		}

		$input         = $request->get_params();
		$input['lang'] = in_array( $request['lang'], array( 'hr', 'en' ), true ) ? (string) $request['lang'] : $lang;
		Container::registration()->register( $input );

		return $accepted;
	}

	/** @return array<string, mixed>|\WP_Error */
	public function verify( \WP_REST_Request $request, string $lang ) {
		$token   = strtolower( trim( (string) $request['token'] ) );
		$limited = self::throttle( 'account_verify', 30, HOUR_IN_SECONDS ) ?? self::throttle( 'account_verify_' . substr( hash( 'sha256', $token ), 0, 16 ), 10, self::LOGIN_WINDOW );
		if ( $limited ) {
			return $limited;
		}
		$user = Container::accounts()->verify( $token, (string) $request['password'] );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		return $this->signedIn( $user, AccountService::pageUrl( 'moj_racun', AccountService::userLang( $user->ID ) ) );
	}

	/** @return array<string, mixed>|\WP_Error */
	public function login( \WP_REST_Request $request, string $lang ) {
		$email = strtolower( trim( (string) $request['email'] ) );
		if ( '' !== trim( (string) $request['website'] ) ) {
			return self::error( 'invalid_request' );
		}
		$failures = 'account_login_' . substr( hash( 'sha256', $email ), 0, 16 );
		$global   = 'mbk_rl_account_login_email_' . substr( hash( 'sha256', $email ), 0, 32 );
		$limited  = self::peek( 'mbk_rl_' . $failures . '_' . self::clientHash(), self::LOGIN_FAILURES, self::LOGIN_WINDOW )
			?? self::peek( $global, self::LOGIN_EMAIL_FAILURES, self::LOGIN_WINDOW )
			?? self::throttle( 'account_login', self::LOGIN_ATTEMPTS, self::LOGIN_WINDOW );
		if ( $limited ) {
			return $limited;
		}

		$user = Container::auth()->login( $email, (string) $request['password'], true === $request['remember'] );
		if ( is_wp_error( $user ) ) {
			self::throttle( $failures, PHP_INT_MAX, self::LOGIN_WINDOW );
			self::count( $global, self::LOGIN_WINDOW );

			return $user;
		}

		$fallback = AccountService::pageUrl( 'moj_racun', AccountService::userLang( $user->ID ) );
		$redirect = trim( (string) $request['redirect'] );

		return $this->signedIn( $user, '' !== $redirect ? wp_validate_redirect( $redirect, $fallback ) : $fallback );
	}

	/** @return array{sent: true}|\WP_Error */
	public function forgot( \WP_REST_Request $request, string $lang ) {
		$sent = array( 'sent' => true );
		if ( '' !== trim( (string) $request['website'] ) ) {
			return $sent;
		}
		$email   = strtolower( trim( (string) $request['email'] ) );
		$limited = self::throttle( 'account_forgot', 5, HOUR_IN_SECONDS ) ?? self::throttle( 'account_forgot_' . substr( hash( 'sha256', $email ), 0, 16 ), 3, HOUR_IN_SECONDS );
		if ( $limited ) {
			return $limited;
		}
		if ( '' === $email || ! is_email( $email ) ) {
			throw ValidationException::fields( array( 'email' => __( 'Enter a valid email address.', 'marclie-booking' ) ) );
		}
		Container::passwordReset()->forgot( $email );

		return $sent;
	}

	/** @return array{reset: true}|\WP_Error */
	public function reset( \WP_REST_Request $request ) {
		$limited = self::throttle( 'account_reset', 10, HOUR_IN_SECONDS );
		if ( $limited ) {
			return $limited;
		}
		$user = Container::passwordReset()->reset( (string) $request['login'], (string) $request['key'], (string) $request['password'] );

		return is_wp_error( $user ) ? $user : array( 'reset' => true );
	}

	/* Signed in ------------------------------------------------------------ */

	/** @return array{loggedIn: false, nonce: string} */
	public function logout(): array {
		if ( is_user_logged_in() ) {
			Container::auth()->logout();
		}

		return array(
			'loggedIn' => false,
			'nonce'    => Auth::nonce(),
		);
	}

	/** @return array<string, mixed>|\WP_Error */
	public function overview( \WP_REST_Request $request, string $lang ) {
		return $this->withUser( static fn( \WP_User $user ): array => Container::accounts()->overview( $user, $lang ) );
	}

	/** @return array<string, mixed>|\WP_Error */
	public function bookings( \WP_REST_Request $request, string $lang ) {
		return $this->withUser( static fn( \WP_User $user ): array => Container::accounts()->bookings( $user, $lang ) );
	}

	/** @return array<string, mixed>|\WP_Error */
	public function payments() {
		return $this->withUser( static fn( \WP_User $user ): array => Container::accounts()->payments( $user ) );
	}

	/** @return array<string, mixed>|\WP_Error */
	public function coupons() {
		return $this->withUser( static fn( \WP_User $user ): array => array( 'items' => Container::accounts()->coupons( $user ) ) );
	}

	/** @return array<string, mixed>|\WP_Error */
	public function membership() {
		return $this->withUser( static fn( \WP_User $user ): array => Container::accounts()->membership( $user ) );
	}

	/** @return array<string, mixed>|\WP_Error */
	public function profile() {
		return $this->withUser( static fn( \WP_User $user ): array => Container::accounts()->profile( $user ) );
	}

	/** @return array<string, mixed>|\WP_Error */
	public function updateProfile( \WP_REST_Request $request ) {
		$patch = array();
		foreach ( array( 'first_name', 'last_name', 'phone', 'country', 'lang', 'newsletter' ) as $field ) {
			if ( null !== $request->get_param( $field ) ) {
				$patch[ $field ] = $request->get_param( $field );
			}
		}

		return $this->withUser( static fn( \WP_User $user ): array => Container::accounts()->updateProfile( $user, $patch ) );
	}

	/** @return array<string, mixed>|\WP_Error */
	public function changePassword( \WP_REST_Request $request ) {
		return $this->withUser(
			static function ( \WP_User $user ) use ( $request ) {
				$limited = self::throttle( 'account_password', 10, self::LOGIN_WINDOW );
				if ( $limited ) {
					return $limited;
				}
				Container::accounts()->changePassword( $user, (string) $request['current'], (string) $request['new'] );
				// The password change ended every session: sign this browser in again, end the others.
				Auth::signIn( new \WP_User( $user->ID ), true );
				wp_destroy_other_sessions();

				return array(
					'changed' => true,
					'nonce'   => Auth::nonce(),
				);
			}
		);
	}

	/** @return array<string, mixed>|\WP_Error */
	public function delete( \WP_REST_Request $request ) {
		return $this->withUser(
			static function ( \WP_User $user ) use ( $request ): array {
				if ( 'DELETE' !== (string) $request['confirm'] ) {
					throw ValidationException::fields( array( 'confirm' => __( 'Type DELETE to confirm.', 'marclie-booking' ) ) );
				}
				Container::accounts()->delete( $user, (string) $request['password'] );
				wp_set_current_user( 0 );
				unset( $_COOKIE[ LOGGED_IN_COOKIE ] );

				return array(
					'deleted' => true,
					'nonce'   => Auth::nonce(),
				);
			}
		);
	}

	/** @return array<string, mixed>|\WP_Error */
	public function cardSetupIntent() {
		return $this->withUser(
			static function ( \WP_User $user ) {
				$limited = self::throttle( 'account_card', 10, HOUR_IN_SECONDS );
				if ( $limited ) {
					return $limited;
				}

				return self::card( static fn(): array => Container::cardUpdate()->setupIntent( $user ) );
			}
		);
	}

	/** @return array<string, mixed>|\WP_Error */
	public function cardConfirm( \WP_REST_Request $request ) {
		return $this->withUser( static fn( \WP_User $user ) => self::card( static fn(): array => Container::cardUpdate()->confirm( $user, (string) $request['setup_intent'] ) ) );
	}

	/** @return array<string, mixed>|\WP_Error */
	public function cancelRequest( \WP_REST_Request $request ) {
		return $this->withUser(
			static function ( \WP_User $user ) use ( $request ) {
				$limited = self::throttle( 'account_cancel', 5, HOUR_IN_SECONDS );
				if ( $limited ) {
					return $limited;
				}

				return Container::cancelRequests()->request( $user, (string) $request['ref'], (string) $request['reason'] );
			}
		);
	}

	/* Internals ------------------------------------------------------------ */

	/**
	 * Runs `$callback` with the signed-in guest account, else 401.
	 *
	 * @param callable(\WP_User): (array<string, mixed>|\WP_Error) $callback
	 * @return array<string, mixed>|\WP_Error
	 */
	private function withUser( callable $callback ) {
		// Fresh from the (cleaned) cache: the global current user object may hold an old password hash.
		$user = get_userdata( get_current_user_id() );
		if ( ! $user instanceof \WP_User || ! Roles::hasGuestRole( $user ) ) {
			return new \WP_Error( 'not_logged_in', __( 'Please sign in to your account.', 'marclie-booking' ), array( 'status' => 401 ) );
		}

		return $callback( $user );
	}

	/**
	 * Card endpoints: payments_unavailable with a message about card changes.
	 *
	 * @param callable(): array<string, mixed> $callback
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function card( callable $callback ) {
		try {
			return $callback();
		} catch ( PaymentException $e ) {
			if ( PaymentException::UNAVAILABLE !== $e->errorCode() ) {
				throw $e;
			}

			return new \WP_Error( PaymentException::UNAVAILABLE, __( 'Changing the card is not possible online at the moment. Please contact us.', 'marclie-booking' ), array( 'status' => 503 ) );
		}
	}

	/** @return array{user: array<string, mixed>, nonce: string, redirect: string} */
	private function signedIn( \WP_User $user, string $redirect ): array {
		return array(
			'user'     => Container::accounts()->summary( $user ),
			'nonce'    => Auth::nonce(),
			'redirect' => $redirect,
		);
	}

	/**
	 * Refusal when the window stored under transient `$key` already holds
	 * `$limit` requests — without counting this one.
	 */
	private static function peek( string $key, int $limit, int $window ): ?\WP_Error {
		$state = get_transient( $key );
		if ( ! is_array( $state ) || ! isset( $state['start'], $state['count'] ) ) {
			return null;
		}
		$now = time();
		if ( $now >= (int) $state['start'] + $window || (int) $state['count'] < $limit ) {
			return null;
		}

		return self::error( 'rate_limited', array( 'retry_after' => max( 1, (int) $state['start'] + $window - $now ) ) );
	}

	/** Counts one request in the window stored under transient `$key` (no client in the key). */
	private static function count( string $key, int $window ): void {
		$stored = get_transient( $key );
		$hit    = RateLimit::hit( is_array( $stored ) ? $stored : null, time(), PHP_INT_MAX, $window );
		set_transient( $key, $hit['state'], $hit['ttl'] );
	}
}
