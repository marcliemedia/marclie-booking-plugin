<?php
/**
 * Frontend widget assets: registered once, enqueued only when a widget renders.
 *
 * Classic deferred footer scripts (not script modules) because wp_set_script_translations()
 * only feeds classic handles; files are plain IIFEs sharing the `window.mbk` namespace.
 * Script translations follow the booking language (LanguageAdapter), not the site locale.
 */

namespace Marclie\Booking\Frontend;

use Marclie\Booking\Container;
use Marclie\Booking\I18n\LanguageAdapter;
use Marclie\Booking\Plugin;
use Marclie\Booking\Payment\StripeConfig;
use Marclie\Booking\Settings;

defined( 'ABSPATH' ) || exit;

final class Assets {

	public const STYLE = 'mbk-base';

	/** Script handle => [file, dependencies]. */
	private const SCRIPTS = array(
		'mbk-front'        => array( 'front.js', array( 'wp-i18n' ) ),
		'mbk-date-range'   => array( 'date-range.js', array( 'mbk-front' ) ),
		'mbk-calendar'     => array( 'calendar.js', array( 'mbk-date-range' ) ),
		'mbk-bookbox'      => array( 'bookbox.js', array( 'mbk-date-range' ) ),
		'mbk-search'       => array( 'search.js', array( 'mbk-date-range' ) ),
		'mbk-checkout'     => array( 'checkout.js', array( 'mbk-date-range', self::PHONE ) ),
		'mbk-confirmation' => array( 'confirmation.js', array( 'mbk-front' ) ),
		'mbk-pay-balance'  => array( 'pay-balance.js', array( 'mbk-front' ) ),
		'mbk-account'      => array( 'account.js', array( 'mbk-front' ) ),
	);

	/** Stripe.js (must load from js.stripe.com, unversioned); enqueued only by the payment pages. */
	public const STRIPE_JS = 'mbk-stripe-js';

	/**
	 * Phone field with country flags (intl-tel-input 29.5.10, MIT, `assets/vendor/intl-tel-input`): script and
	 * stylesheet share this handle; `mbk-phone` (assets/css/mbk-phone.css) fits it to the site's fields.
	 */
	public const PHONE = 'mbk-iti';

	private const PHONE_DIR = 'assets/vendor/intl-tel-input/';

	/** Scripts that need the phone stylesheets. */
	private const PHONE_SCRIPTS = array( 'mbk-checkout' );

	/** Handles that take payments (account: card update): Stripe.js joins them when the keys are configured. */
	private const PAYMENT_SCRIPTS = array( 'mbk-checkout', 'mbk-pay-balance', 'mbk-account' );

	/** Shortcodes whose presence in post content warrants the stylesheet in <head>. */
	private const SHORTCODES = array( 'mbk_bookbox', 'mbk_calendar', 'mbk_bookbar', 'mbk_search_form', 'mbk_search_results', 'mbk_checkout', 'mbk_confirmation', 'mbk_pay_balance', 'mbk_login', 'mbk_register', 'mbk_forgot_password', 'mbk_account' );

	private static bool $registered = false;

	/** Handles of widgets that load on first use (header search dialog), see lazy(). */
	private static array $lazy = array();

	/**
	 * Inline loader of the lazy bundle: `window.mbkLazyLoad()` (idempotent, returns a Promise)
	 * inserts the `<template id="mbk-lazy-assets">` tags one after another (external scripts
	 * wait for `load`, so order and inline translations/config are kept). It also runs on the
	 * first focus inside a `[data-mbk-lazy]` form; themes may call it earlier (dialog opener).
	 */
	private const LAZY_LOADER = '(function(){var t=document.getElementById("mbk-lazy-assets"),p=null;if(!t)return;'
		. 'window.mbkLazyLoad=function(){if(p)return p;p=Array.prototype.reduce.call(t.content.querySelectorAll("script,link"),function(c,n){return c.then(function(){return new Promise(function(r){'
		. 'var e=document.createElement(n.tagName),i;for(i=0;i<n.attributes.length;i++)e.setAttribute(n.attributes[i].name,n.attributes[i].value);'
		. 'if(n.tagName==="SCRIPT"&&!n.src){e.text=n.text;document.body.appendChild(e);r();return;}e.onload=e.onerror=function(){r();};(n.tagName==="LINK"?document.head:document.body).appendChild(e);});});},Promise.resolve());return p;};'
		. 'document.addEventListener("focusin",function f(e){if(e.target.closest&&e.target.closest("[data-mbk-lazy]")){document.removeEventListener("focusin",f,true);window.mbkLazyLoad();}},true);})();';

