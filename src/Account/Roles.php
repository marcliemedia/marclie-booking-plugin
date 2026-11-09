<?php
/**
 * Role `mbk_guest` (capability `read` only) of guest accounts. Guests never
 * see wp-admin (redirected to /moj-racun/) nor the admin bar. Registration
 * goes through the plugin endpoint only: `users_can_register` stays off, so
 * wp-login.php?action=register remains closed.
 *
 * A guest's account data changes only through /account/* (verified email is
 * the identity): core `/wp/v2/users*` writes are refused, no path (REST,
 * XML-RPC, profile forms) may change their email unless staff with
 * `edit_users` does it, application passwords are off, and any email change
 * voids the verification (AccountService::onProfileUpdate()).
 */

namespace Marclie\Booking\Account;

defined( 'ABSPATH' ) || exit;

final class Roles {

	public const GUEST = 'mbk_guest';

	public static function hooks(): void {
		add_action( 'admin_init', array( self::class, 'blockAdmin' ), 1 );
		add_filter( 'show_admin_bar', array( self::class, 'showAdminBar' ) );
		add_filter( 'rest_request_before_callbacks', array( self::class, 'blockRestUserWrite' ), 10, 3 );
		add_filter( 'wp_pre_insert_user_data', array( self::class, 'keepGuestEmail' ), 10, 4 );
		add_filter( 'wp_is_application_passwords_available_for_user', array( self::class, 'applicationPasswords' ), 10, 2 );
		add_action( 'profile_update', array( AccountService::class, 'onProfileUpdate' ), 10, 2 );
	}

	/** Signed-in guest-only user without staff rights. */
	private static function currentIsGuestOnly(): bool {
		return is_user_logged_in() && ! current_user_can( 'edit_users' ) && self::isGuestOnly( wp_get_current_user() );
	}

	/**
	 * `rest_request_before_callbacks`: guests may not create, edit or delete
	 * users (incl. application passwords) through the core `/wp/v2/users*` routes.
	 *
	 * @param mixed            $response
	 * @param array            $handler
	 * @param \WP_REST_Request $request
	 * @return mixed
	 */
	public static function blockRestUserWrite( $response, $handler = array(), $request = null ) {
		if ( ! $request instanceof \WP_REST_Request || 'GET' === $request->get_method() || ! str_starts_with( $request->get_route(), '/wp/v2/users' ) ) {
			return $response;
		}
		if ( self::currentIsGuestOnly() ) {
			return new \WP_Error( 'rest_cannot_edit', __( 'Edit your details in your account.', 'marclie-booking' ), array( 'status' => 403 ) );
		}

		return $response;
	}

	/**
	 * `wp_pre_insert_user_data`: a guest-only user's email stays unchanged
	 * unless staff with `edit_users` (or WP-CLI) changes it (XML-RPC, profile forms…).
	 *
	 * @param array<string, mixed> $data
	 * @param bool                 $update
	 * @param int|null             $userId
	 * @return array<string, mixed>
	 */
	public static function keepGuestEmail( $data, $update = false, $userId = null, $userdata = array() ) {
		if ( ! $update || ! $userId || current_user_can( 'edit_users' ) || ( defined( 'WP_CLI' ) && WP_CLI && ! is_user_logged_in() ) ) {
			return $data;
		}
		$user = get_userdata( (int) $userId );
		if ( $user instanceof \WP_User && self::isGuestOnly( $user ) && isset( $data['user_email'] ) ) {
			$data['user_email'] = $user->user_email;
		}

		return $data;
	}

	/**
	 * @param bool          $available
	 * @param \WP_User|null $user
	 */
	public static function applicationPasswords( $available, $user = null ): bool {
		return $user instanceof \WP_User && self::isGuestOnly( $user ) ? false : (bool) $available;
	}

	/** Idempotent (Activator). */
	public static function register(): void {
		$role = get_role( self::GUEST );
		if ( ! $role ) {
			add_role( self::GUEST, 'Guest account', array( 'read' => true ) );
		}
	}

	/** uninstall.php when data is deleted. */
	public static function unregister(): void {
		remove_role( self::GUEST );
	}

	/** A signed-in user with a guest account (role mbk_guest). */
	public static function hasGuestRole( ?\WP_User $user ): bool {
		return null !== $user && $user->exists() && in_array( self::GUEST, (array) $user->roles, true );
	}

	/** A user whose only access is the guest account (staff with more roles are not). */
	public static function isGuestOnly( ?\WP_User $user ): bool {
		if ( null === $user || ! $user->exists() ) {
			return false;
		}
		$roles = (array) $user->roles;

		return in_array( self::GUEST, $roles, true ) && array() === array_diff( $roles, array( self::GUEST ) );
	}

	/** admin_init: guests land on /moj-racun/ (AJAX and admin-post requests pass). */
	public static function blockAdmin(): void {
		if ( wp_doing_ajax() || ( defined( 'WP_CLI' ) && WP_CLI ) || ! self::isGuestOnly( wp_get_current_user() ) ) {
			return;
		}
		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( (string) wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared only.
		if ( 'admin-post.php' === $script ) {
			return;
		}

		wp_safe_redirect( AccountService::pageUrl( 'moj_racun', AccountService::userLang( get_current_user_id() ) ) );
		exit;
	}

	/**
	 * @param bool $show
	 */
	public static function showAdminBar( $show ): bool {
		return self::isGuestOnly( wp_get_current_user() ) ? false : (bool) $show;
	}
}
