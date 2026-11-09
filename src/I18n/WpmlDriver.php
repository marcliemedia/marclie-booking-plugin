<?php
/**
 * WPML driver, active automatically when ICL_SITEPRESS_VERSION is defined.
 */

namespace Marclie\Booking\I18n;

use Marclie\Booking\Settings;

defined( 'ABSPATH' ) || exit;

final class WpmlDriver implements LanguageDriver {

	public function current(): string {
		return LanguageAdapter::normalize( apply_filters( 'wpml_current_language', null ) ) ?? LanguageAdapter::DEFAULT;
	}

	/**
	 * Translated page via wpml_object_id; falls back to the HR page when no translation exists.
	 */
	public function pageUrl( string $pageKey, string $lang ): ?string {
		$pageId = Settings::pageId( $pageKey );
		if ( ! $pageId ) {
			return null;
		}

		$translatedId = (int) apply_filters( 'wpml_object_id', $pageId, 'page', true, $lang );
		$url          = get_permalink( $translatedId ? $translatedId : $pageId );
		if ( ! $url ) {
			return null;
		}

		return (string) apply_filters( 'wpml_permalink', $url, $lang );
	}

	/**
	 * Restores the exact raw WPML language (may be `all` or null), not the normalized hr|en.
	 */
	public function withLanguage( string $lang, callable $callback ): mixed {
		$previous = apply_filters( 'wpml_current_language', null );
		do_action( 'wpml_switch_language', $lang );

		try {
			return $callback();
		} finally {
			do_action( 'wpml_switch_language', $previous );
		}
	}
}
