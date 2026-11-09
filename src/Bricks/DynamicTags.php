<?php
/**
 * Bricks dynamic data tags, picker group "Booking".
 *
 * Unit tags use the post Bricks renders for (current page, template preview post or the
 * loop item of any post loop, incl. `mbk_available_units`); in the builder without a
 * `property` post they fall back to the first unit. Text output is localized per booking
 * language (HR/EN) and escaped when substituted into content.
 *
 *   {mbk_price_from}            lowest price per person: "od 50 € / osoba / noć · min. 2 osobe" / "from €50 / person / night · min. 2 guests"; :raw → "50"
 *   {mbk_min_guests}            minimum paying guests: "min. 2 osobe" / "min. 2 guests" ('' when the minimum is 1); :raw → "2"
 *   {mbk_min_stay}              the unit's minimum stay ("3"); without a unit the lowest of all units; :max → without a unit the highest
 *   {mbk_checkin_window}        "15:00–22:00" (unit fields, else the policy); :from → "15:00", :to → "22:00"
 *   {mbk_checkout_until}        "10:00"
 *   {mbk_policy:deposit_pct|balance_days|free_cancel_days}  "30" / "14" / "30"
 *   {mbk_coupon_default}        active default coupon code ("DIRECT5"); :amount → "5 %"
 *   {mbk_stay_total}            stay total for the URL dates ("1.365 €"), '' without dates or when not bookable; :raw → "1365"
 *   {mbk_stay_nights}           nights of the URL stay ("7") or ''
 *   {mbk_stay_dim}              "is-dim" when the URL has dates and the unit is not bookable for them, else '' (state class)
 *   {mbk_book_url}              booking-page URL with the stay (no dates: unit page #rezerviraj)
 *   {mbk_facet:order|price|sold|za|kuca|gosti|sobe|kupaonice|vani|pogled|posebno}
 *                               search.js data-* values of the unit (loop card attributes)
 *   {mbk_checkout_unit:title|url|house|house_url}
 *                               unit of the `apartman` URL param (Rezervacija breadcrumb), '' without one
 *   {mbk_search:range}          URL stay as running text: "od 12. do 19. srpnja 2027." / "from 12 to 19 July 2027", '' without dates
 *   {mbk_search:guests}         URL guests (odrasli, default 2, + djeca): "4 gosta" / "4 guests"
 *   {mbk_card_badge:1|2|3}      N-th stay-card badge of the unit ("Jacuzzi", "Pogled na more"…, as on the search cards), '' when none
 */

namespace Marclie\Booking\Bricks;

use Marclie\Booking\Container;
use Marclie\Booking\Frontend\Renderer;
use Marclie\Booking\I18n\Formatter;
use Marclie\Booking\I18n\LanguageAdapter;
use Marclie\Booking\Units\Unit;

defined( 'ABSPATH' ) || exit;

final class DynamicTags {

	public const GROUP = 'Booking';

	/** Tag name => allowed modifiers ('' = none). */
	public const TAGS = array(
		'mbk_price_from'     => array( '', 'raw' ),
		'mbk_min_guests'     => array( '', 'raw' ),
		'mbk_min_stay'       => array( '', 'max' ),
		'mbk_checkin_window' => array( '', 'from', 'to' ),
		'mbk_checkout_until' => array( '' ),
		'mbk_policy'         => array( 'deposit_pct', 'balance_days', 'free_cancel_days' ),
		'mbk_coupon_default' => array( '', 'amount' ),
		'mbk_stay_total'     => array( '', 'raw' ),
		'mbk_stay_nights'    => array( '' ),
		'mbk_stay_dim'       => array( '' ),
		'mbk_book_url'       => array( '' ),
		'mbk_facet'          => array( 'order', 'price', 'sold', 'za', 'kuca', 'gosti', 'sobe', 'kupaonice', 'vani', 'pogled', 'posebno' ),
		'mbk_checkout_unit'  => array( 'title', 'url', 'house', 'house_url' ),
		'mbk_search'         => array( 'range', 'guests' ),
		'mbk_card_badge'     => array( '1', '2', '3' ),
	);

	/** `{mbk_name}` or `{mbk_name:modifier}`. */
	private const PATTERN = '/\{(mbk_[a-z_]+)(?::([a-z0-9_]+))?\}/';

	public static function hooks(): void {
		add_filter( 'bricks/dynamic_tags_list', array( self::class, 'tagsList' ) );
		add_filter( 'bricks/dynamic_data/render_tag', array( self::class, 'renderTag' ), 20, 3 );
		add_filter( 'bricks/dynamic_data/render_content', array( self::class, 'renderContent' ), 20, 3 );
		add_filter( 'bricks/frontend/render_data', array( self::class, 'renderContent' ), 20, 2 );
	}

