<?php
/**
 * Activation: schema, default settings, capabilities, role, cron.
 */

namespace Marclie\Booking\Install;

use Marclie\Booking\Account\Roles;
use Marclie\Booking\Cron;
use Marclie\Booking\Settings;

defined( 'ABSPATH' ) || exit;

final class Activator {

	public const CAP_BOOKINGS = 'mbk_manage_bookings';
	public const CAP_RATES    = 'mbk_manage_rates';
	public const CAP_SETTINGS = 'mbk_manage_settings';

	public const ROLE = 'booking_manager';

	public static function activate(): void {
		self::install();
	}

	/**
	 * plugins_loaded: re-runs install steps when the stored schema version differs
	 * (plugin updated by replacing files, without re-activation).
	 */
	public static function upgrade(): void {
		if ( get_option( Schema::VERSION_OPTION ) !== Schema::VERSION ) {
			self::install();
		}
	}

	/**
	 * Idempotent: schema + migrations, capabilities/role, cron, settings defaults merge.
	 */
	private static function install(): void {
		Schema::install();
		self::addCapabilities();
		Cron::schedule();
		// Adds new defaults, drops removed keys (option is sanitized through Settings::sanitize()).
		update_option( Settings::OPTION, Settings::sanitize( Settings::all() ) );
	}

	public static function addCapabilities(): void {
		Roles::register();

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array( self::CAP_BOOKINGS, self::CAP_RATES, self::CAP_SETTINGS ) as $cap ) {
				$admin->add_cap( $cap );
			}
		}

		$caps = array(
			'read'             => true,
			self::CAP_BOOKINGS => true,
			self::CAP_RATES    => true,
		);

		$role = get_role( self::ROLE );
		if ( ! $role ) {
			add_role( self::ROLE, 'Booking Manager', $caps );
			return;
		}

		foreach ( $caps as $cap => $grant ) {
			$role->add_cap( $cap, $grant );
		}
	}

	/**
	 * Reverse of addCapabilities(); used by uninstall.php when data is deleted.
	 */
	public static function removeCapabilities(): void {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array( self::CAP_BOOKINGS, self::CAP_RATES, self::CAP_SETTINGS ) as $cap ) {
				$admin->remove_cap( $cap );
			}
		}

		remove_role( self::ROLE );
		Roles::unregister();
	}
}
