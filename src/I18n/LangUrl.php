<?php
/**
 * Pure URL helpers for the native `?lang=` switch (WordPress-free, unit tested).
 *
 * The query string is edited as raw `key=value` pairs, so every other parameter keeps
 * its order and encoding (`apartman`, dates, guests, arrays…); only `lang` changes.
 */

namespace Marclie\Booking\I18n;

defined( 'ABSPATH' ) || exit;

final class LangUrl {

	/** `$url` with `lang=$lang` (replacing any previous `lang`). */
	public static function with( string $url, string $lang ): string {
		[ $base, $pairs, $fragment ] = self::split( $url );
		$pairs[]                     = NativeDriver::QUERY_ARG . '=' . rawurlencode( $lang );

		return self::join( $base, $pairs, $fragment );
	}

	/** `$url` without any `lang` parameter. */
	public static function without( string $url ): string {
		[ $base, $pairs, $fragment ] = self::split( $url );

		return self::join( $base, $pairs, $fragment );
	}

	/** Whether the query string of `$url` carries `lang`. */
	public static function has( string $url ): bool {
		$query = (string) parse_url( $url, PHP_URL_QUERY ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure helper.
		foreach ( '' === $query ? array() : explode( '&', $query ) as $pair ) {
			if ( self::isLang( $pair ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array{0: string, 1: list<string>, 2: string} Base (scheme…path), query pairs without `lang`, fragment (with `#`).
	 */
	private static function split( string $url ): array {
		$fragment = '';
		$hash     = strpos( $url, '#' );
		if ( false !== $hash ) {
			$fragment = substr( $url, $hash );
			$url      = substr( $url, 0, $hash );
		}

		$query = '';
		$mark  = strpos( $url, '?' );
		if ( false !== $mark ) {
			$query = substr( $url, $mark + 1 );
			$url   = substr( $url, 0, $mark );
		}

		$pairs = array();
		foreach ( '' === $query ? array() : explode( '&', $query ) as $pair ) {
			if ( '' !== $pair && ! self::isLang( $pair ) ) {
				$pairs[] = $pair;
			}
		}

		return array( $url, $pairs, $fragment );
	}

	/** @param list<string> $pairs */
	private static function join( string $base, array $pairs, string $fragment ): string {
		return $base . ( $pairs ? '?' . implode( '&', $pairs ) : '' ) . $fragment;
	}

	private static function isLang( string $pair ): bool {
		$eq = strpos( $pair, '=' );

		return NativeDriver::QUERY_ARG === urldecode( false === $eq ? $pair : substr( $pair, 0, $eq ) );
	}
}
