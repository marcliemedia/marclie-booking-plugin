<?php
/**
 * /admin/roles — who has the `booking_manager` role (staff access to bookings
 * and rates, not to settings or Stripe).
 */

namespace Marclie\Booking\Rest\Admin;

use Marclie\Booking\Domain\Exception\NotFoundException;
use Marclie\Booking\Domain\Exception\ValidationException;
use Marclie\Booking\Install\Activator;

defined( 'ABSPATH' ) || exit;

final class RolesController extends BaseController {

	public function register(): void {
		$cap = Activator::CAP_SETTINGS;

		$this->route(
			'/roles',
			array(
				$this->endpoint( \WP_REST_Server::READABLE, array( $this, 'index' ), $cap ),
				$this->endpoint(
					\WP_REST_Server::CREATABLE,
					array( $this, 'add' ),
					$cap,
					array(
						'email' => array(
							'type'     => 'string',
							'required' => true,
						),
					)
				),
			)
		);
		$this->route( '/roles/(?P<user_id>\d+)', $this->endpoint( \WP_REST_Server::DELETABLE, array( $this, 'remove' ), $cap ) );
	}

	/** @return array{users: list<array{id:int, name:string, email:string, role:string}>} */
	public function index(): array {
		$users = get_users(
			array(
				'role'    => Activator::ROLE,
				'orderby' => 'display_name',
			)
		);

		return array(
			'users' => array_map(
				static fn( \WP_User $user ): array => array(
					'id'    => (int) $user->ID,
					'name'  => (string) $user->display_name,
					'email' => (string) $user->user_email,
					'role'  => Activator::ROLE,
				),
				$users
			),
		);
	}

	/** @return array{users: list<array<string, mixed>>} */
	public function add( \WP_REST_Request $request ): array {
		$email = sanitize_email( (string) $request['email'] );
		if ( '' === $email || ! is_email( $email ) ) {
			throw ValidationException::fields( array( 'email' => __( 'Enter a valid email address.', 'marclie-booking' ) ) );
		}

		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			throw ValidationException::fields( array( 'email' => __( 'No WordPress user has this email address. Create the user first.', 'marclie-booking' ) ) );
		}

		if ( ! in_array( Activator::ROLE, (array) $user->roles, true ) ) {
			$user->add_role( Activator::ROLE );
		}

		return $this->index();
	}

	/** @return array{users: list<array<string, mixed>>} */
	public function remove( \WP_REST_Request $request ): array {
		$user = get_userdata( (int) $request['user_id'] );
		if ( ! $user || ! in_array( Activator::ROLE, (array) $user->roles, true ) ) {
			throw new NotFoundException( 'User does not have the booking manager role.', array( 'user_id' => (int) $request['user_id'] ) );
		}

		$user->remove_role( Activator::ROLE );
		if ( ! $user->roles ) {
			// Keep the account usable (it can still log in) without booking access. Never
			// the site's default_role: it could be administrator or booking_manager.
			$user->add_role( 'subscriber' );
		}

		return $this->index();
	}
}
