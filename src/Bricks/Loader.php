<?php
/**
 * Bricks integration (phase 08), loaded only when the Bricks theme is active: custom elements
 * (panel category "Marclie Booking"), dynamic tags (group "Booking"), the query type
 * `mbk_available_units`, and the CSS of the booking widgets.
 *
 * CSS: the widget markup carries the mockup's `w-` classes, held by Bricks global classes, but
 * Bricks only prints a global class used by an element of the page. When the page/template
 * data holds an mbk-* element (seen while Bricks generates the page CSS, before its <head>
 * pass), every global class of the "Components" category plus the "Page sections" blocks that
 * element's markup uses (ELEMENT_BLOCKS: the whole block `w-x`, `w-x__*`, `w-x--*`) is
 * registered for output and `mbk-base.css` is enqueued, so both land in <head>. Widgets found
 * only while rendering (shortcodes in post content…) get the Components classes printed in the
 * footer; the child theme's rendered-markup scan prints their Page-sections blocks.
 */

namespace Marclie\Booking\Bricks;

use Marclie\Booking\Frontend\Assets;

defined( 'ABSPATH' ) || exit;

final class Loader {

	public const CATEGORY = 'marclie-booking';

	/** Element name => class (file = Elements/{short class}.php). */
	public const ELEMENTS = array(
		'mbk-booking-box'           => Elements\BookingBox::class,
		'mbk-availability-calendar' => Elements\AvailabilityCalendar::class,
		'mbk-bookbar'               => Elements\Bookbar::class,
		'mbk-search-form'           => Elements\SearchForm::class,
		'mbk-search-results'        => Elements\SearchResults::class,
		'mbk-checkout'              => Elements\Checkout::class,
		'mbk-confirmation'          => Elements\Confirmation::class,
		'mbk-pay-balance'           => Elements\PayBalance::class,
		'mbk-login'                 => Elements\AccountLogin::class,
		'mbk-register'              => Elements\AccountRegister::class,
		'mbk-forgot-password'       => Elements\AccountForgotPassword::class,
		'mbk-account'               => Elements\Account::class,
	);

	/** Site-chrome elements: registered like the widgets but load no booking CSS/JS/classes. */
	public const CHROME_ELEMENTS = array(
		'mbk-lang-switch' => Elements\LangSwitch::class,
	);

	/** Global class categories (name prefixes) printed wherever a booking widget renders. */
	public const CLASS_CATEGORIES = array( 'Components' );

	/** Category of the section blocks (`w-<layout>`) some widget markup lives in. */
	public const SECTION_CATEGORY = 'Page sections';

	/**
	 * Page-sections blocks whose classes the element's own markup prints (checked with
	 * `.tools/m2b/booking_verify.py`); the surrounding section is the page's.
	 *
	 * @var array<string, list<string>>
	 */
	public const ELEMENT_BLOCKS = array(
		'mbk-booking-box'           => array( 'w-unit-body' ),
		'mbk-availability-calendar' => array( 'w-unit-body' ),
		'mbk-bookbar'               => array( 'w-unit-body' ),
		'mbk-search-form'           => array(),
		'mbk-search-results'        => array( 'w-stay-catalogue' ),
		// the date step (no dates in the URL) reuses the stay page's nights calendar
		'mbk-checkout'              => array( 'w-unit-body' ),
		'mbk-confirmation'          => array(),
		'mbk-pay-balance'           => array(),
		'mbk-login'                 => array( 'w-auth-split' ),
		'mbk-register'              => array( 'w-auth-split' ),
		'mbk-forgot-password'       => array( 'w-auth-split' ),
		'mbk-account'               => array( 'w-account-board', 'w-page-title' ),
	);

	/** Page-sections blocks registered for output in this request. */
	private static array $blocksAdded = array();

	/** An mbk-* element is part of this request's Bricks data. */
	private static bool $hasElements = false;

	/** Booking global classes already registered for output. */
	private static bool $classesAdded = false;

	public static function hooks(): void {
		add_action( 'after_setup_theme', array( self::class, 'boot' ) );
	}

	public static function boot(): void {
		if ( ! defined( 'BRICKS_VERSION' ) ) {
			return;
		}

		// Bricks registers its own elements on init (10).
		add_action( 'init', array( self::class, 'registerElements' ), 11 );
		add_filter( 'bricks/builder/i18n', array( self::class, 'builderI18n' ) );
		add_filter( 'bricks/assets/generate_css_from_element', array( self::class, 'onElementCss' ), 10, 2 );
		add_filter( 'mbk_enqueue_front_assets', array( self::class, 'enqueueFrontAssets' ) );
		add_action( 'wp_footer', array( self::class, 'footerClasses' ), 9 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'builderAssets' ), 20 );

