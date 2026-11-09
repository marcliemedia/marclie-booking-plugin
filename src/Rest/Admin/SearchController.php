<?php
/**
 * GET /admin/search?q= — command palette (⌘K): bookings and guests.
 */

namespace Marclie\Booking\Rest\Admin;

use Marclie\Booking\Container;
use Marclie\Booking\Install\Activator;

defined( 'ABSPATH' ) || exit;

final class SearchController extends BaseController {

	private const LIMIT = 8;

	private const MIN_LENGTH = 2;

	public function register(): void {
		$this->route(
			'/search',
			$this->endpoint(
				\WP_REST_Server::READABLE,
				array( $this, 'search' ),
				Activator::CAP_BOOKINGS,
				array(
					'q' => array(
						'type'              => 'string',
						'required'          => true,
						// Length is checked on the trimmed value ("  " is not a query).
						'validate_callback' => static function ( $value ) {
							return is_string( $value ) && mb_strlen( trim( $value ) ) >= self::MIN_LENGTH
								? true
								/* translators: %d: minimum number of characters */
								: new \WP_Error( 'rest_invalid_param', sprintf( __( 'Type at least %d characters.', 'marclie-booking' ), self::MIN_LENGTH ) );
						},
						'sanitize_callback' => static fn( $value ): string => trim( (string) $value ),
					),
				)
			)
		);
	}

	/** @return array{bookings: list<array<string, mixed>>, guests: list<array<string, mixed>>} */
	public function search( \WP_REST_Request $request ): array {
		$q = trim( (string) $request['q'] );

		return array(
			'bookings' => array_map(
				static fn( array $b ): array => array(
					'id'         => (int) $b['id'],
					'ref'        => (string) $b['ref'],
					'guest_name' => trim( $b['first_name'] . ' ' . $b['last_name'] ),
					'unit_title' => Presenter::unitTitle( (int) $b['unit_id'] ),
					'arrival'    => (string) $b['arrival'],
					'status'     => (string) $b['status'],
				),
				Container::bookingRepository()->quickSearch( $q, self::LIMIT )
			),
			'guests'   => array_map(
				static fn( array $g ): array => array(
					'id'    => (int) $g['id'],
					'name'  => trim( $g['first_name'] . ' ' . $g['last_name'] ),
					'email' => (string) $g['email'],
				),
				Container::guestRepository()->quickSearch( $q, self::LIMIT )
			),
		);
	}
}
