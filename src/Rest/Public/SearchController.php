<?php
/**
 * GET /search?dolazak&odlazak&odrasli&djeca — every unit with availability,
 * capacity fit and stay total (`/pretraga/`). Without dates all units are
 * available and totals are null.
 */

namespace Marclie\Booking\Rest\Public;

use Marclie\Booking\Container;

defined( 'ABSPATH' ) || exit;

final class SearchController extends BaseController {

	public const MAX_ADULTS = 10;

	public const MAX_CHILDREN = 6;

	public function register(): void {
		$this->route(
			'/search',
			\WP_REST_Server::READABLE,
			array( $this, 'search' ),
			array(
				'dolazak' => array( 'type' => 'string' ),
				'odlazak' => array( 'type' => 'string' ),
				'odrasli' => self::intArg( 1, self::MAX_ADULTS, 2 ),
				'djeca'   => self::intArg( 0, self::MAX_CHILDREN, 0 ),
			),
			self::CACHE_PUBLIC
		);
	}

	/** @return array<string, mixed> */
	public function search( \WP_REST_Request $request, string $lang ): array {
		$search   = Container::search();
		$stay     = $search->parseStay( (string) $request['dolazak'], (string) $request['odlazak'] );
		$adults   = (int) $request['odrasli'];
		$children = (int) $request['djeca'];

		return array(
			'stay'   => null === $stay ? null : array(
				'arrival'   => $stay->arrivalString(),
				'departure' => $stay->departureString(),
				'nights'    => $stay->nights(),
			),
			'guests' => array(
				'adults'   => $adults,
				'children' => $children,
			),
			'units'  => $search->search( $stay, $adults, $children, $lang ),
		);
	}
}