		DynamicTags::hooks();
		QueryTypes::hooks();
	}

	public static function registerElements(): void {
		if ( ! class_exists( '\Bricks\Elements' ) ) {
			return;
		}

		foreach ( self::ELEMENTS + self::CHROME_ELEMENTS as $name => $class ) {
			$short = substr( $class, strrpos( $class, '\\' ) + 1 );
			\Bricks\Elements::register_element( __DIR__ . '/Elements/' . $short . '.php', $name, $class );
		}
	}

	/**
	 * Panel label of the element category.
	 *
	 * @param array<string, string> $i18n
	 * @return array<string, string>
	 */
	public static function builderI18n( $i18n ) {
		$i18n[ self::CATEGORY ] = esc_html__( 'Marclie Booking', 'marclie-booking' );

		return $i18n;
	}

	/**
	 * Called by Bricks for every element of the page, its templates and components while it
	 * generates the page CSS (wp_enqueue_scripts:11), before the global classes are printed.
	 *
	 * @param mixed                $loopElements Passed through.
	 * @param array<string, mixed> $element
	 * @return mixed
	 */
	public static function onElementCss( $loopElements, $element = array() ) {
		$name = is_array( $element ) ? (string) ( $element['name'] ?? '' ) : '';
		if ( isset( self::ELEMENTS[ $name ] ) ) {
			self::$hasElements = true;
			self::addGlobalClasses();
			self::addBlocks( self::ELEMENT_BLOCKS[ $name ] ?? array() );
		}

		return $loopElements;
	}

	/** `mbk_enqueue_front_assets`: mbk-base.css in <head> on pages with an mbk-* element. */
	public static function enqueueFrontAssets( $enqueue ): bool {
		return (bool) $enqueue || self::$hasElements;
	}

	/** A widget rendered without being announced (shortcode, PHP): its classes in the footer. */
	public static function footerClasses(): void {
		if ( wp_style_is( Assets::STYLE, 'enqueued' ) ) {
			self::addGlobalClasses();
		}
	}

	/**
	 * Registers every global class of the booking class categories for output, as if a
	 * Bricks `div` used it (classes hold their CSS in custom CSS, element-agnostic).
	 */
	public static function addGlobalClasses(): void {
		if ( self::$classesAdded || ! class_exists( '\Bricks\Assets' ) || ! class_exists( '\Bricks\Database' ) ) {
			return;
		}
		self::$classesAdded = true;

		self::register( self::bookingClassIds() );
	}

	/**
	 * Registers the classes of Page-sections blocks (`w-x`, `w-x__*`, `w-x--*`) for output.
	 *
	 * @param list<string> $blocks
	 */
	public static function addBlocks( array $blocks ): void {
		$blocks = array_values( array_diff( $blocks, self::$blocksAdded ) );
		if ( ! $blocks || ! class_exists( '\Bricks\Assets' ) || ! class_exists( '\Bricks\Database' ) ) {
			return;
		}
		self::$blocksAdded = array_merge( self::$blocksAdded, $blocks );

		self::register( self::blockClassIds( $blocks ) );
	}

	/** @param list<string> $ids Global class ids printed as if a Bricks `div` used them. */
	private static function register( array $ids ): void {
		foreach ( $ids as $id ) {
			if ( ! isset( \Bricks\Assets::$global_classes_elements[ $id ] ) ) {
				\Bricks\Assets::$global_classes_elements[ $id ] = array( 'div' );
			}
		}
	}

	/**
	 * Builder iframe: CSS of every booking global class (Components + the Page-sections blocks
	 * of every widget). The builder generates global class CSS only for classes set on its
	 * elements (`_cssGlobalClasses`); the widgets use them from server-rendered markup, so
	 * without this they render unstyled in the canvas. Generated once per load: classes
	 * assigned to builder elements still get Bricks' live CSS after it.
	 */
	public static function builderClassesCss(): string {
		if ( ! class_exists( '\Bricks\Assets' ) || ! class_exists( '\Bricks\Database' ) ) {
			return '';
		}

		$blocks = array_merge( ...array_values( self::ELEMENT_BLOCKS ) );
		$ids    = array_values( array_unique( array_merge( self::bookingClassIds(), self::blockClassIds( array_unique( $blocks ) ) ) ) );
		if ( ! $ids ) {
			return '';
		}

		// generate_global_classes() reads and fills Bricks' request-wide CSS state: isolate it.
		$elements    = \Bricks\Assets::$global_classes_elements;
		$inline      = \Bricks\Assets::$inline_css;
		$breakpoints = \Bricks\Assets::$inline_css_breakpoints;
		$unique      = \Bricks\Assets::$unique_inline_css;
		$dynamic     = \Bricks\Assets::$inline_css_dynamic_data;
		$generating  = \Bricks\Assets::$current_generating_element;
		$css         = '';

		try {
			\Bricks\Assets::$global_classes_elements = array_fill_keys( $ids, array( 'div' ) );
			$css                                     = \Bricks\Assets::generate_global_classes( 'mbk_builder_global_classes' );
		} finally {
			\Bricks\Assets::$global_classes_elements    = $elements;
			\Bricks\Assets::$inline_css                 = $inline;
			\Bricks\Assets::$inline_css_breakpoints     = $breakpoints;
			\Bricks\Assets::$unique_inline_css          = $unique;
			\Bricks\Assets::$inline_css_dynamic_data    = $dynamic;
			\Bricks\Assets::$current_generating_element = $generating;
		}

		return is_string( $css ) ? $css : '';
	}

	/**
	 * IDs of the global classes in the booking class categories.
	 *
	 * @return list<string>
	 */
	private static function bookingClassIds(): array {
		return self::classIds(
			self::globalClasses(),
			get_option( BRICKS_DB_GLOBAL_CLASSES_CATEGORIES, array() ),
			(array) apply_filters( 'mbk_bricks_global_class_categories', self::CLASS_CATEGORIES )
		);
	}

	/**
	 * IDs of the Page-sections global classes of `$blocks`.
	 *
	 * @param list<string> $blocks
	 * @return list<string>
	 */
	private static function blockClassIds( array $blocks ): array {
		return self::classIds( self::globalClasses(), get_option( BRICKS_DB_GLOBAL_CLASSES_CATEGORIES, array() ), array( self::SECTION_CATEGORY ), $blocks );
	}

	/** @return mixed Bricks global classes. */
	private static function globalClasses() {
		return \Bricks\Database::$global_data['globalClasses'] ?? get_option( BRICKS_DB_GLOBAL_CLASSES, array() );
	}

	/**
	 * IDs of the global classes whose category name starts with one of `$prefixes` and, when
	 * `$blocks` is given, whose name belongs to one of those BEM blocks (`b`, `b__*`, `b--*`).
	 *
	 * @param mixed        $classes    Bricks global classes ([id, name, category, …]).
	 * @param mixed        $categories Bricks global class categories ([id, name]).
	 * @param list<string> $prefixes
	 * @param list<string> $blocks
	 * @return list<string>
	 */
	public static function classIds( $classes, $categories, array $prefixes, array $blocks = array() ): array {
		$wanted = array();
		foreach ( is_array( $categories ) ? $categories : array() as $category ) {
			$name = (string) ( $category['name'] ?? '' );
			foreach ( $prefixes as $prefix ) {
				if ( '' !== (string) $prefix && str_starts_with( $name, (string) $prefix ) ) {
					$wanted[ (string) ( $category['id'] ?? '' ) ] = true;
					break;
				}
			}
		}

		$ids = array();
		foreach ( is_array( $classes ) ? $classes : array() as $class ) {
			if ( isset( $class['id'], $class['category'] ) && isset( $wanted[ (string) $class['category'] ] )
				&& ( ! $blocks || self::inBlocks( (string) ( $class['name'] ?? '' ), $blocks ) ) ) {
				$ids[] = (string) $class['id'];
			}
		}

		return $ids;
	}

	/** @param list<string> $blocks */
	public static function inBlocks( string $name, array $blocks ): bool {
		foreach ( $blocks as $block ) {
			if ( $name === $block || str_starts_with( $name, $block . '__' ) || str_starts_with( $name, $block . '--' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Builder iframe: widget CSS (mbk-base.css + the booking global classes), the inline-calendar
	 * script and the element script callback `mbkBricksCalendar` (Bricks calls an element's
	 * `$scripts` after rendering it).
	 */
	public static function builderAssets(): void {
		if ( ! function_exists( 'bricks_is_builder_iframe' ) || ! bricks_is_builder_iframe() ) {
			return;
		}

		Assets::enqueue( 'mbk-calendar' );
		wp_add_inline_script( 'mbk-calendar', 'window.mbkBricksCalendar=function(){if(window.mbk&&window.mbk.initCalendars){window.mbk.initCalendars();}};' );

		$css = self::builderClassesCss();
		if ( '' !== $css ) {
			wp_add_inline_style( Assets::STYLE, $css );
		}
	}
}
