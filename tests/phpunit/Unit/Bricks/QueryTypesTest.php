<?php

namespace Marclie\Booking\Tests\Unit\Bricks;

use Marclie\Booking\Bricks\QueryTypes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QueryTypesTest extends TestCase {

	/** @return list<array<string, mixed>> Cards in the order the search returns them (menu order). */
	private static function cards(): array {
		$card = static fn( string $id, int $order, string $price, int $sold ): array => array(
			'id'   => $id,
			'data' => array(
				'order' => $order,
				'price' => $price,
				'sold'  => $sold,
			),
		);

		return array(
			$card( 'a', 1, '1365', 6 ),
			$card( 'b', 2, '980', 4 ),
			$card( 'c', 3, '1365', 10 ),
			$card( 'd', 4, '409.5', 2 ),
		);
	}

	public static function sorts(): array {
		return array(
			'recommended'      => array( '', array( 'a', 'b', 'c', 'd' ) ),
			'unknown sort'     => array( 'title', array( 'a', 'b', 'c', 'd' ) ),
			'price ascending'  => array( 'price-asc', array( 'd', 'b', 'a', 'c' ) ),
			'price descending' => array( 'price-desc', array( 'a', 'c', 'b', 'd' ) ),
			'sold descending'  => array( 'sold', array( 'c', 'a', 'b', 'd' ) ),
		);
	}

	/** @param list<string> $expected */
	#[DataProvider( 'sorts' )]
	public function testSortCards( string $sort, array $expected ): void {
		$cards = self::cards();
		shuffle( $cards );

		$this->assertSame( $expected, array_column( QueryTypes::sortCards( $cards, $sort ), 'id' ) );
	}
}
