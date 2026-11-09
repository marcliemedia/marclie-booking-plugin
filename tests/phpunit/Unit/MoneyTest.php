<?php

namespace Marclie\Booking\Tests\Unit;

use Marclie\Booking\Domain\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase {

	public static function strings(): array {
		return array(
			array( '1.365', 136500 ),
			array( '409,50', 40950 ),
			array( '409.50', 40950 ),
			array( '1.296,75', 129675 ),
			array( '195', 19500 ),
			array( '2.730', 273000 ),
			array( '0,5', 50 ),
			array( '-68,25', -6825 ),
			array( '1 296,75 €', 129675 ),
		);
	}

	#[DataProvider( 'strings' )]
	public function testFromDecimalString( string $in, int $cents ): void {
		$this->assertSame( $cents, Money::fromDecimalString( $in )->cents() );
	}

	public function testInvalidString(): void {
		$this->expectException( \InvalidArgumentException::class );
		Money::fromDecimalString( 'abc' );
	}

	public function testArithmeticIsImmutable(): void {
		$a = new Money( 100 );
		$b = $a->add( new Money( 50 ) );
		$this->assertSame( 100, $a->cents() );
		$this->assertSame( 150, $b->cents() );
		$this->assertSame( -50, $a->sub( $b )->cents() );
		$this->assertTrue( $a->sub( $b )->isNegative() );
		$this->assertTrue( Money::zero()->isZero() );
	}

	public function testPercentHalfUp(): void {
		$this->assertSame( 40950, ( new Money( 136500 ) )->percent( 30 )->cents() );
		$this->assertSame( 38903, ( new Money( 129675 ) )->percent( 30 )->cents() ); // 389.025 -> 389.03
		$this->assertSame( 6825, ( new Money( 136500 ) )->percent( 5 )->cents() );
		$this->assertSame( 1, ( new Money( 5 ) )->percent( 10 )->cents() );    // 0.5 -> 1
		$this->assertSame( 0, ( new Money( 4 ) )->percent( 10 )->cents() );    // 0.4 -> 0
		$this->assertSame( -1, ( new Money( -5 ) )->percent( 10 )->cents() );
		$this->assertSame( 13, ( new Money( 100 ) )->percent( 12.5 )->cents() );
	}
}
