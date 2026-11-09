<?php
/**
 * Server-side markup for the public booking widgets (shortcodes now, Bricks elements in phase 08).
 *
 * Templates are looked up in the child theme (`marclie-booking/{tpl}.php`), then the parent
 * theme, then the plugin `templates/` folder; `mbk_template_path` filters the final path.
 * Templates receive `$args` (like get_template_part()) and escape late.
 */

namespace Marclie\Booking\Frontend;

use Marclie\Booking\Container;
use Marclie\Booking\Domain\Stay;
use Marclie\Booking\I18n\Formatter;
use Marclie\Booking\I18n\LanguageAdapter;
use Marclie\Booking\Units\Unit;
use Marclie\Booking\Service\PricingService;
use Marclie\Booking\Domain\Exception\BookingException;
use Marclie\Booking\Rest\Public\BaseController;

defined( 'ABSPATH' ) || exit;

final class Renderer {

	/** Shared date-pair key of the bookbox; the inline calendar of the same unit joins it. */
	public const BOOKBOX_KEY = 'bb-dolazak';

	public const MAX_ADULTS   = 10;
	public const MAX_CHILDREN = 6;

	/** Upper bound of the children 0–4 stepper (not limited per apartment). */
	public const MAX_INFANTS = 10;

	/** Search-form variant => [form modifier class, id prefix]. */
	private const FORM_VARIANTS = array(
		'hero'    => array( '', 'h' ),
		'dialog'  => array( 'w-search-form--dialog', 'd' ),
		'compact' => array( 'w-search-form--strip', 'pr' ),
	);

	/** Facet groups of the search results, in mockup order. */
	private const FACET_TAXONOMIES = array( 'za', 'kuca' );

	private const FACET_TAXONOMIES_TAIL = array( 'vani', 'pogled', 'posebno' );

	/** Photos of a search-card slider (mockup Card: Stay shows 4). */
	private const CARD_PHOTOS = 4;

	/** @var array<string, int> Rendered search forms per id prefix (unique ids on one page). */
	private static array $formIds = array();

	/* Rendering ------------------------------------------------------------ */

	public static function locate( string $tpl ): string {
		// `account/login` → one sanitized segment per folder level.
		$tpl  = implode( '/', array_map( 'sanitize_file_name', explode( '/', $tpl ) ) );
		$path = locate_template( array( 'marclie-booking/' . $tpl . '.php' ) );
		if ( '' === $path ) {
			$path = MBK_DIR . 'templates/' . $tpl . '.php';
		}

		return (string) apply_filters( 'mbk_template_path', $path, $tpl );
	}

	/**
	 * @param array<string, mixed> $vars Available to the template as `$args`; `lang_attr`
	 *                                   (Renderer::langAttr()) is added for the widget roots.
	 */
	public static function render( string $tpl, array $vars ): string {
		$path = self::locate( $tpl );
		if ( ! is_readable( $path ) ) {
			return '';
		}

		$vars += array( 'lang_attr' => self::langAttr() );

		ob_start();
		( static function ( string $mbkTemplate, array $args ): void {
			include $mbkTemplate;
		} )( $path, $vars );

		return (string) ob_get_clean();
	}

	/**
	 * ` lang="en"` for widget roots when the booking language differs from the page language
	 * (native driver: EN widgets on an HR page, WCAG 3.1.2); '' otherwise.
	 */
	public static function langAttr(): string {
		$lang = LanguageAdapter::current();

		return LanguageAdapter::normalize( get_bloginfo( 'language' ) ) === $lang ? '' : ' lang="' . esc_attr( $lang ) . '"';
	}

	/* Widgets -------------------------------------------------------------- */

	/**
	 * @param int|string $unitRef Post ID, property slug or unit code; '' = current post.
	 * @param bool       $inquiry Show the "Send an enquiry" button.
	 * @param bool       $note    Show the deposit/cancellation policy note.
	 */
	public static function bookbox( $unitRef = '', bool $inquiry = true, bool $note = true ): string {
		$unit = self::resolveUnit( $unitRef );
		if ( null === $unit ) {
			return '';
		}

		$lang     = LanguageAdapter::current();
		$policy   = Container::policy();
		$request  = self::requestStay();
		$cap      = $unit->capacity;
		$kidsMax  = min( $unit->maxChildren, max( 0, $cap - 1 ) );
		$adults   = $request['adults'] ? min( $cap, $request['adults'] ) : min( 2, $cap );
		$children = min( $request['children'], $kidsMax, $cap - $adults );
		[ $action, $hidden ] = self::formTarget( self::pageUrl( 'rezervacija', $lang ) );
		$hidden['apartman']  = self::slug( $unit );

		Assets::enqueue( 'mbk-bookbox' );

		return self::render(
			'bookbox',
			array(
				'unit'      => $unit,
				'key'       => self::BOOKBOX_KEY,
				'lang'      => $lang,
				'price'     => self::priceFrom( $unit->id, $lang ),
				'per'       => self::perPersonUnit( $unit->id ),
				'direct'    => self::directOffer( $lang ),
				'arrival'   => $request['arrival'],
				'departure' => $request['departure'],
				'adults'    => $adults,
				'children'  => max( 0, $children ),
				'infants'   => $request['infants'],
				'cap'       => $cap,
				'kids_max'  => $kidsMax,
				'rule'      => self::childrenRule(),
				'action'    => $action,
				'hidden'    => $hidden,
				'contact'   => self::contactUrl( $lang ),
				'policy'    => $policy,
				'inquiry'   => $inquiry,
				'note'      => $note,
			)
		);
	}

