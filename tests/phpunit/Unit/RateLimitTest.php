<?php

namespace Marclie\Booking\Tests\Unit;

use Marclie\Booking\Rest\Public\RateLimit;
use PHPUnit\Framework\TestCase;

final class RateLimitTest extends TestCase {

	public function testFirstHitOpensWindow(): void {
		$hit = RateLimit::hit( null, 1000, 30, 60 );

		$this->assertTrue( $hit['allowed'] );
		$this->assertSame( array( 'start' => 1000, 'count' => 1 ), $hit['state'] );
		$this->assertSame( 0, $hit['retry_after'] );
		$this->assertSame( 60, $hit['ttl'] );
	}

	public function testLimitWithinWindow(): void {
		$state = null;
		for ( $i = 0; $i < 30; $i++ ) {
			$hit   = RateLimit::hit( $state, 1000 + $i, 30, 60 );
			$state = $hit['state'];
			$this->assertTrue( $hit['allowed'], "request {$i}" );
		}

		$refused = RateLimit::hit( $state, 1040, 30, 60 );
		$this->assertFalse( $refused['allowed'] );
		$this->assertSame( 20, $refused['retry_after'] );
		$this->assertSame( 30, $refused['state']['count'], 'refused requests are not counted' );
		$this->assertSame( 1000, $refused['state']['start'] );
	}

	public function testNewWindowAfterExpiry(): void {
		$full = array(
			'start' => 1000,
			'count' => 10,
		);

		$this->assertFalse( RateLimit::hit( $full, 1059, 10, 60 )['allowed'] );

		$hit = RateLimit::hit( $full, 1060, 10, 60 );
		$this->assertTrue( $hit['allowed'] );
		$this->assertSame( array( 'start' => 1060, 'count' => 1 ), $hit['state'] );
	}

	public function testClockSkewResetsWindow(): void {
		$hit = RateLimit::hit( array( 'start' => 2000, 'count' => 10 ), 1500, 10, 60 );

		$this->assertTrue( $hit['allowed'] );
		$this->assertSame( 1500, $hit['state']['start'] );
	}

	public function testIpv6BucketedPerSlash64(): void {
		$this->assertSame( '2001:db8:1:2::/64', RateLimit::bucket( '2001:db8:1:2:aaaa:bbbb:cccc:dddd' ) );
		$this->assertSame( RateLimit::bucket( '2001:db8:1:2::1' ), RateLimit::bucket( '2001:0db8:0001:0002:ffff::9' ) );
		$this->assertNotSame( RateLimit::bucket( '2001:db8:1:2::1' ), RateLimit::bucket( '2001:db8:1:3::1' ) );
		$this->assertSame( '203.0.113.7', RateLimit::bucket( '203.0.113.7' ) );
		$this->assertSame( '203.0.113.7', RateLimit::bucket( '::ffff:203.0.113.7' ), 'IPv4-mapped IPv6 is IPv4' );
		$this->assertSame( '', RateLimit::bucket( 'not-an-ip' ) );
	}

	public function testInRange(): void {
		$this->assertTrue( RateLimit::inRange( '10.1.2.3', '10.0.0.0/8' ) );
		$this->assertFalse( RateLimit::inRange( '11.1.2.3', '10.0.0.0/8' ) );
		$this->assertTrue( RateLimit::inRange( '192.168.1.130', '192.168.1.128/25' ) );
		$this->assertFalse( RateLimit::inRange( '192.168.1.127', '192.168.1.128/25' ) );
		$this->assertTrue( RateLimit::inRange( '203.0.113.7', '203.0.113.7' ) );
		$this->assertTrue( RateLimit::inRange( '2400:cb00::1', '2400:cb00::/32' ) );
		$this->assertFalse( RateLimit::inRange( '2400:cb01::1', '2400:cb00::/32' ) );
		$this->assertFalse( RateLimit::inRange( '10.1.2.3', '2400:cb00::/32' ), 'families never match' );
		$this->assertFalse( RateLimit::inRange( '10.1.2.3', '10.0.0.0/abc' ) );
		$this->assertFalse( RateLimit::inRange( '10.1.2.3', 'garbage' ) );
	}

	public function testClientIpIgnoresForwardedForWithoutTrustedProxy(): void {
		$this->assertSame( '198.51.100.9', RateLimit::clientIp( '198.51.100.9', '1.2.3.4', array() ) );
		$this->assertSame( '198.51.100.9', RateLimit::clientIp( '198.51.100.9', '1.2.3.4', array( '10.0.0.0/8' ) ), 'REMOTE_ADDR not a trusted proxy' );
	}

	public function testClientIpBehindTrustedProxies(): void {
		$trusted = array( '10.0.0.0/8', '2400:cb00::/32' );

		$this->assertSame( '203.0.113.7', RateLimit::clientIp( '10.0.0.5', '203.0.113.7', $trusted ) );
		// Spoofed left-most entry is ignored: the right-most untrusted hop wins.
		$this->assertSame( '203.0.113.7', RateLimit::clientIp( '10.0.0.5', '6.6.6.6, 203.0.113.7, 10.0.0.9', $trusted ) );
		$this->assertSame( '2001:db8::1', RateLimit::clientIp( '2400:cb00::7', '2001:db8::1', $trusted ) );
		$this->assertSame( '10.0.0.5', RateLimit::clientIp( '10.0.0.5', null, $trusted ), 'no header → the proxy itself' );
		$this->assertSame( '10.0.0.9', RateLimit::clientIp( '10.0.0.5', 'unknown, 10.0.0.9', $trusted ), 'garbage stops the walk' );
	}
}
