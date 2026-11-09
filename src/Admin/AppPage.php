<?php
/**
 * Top-level "Rezervacije" menu and mount point of the React admin app.
 *
 * Assets are enqueued only on this page so other wp-admin screens stay untouched.
 */

namespace Marclie\Booking\Admin;

use Marclie\Booking\Install\Activator;
use Marclie\Booking\Plugin;

defined( 'ABSPATH' ) || exit;

final class AppPage {

	public const SLUG = 'marclie-booking';

	public const HANDLE = 'mbk-admin-app';

	private static string $hookSuffix = '';

	public static function hooks(): void {
		add_action( 'admin_menu', array( self::class, 'registerMenu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'admin_notices', array( self::class, 'notices' ) );
	}

	public static function registerMenu(): void {
		self::$hookSuffix = (string) add_menu_page(
			__( 'Bookings', 'marclie-booking' ),
			__( 'Bookings', 'marclie-booking' ),
			Activator::CAP_BOOKINGS,
			self::SLUG,
			array( self::class, 'render' ),
			'dashicons-calendar-alt',
			26
		);
	}

	public static function render(): void {
		echo '<div id="mbk-app" class="mbk-app"></div>';
	}

	/**
	 * Build artefact paths, or null when the admin app has not been built.
	 *
	 * @return array{dependencies: list<string>, version: string}|null
	 */
	public static function asset(): ?array {
		$assetFile = MBK_DIR . 'build/admin/index.asset.php';
		if ( ! is_readable( $assetFile ) || ! is_readable( MBK_DIR . 'build/admin/index.js' ) ) {
			return null;
		}

		$asset = require $assetFile;
		if ( ! is_array( $asset ) ) {
			return null;
		}

		return array(
			'dependencies' => array_values( (array) ( $asset['dependencies'] ?? array() ) ),
			'version'      => (string) ( $asset['version'] ?? MBK_VERSION ),
		);
	}

	public static function enqueue( string $hookSuffix ): void {
		if ( '' === self::$hookSuffix || $hookSuffix !== self::$hookSuffix ) {
			return;
		}

		$asset = self::asset();
		if ( null === $asset ) {
			return;
		}

		wp_enqueue_script(
			self::HANDLE,
			MBK_URL . 'build/admin/index.js',
			$asset['dependencies'],
			$asset['version'],
			array( 'in_footer' => true )
		);

		$config = array(
			'restRoot'      => esc_url_raw( rest_url() ),
			'restNamespace' => Plugin::REST_NAMESPACE,
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			'version'       => MBK_VERSION,
			'locale'        => get_user_locale(),
			'adminUrl'      => admin_url(),
		);
		wp_add_inline_script( self::HANDLE, 'window.mbkAdmin = ' . wp_json_encode( $config ) . ';', 'before' );
		wp_set_script_translations( self::HANDLE, 'marclie-booking', MBK_DIR . 'languages' );
		self::addChunkTranslations();

		if ( is_readable( MBK_DIR . 'build/admin/index.css' ) ) {
			wp_enqueue_style( self::HANDLE, MBK_URL . 'build/admin/index.css', array(), $asset['version'] );
			if ( is_readable( MBK_DIR . 'build/admin/index-rtl.css' ) ) {
				wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
			}
		}
	}

	/**
	 * Lazy-loaded chunks (build/admin/*.js besides index.js) are not enqueued, so
	 * WordPress never loads their translations. Their make-json files
	 * (`marclie-booking-{locale}-{md5(build/admin/<chunk>.js)}.json`) are merged
	 * into the `marclie-booking` domain before the app boots.
	 */
	private static function addChunkTranslations(): void {
		$locale = determine_locale();
		if ( 'en_US' === $locale ) {
			return;
		}

		$translations = array();
		foreach ( glob( MBK_DIR . 'build/admin/*.js' ) ?: array() as $path ) {
			$relative = 'build/admin/' . basename( $path );
			if ( 'build/admin/index.js' === $relative ) {
				continue; // Loaded by wp_set_script_translations().
			}

			$name = sprintf( '%s-%s-%s.json', Plugin::TEXT_DOMAIN, $locale, md5( $relative ) );
			foreach ( array( WP_LANG_DIR . '/plugins/' . $name, MBK_DIR . 'languages/' . $name ) as $file ) {
				$json = load_script_translations( $file, self::HANDLE, Plugin::TEXT_DOMAIN );
				if ( is_string( $json ) && '' !== $json ) {
					$translations[] = $json;
					break;
				}
			}
		}

		if ( ! $translations ) {
			return;
		}

		wp_add_inline_script(
			self::HANDLE,
			sprintf(
				'( function( domain, list ) { list.forEach( function( t ) { var d = t.locale_data[ domain ] || t.locale_data.messages; d[""].domain = domain; wp.i18n.setLocaleData( d, domain ); } ); } )( %s, [ %s ] );',
				wp_json_encode( Plugin::TEXT_DOMAIN ),
				implode( ', ', $translations )
			),
			'before'
		);
	}

	public static function notices(): void {
		if ( ! class_exists( 'ACF' ) && current_user_can( Activator::CAP_BOOKINGS ) ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'Marclie Booking: Advanced Custom Fields PRO is not active. Apartment data and business details may be incomplete.', 'marclie-booking' )
			);
		}

		$screen = get_current_screen();
		if ( $screen && $screen->id === self::$hookSuffix && null === self::asset() ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'The admin app is not built — run npm run build in admin-app/.', 'marclie-booking' )
			);
		}
	}
}