	/**
	 * @param int|string $unitRef
	 */
	public static function calendar( $unitRef = '', int $months = 2, string $view = '' ): string {
		$unit = self::resolveUnit( $unitRef );
		if ( null === $unit ) {
			return '';
		}

		Assets::enqueue( 'mbk-calendar' );

		return self::render(
			'calendar',
			array(
				'unit'   => $unit,
				'key'    => self::BOOKBOX_KEY,
				'months' => max( 1, min( 2, $months ) ),
				'view'   => preg_match( '/^\d{4}-\d{2}$/', $view ) ? $view : '',
			)
		);
	}

	/**
	 * @param int|string $unitRef
	 */
	public static function bookbar( $unitRef = '' ): string {
		$unit = self::resolveUnit( $unitRef );
		if ( null === $unit ) {
			return '';
		}

		Assets::enqueue( 'mbk-bookbox' );

		return self::render(
			'bookbar',
			array(
				'unit'  => $unit,
				'key'   => self::BOOKBOX_KEY,
				'price' => self::priceFromText( $unit->id, LanguageAdapter::current() ),
			)
		);
	}

	public static function searchForm( string $variant = 'hero' ): string {
		$variant = isset( self::FORM_VARIANTS[ $variant ] ) ? $variant : 'hero';
		[ $modifier, $prefix ] = self::FORM_VARIANTS[ $variant ];

		$n = self::$formIds[ $prefix ] = ( self::$formIds[ $prefix ] ?? 0 ) + 1;
		if ( $n > 1 ) {
			$prefix .= $n;
		}

		$lang    = LanguageAdapter::current();
		$request = self::requestStay();
		[ $action, $hidden ] = self::formTarget( self::pageUrl( 'pretraga', $lang ) );

		// The header dialog renders on every page: its scripts load when it is first used (Assets::lazy()).
		$lazy = 'dialog' === $variant;
		if ( $lazy ) {
			Assets::lazy( 'mbk-search' );
		} else {
			Assets::enqueue( 'mbk-search' );
		}

		return self::render(
			'search-form',
			array(
				'variant'   => $variant,
				'modifier'  => $modifier,
				'prefix'    => $prefix,
				'action'    => $action,
				'hidden'    => $hidden,
				'arrival'   => $request['arrival'],
				'departure' => $request['departure'],
				'adults'    => $request['adults'] ? min( self::MAX_ADULTS, $request['adults'] ) : 2,
				'children'  => min( self::MAX_CHILDREN, $request['children'] ),
				'lazy'      => $lazy,
			)
		);
	}

	public static function searchResults(): string {
		$found = self::searchCards();

		Assets::enqueue( 'mbk-search' );

		return self::render(
			'search-results',
			array(
				'cards'       => $found['cards'],
				'unavailable' => $found['unavailable'],
				'stay'        => $found['stay'],
				'facets'      => self::facets( $found['cards'] ),
				'lang'        => $found['lang'],
				'groups_url'  => self::pageUrl( 'za-grupe', $found['lang'] ),
			)
		);
	}

	/**
	 * Stay cards of the bookable units for the K9 URL params, in recommended (menu) order;
	 * shared by [mbk_search_results] and the Bricks query type `mbk_available_units`.
	 *
	 * @return array{cards: list<array<string, mixed>>, unavailable: int, stay: ?Stay, lang: string}
	 */
	public static function searchCards(): array {
		$lang    = LanguageAdapter::current();
		$request = self::requestStay();
		$adults  = $request['adults'] ? $request['adults'] : 2;
		$search  = Container::search();
		$stay    = $search->stayFromRequest( $request['arrival'], $request['departure'] );
		$results = $search->search( $stay, $adults, $request['children'], $lang );

		$units = array();
		foreach ( Container::units()->all() as $order => $unit ) {
			$units[ $unit->id ] = array( $unit, $order + 1 );
		}

		// Only bookable units are listed (spec + mockup); the rest is summarised under the grid.
		$cards       = array();
		$unavailable = 0;
		foreach ( $results as $result ) {
			if ( ! isset( $units[ (int) $result['id'] ] ) ) {
				continue;
			}
			if ( ! self::bookable( $result, $stay ) ) {
				++$unavailable;
				continue;
			}
			[ $unit, $order ] = $units[ (int) $result['id'] ];
			$cards[]          = self::stayCard( $unit, $order, $result, $stay, $adults, $request['children'], $lang );
		}

		return array(
			'cards'       => $cards,
			'unavailable' => $unavailable,
			'stay'        => $stay,
			'lang'        => $lang,
		);
	}

