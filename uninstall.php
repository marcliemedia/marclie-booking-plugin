<?php
/**
 * Uninstall: data is removed ONLY when Settings → advanced → delete_data_on_uninstall is on.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

use Marclie\Booking\Cron;
use Marclie\Booking\Install\Activator;
use Marclie\Booking\Install\Schema;
use Marclie\Booking\Settings;

Cron::clear();

if ( ! Settings::deleteDataOnUninstall() ) {
	return;
}

Schema::drop();
Activator::removeCapabilities();

delete_option( Settings::OPTION );
delete_option( 'mbk_stripe_status' );
delete_option( 'mbk_dummy_password_hash' );
