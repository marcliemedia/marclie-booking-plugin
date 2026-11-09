<?php
/**
 * HR / EN language menu of the booking UI (`[mbk_lang_switch]`, Bricks `mbk-lang-switch`).
 *
 * Native driver: each link is the current URL with its whole query string (`apartman`, dates,
 * guests…) and only `lang` changed. When HR is already current the HR link is the clean URL
 * (the cookie already says HR); links carrying `lang` are `rel="nofollow"` (noindex targets).
 * WPML driver: the WPML permalink of the current URL in the other language.
 * The server reads `lang` and persists it in the `mbk_lang` cookie.
 * Markup = a header dropdown: `button.w-lang-switch__toggle` (globe, current code, caret) opening
 * `ul.w-lang-switch__menu` with one `a.w-lang-switch__option` per language (endonym, current one
 * `is-active` + check). Opening is the site's header-dropdown engine (`data-menu-toggle` /
 * `data-menu`, theme w-site.js initMenus); the wrapper carries `translate="no"` so page
 * translators keep the language names. Icons are `<i data-icon>` placeholders (theme icon filter).
 */

namespace Marclie\Booking\Frontend;

use Marclie\Booking\I18n\LangUrl;
use Marclie\Booking\I18n\LanguageAdapter;
use Marclie\Booking\I18n\NativeDriver;

defined( 'ABSPATH' ) || exit;

final class LanguageSwitcher {

	/** Classes of the wrapper (shortcode; the Bricks element takes its global classes). */
	public const WRAP_CLASS = 'w-lang-switch';

	/** Plugin language => short code on the toggle. */
	public const LABELS = array(
		'hr' => 'HR',
		'en' => 'EN',
	);

	/** Plugin language => its own name in the menu (never translated). */
	public const NAMES = array(
		'hr' => 'Hrvatski',
		'en' => 'English',
	);

	/**
	 * Hrefs per language for `$url` while `$current` is active (native driver, pure).
	 *
	 * @return array<string, string>
	 */
	public static function nativeLinks( string $url, string $current ): array {
		$links = array();
		foreach ( array_keys( self::LABELS ) as $lang ) {
			$links[ $lang ] = ( LanguageAdapter::DEFAULT === $lang && LanguageAdapter::DEFAULT === $current )
				? LangUrl::without( $url )
				: LangUrl::with( $url, $lang );
		}

		return $links;
	}

	/** Toggle + menu, for a wrapper that carries the classes and `translate="no"`. */
	public static function menu(): string {
		$current = LanguageAdapter::current();
		$url     = self::currentUrl();
		$native  = LanguageAdapter::driver() instanceof NativeDriver;
		$hrefs   = $native ? self::nativeLinks( $url, $current ) : array();
		$menuId  = wp_unique_id( 'mbk-lang-menu-' );

		$items = '';
		foreach ( self::NAMES as $lang => $name ) {
			$href   = $native ? $hrefs[ $lang ] : (string) apply_filters( 'wpml_permalink', LangUrl::without( $url ), $lang );
			$on     = $lang === $current;
			$items .= sprintf(
				'<li class="w-lang-switch__item"><a class="w-lang-switch__option%1$s" href="%2$s" lang="%3$s" hreflang="%3$s"%4$s%5$s>%6$s%7$s</a></li>',
				$on ? ' is-active' : '',
				esc_url( $href ),
				esc_attr( $lang ),
				$native && LangUrl::has( $href ) ? ' rel="nofollow"' : '',
				$on ? ' aria-current="true"' : '',
				esc_html( $name ),
				$on ? '<i data-icon="check" class="w-icon--small w-lang-switch__check"></i>' : ''
			);
		}

		return sprintf(
			'<button class="w-lang-switch__toggle" type="button" data-menu-toggle aria-controls="%1$s" aria-expanded="false"><i data-icon="globe" class="w-icon--small"></i><span class="w-visually-hidden">%2$s: </span><span class="w-lang-switch__current">%3$s</span><i data-icon="caret-down" class="w-icon--small w-lang-switch__caret"></i></button><ul class="w-lang-switch__menu" id="%1$s" data-menu hidden>%4$s</ul>',
			esc_attr( $menuId ),
			esc_html( self::groupLabel() ),
			esc_html( self::LABELS[ $current ] ?? strtoupper( $current ) ),
			$items
		);
	}

	/** Hidden prefix of the toggle's accessible name ("Language: HR"). */
	public static function groupLabel(): string {
		return __( 'Language', 'marclie-booking' );
	}

	/** Shortcode markup: wrapper + menu. `$class` replaces the wrapper class when given. */
	public static function render( string $class = '' ): string {
		$classes = '' !== trim( $class ) ? trim( $class ) : self::WRAP_CLASS;

		return sprintf(
			'<div class="%1$s" translate="no" data-mbk-widget data-mbk-lang-switch>%2$s</div>',
			esc_attr( $classes ),
			self::menu()
		);
	}

	/** Path + query of this request (relative URL: never trusts the Host header). */
	private static function currentUrl(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- escaped on output.

		return '' === $uri || '/' !== $uri[0] || str_starts_with( $uri, '//' ) ? '/' : $uri;
	}
}