	/**
	 * Checkout (/rezervacija/): server state from the K9 params — `no_unit`, `dates` (inline
	 * picker), `booked` or `free`; checkout.js re-quotes and runs the two steps + Stripe.
	 */
	public static function checkout(): string {
		$lang    = LanguageAdapter::current();
		$request = self::requestStay();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only prefill.
		$ref  = isset( $_GET['apartman'] ) ? sanitize_text_field( wp_unslash( $_GET['apartman'] ) ) : '';
		$unit = '' !== $ref ? self::resolveUnit( $ref ) : null;

		if ( null === $unit ) {
			Assets::enqueue();

			return self::render(
				'checkout',
				array(
					'state'  => 'no_unit',
					'lang'   => $lang,
					'search' => self::pageUrl( 'pretraga', $lang ),
				)
			);
		}

		$cap      = $unit->capacity;
		$kidsMax  = min( $unit->maxChildren, max( 0, $cap - 1 ) );
		$adults   = $request['adults'] ? max( 1, min( $cap, $request['adults'] ) ) : min( 2, $cap );
		$children = max( 0, min( $request['children'], $kidsMax, $cap - $adults ) );
		$infants  = $request['infants'];

		// Dates that can never be booked (past, below the minimum stay, no rate) go back to the picker.
		$stay   = null;
		$state  = 'dates';
		$notice = '';
		$view   = '';
		if ( $request['arrival'] && $request['departure'] ) {
			$view = substr( $request['arrival'], 0, 7 );
			try {
				$stay = Stay::fromStrings( $unit->id, $request['arrival'], $request['departure'], wp_timezone() );
				Container::pricing()->quote( $stay, $adults, $children, null, null, $infants );
				$state = Container::availability()->isAvailable( $stay ) ? 'free' : 'booked';
			} catch ( BookingException $e ) {
				$stay   = null;
				$notice = BaseController::message( $e->errorCode(), $e->context() );
			}
		}

		$policy   = Container::policy();
		$profile  = AccountPages::profile( $lang );
		$search   = add_query_arg(
			null === $stay ? array() : array(
				'dolazak' => $stay->arrivalString(),
				'odlazak' => $stay->departureString(),
				'odrasli' => $adults,
				'djeca'   => $children,
				'bebe'    => $infants ?: null,
			),
			self::pageUrl( 'pretraga', $lang )
		);

		if ( 'dates' === $state ) {
			Assets::enqueue( 'mbk-calendar', 'mbk-checkout' );
		} else {
			Assets::enqueue( 'mbk-checkout' );
		}

		return self::render(
			'checkout',
			array(
				'state'          => $state,
				'notice'         => $notice,
				'view'           => $view > gmdate( 'Y-m' ) ? $view : '',
				'lang'           => $lang,
				'unit'           => $unit,
				'card'           => self::unitCard( $unit, $lang ),
				'photo'          => $unit->thumbnailId ? (string) wp_get_attachment_image(
					$unit->thumbnailId,
					'large',
					false,
					array(
						'class'   => 'w-photo__image',
						'sizes'   => '(max-width: 767px) 100vw, 50vw',
						'loading' => 'eager',
					)
				) : '',
				'slug'           => self::slug( $unit ),
				'arrival'        => null === $stay ? '' : $stay->arrivalString(),
				'departure'      => null === $stay ? '' : $stay->departureString(),
				'arrival_fmt'    => null === $stay ? '' : Formatter::date( $stay->arrivalString(), $lang, Formatter::SHORT ),
				'departure_fmt'  => null === $stay ? '' : Formatter::date( $stay->departureString(), $lang, Formatter::SHORT ),
				'arrival_long'   => null === $stay ? '' : Formatter::date( $stay->arrivalString(), $lang ),
				'departure_long' => null === $stay ? '' : Formatter::date( $stay->departureString(), $lang ),
				'nights'         => null === $stay ? 0 : $stay->nights(),
				'adults'         => $adults,
				'children'       => $children,
				'infants'        => $infants,
				'cap'            => $cap,
				'kids_max'       => $kidsMax,
				'rule'           => self::childrenRule(),
				'checkin_window' => $unit->checkinFrom . '–' . $unit->checkinTo,
				'policy'         => $policy,
				'profile'        => $profile,
				'login_url'      => AccountPages::isGuest() ? '' : AccountPages::loginUrl( $lang, self::bookingUrl( $unit, $stay, $adults, $children, $lang, $infants ) ),
				'stripe'         => \Marclie\Booking\Payment\StripeConfig::configured(),
				'phone'          => self::businessPhone(),
				'email'          => self::businessField( 'email' ),
				'dates_url'      => $unit->permalink . '#dostupnost',
				'search'         => $search,
				'contact'        => self::contactUrl( $lang ),
				'terms'          => self::pageUrl( 'uvjeti', $lang ),
				'privacy'        => self::privacyUrl( $lang ),
				'checkout_url'   => self::pageUrl( 'rezervacija', $lang ),
			)
		);
	}

