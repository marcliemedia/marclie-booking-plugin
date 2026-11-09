<?php
/**
 * Source of the visitor's booking-UI language.
 */

namespace Marclie\Booking\I18n;

defined( 'ABSPATH' ) || exit;

interface LanguageDriver {

	/**
	 * Current language code: `hr` or `en`.
	 */
	public function current(): string;

	/**
	 * URL of a mapped plugin page (Settings page key) in the given language; null when the page is not mapped.
	 */
	public function pageUrl( string $pageKey, string $lang ): ?string;

	/**
	 * Runs $callback with the driver's own language state switched to $lang and
	 * restores the exact previous state afterwards (also on exceptions).
	 *
	 * @template T
	 * @param callable(): T $callback
	 * @return T
	 */
	public function withLanguage( string $lang, callable $callback ): mixed;
}
