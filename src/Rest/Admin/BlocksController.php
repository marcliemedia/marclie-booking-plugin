<?php
/**
 * /admin/blocks — manual calendar blocks (`date_to` exclusive, like a departure),
 * for one or several units at once (closing days): all or nothing.
 */

namespace Marclie\Booking\Rest\Admin;

use Marclie\Booking\Container;
use Marclie\Booking\Domain\Exception\UnavailableException;
use Marclie\Booking\Domain\Stay;
use Marclie\Booking\Install\Activator;

defined( 'ABSPATH' ) || exit;

final class BlocksController extends BaseController {

	public function register(): void {
		$this->route(
			'/blocks',
			$this->endpoint(
				\WP_REST_Server::CREATABLE,
				array( $this, 'create' ),
				Activator::CAP_BOOKINGS,
				array(
					'unit_ids'  => array(
						'type'     => 'array',
						'required' => true,
						'minItems' => 1,
						'items'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'date_from' => self::dateArg( true ),
					'date_to'   => self::dateArg( true ),
					'reason'    => array(
						'type'      => array( 'string', 'null' ),
						'maxLength' => 255,
					),
				)
			)
		);
		$this->route(
			'/blocks/(?P<id>\d+)',
			$this->endpoint( \WP_REST_Server::DELETABLE, array( $this, 'delete' ), Activator::CAP_BOOKINGS )
		);
	}

	/** @return \WP_REST_Response|\WP_Error 201 `{ blocks }`; 409 `unavailable` naming the first taken unit. */
	public function create( \WP_REST_Request $request ) {
		$stays = array();
		foreach ( array_map( 'intval', (array) $request['unit_ids'] ) as $unitId ) {
			$stays[] = Stay::fromStrings( $unitId, (string) $request['date_from'], (string) $request['date_to'], wp_timezone() );
		}

		try {
			$blocks = Container::blocks()->createMany( $stays, sanitize_text_field( (string) $request['reason'] ), self::userId() );
		} catch ( UnavailableException $e ) {
			$error = self::domainError( $e );
			$unit  = Container::units()->get( (int) ( $e->context()['unit_id'] ?? 0 ) );
			if ( count( $stays ) > 1 && null !== $unit ) {
				/* translators: %s: apartment name */
				return new \WP_Error( 'unavailable', sprintf( __( '%s is already taken on some of these nights. Nothing was blocked.', 'marclie-booking' ), $unit->title ), $error->get_error_data() );
			}
			return $error;
		}

		return new \WP_REST_Response(
			array(
				'blocks' => array_map(
					static fn( array $block ): array => array(
						'id'        => (int) $block['id'],
						'unit_id'   => (int) $block['unit_id'],
						'date_from' => (string) $block['date_from'],
						'date_to'   => (string) $block['date_to'],
						'reason'    => (string) $block['reason'],
					),
					$blocks
				),
			),
			201
		);
	}

	/** @return array{deleted: true} */
	public function delete( \WP_REST_Request $request ): array {
		Container::blocks()->delete( (int) $request['id'], self::userId() );

		return array( 'deleted' => true );
	}
}