	/** Thank-you page (/rezervacija/hvala/?ref&t): shell filled by confirmation.js from GET /bookings/{ref}. */
	public static function confirmation(): string {
		$lang = LanguageAdapter::current();
		Assets::enqueue( 'mbk-confirmation' );
		$guide = get_page_by_path( 'kako-doci', OBJECT, 'post' );

		return self::render(
			'confirmation',
			array(
				'lang'     => $lang,
				'terms'    => self::pageUrl( 'uvjeti', $lang ),
				'guide'    => $guide instanceof \WP_Post && 'publish' === $guide->post_status ? (string) get_permalink( $guide ) : '',
				'checkout' => self::pageUrl( 'rezervacija', $lang ),
				'phone'    => self::businessPhone(),
				'email'    => self::businessField( 'email' ),
				'search'   => self::pageUrl( 'pretraga', $lang ),
				'register' => AccountPages::url( AccountPages::REGISTER, $lang ),
				'account'  => AccountPages::url( AccountPages::ACCOUNT, $lang ) . '#bookings',
			)
		);
	}

	/** Balance payment page (/rezervacija/placanje/?ref&t): pay-balance.js + Payment Element. */
	public static function payBalance(): string {
		$lang = LanguageAdapter::current();
		Assets::enqueue( 'mbk-pay-balance' );

		return self::render(
			'pay-balance',
			array(
				'lang'   => $lang,
				'stripe' => \Marclie\Booking\Payment\StripeConfig::configured(),
				'phone'  => self::businessPhone(),
				'email'  => self::businessField( 'email' ),
				'terms'  => self::pageUrl( 'uvjeti', $lang ),
			)
		);
	}

	/* Helpers shared with templates ------------------------------------------ */

	/**
	 * Language-specific page URL; HR slug fallback before `wp mbk pages --create` ran.
	 */
	public static function pageUrl( string $key, string $lang ): string {
		$url = LanguageAdapter::pageUrl( $key, $lang );
		if ( null === $url ) {
			$url = home_url( '/' . $key . '/' );
			if ( 'en' === $lang ) {
				$url = add_query_arg( 'lang', 'en', $url );
			}
		}

		return $url;
	}

	/** Contact page enquiry anchor (`kontakt` page-map key, HR slug fallback). */
	public static function contactUrl( string $lang ): string {
		return self::pageUrl( 'kontakt', $lang ) . '#upit';
	}

	/**
	 * Booking-page URL with the stay prefilled (stay-card CTA).
	 */
	public static function bookingUrl( Unit $unit, ?Stay $stay, int $adults, int $children, string $lang, int $infants = 0 ): string {
		$args = array( 'apartman' => self::slug( $unit ) );
		if ( null !== $stay ) {
			$args += array(
				'dolazak' => $stay->arrivalString(),
				'odlazak' => $stay->departureString(),
				'odrasli' => $adults,
				'djeca'   => $children,
			);
			if ( $infants > 0 ) {
				$args['bebe'] = $infants;
			}
		}

		return add_query_arg( $args, self::pageUrl( 'rezervacija', $lang ) );
	}

	/**
	 * GET forms drop the query string of their action, so it travels as hidden fields.
	 *
	 * @return array{0: string, 1: array<string, string>}
	 */
	public static function formTarget( string $url ): array {
		$hidden = array();
		$query  = (string) wp_parse_url( $url, PHP_URL_QUERY );
		if ( '' !== $query ) {
			wp_parse_str( $query, $hidden );
			$url = strtok( $url, '?' );
		}

		return array( (string) $url, array_map( 'strval', array_filter( $hidden, 'is_scalar' ) ) );
	}

	public static function slug( Unit $unit ): string {
		return (string) get_post_field( 'post_name', $unit->id );
	}

	/** Lowest price per person per night, "50 €" / "€50"; null without any price. */
	public static function priceFrom( int $unitId, string $lang ): ?string {
		$money = Container::search()->personPriceFrom( $unitId );

		return null === $money ? null : Formatter::price( $money->cents(), $lang );
	}

	/** "min. 2 guests" (non-breaking spaces) next to a per-person price; '' when the unit has no minimum (1). */
	public static function minGuestsNote( int $unitId ): string {
		$min = Container::priceRepository()->minGuests( $unitId );

		/* translators: %d: minimum paying guests of the apartment. */
		return $min > 1 ? str_replace( ' ', "\u{00A0}", sprintf( _n( 'min. %d guest', 'min. %d guests', $min, 'marclie-booking' ), $min ) ) : '';
	}

	/** Unit text after a "from 50 €" price, price-list notation: "/ person / night · min. 2 guests". */
	public static function perPersonUnit( int $unitId ): string {
		return self::withMinGuests( __( '/ person / night', 'marclie-booking' ), $unitId );
	}

