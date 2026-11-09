<?php
/**
 * Unit lookup port.
 */

namespace Marclie\Booking\Units;

defined( 'ABSPATH' ) || exit;

interface UnitProvider {

	public function get( int $id ): ?Unit;

	public function byCode( string $code ): ?Unit;

	/** @return list<Unit> */
	public function all(): array;
}
