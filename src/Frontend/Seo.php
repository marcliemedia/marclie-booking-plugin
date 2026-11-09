<?php
/**
 * Plugin-side SEO: robots, canonical and sitemap rules of the booking funnel and of `?lang=`.
 *
 * - `noindex, follow` on the mapped Rezervacija / Hvala / Plaćanje / Pretraga pages, the
 *   guest account pages (Prijava, Registracija, Moj račun, Zaboravljena lozinka) and on
 *   every frontend URL carrying `lang` (native driver: EN = HR URL + `lang=en`).
 * - Canonical: funnel pages → the clean page URL (no query); `lang` URLs → the same URL
 *   without `lang`. Singular pages go through core's `rel_canonical` (`get_canonical_url`
 *   filter), so only one tag is printed. Other `lang` URLs get a tag here unless an SEO plugin runs.
 * - Funnel pages and Bricks templates are kept out of the XML sitemaps.
 * - Rank Math replaces core robots, canonical and sitemaps, so the same rules are applied through its filters
 *   (`rank_math/frontend/robots`, `rank_math/frontend/canonical`, `rank_math/sitemap/posts_to_exclude`,
 *   `rank_math/sitemap/exclude_post_type`).
 *
 * The plugin prints no JSON-LD (the site's Rank Math schema snippet does, using mbk_get_price_from()).
 */

namespace Marclie\Booking\Frontend;

use Marclie\Booking\I18n\LangUrl;
use Marclie\Booking\I18n\NativeDriver;
use Marclie\Booking\Settings;

defined( 'ABSPATH' ) || exit;

final class Seo {

	/** Page-map keys of the booking funnel and the account pages (noindex, outside the sitemap). */
	public const FUNNEL_PAGES = array( 'rezervacija', 'hvala', 'placanje', 'pretraga', 'prijava', 'registracija', 'moj_racun', 'zaboravljena_lozinka' );

	public static function hooks(): void {
		add_filter( 'wp_robots', array( self::class, 'robots' ) );
		add_filter( 'get_canonical_url', array( self::class, 'canonical' ), 10, 2 );
		add_action( 'wp_head', array( self::class, 'archiveCanonical' ), 11 );
		add_filter( 'wp_sitemaps_posts_query_args', array( self::class, 'sitemapQueryArgs' ), 10, 2 );
		add_filter( 'wp_sitemaps_post_types', array( self::class, 'sitemapPostTypes' ) );
		add_filter( 'rank_math/frontend/robots', array( self::class, 'rankMathRobots' ) );
		add_filter( 'rank_math/frontend/canonical', array( self::class, 'rankMathCanonical' ) );
		add_filter( 'rank_math/sitemap/posts_to_exclude', array( self::class, 'rankMathSitemapExclude' ) );
		add_filter( 'rank_math/sitemap/exclude_post_type', array( self::class, 'rankMathSitemapExcludeType' ), 10, 2 );
	}

	/**
	 * @param array<string, bool|string> $robots
	 * @return array<string, bool|string>
	 */
	public static function robots( $robots ) {
		if ( is_admin() || ! ( self::isFunnelPage() || self::requestHasLang() ) ) {
			return $robots;
		}

		unset( $robots['index'], $robots['nofollow'] );
		$robots['noindex'] = true;
		$robots['follow']  = true;

		return $robots;
	}

	/**
	 * Rank Math robots (`index` / `follow` keys): the same noindex, follow rule.
	 *
	 * @param array<string, string> $robots
	 * @return array<string, string>
	 */
	public static function rankMathRobots( $robots ) {
		if ( ! ( self::isFunnelPage() || self::requestHasLang() ) ) {
			return $robots;
		}

		return array_merge( (array) $robots, array( 'index' => 'noindex', 'follow' => 'follow' ) );
	}

	/**
	 * Rank Math canonical: funnel page → clean permalink; anything else → without `lang`.
	 *
	 * @param string|false $url
	 * @return string|false
	 */
	public static function rankMathCanonical( $url ) {
		$post = is_singular() ? get_queried_object() : null;

		return self::canonical( $url, $post instanceof \WP_Post ? $post : null );
	}

	/**
	 * @param list<int> $ids Post IDs Rank Math leaves out of its sitemaps.
	 * @return list<int>
	 */
	public static function rankMathSitemapExclude( $ids ) {
		return array_values( array_unique( array_merge( array_map( 'intval', (array) $ids ), self::funnelPageIds() ) ) );
	}

	/**
	 * @param bool   $exclude
	 * @param string $postType
	 * @return bool
	 */
	public static function rankMathSitemapExcludeType( $exclude, $postType = '' ) {
		return 'bricks_template' === $postType ? true : $exclude;
	}

	/**
	 * Core `rel_canonical` (singular): funnel page → clean permalink; anything else → without `lang`.
	 *
	 * @param string|false $url
	 * @param \WP_Post     $post
	 * @return string|false
	 */
	public static function canonical( $url, $post = null ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return $url;
		}

		if ( $post instanceof \WP_Post && in_array( $post->ID, self::funnelPageIds(), true ) ) {
			$permalink = get_permalink( $post );
			if ( $permalink ) {
				return strtok( $permalink, '?' );
			}
		}

		return LangUrl::without( $url );
	}

	/**
	 * Non-singular `lang` URLs (archives) whose canonical nobody else prints.
	 */
	public static function archiveCanonical(): void {
		if ( is_singular() || is_404() || is_search() || ! self::requestHasLang() || self::seoPluginActive() ) {
			return;
		}

		$home   = wp_parse_url( home_url() );
		$origin = ( $home['scheme'] ?? 'https' ) . '://' . ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		$path   = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- escaped by esc_url().
		printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( LangUrl::without( $origin . $path ) ) );
	}

	/**
	 * Funnel pages out of the `page` sitemap.
	 *
	 * @param array<string, mixed> $args
	 * @param string               $postType
	 * @return array<string, mixed>
	 */
	public static function sitemapQueryArgs( $args, $postType = '' ) {
		if ( 'page' !== $postType ) {
			return $args;
		}

		$ids = self::funnelPageIds();
		if ( $ids ) {
			$args['post__not_in'] = array_values( array_unique( array_merge( (array) ( $args['post__not_in'] ?? array() ), $ids ) ) );
		}

		return $args;
	}

	/**
	 * @param array<string, mixed> $postTypes
	 * @return array<string, mixed>
	 */
	public static function sitemapPostTypes( $postTypes ) {
		unset( $postTypes['bricks_template'] );

		return $postTypes;
	}

	/**
	 * Mapped funnel page IDs, plus their WPML translations when WPML runs.
	 *
	 * @return list<int>
	 */
	public static function funnelPageIds(): array {
		$ids = array();
		foreach ( self::FUNNEL_PAGES as $key ) {
			$id = Settings::pageId( $key );
			if ( ! $id ) {
				continue;
			}
			$ids[] = $id;
			if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
				$translated = (int) apply_filters( 'wpml_object_id', $id, 'page', false );
				if ( $translated ) {
					$ids[] = $translated;
				}
			}
		}

		return array_values( array_unique( $ids ) );
	}

	private static function isFunnelPage(): bool {
		$ids = self::funnelPageIds();

		return $ids && is_page( $ids );
	}

	private static function requestHasLang(): bool {
		return isset( $_GET[ NativeDriver::QUERY_ARG ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check.
	}

	/** Yoast, Rank Math, SEOPress or AIOSEO prints canonicals (and robots) itself. */
	private static function seoPluginActive(): bool {
		return defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'SEOPRESS_VERSION' ) || defined( 'AIOSEO_VERSION' );
	}
}
