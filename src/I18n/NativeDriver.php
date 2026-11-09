<?php
/**
 * Default driver (no multilingual plugin): `?lang=` → cookie `mbk_lang` → `hr`.
 *
 * Never guesses from Accept-Language, so crawlers always get HR. EN pages use the
 * HR URL plus `lang=en`.
 */

namespace Marclie\Booking\I18n;

use Marclie\Booking\Settings;

defined( 'ABSPATH' ) || exit;

final class NativeDriver implements LanguageDriver {

	public const COOKIE = 'mbk_lang';

	public const QUERY_ARG = 'lang';

	public function current(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only language switch.
		$fromQuery = LanguageAdapter::normalize( isset( $_GET[ self::QUERY_ARG ] ) ? wp_unslash( $_GET[ self::QUERY_ARG ] ) : null );
		if ( null !== $fromQuery ) {
			return $fromQuery;
		}

		$fromCookie = LanguageAdapter::normalize( isset( $_COOKIE[ self::COOKIE ] ) ? wp_unslash( $_COOKIE[ self::COOKIE ] ) : null );

		return $fromCookie ?? LanguageAdapter::DEFAULT;
	}

	/**
	 * Persists `?lang=` into the cookie (1 year, SameSite=Lax, strictly necessary). Runs on `init`, frontend only.
	 */
	public function persistQueryLanguage(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only language switch.
		$lang = LanguageAdapter::normalize( isset( $_GET[ self::QUERY_ARG ] ) ? wp_unslash( $_GET[ self::QUERY_ARG ] ) : null );
		if ( null === $lang || headers_sent() ) {
			return;
		}

		if ( ( $_COOKIE[ self::COOKIE ] ?? null ) === $lang ) {
			return;
		}

		setcookie(
			self::COOKIE,
			$lang,
			array(
				'expires'  => time() + YEAR_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		$_COOKIE[ self::COOKIE ] = $lang;
	}

	public function pageUrl( string $pageKey, string $lang ): ?string {
		$pageId = Settings::pageId( $pageKey );
		$url    = $pageId ? get_permalink( $pageId ) : false;
		if ( ! $url ) {
			return null;
		}

		return 'en' === $lang ? add_query_arg( self::QUERY_ARG, 'en', $url ) : $url;
	}

	public function withLanguage( string $lang, callable $callback ): mixed {
		// Language lives in the request; LanguageAdapter's forced language covers the switch.
		return $callback();
	}
}
