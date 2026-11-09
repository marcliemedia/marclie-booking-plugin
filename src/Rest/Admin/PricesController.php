<?php
/**
 * /admin/prices — the 12-month price table: per unit the price per person per
 * night of each month (null = closed), the minimum stay and the minimum paying guests.
 */

namespace Marclie\Booking\Rest\Admin;

use Marclie\Booking\Container;
use Marclie\Booking\Install\Activator;

defined( 'ABSPATH' ) || exit;

final class PricesController extends BaseController {

	public function register(): void {
		$cap = Activator::CAP_RATES;

		$this->route(
			'/prices',
			array(
				$this->endpoint( \WP_REST_Server::READABLE, array( $this, 'index' ), $cap ),
				$this->endpoint(
					'PUT',
					array( $this, 'update' ),
					$cap,
					array(
						'changes' => array(
							'type'     => 'array',
							'required' => true,
							'items'    => array(
								'type'       => 'object',
								'properties' => array(
									'unit_id'  => array(
										'type'     => 'integer',
										'required' => true,
									),
									'min_stay' => array(
										'type'     => 'integer',
										'required' => true,
									),
									'min_guests' => array(
										'type'     => 'integer',
										'required' => true,
									),
									'months'   => array(
										'type'     => 'array',
										'required' => true,
										'minItems' => 12,
										'maxItems' => 12,
										'items'    => array( 'type' => array( 'integer', 'null' ) ),
									),
								),
							),
						),
					)
				),
			)
		);
	}

	/** @return array{units: list<array{unit_id:int, min_stay:int, min_guests:int, months:list<?int>}>} */
	public function index(): array {
		return array( 'units' => Container::prices()->rows() );
	}

	/** @return array{units: list<array{unit_id:int, min_stay:int, min_guests:int, months:list<?int>}>} */
	public function update( \WP_REST_Request $request ): array {
		$changes = array_map(
			static fn( array $c ): array => array(
				'unit_id'    => (int) $c['unit_id'],
				'min_stay'   => (int) $c['min_stay'],
				'min_guests' => (int) $c['min_guests'],
				'months'     => array_map( static fn( $v ): ?int => null === $v ? null : (int) $v, array_values( (array) $c['months'] ) ),
			),
			array_values( (array) $request['changes'] )
		);
		Container::prices()->save( $changes, self::actor() );

		return $this->index();
	}
}
