<?php

namespace Marclie\Booking\Tests\Unit;

use Marclie\Booking\Rest\Public\SameOrigin;
use PHPUnit\Framework\TestCase;

final class SameOriginTest extends TestCase {

	public function testOwnPagesPass(): void {
		$this->assertTrue( SameOrigin::allowed( 'same-origin', 'https://kristalparis.com', 'kristalparis.com' ) );
		$this->assertTrue( SameOrigin::allowed( null, 'https://KristalParis.com', 'kristalparis.com' ), 'host is case-insensitive' );
		$this->assertTrue( SameOrigin::allowed( 'same-origin', 'https://site.local:8443', 'site.local:8443' ) );
	}

	public function testRequestsWithoutBrowserHeadersPass(): void {
		$this->assertTrue( SameOrigin::allowed( null, null, 'kristalparis.com' ) );
		$this->assertTrue( SameOrigin::allowed( 'none', null, 'kristalparis.com' ) );
	}

	public function testCrossSiteFormPostIsRefused(): void {
		$this->assertFalse( SameOrigin::allowed( 'cross-site', 'https://evil.example', 'kristalparis.com' ) );
		$this->assertFalse( SameOrigin::allowed( null, 'https://evil.example', 'kristalparis.com' ), 'browsers without Sec-Fetch-Site' );
		$this->assertFalse( SameOrigin::allowed( 'cross-site', null, 'kristalparis.com' ) );
	}

	public function testLookAlikeOriginsAreRefused(): void {
		$this->assertFalse( SameOrigin::allowed( 'same-site', 'https://shop.kristalparis.com', 'kristalparis.com' ) );
		$this->assertFalse( SameOrigin::allowed( null, 'https://kristalparis.com.evil.example', 'kristalparis.com' ) );
		$this->assertFalse( SameOrigin::allowed( null, 'https://kristalparis.com:444', 'kristalparis.com' ) );
		$this->assertFalse( SameOrigin::allowed( null, 'null', 'kristalparis.com' ), 'sandboxed frame' );
		$this->assertFalse( SameOrigin::allowed( null, 'https://kristalparis.com', '' ) );
	}
}
