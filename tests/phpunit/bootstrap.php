<?php
/**
 * PHPUnit bootstrap: composer autoload (domain classes are WordPress-free) plus
 * a few WordPress function stubs for the display classes.
 */

// Plugin files guard with `defined( 'ABSPATH' ) || exit;`.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require __DIR__ . '/wp-stubs.php';

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'Marclie\\Booking\\Tests\\';
		if ( str_starts_with( $class, $prefix ) ) {
			$file = __DIR__ . '/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
			if ( is_file( $file ) ) {
				require $file;
			}
		}
	}
);
