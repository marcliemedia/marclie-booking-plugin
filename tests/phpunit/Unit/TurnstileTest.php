<?php

namespace Marclie\Booking\Tests\Unit;

use Marclie\Booking\Service\Turnstile;
use PHPUnit\Framework\TestCase;

final class TurnstileTest extends TestCase {

	public function testValidBookingTokenPasses(): void {
		$this->assertTrue( Turnstile::accepts( array( 'success' => true, 'action' => 'booking' ) ) );
		$this->assertTrue( Turnstile::accepts( array( 'success' => true ) ), 'Cloudflare testing keys send no action' );
	}

	public function testTokenOfAnotherWidgetIsRefused(): void {
		$this->assertFalse( Turnstile::accepts( array( 'success' => true, 'action' => 'login' ) ) );
	}

	public function testFailedOrReusedTokenIsRefused(): void {
		$this->assertFalse( Turnstile::accepts( array( 'success' => false, 'error-codes' => array( 'invalid-input-response' ) ) ) );
		$this->assertFalse( Turnstile::accepts( array( 'success' => false, 'error-codes' => array( 'timeout-or-duplicate' ) ) ) );
		$this->assertFalse( Turnstile::accepts( array( 'success' => false ) ) );
	}

	public function testOwnSecretMistakeDoesNotBlockBookings(): void {
		$log = ini_set( 'error_log', (string) tempnam( sys_get_temp_dir(), 'mbk' ) );
		try {
			$this->assertTrue( Turnstile::accepts( array( 'success' => false, 'error-codes' => array( 'invalid-input-secret' ) ) ) );
		} finally {
			ini_set( 'error_log', (string) $log );
		}
	}

	public function testOffWithoutKeysPassesEveryRequest(): void {
		$this->assertFalse( Turnstile::enabled() );
		$this->assertNull( Turnstile::siteKey() );
		$this->assertTrue( Turnstile::verify( '', '203.0.113.9' ) );
	}
}
