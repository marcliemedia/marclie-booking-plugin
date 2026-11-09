<?php
/**
 * Minimal WordPress function stubs for classes under test (not loaded in
 * WordPress). Translations come from `$GLOBALS['mbk_test_l10n']`
 * (msgid => msgstr, or msgid => [3 Croatian plural forms]); unset = source string.
 * Fired actions are recorded in `$GLOBALS['mbk_test_actions']` ([hook, args]).
 */

if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone(): DateTimeZone {
		return new DateTimeZone( 'Europe/Zagreb' );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		$t = $GLOBALS['mbk_test_l10n'][ $text ] ?? null;

		return is_string( $t ) ? $t : $text;
	}
}

if ( ! function_exists( '_x' ) ) {
	/** Looked up as "context\x04text", like a .mo file. */
	function _x( string $text, string $context, string $domain = 'default' ): string {
		$t = $GLOBALS['mbk_test_l10n'][ $context . "\x04" . $text ] ?? null;

		return is_string( $t ) ? $t : $text;
	}
}

if ( ! function_exists( '_n' ) ) {
	function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
		$t = $GLOBALS['mbk_test_l10n'][ $single ] ?? null;
		if ( is_array( $t ) ) {
			// Plural-Forms of languages/marclie-booking-hr.po.
			$n = $number;
			$i = ( 1 === $n % 10 && 11 !== $n % 100 ) ? 0 : ( ( $n % 10 >= 2 && $n % 10 <= 4 && ( $n % 100 < 10 || $n % 100 >= 20 ) ) ? 1 : 2 );

			return $t[ $i ];
		}

		return 1 === $number ? $single : $plural;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, mixed ...$args ): void {
		$GLOBALS['mbk_test_actions'][] = array( $hook, $args );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		return $value;
	}
}
