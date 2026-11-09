<?php
/**
 * WP-CLI commands: `studio wp mbk …`.
 */

namespace Marclie\Booking\Cli;

use Marclie\Booking\Admin\AppPage;
use Marclie\Booking\Container;
use Marclie\Booking\Cron;
use Marclie\Booking\I18n\Formatter;
use Marclie\Booking\Install\Activator;
use Marclie\Booking\Install\Schema;
use Marclie\Booking\Payment\StripeConfig;
use Marclie\Booking\Plugin;
use Marclie\Booking\Rest\Admin\PingController;
use Marclie\Booking\Settings;

defined( 'ABSPATH' ) || exit;

final class Commands {

	private const OK   = 'OK';
	private const WARN = 'WARN';
	private const FAIL = 'FAIL';

	/**
	 * Checks tables, schema version, cron, timezone, Stripe keys, page map, REST and admin build.
	 *
	 * Exits non-zero when any check fails.
	 *
	 * ## EXAMPLES
	 *
	 *     studio wp mbk doctor
	 *
	 * @param list<string>          $args
	 * @param array<string, string> $assocArgs
	 */
	public function doctor( array $args, array $assocArgs ): void {
		$rows = array_merge(
			$this->checkTables(),
			array(
				$this->checkDbVersion(),
				$this->checkSchedule(),
			),
			$this->checkCron(),
			array(
				$this->checkTimezone(),
				$this->checkStripe(),
				$this->checkPages(),
				$this->checkRest(),
				$this->checkAdminBuild(),
				$this->checkIntl(),
				$this->checkIntlLocale(),
			)
		);

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'check', 'status', 'details' ) );

		$failed = count( array_filter( $rows, static fn( array $row ): bool => self::FAIL === $row['status'] ) );
		$warned = count( array_filter( $rows, static fn( array $row ): bool => self::WARN === $row['status'] ) );

		if ( $failed > 0 ) {
			\WP_CLI::error( sprintf( '%d check(s) failed, %d warning(s).', $failed, $warned ) );
		}

		\WP_CLI::success( sprintf( 'All checks passed, %d warning(s).', $warned ) );
	}

	/**
	 * Seeds the DIRECT5 coupon (prices are entered in admin › Prices).
	 *
	 * Idempotent: an existing coupon is kept unless --force.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Overwrite the existing coupon values.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mbk seed
	 *
	 * @param list<string>               $args
	 * @param array<string, string|bool> $assocArgs
	 */
	public function seed( array $args, array $assocArgs ): void {
		( new Seed( Container::couponRepository() ) )->run( $assocArgs );
	}

	/**
	 * Lists the booking-flow pages (Rezervacija, Hvala, Plaćanje, Pretraga,
	 * Uvjeti rezervacije) and their page-map entries.
	 *
	 * ## OPTIONS
	 *
	 * [--create]
	 * : Create missing pages (idempotent), publish adopted drafts and store their IDs in the page map.
	 *
	 * ## EXAMPLES
	 *
	 *     studio wp mbk pages
	 *     studio wp mbk pages --create
	 *
	 * @param list<string>          $args
	 * @param array<string, string> $assocArgs
	 */
	public function pages( array $args, array $assocArgs ): void {
		$create = (bool) \WP_CLI\Utils\get_flag_value( $assocArgs, 'create', false );
		$rows   = ( new Pages() )->run( $create );

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'key', 'id', 'path', 'status', 'action', 'url' ) );

		$missing = count( array_filter( $rows, static fn( array $row ): bool => 'publish' !== $row['status'] ) );
		if ( $create ) {
			\WP_CLI::success( 'Booking pages are in place and mapped.' );
		} elseif ( $missing ) {
			\WP_CLI::warning( sprintf( '%d page(s) missing or unpublished; run with --create.', $missing ) );
		}
	}

	/**
	 * Charges due balances off-session with the saved cards (what the daily
	 * cron does at 09:00), or lists them.
	 *
	 * ## OPTIONS
	 *
	 * [--date=<Y-m-d>]
	 * : Treat this day as today (balances due on or before it). Default: today.
	 *
	 * [--dry-run]
	 * : Only list what would be charged.
	 *
	 * ## EXAMPLES
	 *
	 *     studio wp mbk charge-balances --dry-run
	 *     studio wp mbk charge-balances --date=2027-06-28 --dry-run
	 *
	 * @subcommand charge-balances
	 * @param list<string>          $args
	 * @param array<string, string> $assocArgs
	 */
	public function chargeBalances( array $args, array $assocArgs ): void {
		$date = isset( $assocArgs['date'] ) ? (string) $assocArgs['date'] : null;
		if ( null !== $date && ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || false === \DateTimeImmutable::createFromFormat( '!Y-m-d', $date ) ) ) {
			\WP_CLI::error( 'Use --date=YYYY-MM-DD.' );
		}
		$dryRun = (bool) \WP_CLI\Utils\get_flag_value( $assocArgs, 'dry-run', false );
		if ( ! $dryRun && null === Container::paymentGateway() ) {
			\WP_CLI::error( 'Stripe is not configured (MBK_STRIPE_* constants in wp-config.php).' );
		}

		$rows = Container::balanceCharger()->run( $date, $dryRun );
		if ( ! $rows ) {
			\WP_CLI::success( 'No balances due.' );
			return;
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'booking_id', 'ref', 'amount_cents', 'attempt', 'action', 'result' ) );
		$charged = count( array_filter( $rows, static fn( array $r ): bool => 'succeeded' === $r['result'] ) );
		\WP_CLI::success( $dryRun ? sprintf( '%d booking(s) listed (dry run).', count( $rows ) ) : sprintf( '%d of %d balance(s) charged.', $charged, count( $rows ) ) );
	}

	/**
	 * @return array{check: string, status: string, details: string}
	 */
	private function row( string $check, string $status, string $details ): array {
		return array(
			'check'   => $check,
			'status'  => $status,
			'details' => $details,
		);
	}

	/**
	 * @return list<array{check: string, status: string, details: string}>
	 */
	private function checkTables(): array {
		$missing = Schema::missingTables();
		$total   = count( Schema::TABLES );
		if ( $missing ) {
			return array( $this->row( 'tables', self::FAIL, sprintf( '%d/%d present; missing: %s', $total - count( $missing ), $total, implode( ', ', $missing ) ) ) );
		}

		return array( $this->row( 'tables', self::OK, sprintf( '%d/%d present', $total, $total ) ) );
	}

	private function checkDbVersion(): array {
		$stored = get_option( Schema::VERSION_OPTION );

		return $stored === Schema::VERSION
			? $this->row( 'db version', self::OK, 'v' . Schema::VERSION )
			: $this->row( 'db version', self::FAIL, sprintf( 'stored %s, expected %s', var_export( $stored, true ), Schema::VERSION ) );
	}

	private function checkSchedule(): array {
		$schedules = wp_get_schedules();

		return isset( $schedules[ Cron::FIVE_MINUTES ] )
			? $this->row( 'cron schedule', self::OK, Cron::FIVE_MINUTES . ' = ' . $schedules[ Cron::FIVE_MINUTES ]['interval'] . 's' )
			: $this->row( 'cron schedule', self::FAIL, Cron::FIVE_MINUTES . ' not registered' );
	}

	/**
	 * @return list<array{check: string, status: string, details: string}>
	 */
	private function checkCron(): array {
		$crons = _get_cron_array();
		$rows  = array();

		foreach ( Cron::HOOKS as $hook ) {
			$count = 0;
			foreach ( $crons as $events ) {
				$count += isset( $events[ $hook ] ) ? count( $events[ $hook ] ) : 0;
			}

			$next = wp_next_scheduled( $hook );
			if ( ! $next ) {
				$rows[] = $this->row( 'cron ' . $hook, self::FAIL, 'not scheduled (re-activate the plugin)' );
				continue;
			}

			$at         = wp_date( 'Y-m-d H:i T', $next, wp_timezone() );
			$recurrence = wp_get_schedule( $hook );
			$details    = sprintf( 'next %s, %s', $at, false === $recurrence ? 'single event' : 'recurrence ' . $recurrence );

			if ( $count > 1 ) {
				$rows[] = $this->row( 'cron ' . $hook, self::WARN, sprintf( '%d events scheduled (duplicates); %s', $count, $details ) );
			} elseif ( Cron::DAILY === $hook && ( false !== $recurrence || sprintf( '%02d:00', Cron::DAILY_HOUR ) !== wp_date( 'H:i', $next, wp_timezone() ) ) ) {
				$rows[] = $this->row( 'cron ' . $hook, self::WARN, sprintf( 'expected a single event at %02d:00 site time; %s', Cron::DAILY_HOUR, $details ) );
			} else {
				$rows[] = $this->row( 'cron ' . $hook, self::OK, $details );
			}
		}

		return $rows;
	}

	private function checkTimezone(): array {
		$timezone = (string) get_option( 'timezone_string' );

		// A named zone (not a UTC offset) keeps the 09:00 cron and stay dates right across DST.
		return '' !== $timezone
			? $this->row( 'timezone', self::OK, $timezone )
			: $this->row( 'timezone', self::FAIL, 'timezone_string is empty (UTC offset); choose a city in Settings › General' );
	}

	private function checkStripe(): array {
		$keys    = StripeConfig::keys();
		$names   = array(
			'publishable' => StripeConfig::PUBLISHABLE,
			'secret'      => StripeConfig::SECRET,
			'webhook'     => StripeConfig::WEBHOOK,
		);
		$missing = array();
		foreach ( $names as $key => $constant ) {
			if ( ! $keys[ $key ]['set'] ) {
				$missing[] = $constant;
			}
		}

		if ( count( $missing ) === count( $names ) ) {
			return $this->row( 'stripe keys', self::WARN, 'not configured (define MBK_STRIPE_* in wp-config.php)' );
		}

		if ( $missing ) {
			return $this->row( 'stripe keys', self::FAIL, 'missing: ' . implode( ', ', $missing ) );
		}

		$problems = array();
		$masked   = array();
		foreach ( $names as $key => $constant ) {
			if ( ! StripeConfig::hasValidPrefix( $constant ) ) {
				$problems[] = sprintf( '%s should start with %s', $constant, implode( ' or ', StripeConfig::PREFIXES[ $constant ] ) );
			}
			if ( 'webhook' !== $key && null === $keys[ $key ]['mode'] ) {
				$problems[] = sprintf( '%s has no test/live mode prefix', $constant );
			}
			$masked[] = sprintf( '%s …%s', $constant, $keys[ $key ]['last4'] );
		}

		if ( StripeConfig::mismatch() ) {
			$problems[] = 'publishable and secret key modes differ (test vs live)';
		}

		$details = sprintf( 'mode %s; %s', StripeConfig::mode() ?? '?', implode( ', ', $masked ) );

		return $problems
			? $this->row( 'stripe keys', self::FAIL, implode( '; ', $problems ) . ' (' . $details . ')' )
			: $this->row( 'stripe keys', self::OK, $details );
	}

	private function checkPages(): array {
		$unset  = array();
		$broken = array();
		foreach ( Settings::pages() as $key => $pageId ) {
			if ( ! $pageId ) {
				$unset[] = $key;
				continue;
			}

			if ( 'page' !== get_post_type( $pageId ) || 'publish' !== get_post_status( $pageId ) ) {
				$broken[] = sprintf( '%s (#%d)', $key, $pageId );
			}
		}

		if ( $broken ) {
			return $this->row( 'page map', self::FAIL, 'not a published page: ' . implode( ', ', $broken ) );
		}

		return $unset
			? $this->row( 'page map', self::WARN, 'not set: ' . implode( ', ', $unset ) )
			: $this->row( 'page map', self::OK, 'all pages mapped' );
	}

	private function checkRest(): array {
		$route    = '/' . Plugin::REST_NAMESPACE . PingController::ROUTE;
		$previous = get_current_user_id();

		$admins = get_users(
			array(
				'capability' => Activator::CAP_BOOKINGS,
				'number'     => 1,
				'fields'     => 'ID',
			)
		);
		if ( ! $admins ) {
			return $this->row( 'rest ping', self::FAIL, 'no user with ' . Activator::CAP_BOOKINGS );
		}

		wp_set_current_user( (int) $admins[0] );
		$authed = rest_do_request( new \WP_REST_Request( 'GET', $route ) );

		wp_set_current_user( 0 );
		$anonymous = rest_do_request( new \WP_REST_Request( 'GET', $route ) );

		wp_set_current_user( $previous );

		$data = $authed->get_data();
		if ( 200 !== $authed->get_status() || empty( $data['ok'] ) ) {
			return $this->row( 'rest ping', self::FAIL, sprintf( '%s → HTTP %d', $route, $authed->get_status() ) );
		}

		if ( ! in_array( $anonymous->get_status(), array( 401, 403 ), true ) ) {
			return $this->row( 'rest ping', self::FAIL, sprintf( 'anonymous %s → HTTP %d (expected 401/403)', $route, $anonymous->get_status() ) );
		}

		return $this->row( 'rest ping', self::OK, sprintf( '%s → 200 (admin), %d (anonymous)', $route, $anonymous->get_status() ) );
	}

	private function checkAdminBuild(): array {
		$asset = AppPage::asset();

		return null === $asset
			? $this->row( 'admin build', self::WARN, 'build/admin missing — run npm run build in admin-app/' )
			: $this->row( 'admin build', self::OK, 'build/admin/index.js v' . $asset['version'] );
	}

	private function checkIntl(): array {
		return extension_loaded( 'intl' )
			? $this->row( 'php intl', self::OK, 'IntlDateFormatter available' )
			: $this->row( 'php intl', self::WARN, 'ext-intl missing; Formatter uses built-in month names' );
	}

	private function checkIntlLocale(): array {
		if ( ! extension_loaded( 'intl' ) ) {
			return $this->row( 'php intl hr locale', self::WARN, 'ext-intl missing; Formatter uses built-in month names' );
		}

		return Formatter::hasIntlLocale( 'hr' )
			? $this->row( 'php intl hr locale', self::OK, 'ICU has hr_HR data (' . Formatter::date( '2028-04-03', 'hr' ) . ')' )
			: $this->row( 'php intl hr locale', self::WARN, 'ICU lacks hr_HR data (e.g. Alpine icu-data-en); Formatter uses built-in month names' );
	}
}
