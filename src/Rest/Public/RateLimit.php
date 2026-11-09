<?php
/**
 * Rate limit arithmetic and client identification (pure): fixed windows,
 * client IP behind trusted proxies, IPv6 /64 buckets. Storage — a transient
 * per bucket and hashed client — lives in BaseController.
 */

namespace Marclie\Booking\Rest\Public;

defined( 'ABSPATH' ) || exit;

final class RateLimit {

	/**
	 * Counts one request.
	 *
	 * @param array{start:int, count:int}|null $state  Stored window, null = none.
	 * @param int                              $now    Unix time.
	 * @param int                              $limit  Requests allowed per window.
	 * @param int                              $window Window length in seconds.
	 * @return array{allowed:bool, state:array{start:int, count:int}, retry_after:int, ttl:int}
	 *         `state` to store for `ttl` seconds; `retry_after` > 0 only when refused.
	 */
	public static function hit( ?array $state, int $now, int $limit, int $window ): array {
		$start = (int) ( $state['start'] ?? 0 );
		$count = (int) ( $state['count'] ?? 0 );
		if ( null === $state || $now < $start || $now >= $start + $window ) {
			$start = $now;
			$count = 0;
		}

		$allowed = $count < $limit;
		if ( $allowed ) {
			++$count;
		}
		$remaining = max( 1, $start + $window - $now );

		return array(
			'allowed'     => $allowed,
			'state'       => array(
				'start' => $start,
				'count' => $count,
			),
			'retry_after' => $allowed ? 0 : $remaining,
			'ttl'         => $remaining,
		);
	}

	/**
	 * The client IP: REMOTE_ADDR, unless it is a trusted proxy — then the
	 * X-Forwarded-For chain is walked from the right (the hop closest to us)
	 * and the first address that is not a trusted proxy wins. Addresses a
	 * client could forge (left of an untrusted hop) are never used.
	 *
	 * @param list<string> $trustedProxies IPs or CIDRs.
	 */
	public static function clientIp( string $remoteAddr, ?string $forwardedFor, array $trustedProxies ): string {
		$remote = self::normalize( $remoteAddr );
		if ( '' === $remote || ! $trustedProxies || null === $forwardedFor || ! self::inAny( $remote, $trustedProxies ) ) {
			return $remote;
		}

		$client = $remote;
		foreach ( array_reverse( explode( ',', $forwardedFor ) ) as $hop ) {
			$ip = self::normalize( trim( $hop ) );
			if ( '' === $ip ) {
				break;
			}
			$client = $ip;
			if ( ! self::inAny( $ip, $trustedProxies ) ) {
				break;
			}
		}

		return $client;
	}

	/**
	 * Rate-limit bucket of an IP: the IPv4 address itself, or the /64 prefix of
	 * an IPv6 address (one subscriber usually owns a whole /64).
	 */
	public static function bucket( string $ip ): string {
		$ip = self::normalize( $ip );
		if ( ! str_contains( $ip, ':' ) ) {
			return $ip;
		}

		$prefix = substr( (string) inet_pton( $ip ), 0, 8 ) . str_repeat( "\0", 8 );

		return inet_ntop( $prefix ) . '/64';
	}

	/** Whether `$ip` is inside `$cidr` (an address or address/prefix, IPv4 or IPv6). */
	public static function inRange( string $ip, string $cidr ): bool {
		[ $net, $bits ] = array_pad( explode( '/', trim( $cidr ), 2 ), 2, null );
		$ip             = self::normalize( $ip );
		$net            = self::normalize( (string) $net );
		if ( '' === $ip || '' === $net || ( null !== $bits && ! ctype_digit( $bits ) ) ) {
			return false;
		}
		$ipBin  = (string) inet_pton( $ip );
		$netBin = (string) inet_pton( $net );
		if ( strlen( $ipBin ) !== strlen( $netBin ) ) {
			return false;
		}

		$max  = strlen( $ipBin ) * 8;
		$bits = null === $bits || '' === $bits ? $max : (int) $bits;
		if ( $bits < 0 || $bits > $max ) {
			return false;
		}

		$bytes = intdiv( $bits, 8 );
		if ( substr( $ipBin, 0, $bytes ) !== substr( $netBin, 0, $bytes ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}
		$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;

		return ( ord( $ipBin[ $bytes ] ) & $mask ) === ( ord( $netBin[ $bytes ] ) & $mask );
	}

	/** @param list<string> $ranges */
	private static function inAny( string $ip, array $ranges ): bool {
		foreach ( $ranges as $range ) {
			if ( self::inRange( $ip, $range ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Canonical form of a valid IP ('' when invalid); IPv4-mapped IPv6
	 * (::ffff:1.2.3.4) becomes the IPv4 address.
	 */
	private static function normalize( string $ip ): string {
		$ip = trim( $ip, " \t[]" );
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}
		if ( str_contains( $ip, ':' ) ) {
			$bin = (string) inet_pton( $ip );
			if ( str_starts_with( $bin, str_repeat( "\0", 10 ) . "\xff\xff" ) ) {
				return (string) inet_ntop( substr( $bin, 12 ) );
			}

			return (string) inet_ntop( $bin );
		}

		return $ip;
	}
}