	/**
	 * Picker entries (one per tag/modifier).
	 *
	 * @param array<int, array<string, mixed>> $tags
	 * @return array<int, array<string, mixed>>
	 */
	public static function tagsList( $tags ) {
		$labels = array(
			'mbk_price_from'     => __( 'Price from (per person per night)', 'marclie-booking' ),
			'mbk_min_guests'     => __( 'Minimum paying guests', 'marclie-booking' ),
			'mbk_min_stay'       => __( 'Minimum stay', 'marclie-booking' ),
			'mbk_checkin_window' => __( 'Check-in window', 'marclie-booking' ),
			'mbk_checkout_until' => __( 'Check-out until', 'marclie-booking' ),
			'mbk_policy'         => __( 'Policy', 'marclie-booking' ),
			'mbk_coupon_default' => __( 'Default coupon', 'marclie-booking' ),
			'mbk_stay_total'     => __( 'Stay total (URL dates)', 'marclie-booking' ),
			'mbk_stay_nights'    => __( 'Stay nights (URL dates)', 'marclie-booking' ),
			'mbk_book_url'       => __( 'Booking URL (URL dates)', 'marclie-booking' ),
			'mbk_facet'          => __( 'Search facet', 'marclie-booking' ),
			'mbk_stay_dim'       => __( 'Not bookable for the URL dates (is-dim)', 'marclie-booking' ),
			'mbk_checkout_unit'  => __( 'Apartment of the booking link', 'marclie-booking' ),
			'mbk_search'         => __( 'Search (URL dates and guests)', 'marclie-booking' ),
			'mbk_card_badge'     => __( 'Stay card badge', 'marclie-booking' ),
		);

		$tags = is_array( $tags ) ? $tags : array();
		foreach ( self::TAGS as $name => $modifiers ) {
			foreach ( $modifiers as $modifier ) {
				$tags[] = array(
					'name'  => '{' . $name . ( '' === $modifier ? '' : ':' . $modifier ) . '}',
					'label' => $labels[ $name ] . ( '' === $modifier ? '' : ' (' . $modifier . ')' ),
					'group' => self::GROUP,
				);
			}
		}

		return $tags;
	}

	/**
	 * Single-tag resolution (link/image fields, conditions). Bricks' own callback (priority 10)
	 * hands unknown tags back as "{tag}" — or "&#123;tag&#125;" for its `:raw` filter, which
	 * here means our raw number; other providers' values pass through untouched.
	 *
	 * @param mixed         $value
	 * @param \WP_Post|null $post
	 * @param string        $context
	 * @return mixed
	 */
	public static function renderTag( $value, $post, $context = 'text' ) {
		if ( ! is_string( $value ) || ! str_contains( $value, 'mbk_' ) ) {
			return $value;
		}

		if ( preg_match( '/^\{?(mbk_[a-z_]+)(?::([a-z0-9_]+))?\}?$/', $value, $m ) ) {
			$resolved = self::value( $m[1], $m[2] ?? '', $post );
		} elseif ( preg_match( '/^&#123;(mbk_[a-z_]+)&#125;$/', $value, $m ) ) {
			$resolved = self::value( $m[1], 'raw', $post );
		} else {
			return $value;
		}

		return $resolved ?? $value;
	}

	/**
	 * Replaces our tags inside content (element text, attributes, whole render data),
	 * keeping surrounding HTML and other providers' tags.
	 *
	 * @param mixed         $content
	 * @param \WP_Post|null $post
	 * @param string        $context
	 * @return mixed
	 */
	public static function renderContent( $content, $post = null, $context = 'text' ) {
		if ( ! is_string( $content ) || ! str_contains( $content, '{mbk_' ) ) {
			return $content;
		}

		return self::replace(
			$content,
			static function ( string $name, string $modifier ) use ( $post, $context ): ?string {
				$value = self::value( $name, $modifier, $post );

				return null === $value || 'link' === $context ? $value : esc_html( $value );
			}
		);
	}

	/**
	 * @param callable(string, string): ?string $resolve Name, modifier => replacement; null keeps the tag.
	 */
	public static function replace( string $content, callable $resolve ): string {
		return (string) preg_replace_callback(
			self::PATTERN,
			static function ( array $m ) use ( $resolve ): string {
				return $resolve( $m[1], $m[2] ?? '' ) ?? $m[0];
			},
			$content
		);
	}

