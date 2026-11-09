<?php
/**
 * Sender identity and footer data from the site's ACF options page (`get_field( …, 'option' )`):
 * brand_name, phone, email, street, postcode, city, maps_url. Blog name /
 * admin email when ACF is missing.
 *
 * Optional branding: ACF option `logo` (image ID, array or URL; else the site
 * custom logo; else the brand name as text) and `accent_color` (#RRGGBB,
 * default rose #E0174F).
 */

namespace Marclie\Booking\Email;

defined( 'ABSPATH' ) || exit;

final class BusinessInfo {

	public const ACCENT = '#E0174F';

	/** @var array{name: string, phone: string, email: string, address: string, maps_url: string, logo_url: string, accent: string}|null */
	private static ?array $cache = null;

	/**
	 * @return array{name: string, phone: string, email: string, address: string, maps_url: string, logo_url: string, accent: string}
	 */
	public static function get(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$name  = self::field( 'brand_name' );
		$email = self::field( 'email' );
		$place = trim( self::field( 'postcode' ) . ' ' . self::field( 'city' ) );

		return self::$cache = array(
			'name'     => '' !== $name ? $name : wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'phone'    => self::field( 'phone' ),
			'email'    => '' !== $email ? $email : (string) get_option( 'admin_email' ),
			'address'  => implode( ', ', array_filter( array( self::field( 'street' ), $place ) ) ),
			'maps_url' => self::field( 'maps_url' ),
			'logo_url' => self::logoUrl(),
			'accent'   => self::accent(),
		);
	}

	/** Drops the per-request cache (tests). */
	public static function flush(): void {
		self::$cache = null;
	}

	private static function logoUrl(): string {
		$logo = function_exists( 'get_field' ) ? get_field( 'logo', 'option' ) : null;
		if ( is_array( $logo ) ) {
			$logo = $logo['ID'] ?? $logo['id'] ?? $logo['url'] ?? null;
		}
		if ( null === $logo || '' === $logo || false === $logo ) {
			$logo = (int) get_theme_mod( 'custom_logo' );
		}
		if ( is_numeric( $logo ) ) {
			$logo = (int) $logo > 0 ? wp_get_attachment_image_url( (int) $logo, 'medium' ) : '';
		}

		return is_string( $logo ) ? esc_url_raw( $logo ) : '';
	}

	private static function accent(): string {
		$color = sanitize_hex_color( self::field( 'accent_color' ) );

		return is_string( $color ) && 7 === strlen( $color ) ? strtoupper( $color ) : self::ACCENT;
	}

	private static function field( string $name ): string {
		$value = function_exists( 'get_field' ) ? get_field( $name, 'option' ) : '';

		return is_string( $value ) ? trim( $value ) : '';
	}
}
