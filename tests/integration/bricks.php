<?php
/**
 * Integration checks of the Bricks integration (phase 08): elements registered and
 * rendering inside the Bricks root, dynamic tags for a unit and inside the query loop,
 * the `mbk_available_units` query type (2027-07-12 → 19, 2 + 2 = 14 free units), sort.
 * Prices: run.php's test table (40 € per person every month, minimum 3 nights, minimum guests = capacity).
 *
 * Included by run.php inside its try block when Bricks is the active theme (uses
 * $mbkCheck). Read-only: creates no data; `$_GET` and the global post are restored.
 */

use Marclie\Booking\Bricks\Loader;
use Marclie\Booking\Bricks\QueryTypes;

defined( 'ABSPATH' ) || exit( 1 );

$bricksGet  = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$bricksPost = $GLOBALS['post'] ?? null;

$bricksQuery = static function ( string $id ): \Bricks\Query {
	return new \Bricks\Query(
		array(
			'id'       => $id,
			'name'     => 'div',
			'settings' => array(
				'hasLoop' => true,
				'query'   => array( 'objectType' => QueryTypes::TYPE ),
			),
		)
	);
};

try {
	$registered = array_intersect( array_keys( Loader::ELEMENTS ), array_keys( \Bricks\Elements::$elements ) );
	$mbkCheck( 'bricks: 12 mbk-* elements registered', 12 === count( $registered ), implode( ',', $registered ) );

	$u101 = get_page_by_path( 'apartman-101', OBJECT, 'property' );
	$u1   = get_page_by_path( 'apartman-1', OBJECT, 'property' );
	$mbkCheck( 'bricks: {mbk_checkin_window} per unit', '16:00–21:00' === bricks_render_dynamic_data( '{mbk_checkin_window}', $u101->ID ) && '15:00–22:00' === bricks_render_dynamic_data( '{mbk_checkin_window}', $u1->ID ) );
	// Apartman 1 sleeps 6; the test table charges at least the capacity. "min. 6 osoba" keeps together (no-break spaces).
	$mbkMin6 = "min.\u{00A0}6\u{00A0}osoba";
	$mbkCheck( 'bricks: {mbk_price_from} / :raw (per person, with the minimum)', 'od 40 € / osoba / noć · ' . $mbkMin6 === bricks_render_dynamic_data( '{mbk_price_from}', $u1->ID ) && '40' === bricks_render_dynamic_data( '{mbk_price_from:raw}', $u1->ID ), bricks_render_dynamic_data( '{mbk_price_from}', $u1->ID ) );
	$mbkCheck( 'bricks: {mbk_min_guests} / :raw', $mbkMin6 . ' · 6' === bricks_render_dynamic_data( '{mbk_min_guests} · {mbk_min_guests:raw}', $u1->ID ), bricks_render_dynamic_data( '{mbk_min_guests} · {mbk_min_guests:raw}', $u1->ID ) );
	$mbkCheck(
		'bricks: policy, coupon, min stay, checkout tags',
		'30 · 14 · 30 · 10:00 · 3 · 3' === bricks_render_dynamic_data( '{mbk_policy:deposit_pct} · {mbk_policy:balance_days} · {mbk_policy:free_cancel_days} · {mbk_checkout_until} · {mbk_min_stay} · {mbk_min_stay:max}', $u101->ID )
			&& 'DIRECT5' === bricks_render_dynamic_data( '{mbk_coupon_default}', $u101->ID )
	);
	$mbkCheck( 'bricks: tags keep HTML and foreign tags', '<b>16:00–21:00</b> {mbk_nope}' === bricks_render_dynamic_data( '<b>{mbk_checkin_window}</b> {mbk_nope}', $u101->ID ) );
	$mbkCheck( 'bricks: single-tag render', '30' === \Bricks\Integrations\Dynamic_Data\Providers::render_tag( '{mbk_policy:deposit_pct}', $u101->ID ) );

	$_GET = array(
		'dolazak' => '2027-07-12',
		'odlazak' => '2027-07-19',
		'odrasli' => '2',
		'djeca'   => '2',
	);
	QueryTypes::flush();
	$query = $bricksQuery( 'mbkit1' );
	$names = array_map( static fn( \WP_Post $p ): string => $p->post_name, $query->query_result );
	$mbkCheck( 'bricks: mbk_available_units 2027-07-12→19 2+2 → 14 free units, menu order', 14 === $query->count && 'apartman-1' === ( $names[0] ?? '' ) && ! in_array( 'apartman-6', $names, true ), implode( ',', $names ) );
	$html = $query->render( static fn(): string => '<i data-price="{mbk_facet:price}">{post_title}|{mbk_stay_total}|{mbk_stay_nights}</i>', array() );
	$query->destroy();
	// Apartman 101 sleeps 4 and the test table charges at least 4: 7 × 160 €.
	$mbkCheck( 'bricks: loop tags use the loop unit', str_contains( $html, 'Apartman 101|1.120 €|7</i>' ) && str_contains( $html, 'data-price="1120"' ), substr( $html, 0, 160 ) );
	$mbkCheck( 'bricks: {mbk_stay_total} outside the loop (URL dates)', '1.120 €' === bricks_render_dynamic_data( '{mbk_stay_total}', $u101->ID ) );

	$_GET['sort'] = 'price-desc';
	$query        = $bricksQuery( 'mbkit2' );
	$first        = $query->query_result[0]->post_name ?? '';
	$query->destroy();
	$mbkCheck( 'bricks: ?sort=price-desc puts the dearest first', str_starts_with( $first, 'predsjednicki-apartman' ), $first );

	$_GET = array( 'odrasli' => '5' );
	$query = $bricksQuery( 'mbkit3' );
	$count = $query->count;
	$query->destroy();
	$mbkCheck( 'bricks: no dates → every unit that fits (5 adults: 8)', 8 === $count, (string) $count );
	$mbkCheck( 'bricks: {mbk_stay_total} empty without dates', '' === bricks_render_dynamic_data( '{mbk_stay_total}', $u101->ID ) );

	$_GET = array();
	$GLOBALS['post'] = $u101; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	setup_postdata( $u101 );
	$box = \Bricks\Frontend::render_element(
		array(
			'id'       => 'mbkbox',
			'name'     => 'mbk-booking-box',
			'settings' => array(
				'hideNote'    => true,
				'_cssClasses' => 'mbk-extra',
			),
		)
	);
	$mbkCheck( 'bricks: booking box inside the Bricks root, options applied', (bool) preg_match( '/^<div id="brxe-mbkbox" class="brxe-mbk-booking-box mbk-extra"><aside class="w-unit-body__booking"/', $box ) && str_contains( $box, 'w-button-primary w-button-primary--block' ) && ! str_contains( $box, 'w-unit-body__note' ) && str_contains( $box, 'data-unit="' . $u101->ID . '"' ) );

	Loader::onElementCss( array(), array( 'name' => 'mbk-search-form' ) );
	$mbkCheck( 'bricks: an mbk-* element in the page CSS pass enqueues mbk-base in <head>', true === Loader::enqueueFrontAssets( false ) );

	$usedClasses = \Bricks\Assets::$global_classes_elements;
	$builderCss  = Loader::builderClassesCss();
	$mbkCheck( 'bricks: builder canvas gets the booking global class CSS, page CSS state untouched', str_contains( $builderCss, '.w-checkout__steps' ) && str_contains( $builderCss, '.w-stay-catalogue__grid' ) && $usedClasses === \Bricks\Assets::$global_classes_elements, strlen( $builderCss ) . ' bytes' );

	$_GET = array( 'apartman' => 'apartman-101' );
	$crumb = bricks_render_dynamic_data( '{mbk_checkout_unit:house}|{mbk_checkout_unit:title}|{mbk_checkout_unit:url}|{mbk_checkout_unit:house_url}', $u1->ID );
	$mbkCheck( 'bricks: {mbk_checkout_unit:*} from ?apartman=', (bool) preg_match( '#^[^|]+\|Apartman 101\|https?://[^|]+/apartman-101/\|https?://[^|]+/[^|/]+/$#', $crumb ), $crumb );
	$_GET = array( 'apartman' => 'nope' );
	$mbkCheck( 'bricks: {mbk_checkout_unit:title} empty for an unknown apartment', '' === bricks_render_dynamic_data( '{mbk_checkout_unit:title}', $u1->ID ) );

	$_GET  = array();
	$cards = \Marclie\Booking\Frontend\Renderer::searchResults();
	$mbkCheck(
		'search cards: photo slider, first photo of the first card only not lazy',
		17 === substr_count( $cards, 'class="w-card-stay__media w-slider" data-slider' ) && str_contains( $cards, 'data-slider-next' ) && 1 === substr_count( $cards, '<img' ) - substr_count( $cards, 'loading="lazy"' )
	);
} finally {
	$_GET = $bricksGet;
	QueryTypes::flush();
	$GLOBALS['post'] = $bricksPost; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	wp_reset_postdata();
}
