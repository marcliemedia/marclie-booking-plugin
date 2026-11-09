<?php
/**
 * Plugin Name:       Marclie Booking
 * Description:       Direct booking engine for short-term rentals: availability, monthly per-person pricing, Stripe deposits, iCal sync, guest CRM and the booking admin app.
 * Version:           0.1.0
 * Author:            Marclie Agency
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Text Domain:       marclie-booking
 * Domain Path:       /languages
 *
 * ACF Pro is a soft dependency (no "Requires Plugins" header on purpose): the
 * plugin keeps running without it and only shows an admin warning.
 */

defined( 'ABSPATH' ) || exit;

define( 'MBK_VERSION', '0.1.0' );
define( 'MBK_FILE', __FILE__ );
define( 'MBK_DIR', plugin_dir_path( __FILE__ ) );
define( 'MBK_URL', plugin_dir_url( __FILE__ ) );

if ( ! is_readable( MBK_DIR . 'vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Marclie Booking: Composer dependencies are missing. Run "composer install" in the plugin directory.', 'marclie-booking' )
			);
		}
	);
	return;
}

require_once MBK_DIR . 'vendor/autoload.php';
require_once MBK_DIR . 'src/functions.php';

register_activation_hook( MBK_FILE, array( \Marclie\Booking\Install\Activator::class, 'activate' ) );
register_deactivation_hook( MBK_FILE, array( \Marclie\Booking\Install\Deactivator::class, 'deactivate' ) );

\Marclie\Booking\Plugin::boot();
