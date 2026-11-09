<?php
/**
 * Booking-UI language (hr|en), independent of the site locale.
 *
 * Driver: WpmlDriver when WPML is active, NativeDriver otherwise.
 *
 * Only the `marclie-booking` text domain follows the visitor language; theme/Bricks
 * content keeps the site locale. Since WP 6.7 plugin translations are loaded
 * just-in-time with determine_locale() and core no longer applies the
 * `plugin_locale` filter, so the switch hooks `pre_load_textdomain` for our
 * domain only. Emails/cron use withLanguage() (switch_to_locale + restore).
 */

namespace Marclie\Booking\I18n;

defined( 'ABSPATH' ) || exit;

final class LanguageAdapter {

	public const DOMAIN = 'marclie-booking';

	public const DEFAULT = 'hr';

	/** Plugin language => WordPress locale. EN uses the source strings. */
	public const LOCALES = array(
		'hr' => 'hr',
		'en' => 'en_US',
	);

	private static ?LanguageDriver $driver = null;

	/** Language forced by withLanguage(), if any. */
	private static ?string $forced = null;

	public static function hooks(): void {
		add_filter( 'pre_load_textdomain', array( self::class, 'filterPreLoadTextdomain' ), 10, 4 );
		add_action( 'plugins_loaded', array( self::class, 'onPluginsLoaded' ) );
	}

	/**
	 * All plugins are loaded (WPML loads after us alphabetically), so the driver can be chosen.
	 */
	public static function onPluginsLoaded(): void {
		$driver = self::driver();
		if ( $driver instanceof NativeDriver && ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			add_action( 'init', array( $driver, 'persistQueryLanguage' ), 1 );
		}
	}

	/**
	 * Cached only once plugins_loaded has fired; earlier calls get a fresh, uncached driver.
	 */
	public static function driver(): LanguageDriver {
		if ( null !== self::$driver ) {
			return self::$driver;
		}

		$driver = defined( 'ICL_SITEPRESS_VERSION' ) ? new WpmlDriver() : new NativeDriver();
		if ( did_action( 'plugins_loaded' ) ) {
			self::$driver = $driver;
		}

		return $driver;
	}

	/**
	 * @return string `hr` or `en`.
	 */
	public static function current(): string {
		return self::$forced ?? self::driver()->current();
	}

	public static function pageUrl( string $pageKey, string $lang ): ?string {
		return self::driver()->pageUrl( $pageKey, self::normalize( $lang ) ?? self::DEFAULT );
	}

	/**
	 * Runs $callback with WordPress and the plugin text domain switched to $lang
	 * (emails, cron), restoring the previous state afterwards.
	 *
	 * @template T
	 * @param callable(): T $callback
	 * @return T
	 */
	public static function withLanguage( string $lang, callable $callback ): mixed {
		$lang   = self::normalize( $lang ) ?? self::DEFAULT;
		$forced = self::$forced;

		self::$forced = $lang;
		$switched     = switch_to_locale( self::LOCALES[ $lang ] );
		if ( ! $switched ) {
			// Locale unchanged (already active or not installed): still reload our domain.
			unload_textdomain( self::DOMAIN, true );
		}

		try {
			return self::driver()->withLanguage( $lang, $callback );
		} finally {
			self::$forced = $forced;
			if ( $switched ) {
				restore_previous_locale();
			}
			unload_textdomain( self::DOMAIN, true );
		}
	}

	/**
	 * Normalizes `hr`, `en`, `en_US`, `hr-HR`… to `hr|en`; null when unsupported.
	 *
	 * @param mixed $value
	 */
	public static function normalize( $value ): ?string {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		$code = strtolower( substr( $value, 0, 2 ) );

		return isset( self::LOCALES[ $code ] ) ? $code : null;
	}

	/**
	 * Swaps translations of the plugin domain when the plugin language differs
	 * from the locale WordPress is loading.
	 *
	 * @param bool|null   $loaded
	 * @param string      $domain
	 * @param string      $mofile
	 * @param string|null $locale
	 * @return bool|null
	 */
	public static function filterPreLoadTextdomain( $loaded, $domain, $mofile, $locale ) {
		if ( null !== $loaded || self::DOMAIN !== $domain ) {
			return $loaded;
		}

		$lang = self::pluginLanguage();
		if ( null === $lang || self::normalize( $locale ? $locale : determine_locale() ) === $lang ) {
			return null;
		}

		global $l10n;

		if ( 'en' !== $lang ) {
			$file = self::translationFile( self::LOCALES[ $lang ] );
			if ( $file ) {
				$mo = new \MO();
				if ( $mo->import_from_file( $file ) ) {
					$l10n[ $domain ] = $mo;
					return true;
				}
			}
		}

		// EN (source strings) or missing translation file.
		$l10n[ $domain ] = new \NOOP_Translations();

		return true;
	}

	/**
	 * Plugin language for the current request; null = follow WordPress (admin, REST
	 * calls from the admin app with `_locale=user`, cron, CLI).
	 */
	private static function pluginLanguage(): ?string {
		if ( null !== self::$forced ) {
			return self::$forced;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$userLocale = isset( $_GET['_locale'] ) && 'user' === $_GET['_locale'];
		if ( is_admin() || $userLocale || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return null;
		}

		return self::current();
	}

	private static function translationFile( string $locale ): ?string {
		$name       = self::DOMAIN . '-' . $locale . '.mo';
		$candidates = array( WP_LANG_DIR . '/plugins/' . $name );
		if ( defined( 'MBK_DIR' ) ) {
			$candidates[] = MBK_DIR . 'languages/' . $name;
		}

		foreach ( $candidates as $file ) {
			if ( is_readable( $file ) ) {
				return $file;
			}
		}

		return null;
	}
}