	/** "from 50 € / person / night · min. 2 guests"; null without any price. */
	public static function priceFromText( int $unitId, string $lang ): ?string {
		$price = self::priceFrom( $unitId, $lang );

		/* translators: %s: lowest price per person per night, e.g. "50 €". Price-list notation: HR "od %s / osoba / noć". */
		return null === $price ? null : self::withMinGuests( sprintf( __( 'from %s / person / night', 'marclie-booking' ), $price ), $unitId );
	}

	private static function withMinGuests( string $text, int $unitId ): string {
		$note = self::minGuestsNote( $unitId );

		return '' === $note ? $text : $text . ' · ' . $note;
	}

	/** Guest stepper label: "Adults (11+)". */
	public static function adultsLabel(): string {
		/* translators: %d: youngest adult age, e.g. 11. */
		return sprintf( __( 'Adults (%d+)', 'marclie-booking' ), PricingService::CHILD_MAX_AGE + 1 );
	}

	/** Guest stepper label: "Children (5–10)" (half a person each). */
	public static function childrenLabel(): string {
		/* translators: 1: youngest paying child age, e.g. 5, 2: oldest child age, e.g. 10. */
		return sprintf( __( 'Children (%1$d–%2$d)', 'marclie-booking' ), PricingService::CHILD_MIN_AGE, PricingService::CHILD_MAX_AGE );
	}

	/** Guest stepper label: "Children (0–4)" (free, not counted in capacity). */
	public static function infantsLabel(): string {
		/* translators: %d: oldest free child age, e.g. 4. */
		return sprintf( __( 'Children (0–%d)', 'marclie-booking' ), PricingService::CHILD_MIN_AGE - 1 );
	}

	/**
	 * "children pay half the price" after "Sleeps up to 4" under the guest steppers. The minimum of
	 * paying guests is shown next to the price, the ages are in the stepper labels.
	 */
	public static function childrenRule(): string {
		return __( 'children pay half the price', 'marclie-booking' );
	}

	/** Checkbox revealing the children 0–4 stepper; it also says they are free: "Children up to 4 · stay free". */
	public static function infantsToggleLabel(): string {
		/* translators: %d: oldest free child age, e.g. 4. */
		return sprintf( __( 'Children up to %d · stay free', 'marclie-booking' ), PricingService::CHILD_MIN_AGE - 1 );
	}

	/**
	 * Label of the stepper that checkbox reveals: visible "Number of children", plus the age for
	 * screen readers (the checkbox right above already shows it).
	 *
	 * @return array{visible: string, hidden: string}
	 */
	public static function infantsCountLabel(): array {
		return array(
			'visible' => __( 'Number of children', 'marclie-booking' ),
			/* translators: %d: oldest free child age, e.g. 4. */
			'hidden'  => sprintf( __( 'up to %d years', 'marclie-booking' ), PricingService::CHILD_MIN_AGE - 1 ),
		);
	}

	/**
	 * Summary card of a unit for the checkout aside: house, place, photo.
	 *
	 * @return array{house: string, place: string, thumb: string, permalink: string}
	 */
	public static function unitCard( Unit $unit, string $lang ): array {
		$terms = array();
		foreach ( array( 'kuca', 'kat', 'posebno' ) as $taxonomy ) {
			$list               = get_the_terms( $unit->id, $taxonomy );
			$terms[ $taxonomy ] = is_array( $list ) ? wp_list_pluck( $list, 'slug' ) : array();
			if ( 'kuca' === $taxonomy ) {
				$terms['kuca_name'] = is_array( $list ) && $list ? $list[0]->name : '';
			}
		}

		return array(
			'house'     => (string) $terms['kuca_name'],
			'place'     => self::place( $terms, $unit->id, $lang ),
			'thumb'     => $unit->thumbnailId ? (string) wp_get_attachment_image( $unit->thumbnailId, 'medium_large', false, array( 'alt' => '' ) ) : '',
			'permalink' => $unit->permalink ?: (string) get_permalink( $unit->id ),
		);
	}

	/** Business phone from the ACF options page „Podaci o tvrtki”, '' when unset. */
	public static function businessPhone(): string {
		return self::businessField( 'phone' );
	}

	/** Business email from the ACF options, '' when unset. */
	public static function businessEmail(): string {
		return self::businessField( 'email' );
	}

	private static function businessField( string $name ): string {
		$value = function_exists( 'get_field' ) ? get_field( $name, 'option' ) : '';

		return is_string( $value ) ? trim( $value ) : '';
	}

	/** WordPress privacy policy page, else the HR slug `pravila-privatnosti`. */
	public static function privacyUrl( string $lang ): string {
		$url = get_privacy_policy_url();
		if ( '' === $url ) {
			$url = home_url( '/pravila-privatnosti/' );
		}

		return 'en' === $lang ? add_query_arg( 'lang', 'en', $url ) : $url;
	}

	/* Internals -------------------------------------------------------------- */