	/**
	 * Plain-text value of a tag; null for an unknown tag or modifier.
	 *
	 * @param \WP_Post|null|mixed $post
	 */
	public static function value( string $name, string $modifier, $post ): ?string {
		if ( ! isset( self::TAGS[ $name ] ) || ! in_array( $modifier, self::TAGS[ $name ], true ) ) {
			return null;
		}

		$lang = LanguageAdapter::current();

		switch ( $name ) {
			case 'mbk_policy':
				$policy = Container::policy();
				return (string) match ( $modifier ) {
					'deposit_pct'      => $policy->depositPct,
					'balance_days'     => $policy->balanceDays,
					'free_cancel_days' => $policy->freeCancelDays,
				};

			case 'mbk_coupon_default':
				$offer = Renderer::directOffer( $lang );
				return null === $offer ? '' : ( 'amount' === $modifier ? $offer['amount'] : $offer['code'] );

			case 'mbk_checkout_unit':
				return self::checkoutUnit( $modifier, $lang );

			case 'mbk_search':
				$request = Renderer::requestStay();
				if ( 'range' === $modifier ) {
					return null === $request['arrival'] ? '' : Formatter::stayRange( $request['arrival'], (string) $request['departure'], $lang );
				}
				$guests = ( $request['adults'] ?: 2 ) + $request['children'] + $request['infants'];
				/* translators: %d: number of guests. */
				return sprintf( _n( '%d guest', '%d guests', $guests, 'marclie-booking' ), $guests );
		}

		$unit = self::unit( $post );

		switch ( $name ) {
			case 'mbk_checkin_window':
				$policy = Container::policy();
				$from   = null === $unit ? $policy->checkinFrom : $unit->checkinFrom;
				$to     = null === $unit ? $policy->checkinTo : $unit->checkinTo;
				return match ( $modifier ) {
					'from'  => $from,
					'to'    => $to,
					default => $from . '–' . $to,
				};

			case 'mbk_checkout_until':
				return null === $unit ? Container::policy()->checkoutUntil : $unit->checkoutUntil;

			case 'mbk_min_stay':
				$table = Container::priceRepository()->table();
				if ( null !== $unit ) {
					return (string) $table->minStay( $unit->id );
				}
				$all = array_map( static fn( Unit $u ): int => $table->minStay( $u->id ), Container::units()->all() );
				if ( ! $all ) {
					return '';
				}
				return (string) ( 'max' === $modifier ? max( $all ) : min( $all ) );
		}

		if ( null === $unit ) {
			return '';
		}

		if ( 'mbk_card_badge' === $name ) {
			return Renderer::unitBadges( $unit->id )[ (int) $modifier - 1 ] ?? '';
		}

		if ( 'mbk_price_from' === $name ) {
			$money = Container::search()->personPriceFrom( $unit->id );
			if ( null === $money ) {
				return '';
			}
			return 'raw' === $modifier ? self::amount( $money->cents() ) : (string) Renderer::priceFromText( $unit->id, $lang );
		}

		if ( 'mbk_min_guests' === $name ) {
			return 'raw' === $modifier ? (string) Container::priceRepository()->minGuests( $unit->id ) : Renderer::minGuestsNote( $unit->id );
		}

		$card = QueryTypes::card( $unit->id );

		return match ( $name ) {
			'mbk_stay_total'  => null === $card || null === $card['total'] ? '' : ( 'raw' === $modifier ? (string) $card['data']['price'] : (string) $card['total'] ),
			'mbk_stay_nights' => null === $card || null === $card['total'] ? '' : (string) $card['nights'],
			'mbk_stay_dim'    => null === Renderer::requestStay()['arrival'] || ( null !== $card && null !== $card['total'] ) ? '' : 'is-dim',
			'mbk_book_url'    => null === $card ? '' : (string) $card['book_url'],
			'mbk_facet'       => null === $card ? '' : (string) ( $card['data'][ $modifier ] ?? '' ),
			default           => null,
		};
	}

	/** Unit of the `apartman` URL param (slug, ID or code); builder without one: the sample unit. */
	private static function checkoutUnit( string $field, string $lang ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
		$ref  = isset( $_GET['apartman'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['apartman'] ) ) : '';
		$unit = '' !== $ref ? Renderer::resolveUnit( $ref ) : ( Preview::active() ? Preview::sampleUnit() : null );
		if ( null === $unit ) {
			return '';
		}

		$terms = get_the_terms( $unit->id, 'kuca' );
		$house = is_array( $terms ) && $terms ? $terms[0] : null;

		return match ( $field ) {
			'title'     => $unit->title,
			'url'       => \Marclie\Booking\Service\SearchService::permalink( $unit, $lang ),
			'house'     => null === $house ? '' : $house->name,
			'house_url' => null === $house || is_wp_error( $link = get_term_link( $house ) ) ? '' : ( 'en' === $lang ? add_query_arg( 'lang', 'en', $link ) : $link ),
			default     => '',
		};
	}

	/** Cents as a plain decimal number: 11500 → "115", 40950 → "409.5". */
	public static function amount( int $cents ): string {
		return (string) round( $cents / 100, 2 );
	}

	/**
	 * Unit of the rendered post; the first unit when previewing in the builder without one.
	 *
	 * @param mixed $post
	 */
	private static function unit( $post ): ?Unit {
		if ( is_numeric( $post ) ) {
			$post = get_post( (int) $post );
		}
		if ( $post instanceof \WP_Post && 'property' === $post->post_type ) {
			$unit = Container::units()->get( $post->ID );
			if ( null !== $unit ) {
				return $unit;
			}
		}

		return Preview::active() ? Preview::sampleUnit() : null;
	}
}
