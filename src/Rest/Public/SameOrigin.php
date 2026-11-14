<?php
/**
 * Same-origin check for the anonymous routes that sign a visitor in (login,
 * email verification, password reset): without it a foreign page could post
 * its own credentials and sign the visitor into the attacker's account
 * (login CSRF). Browsers send `Sec-Fetch-Site` and `Origin` with every
 * cross-site POST; requests without either (server-to-server, old browsers)
 * carry no victim cookie and pass.
 */

namespace Marclie\Booking\Rest\Public;

defined( 'ABSPATH' ) || exit;

final class SameOrigin {

	/**
	 * @param string|null $fetchSite `Sec-Fetch-Site` header.
	 * @param string|null $origin    `Origin` header.
	 * @param string      $host      `Host` header of the request (host[:port]).
	 */
	public static function allowed( ?string $fetchSite, ?string $origin, string $host ): bool {
		$fetchSite = strtolower( trim( (string) $fetchSite ) );
		if ( '' !== $fetchSite && ! in_array( $fetchSite, array( 'same-origin', 'none' ), true ) ) {
			return false;
		}

		$origin = trim( (string) $origin );
		if ( '' === $origin ) {
			return true;
		}

		$parts = wp_parse_url( $origin );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false; // `Origin: null` (sandboxed frame, data: URL) or garbage.
		}
		$authority = $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );

		return '' !== $host && strtolower( $authority ) === strtolower( trim( $host ) );
	}
}