	/**
	 * Unit by post ID, `property` slug or unit code; '' = the current `property` post.
	 *
	 * @param int|string $ref
	 */
	public static function resolveUnit( $ref ): ?Unit {
		$units = Container::units();
		$ref   = is_string( $ref ) ? trim( $ref ) : $ref;

		if ( '' === $ref || null === $ref || 0 === $ref ) {
			$id = (int) get_the_ID();
			return $id && 'property' === get_post_type( $id ) ? $units->get( $id ) : null;
		}
		if ( is_numeric( $ref ) ) {
			return $units->get( (int) $ref );
		}

		$post = get_page_by_path( sanitize_title( (string) $ref ), OBJECT, 'property' );

		return $post instanceof \WP_Post ? $units->get( $post->ID ) : $units->byCode( (string) $ref );
	}

	/**
	 * Stay/guest values from the K9 URL params (validated, never trusted further).
	 *
	 * @return array{arrival: ?string, departure: ?string, adults: int, children: int, infants: int}
	 */
	public static function requestStay(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only prefill.
		$date = static function ( string $key ): ?string {
			$value = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : null;
		};
		$arrival   = $date( 'dolazak' );
		$departure = $date( 'odlazak' );
		$adults    = isset( $_GET['odrasli'] ) ? absint( $_GET['odrasli'] ) : 0;
		$children  = isset( $_GET['djeca'] ) ? absint( $_GET['djeca'] ) : 0;
		$infants   = isset( $_GET['bebe'] ) ? absint( $_GET['bebe'] ) : 0;
		// phpcs:enable

		if ( null === $arrival || null === $departure || $departure <= $arrival ) {
			$arrival   = null;
			$departure = null;
		}

		return array(
			'arrival'   => $arrival,
			'departure' => $departure,
			'adults'    => min( self::MAX_ADULTS, $adults ),
			'children'  => min( self::MAX_CHILDREN, $children ),
			'infants'   => min( self::MAX_INFANTS, $infants ),
		);
	}

	/**
	 * Default coupon teaser ("5 %" / "5%" off with code DIRECT5), null when none is active.
	 *
	 * @return array{amount: string, code: string}|null
	 */
	public static function directOffer( string $lang ): ?array {
		$code   = Container::policy()->defaultCoupon;
		$coupon = $code ? Container::couponRepository()->findActiveByCode( $code ) : null;
		if ( null === $coupon || ! $coupon->active || $coupon->amount <= 0 || null !== $coupon->guestId ) {
			return null;
		}

		$amount = \Marclie\Booking\Coupons\Coupon::PCT === $coupon->type
			? Formatter::percent( $coupon->amount, $lang )
			: Formatter::price( $coupon->amount, $lang );

		return array(
			'amount' => $amount,
			'code'   => $coupon->code,
		);
	}

	/**
	 * Bookable = free, fits the party, no blocking reason, and (with dates) a stay total.
	 *
	 * @param array<string, mixed> $result One SearchService unit row.
	 */
	private static function bookable( array $result, ?Stay $stay ): bool {
		return $result['available'] && $result['fits'] && null === $result['reason']
			&& ( null === $stay || null !== $result['total_cents'] );
	}

	/**
	 * @param array<string, mixed> $result One bookable SearchService unit row.
	 * @return array<string, mixed>
	 */
	private static function stayCard( Unit $unit, int $order, array $result, ?Stay $stay, int $adults, int $children, string $lang ): array {
		$terms = self::cardTerms( $unit->id );

		$bedrooms  = (int) get_post_meta( $unit->id, 'bedrooms', true );
		$bathrooms = max( 1, (int) get_post_meta( $unit->id, 'bathrooms', true ) );
		$size      = (int) get_post_meta( $unit->id, 'size_m2', true );
		$permalink = (string) ( $result['permalink'] ?: $unit->permalink );
		// With dates the sort/price facet works on stay totals, without on the lowest apartment price per night.
		$price = null !== $stay ? (int) $result['total_cents'] : (int) $result['nightly_from_cents'];

		return array(
			'unit'      => $unit,
			'order'     => $order,
			'place'     => self::place( $terms, $unit->id, $lang, true ),
			'badges'    => self::badges( $terms ),
			'guests'    => $unit->capacity,
			'bedrooms'  => $bedrooms,
			'bathrooms' => $bathrooms,
			'size'      => $size,
			'terms'     => $terms,
			'data'      => array(
				'order'     => $order,
				'price'     => (string) round( $price / 100, 2 ),
				'sold'      => $unit->capacity,
				'za'        => implode( '|', $terms['za'] ),
				'kuca'      => implode( '|', $terms['kuca'] ),
				'gosti'     => implode( '|', array_filter( array( 2, 4, 6, 8 ), static fn( int $t ): bool => $unit->capacity >= $t ) ),
				'sobe'      => $bedrooms > 0 ? (string) $bedrooms : 'studio',
				'kupaonice' => implode( '|', range( 1, min( 3, $bathrooms ) ) ),
				'vani'      => implode( '|', $terms['vani'] ),
				'pogled'    => implode( '|', $terms['pogled'] ),
				'posebno'   => implode( '|', $terms['posebno'] ),
			),
			'total'     => null !== $stay ? (string) $result['total_formatted'] : null,
			'nights'    => null !== $stay ? $stay->nights() : 0,
			'from'      => $result['person_from_cents'] > 0 ? (string) $result['person_from_formatted'] : null,
			'book_url'  => null !== $stay ? self::bookingUrl( $unit, $stay, $adults, $children, $lang, self::requestStay()['infants'] ) : $permalink . '#rezerviraj',
			'permalink' => $permalink,
			'photos'    => self::cardPhotos( $unit ),
			'lang'      => $lang,
		);
	}

