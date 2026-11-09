<?php

namespace Marclie\Booking\Tests\Unit;

use Marclie\Booking\Service\ReferenceGenerator;
use PHPUnit\Framework\TestCase;

final class ReferenceTest extends TestCase {

	private function arrival(): \DateTimeImmutable {
		return new \DateTimeImmutable( '2027-07-12', new \DateTimeZone( 'Europe/Zagreb' ) );
	}

	public function testSingleDigitCodeIsPadded(): void {
		$this->assertSame( 'BK-27-0712-001', ReferenceGenerator::base( 'BK', $this->arrival(), '1' ) );
	}

	public function testThreeDigitCodeUnchanged(): void {
		$this->assertSame( 'BK-27-0712-101', ReferenceGenerator::base( 'BK', $this->arrival(), '101' ) );
	}

	public function testAlphanumericCodesUnchanged(): void {
		$this->assertSame( 'BK-27-0712-PS1', ReferenceGenerator::base( 'BK', $this->arrival(), 'PS1' ) );
		$this->assertSame( 'BK-27-0712-PH2', ReferenceGenerator::base( 'BK', $this->arrival(), 'ph2' ) );
	}

	public function testEmptyCodeFallsBackToUnitId(): void {
		$this->assertSame( 'BK-27-0712-42', ReferenceGenerator::base( 'BK', $this->arrival(), ' - ', 42 ) );
	}

	public function testFreeBaseIsUsed(): void {
		$this->assertSame( 'BK-27-0712-001', ReferenceGenerator::nextFree( 'BK-27-0712-001', array( 'BK-27-0712-101' ) ) );
	}

	public function testCollisionSuffixes(): void {
		$base = 'BK-27-0712-001';
		$this->assertSame( $base . '-2', ReferenceGenerator::nextFree( $base, array( $base ) ) );
		$this->assertSame( $base . '-3', ReferenceGenerator::nextFree( $base, array( $base, $base . '-2' ) ) );
		$this->assertSame( $base . '-2', ReferenceGenerator::nextFree( $base, array( $base, $base . '-3' ) ) );
	}
}