	public static function hooks(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueueStyleEarly' ), 20 );
		add_filter( 'load_script_translation_file', array( self::class, 'filterTranslationFile' ), 10, 3 );
		add_action( 'wp_footer', array( self::class, 'printLazy' ), 19 );
	}

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		wp_register_style( self::STYLE, MBK_URL . 'assets/css/mbk-base.css', array(), self::version( 'assets/css/mbk-base.css' ) );

		foreach ( self::SCRIPTS as $handle => [ $file, $deps ] ) {
			wp_register_script(
				$handle,
				MBK_URL . 'assets/js/' . $file,
				$deps,
				self::version( 'assets/js/' . $file ),
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
			wp_set_script_translations( $handle, Plugin::TEXT_DOMAIN, MBK_DIR . 'languages' );
		}

		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Stripe requires the unversioned URL.
		wp_register_script(
			self::STRIPE_JS,
			'https://js.stripe.com/v3/',
			array(),
			null,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_register_script(
			self::PHONE,
			MBK_URL . self::PHONE_DIR . 'js/intlTelInput.min.js',
			array(),
			self::version( self::PHONE_DIR . 'js/intlTelInput.min.js' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_register_style( self::PHONE, MBK_URL . self::PHONE_DIR . 'css/intlTelInput.min.css', array(), self::version( self::PHONE_DIR . 'css/intlTelInput.min.css' ) );
		// Unlayered (unlike mbk-base), so it beats the library's own unlayered rules.
		wp_register_style( 'mbk-phone', MBK_URL . 'assets/css/mbk-phone.css', array( self::PHONE ), self::version( 'assets/css/mbk-phone.css' ) );

		wp_add_inline_script( 'mbk-front', 'window.mbkFront=' . wp_json_encode( self::config() ) . ';', 'before' );
	}

	/**
	 * Called by Renderer while a widget renders (scripts land in the footer).
	 */
	public static function enqueue( string ...$handles ): void {
		self::register();
		wp_enqueue_style( self::STYLE );
		foreach ( $handles as $handle ) {
			if ( in_array( $handle, self::PAYMENT_SCRIPTS, true ) && StripeConfig::configured() ) {
				wp_enqueue_script( self::STRIPE_JS );
			}
			if ( in_array( $handle, self::PHONE_SCRIPTS, true ) ) {
				wp_enqueue_style( 'mbk-phone' );
			}
			wp_enqueue_script( $handle );
		}
	}

	/**
	 * A widget whose scripts load on first use (header search dialog on every page): only the
	 * stylesheet is enqueued now; the scripts become the lazy bundle printed by printLazy().
	 */
	public static function lazy( string ...$handles ): void {
		self::register();
		wp_enqueue_style( self::STYLE );
		foreach ( $handles as $handle ) {
			self::$lazy[ $handle ] = true;
		}
	}

	/**
	 * Footer (before the footer scripts print): the lazy handles not loaded by a real widget of
	 * the page, with every dependency not already printed or queued, as inert tags in a
	 * `<template>` + the inline loader. Nothing when a widget on the page loads them anyway.
	 */
	public static function printLazy(): void {
		$handles = array_values( array_filter( array_keys( self::$lazy ), static fn( string $h ): bool => ! wp_script_is( $h, 'enqueued' ) && ! wp_script_is( $h, 'done' ) ) );
		if ( ! $handles ) {
			return;
		}

		$scripts = wp_scripts();
		$queued  = clone $scripts;
		$queued->all_deps( $queued->queue );

		// A copy, so the page's own queue and `done` list stay untouched.
		$bundle        = clone $scripts;
		$bundle->done  = array_values( array_unique( array_merge( $scripts->done, $queued->to_do ) ) );
		$bundle->to_do = array();
		ob_start();
		$bundle->do_items( $handles );
		$html = trim( (string) ob_get_clean() );
		if ( '' === $html ) {
			return;
		}

		echo '<template id="mbk-lazy-assets">' . $html . '</template>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- script tags printed by WP_Scripts.
		wp_print_inline_script_tag( self::LAZY_LOADER );
	}

	/**
	 * Prints the stylesheet in <head> (no flash of an unstyled calendar) when this request is
	 * expected to render widgets: a unit page, a mapped booking page, or content with a widget
	 * shortcode. Templates rendering widgets elsewhere (Bricks) opt in via `mbk_enqueue_front_assets`.
	 */
	public static function enqueueStyleEarly(): void {
		if ( (bool) apply_filters( 'mbk_enqueue_front_assets', self::willRenderWidgets() ) ) {
			self::register();
			wp_enqueue_style( self::STYLE );
		}
	}

	private static function willRenderWidgets(): bool {
		if ( is_singular( 'property' ) ) {
			return true;
		}
		$pages = array_filter( Settings::pages() );
		if ( $pages && is_page( array_values( $pages ) ) ) {
			return true;
		}

		$post = is_singular() ? get_post() : null;
		if ( ! $post instanceof \WP_Post || ! str_contains( $post->post_content, '[mbk_' ) ) {
			return false;
		}
		foreach ( self::SHORTCODES as $tag ) {
			if ( has_shortcode( $post->post_content, $tag ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * JS translations follow the booking language: EN uses the source strings,
	 * HR loads the `hr` JSON even when the site locale differs.
	 *
	 * @param string|false $file
	 * @param string       $handle
	 * @param string       $domain
	 * @return string|false
	 */
	public static function filterTranslationFile( $file, $handle, $domain ) {
		if ( Plugin::TEXT_DOMAIN !== $domain || ! isset( self::SCRIPTS[ $handle ] ) || ! is_string( $file ) || is_admin() ) {
			return $file;
		}

		$lang = LanguageAdapter::current();
		if ( 'en' === $lang ) {
			return false;
		}

		$siteLocale = determine_locale();
		$target     = LanguageAdapter::LOCALES[ $lang ];

		return $siteLocale === $target ? $file : str_replace( '-' . $siteLocale . '-', '-' . $target . '-', $file );
	}

	/**
	 * `window.mbkFront` (printed once, before the first widget script).
	 *
	 * @return array<string, mixed>
	 */
	public static function config(): array {
		$lang = LanguageAdapter::current();

		return array(
			'restRoot'      => esc_url_raw( rest_url( Plugin::REST_NAMESPACE . '/' ) ),
			'lang'          => $lang,
			'locale'        => 'en' === $lang ? 'en-GB' : 'hr-HR',
			'timeZone'      => wp_timezone_string(),
			'currency'      => 'EUR',
			'pages'         => array(
				'rezervacija' => Renderer::pageUrl( 'rezervacija', $lang ),
				'hvala'       => Renderer::pageUrl( 'hvala', $lang ),
				'placanje'    => Renderer::pageUrl( 'placanje', $lang ),
				'pretraga'    => Renderer::pageUrl( 'pretraga', $lang ),
				'kontakt'     => Renderer::contactUrl( $lang ),
			),
			'defaultCoupon' => Container::policy()->defaultCoupon,
			// libphonenumber for the phone field, loaded with import() after the page (≈ 260 KB).
			'phoneUtils'    => MBK_URL . self::PHONE_DIR . 'js/utils.js?ver=' . self::version( self::PHONE_DIR . 'js/utils.js' ),
			'account'       => Container::accounts()->frontConfig(),
			'stripe'        => array(
				'publishableKey' => StripeConfig::configured() ? StripeConfig::publishableKey() : null,
				'mode'           => StripeConfig::mode(),
			),
		);
	}

	private static function version( string $relative ): string {
		$mtime = @filemtime( MBK_DIR . $relative ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- missing file falls back to the plugin version.

		return $mtime ? MBK_VERSION . '.' . $mtime : MBK_VERSION;
	}
}
