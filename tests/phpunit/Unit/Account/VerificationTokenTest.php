<?php

namespace Marclie\Booking\Tests\Unit\Account;

use Marclie\Booking\Account\Verification;
use PHPUnit\Framework\TestCase;

final class VerificationTokenTest extends TestCase {

	public function testNewTokensAreRandom64Hex(): void {
		$a = Verification::newToken();
		$b = Verification::newToken();
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $a );
		self::assertNotSame( $a, $b );
		self::assertTrue( Verification::isWellFormed( $a ) );
	}

	public function testHashIsStableAndNotTheToken(): void {
		$token = Verification::newToken();
		self::assertSame( Verification::hashToken( $token ), Verification::hashToken( $token ) );
		self::assertNotSame( $token, Verification::hashToken( $token ) );
		self::assertSame( hash( 'sha256', $token ), Verification::hashToken( $token ) );
	}

	public function testMalformedTokensAreRejected(): void {
		self::assertFalse( Verification::isWellFormed( '' ) );
		self::assertFalse( Verification::isWellFormed( str_repeat( 'a', 63 ) ) );
		self::assertFalse( Verification::isWellFormed( str_repeat( 'A', 64 ) ) );
		self::assertFalse( Verification::isWellFormed( str_repeat( 'g', 64 ) ) );
		self::assertFalse( Verification::isWellFormed( str_repeat( 'a', 64 ) . "\n" ) );
	}
}
