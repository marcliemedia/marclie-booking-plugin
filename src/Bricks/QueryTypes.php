<?php
/**
 * Bricks query type `mbk_available_units` ("Available apartments (booking)").
 *
 * Reads the K9 URL params (dolazak,
 * odlazak, odrasli, djeca) like [mbk_search_results]: with dates only the units that are free,
 * fit the party and have a stay total; without dates every published unit that fits the party.
 * Loop objects are the `property` WP_Posts (global post set up, so {post_title},
 * {featured_image}, ACF tags work), in recommended (menu) order or by
 * `?sort=price-asc|price-desc|sold`; without a valid `?sort=` the query setting
 * `query.mbkSort` (same values) applies. The stay card of each item (total, booking URL, facet
 * data) feeds the {mbk_stay_total}, {mbk_book_url}, {mbk_facet:*} tags.
 */

namespace Marclie\Booking\Bricks;

use Marclie\Booking\Container;
use Marclie\Booking\Frontend\Renderer;
use Marclie\Booking\I18n\LanguageAdapter;

defined( 'ABSPATH' ) || exit;

final class QueryTypes {

	public const TYPE = 'mbk_available_units';

	/** `?sort=` values (same as the search-results sort select). */
	public const SORTS = array( 'price-asc', 'price-desc', 'sold' );

	/** URL params the search depends on (the order, `?sort=` / `query.mbkSort`, is applied per call). */
	private const PARAMS = array( 'dolazak', 'odlazak', 'odrasli', 'djeca' );

	/** @var array<string, list<array<string, mixed>>> Request signature => cards in recommended order. */
	private static array $memo = array();

	public static function hooks(): void {
		add_filter( 'bricks/setup/control_options', array( self::class, 'registerType' ) );
		add_filter( 'bricks/query/run', array( self::class, 'run' ), 10, 2 );
		add_filter( 'bricks/query/loop_object', array( self::class, 'loopObject' ), 10, 3 );
	}

	/**
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>
	 */
	public static function registerType( $options ) {
		if ( is_array( $options ) ) {
			$options['queryTypes'][ self::TYPE ] = esc_html__( 'Available apartments (booking)', 'marclie-booking' );
		}

		return $options;
	}

	/**
	 * @param mixed         $result
	 * @param \Bricks\Query $query
	 * @return mixed list<\WP_Post> for our type.
	 */
	public static function run( $result, $query ) {
		if ( self::TYPE !== ( $query->object_type ?? '' ) ) {
			return $result;
		}

		$sort  = (string) ( $query->settings['query']['mbkSort'] ?? '' );
		$posts = array();
		foreach ( self::cards( in_array( $sort, self::SORTS, true ) ? $sort : '' ) as $unitId => $card ) {
			$post = get_post( $unitId );
			if ( $post instanceof \WP_Post ) {
				$posts[] = $post;
			}
		}

		return $posts;
	}

	/**
	 * Sets up the global post per item so post-context tags resolve against the unit
	 * (Bricks restores the outer post after the loop).
	 *
	 * @param mixed         $object
	 * @param mixed         $key
	 * @param \Bricks\Query $query
	 * @return mixed
	 */
	public static function loopObject( $object, $key, $query ) {
		if ( self::TYPE !== ( $query->object_type ?? '' ) || ! $object instanceof \WP_Post ) {
			return $object;
		}

		global $post;
		$post = $object; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- loop context, restored by Bricks.
		setup_postdata( $post );

		return $object;
	}

	/**
	 * Bookable stay cards for the current URL (builder: sample stay), keyed by unit ID in
	 * display order: the URL `?sort=` when valid, else `$sort` (query setting), else recommended.
	 * The search is memoized per request params and language.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function cards( string $sort = '' ): array {
		return Preview::run(
			static function () use ( $sort ): array {
				$params = array();
				foreach ( self::PARAMS as $key ) {
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, sanitized by Renderer.
					$params[ $key ] = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : '';
				}
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, checked against SORTS.
				$urlSort   = isset( $_GET['sort'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['sort'] ) ) : '';
				$sort      = in_array( $urlSort, self::SORTS, true ) ? $urlSort : $sort;
				$signature = md5( LanguageAdapter::current() . '|' . implode( '|', $params ) );

				self::$memo[ $signature ] ??= Renderer::searchCards()['cards'];

				$cards = array();
				foreach ( self::sortCards( self::$memo[ $signature ], $sort ) as $card ) {
					$cards[ $card['unit']->id ] = $card;
				}

				return $cards;
			}
		);
	}

	/**
	 * Stay card of one unit for the current URL, null when it is not bookable.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function card( int $postId ): ?array {
		$unit = Container::units()->get( $postId );

		return null === $unit ? null : ( self::cards()[ $unit->id ] ?? null );
	}

	/** Drops the memo (tests, rate/booking changes within one request). */
	public static function flush(): void {
		self::$memo = array();
	}

	/**
	 * Orders stay cards like search.js: recommended (menu order) by default, by price
	 * (stay total with dates, nightly "from" without) or by `sold` descending.
	 *
	 * @param list<array<string, mixed>> $cards Cards with `data` => [order, price, sold, …].
	 * @return list<array<string, mixed>>
	 */
	public static function sortCards( array $cards, string $sort ): array {
		$num = static fn( array $card, string $key ): float => (float) ( $card['data'][ $key ] ?? 0 );
		$cmp = match ( $sort ) {
			'price-asc'  => static fn( array $a, array $b ): int => $num( $a, 'price' ) <=> $num( $b, 'price' ),
			'price-desc' => static fn( array $a, array $b ): int => $num( $b, 'price' ) <=> $num( $a, 'price' ),
			'sold'       => static fn( array $a, array $b ): int => $num( $b, 'sold' ) <=> $num( $a, 'sold' ),
			default      => null,
		};

		// Ties (and the default) keep the recommended order.
		usort(
			$cards,
			static fn( array $a, array $b ): int => ( null === $cmp ? 0 : $cmp( $a, $b ) ) ?: $num( $a, 'order' ) <=> $num( $b, 'order' )
		);

		return $cards;
	}
}