	/**
	 * Photos of a search-card slider: the featured image, then the gallery, at most CARD_PHOTOS.
	 *
	 * @return list<int> Attachment IDs.
	 */
	public static function cardPhotos( Unit $unit ): array {
		$gallery = function_exists( 'get_field' ) ? get_field( 'gallery', $unit->id, false ) : get_post_meta( $unit->id, 'gallery', true );
		$ids     = array_merge( array( $unit->thumbnailId ), is_array( $gallery ) ? array_map( 'intval', $gallery ) : array() );

		return array_slice( array_values( array_unique( array_filter( $ids ) ) ), 0, self::CARD_PHOTOS );
	}

	/**
	 * Translated labels of known facet/floor slugs (taxonomy term names are HR content).
	 *
	 * @return array<string, string>
	 */
	public static function termLabels( string $taxonomy ): array {
		return match ( $taxonomy ) {
			'za'      => array(
				'dvoje'   => __( 'For two', 'marclie-booking' ),
				'obitelj' => __( 'For families', 'marclie-booking' ),
				'grupe'   => __( 'For larger groups', 'marclie-booking' ),
			),
			'vani'    => array(
				'terasa'         => __( 'Terrace', 'marclie-booking' ),
				'balkon'         => __( 'Balcony', 'marclie-booking' ),
				'izlaz-na-bazen' => __( 'Direct pool access', 'marclie-booking' ),
			),
			'pogled'  => array(
				'more'        => __( 'Sea', 'marclie-booking' ),
				'vrt-i-bazen' => __( 'Garden and pool', 'marclie-booking' ),
			),
			'posebno' => array(
				'jacuzzi'     => __( 'Hot tub on the terrace', 'marclie-booking' ),
				'dvije-etaze' => __( 'Over two floors', 'marclie-booking' ),
				'prizemlje'   => __( 'Ground floor', 'marclie-booking' ),
				'vanjski-tus' => __( 'Outdoor shower', 'marclie-booking' ),
			),
			'kat'     => array(
				'prizemlje' => __( 'Ground floor', 'marclie-booking' ),
				'1-kat'     => __( '1st floor', 'marclie-booking' ),
				'2-kat'     => __( '2nd floor', 'marclie-booking' ),
				'3-kat'     => __( '3rd floor', 'marclie-booking' ),
				'vrh'       => __( 'Top floor', 'marclie-booking' ),
			),
			default   => array(),
		};
	}

	/**
	 * "House 1 · Prizemlje": house + the unit's `kat` term — in Croatian its own name (the site's wording,
	 * e.g. "Vrh"), in other languages the translated label — + "(dvije etaže)" / "(over two floors)" when the unit
	 * has the `posebno` term dvije-etaze. $short (stay card, one line): without the "(…)" part.
	 *
	 * @param array<string, mixed> $terms
	 */
	private static function place( array $terms, int $unitId, string $lang, bool $short = false ): string {
		$floor = '';
		if ( 'hr' === $lang ) {
			$own   = get_the_terms( $unitId, 'kat' );
			$floor = is_array( $own ) ? implode( ' / ', wp_list_pluck( $own, 'name' ) ) : '';
		} else {
			$floors = self::termLabels( 'kat' );
			foreach ( $terms['kat'] as $slug ) {
				$floor = $floors[ $slug ] ?? $floor;
			}
		}
		if ( ! $short && '' !== $floor && in_array( 'dvije-etaze', $terms['posebno'], true ) ) {
			/* translators: %s: floor, e.g. "3rd floor". */
			$floor = sprintf( __( '%s (over two floors)', 'marclie-booking' ), $floor );
		}

		$parts = array_filter( array( (string) $terms['kuca_name'], $floor ) );

		return implode( ' · ', $parts );
	}

	/**
	 * Term slugs of a unit as the stay card uses them (za, kuca, kat, vani, pogled, posebno) + `kuca_name`.
	 *
	 * @return array<string, mixed>
	 */
	private static function cardTerms( int $unitId ): array {
		$terms = array();
		foreach ( array( 'za', 'kuca', 'kat', 'vani', 'pogled', 'posebno' ) as $taxonomy ) {
			$list               = get_the_terms( $unitId, $taxonomy );
			$terms[ $taxonomy ] = is_array( $list ) ? wp_list_pluck( $list, 'slug' ) : array();
			if ( 'kuca' === $taxonomy ) {
				$terms['kuca_name'] = is_array( $list ) && $list ? $list[0]->name : '';
			}
		}

		return $terms;
	}

