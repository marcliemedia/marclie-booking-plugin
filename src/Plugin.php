<?php
/**
 * Bootstrap: registers hooks per context (always / admin / REST / CLI) so each
 * request only loads the classes it needs (Composer autoloads lazily).
 */

namespace Marclie\Booking;

use Marclie\Booking\Admin\AppPage;
use Marclie\Booking\I18n\LanguageAdapter;
use Marclie\Booking\Install\Activator;
use Marclie\Booking\Payment\WebhookController;
use Marclie\Booking\Rest\Admin\Routes as AdminRoutes;
use Marclie\Booking\Rest\Public\Routes as PublicRoutes;
use Marclie\Booking\Service\AvailabilityCache;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public const REST_NAMESPACE = 'marclie-booking/v1';

	public const TEXT_DOMAIN = 'marclie-booking';

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		// Every context.
		Cron::hooks();
		add_action( 'plugins_loaded', array( Activator::class, 'upgrade' ) );
		add_action( 'init', array( self::class, 'loadTextdomain' ) );
		Settings::hooks();
		LanguageAdapter::hooks();
		add_action( Cron::REAP_HOLDS, array( self::class, 'reapHolds' ) );
		add_action( Cron::DAILY, array( self::class, 'daily' ) );
		add_action( Cron::HOURLY, array( self::class, 'hourly' ) );
		add_action( 'template_redirect', array( self::class, 'referrerPolicy' ) );
		add_filter( 'mbk_hold_reaper_should_expire', array( self::class, 'holdShouldExpire' ), 10, 2 );
		add_action( 'mbk_booking_status_changed', array( self::class, 'onBookingStatusChanged' ), 10, 3 );
		Email\Listeners::hooks();
		Account\Roles::hooks();
		add_action( Account\AccountMail::HOOK, array( Account\AccountMail::class, 'handle' ) );
		// Policy and services are cached per request; rebuild them after a settings save.
		add_action( 'update_option_' . Settings::OPTION, array( Container::class, 'reset' ), 10, 0 );
		AvailabilityCache::hooks();
		Frontend\Shortcodes::hooks();
		Bricks\Loader::hooks();
		Ical\SyncService::hooks();

		// REST (rest_api_init only fires on REST requests).
		add_action( 'rest_api_init', array( AdminRoutes::class, 'register' ) );
		add_action( 'rest_api_init', array( PublicRoutes::class, 'register' ) );
		add_action( 'rest_api_init', array( WebhookController::class, 'register' ) );

		if ( is_admin() ) {
			AppPage::hooks();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'mbk', Cli\Commands::class );
			\WP_CLI::add_command( 'mbk ical', Cli\Ical::class );
		}
	}

	public static function loadTextdomain(): void {
		load_plugin_textdomain( self::TEXT_DOMAIN, false, dirname( plugin_basename( MBK_FILE ) ) . '/languages' );
	}

	/** Cron `mbk_reap_holds`. */
	public static function reapHolds(): void {
		Container::holdReaper()->run();
	}

	/**
	 * Filter `mbk_hold_reaper_should_expire`: Stripe is asked before a checkout hold expires.
	 *
	 * @param bool                 $expire
	 * @param array<string, mixed> $booking
	 */
	public static function holdShouldExpire( $expire, array $booking ): bool {
		return Container::reconciler()->shouldExpire( (bool) $expire, $booking );
	}

	/** Cron `mbk_daily` (09:00 site time): due balances, reminder and pre-arrival emails, membership tiers and coupons, then activity log retention. */
	public static function daily(): void {
		try {
			Container::balanceCharger()->run();
		} finally {
			try {
				Container::emailScheduler()->run();
			} finally {
				try {
					Container::accounts()->runMembership();
				} finally {
					Container::eventRepository()->prune( Container::clock()->now() );
				}
			}
		}
	}

	/** Cron `mbk_hourly`: Stripe refunds left unsent. */
	public static function hourly(): void {
		Container::refunds()->retryUnsent( 'system:cron' );
	}

	/**
	 * `Referrer-Policy: no-referrer` on the booking-flow pages: their URLs carry
	 * the guest link token (`?ref=&t=`) and Stripe redirect parameters; on the
	 * account pages, the email verification token and the password reset key.
	 */
	public static function referrerPolicy(): void {
		if ( ! is_page() || headers_sent() ) {
			return;
		}
		$id = (int) get_queried_object_id();
		// WPML: compare the Croatian original of a translated page.
		$original = (int) apply_filters( 'wpml_object_id', $id, 'page', true, 'hr' );
		foreach ( array( 'rezervacija', 'hvala', 'placanje', 'moj_racun', 'zaboravljena_lozinka' ) as $key ) {
			$pageId = Settings::pageId( $key );
			if ( $pageId > 0 && in_array( $pageId, array( $id, $original ), true ) ) {
				header( 'Referrer-Policy: no-referrer' );
				return;
			}
		}
	}

	/**
	 * Keeps guest statistics in sync with booking statuses.
	 *
	 * @param array<string, mixed> $booking
	 * @param mixed                $from
	 * @param mixed                $to
	 */
	public static function onBookingStatusChanged( array $booking, $from, $to ): void {
		Container::guests()->onStatusChanged( $booking, $from, $to );
	}
}
