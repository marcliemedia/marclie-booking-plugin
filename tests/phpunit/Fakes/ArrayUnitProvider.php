<?php

namespace Marclie\Booking\Tests\Fakes;

use Marclie\Booking\Units\Unit;
use Marclie\Booking\Units\UnitProvider;

final class ArrayUnitProvider implements UnitProvider {

	/** @var array<int, Unit> */
	private array $units = array();

	/**
	 * @param list<Unit>      $units
	 * @param array<int, int> $aliases Translation ID => original unit ID (mimics WPML mapping).
	 */
	public function __construct( array $units, private array $aliases = array() ) {
		foreach ( $units as $u ) {
			$this->units[ $u->id ] = $u;
		}
	}

	public function get( int $id ): ?Unit {
		return $this->units[ $this->aliases[ $id ] ?? $id ] ?? null;
	}

	public function byCode( string $code ): ?Unit {
		foreach ( $this->units as $u ) {
			if ( $u->code === $code ) {
				return $u;
			}
		}
		return null;
	}

	public function all(): array {
		return array_values( $this->units );
	}
}