	/**
	 * Stay-card badge labels of a unit (same as the search results' cards; Bricks tag `{mbk_card_badge:N}`).
	 *
	 * @return list<string>
	 */
	public static function unitBadges( int $unitId ): array {
		return self::badges( self::cardTerms( $unitId ) );
	}

	/**
	 * @param array<string, mixed> $terms
	 * @return list<string>
	 */
	private static function badges( array $terms ): array {
		$badges = array();
		if ( in_array( 'jacuzzi', $terms['posebno'], true ) ) {
			$badges[] = __( 'Hot tub', 'marclie-booking' );
		}
		if ( in_array( 'dvije-etaze', $terms['posebno'], true ) ) {
			$badges[] = __( 'Two floors', 'marclie-booking' );
		}
		if ( in_array( 'izlaz-na-bazen', $terms['vani'], true ) ) {
			$badges[] = __( 'Direct pool access', 'marclie-booking' );
		} elseif ( in_array( 'more', $terms['pogled'], true ) ) {
			$badges[] = __( 'Sea view', 'marclie-booking' );
		} elseif ( in_array( 'vrt-i-bazen', $terms['pogled'], true ) ) {
			$badges[] = __( 'Pool view', 'marclie-booking' );
		}

		return (array) apply_filters( 'mbk_stay_card_badges', $badges, $terms );
	}

	/**
	 * Facet groups with option counts over all rendered cards.
	 *
	 * @param list<array<string, mixed>> $cards
	 * @return list<array<string, mixed>>
	 */
	private static function facets( array $cards ): array {
		$count = static function ( string $facet, string $value ) use ( $cards ): int {
			$n = 0;
			foreach ( $cards as $card ) {
				$n += in_array( $value, explode( '|', (string) $card['data'][ $facet ] ), true ) ? 1 : 0;
			}
			return $n;
		};

		$legends = array(
			'za'        => __( "Who's travelling", 'marclie-booking' ),
			'kuca'      => __( 'House', 'marclie-booking' ),
			'gosti'     => __( 'Guests (min.)', 'marclie-booking' ),
			'sobe'      => __( 'Bedrooms', 'marclie-booking' ),
			'kupaonice' => __( 'Bathrooms', 'marclie-booking' ),
			'vani'      => __( 'Outdoor space', 'marclie-booking' ),
			'pogled'    => __( 'View', 'marclie-booking' ),
			'posebno'   => __( 'Special features', 'marclie-booking' ),
		);

		$termGroup = static function ( string $taxonomy ) use ( $legends, $count ): ?array {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => true,
					// term order = creation order (mockup facet order), not the label
					'orderby'    => 'term_id',
				)
			);
			if ( ! is_array( $terms ) || ! $terms ) {
				return null;
			}
			$labels  = self::termLabels( $taxonomy );
			$options = array();
			foreach ( $terms as $term ) {
				$n = $count( $taxonomy, $term->slug );
				if ( $n > 0 ) {
					$options[] = array(
						'value' => $term->slug,
						'label' => $labels[ $term->slug ] ?? $term->name,
						'count' => $n,
					);
				}
			}
			return $options ? array(
				'facet'   => $taxonomy,
				'legend'  => $legends[ $taxonomy ],
				'type'    => 'check',
				'options' => $options,
			) : null;
		};

		$pills = static function ( string $facet, array $values, bool $radio, string $last = '' ) use ( $legends ): array {
			$options = array();
			foreach ( $values as $i => $value ) {
				$isLast    = $i === count( $values ) - 1;
				$options[] = array(
					'value' => (string) $value,
					'label' => 'studio' === $value ? __( 'Studio', 'marclie-booking' ) : ( $radio && ! ( $isLast && '' !== $last ) ? $value . '+' : (string) $value ),
				);
			}
			return array(
				'facet'   => $facet,
				'legend'  => $legends[ $facet ],
				'type'    => $radio ? 'radio' : 'pill',
				'options' => $options,
			);
		};

		$groups = array();
		foreach ( self::FACET_TAXONOMIES as $taxonomy ) {
			$groups[] = $termGroup( $taxonomy );
		}
		$groups[] = $pills( 'gosti', array( 2, 4, 6, 8 ), true );
		// "Studio" pill only when a listed unit has no separate bedroom (mockup: apartmani yes, pretraga with dates no).
		$groups[] = $pills( 'sobe', array_merge( $count( 'sobe', 'studio' ) > 0 ? array( 'studio' ) : array(), array( 1, 2, 3 ) ), false );
		$groups[] = $pills( 'kupaonice', array( 1, 2, 3 ), true, 'exact' );
		foreach ( self::FACET_TAXONOMIES_TAIL as $taxonomy ) {
			$groups[] = $termGroup( $taxonomy );
		}

		return array_values( array_filter( $groups ) );
	}
}
