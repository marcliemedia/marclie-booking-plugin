<?php
/**
 * Public widget shortcodes — thin wrappers over Renderer (Bricks elements reuse Renderer).
 *
 * [mbk_bookbox unit="" inquiry="1" note="1"] [mbk_calendar unit="" months="2" view="YYYY-MM"] [mbk_bookbar unit=""]
 * [mbk_search_form variant="hero|dialog|compact"] [mbk_search_results]
 * [mbk_checkout] [mbk_confirmation] [mbk_pay_balance] (booking-flow pages, state from the URL)
 * [mbk_lang_switch class="w-lang-switch"] (HR / EN dropdown, no plugin assets)
 * [mbk_login] [mbk_register] [mbk_forgot_password] [mbk_account part="all|head|board"] (guest account pages)
 */

namespace Marclie\Booking\Frontend;

defined( 'ABSPATH' ) || exit;

final class Shortcodes {

	public static function hooks(): void {
		add_action( 'init', array( self::class, 'register' ) );
		Assets::hooks();
		Seo::hooks();
		AccountPages::hooks();
	}

	public static function register(): void {
		add_shortcode( 'mbk_bookbox', array( self::class, 'bookbox' ) );
		add_shortcode( 'mbk_calendar', array( self::class, 'calendar' ) );
		add_shortcode( 'mbk_bookbar', array( self::class, 'bookbar' ) );
		add_shortcode( 'mbk_search_form', array( self::class, 'searchForm' ) );
		add_shortcode( 'mbk_search_results', array( self::class, 'searchResults' ) );
		add_shortcode( 'mbk_checkout', array( Renderer::class, 'checkout' ) );
		add_shortcode( 'mbk_confirmation', array( Renderer::class, 'confirmation' ) );
		add_shortcode( 'mbk_pay_balance', array( Renderer::class, 'payBalance' ) );
		add_shortcode( 'mbk_lang_switch', array( self::class, 'langSwitch' ) );
		add_shortcode( 'mbk_login', static fn(): string => AccountPages::login() );
		add_shortcode( 'mbk_register', static fn(): string => AccountPages::register() );
		add_shortcode( 'mbk_forgot_password', static fn(): string => AccountPages::forgotPassword() );
		add_shortcode( 'mbk_account', array( self::class, 'account' ) );
	}

	/** @param array<string, string>|string $atts */
	public static function bookbox( $atts ): string {
		$atts = shortcode_atts(
			array(
				'unit'    => '',
				'inquiry' => '1',
				'note'    => '1',
			),
			$atts,
			'mbk_bookbox'
		);

		return Renderer::bookbox( $atts['unit'], wp_validate_boolean( $atts['inquiry'] ), wp_validate_boolean( $atts['note'] ) );
	}

	/** @param array<string, string>|string $atts */
	public static function calendar( $atts ): string {
		$atts = shortcode_atts(
			array(
				'unit'   => '',
				'months' => '2',
				'view'   => '',
			),
			$atts,
			'mbk_calendar'
		);

		return Renderer::calendar( $atts['unit'], (int) $atts['months'], (string) $atts['view'] );
	}

	/** @param array<string, string>|string $atts */
	public static function bookbar( $atts ): string {
		$atts = shortcode_atts( array( 'unit' => '' ), $atts, 'mbk_bookbar' );

		return Renderer::bookbar( $atts['unit'] );
	}

	/** @param array<string, string>|string $atts */
	public static function searchForm( $atts ): string {
		$atts = shortcode_atts( array( 'variant' => 'hero' ), $atts, 'mbk_search_form' );

		return Renderer::searchForm( (string) $atts['variant'] );
	}

	public static function searchResults(): string {
		return Renderer::searchResults();
	}

	/** @param array<string, string>|string $atts */
	public static function langSwitch( $atts ): string {
		$atts = shortcode_atts( array( 'class' => '' ), $atts, 'mbk_lang_switch' );

		return LanguageSwitcher::render( sanitize_text_field( (string) $atts['class'] ) );
	}

	/** @param array<string, string>|string $atts */
	public static function account( $atts ): string {
		$atts = shortcode_atts( array( 'part' => 'all' ), $atts, 'mbk_account' );

		return AccountPages::account( (string) $atts['part'] );
	}
}
