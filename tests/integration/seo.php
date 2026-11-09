<?php
/**
 * Integration checks of phase 09 SEO over HTTP (rendered pages, as a crawler sees them):
 * robots + a single canonical on funnel pages and `?lang=` URLs, the apartment page JSON-LD
 * (site Schema JSON-LD snippet, valid JSON, Apartment + BreadcrumbList, no AggregateRating/Review), the
 * server-rendered „od 40 € / osoba / noć” of apartman-101 (run.php's test price table), the HR | EN switch keeping the query,
 * and the page sitemap without funnel pages.
 *
 * Included by run.php inside its try block (uses $mbkCheck). Read-only. Base URL: home_url(),
 * or MBK_TEST_SITE_URL when the CLI home differs from the served site.
 */

use Marclie\Booking\Bricks\Loader;
use Marclie\Booking\Frontend\Seo;
use Marclie\Booking\Settings;

defined( 'ABSPATH' ) || exit( 1 );

$seoBase = untrailingslashit( getenv( 'MBK_TEST_SITE_URL' ) ? (string) getenv( 'MBK_TEST_SITE_URL' ) : home_url() );
$seoGet  = static function ( string $path ) use ( $seoBase ): string {
	$res = wp_remote_get(
		$seoBase . $path,
		array(
			'timeout'     => 60,
			'sslverify'   => false,
			'redirection' => 3,
		)
	);

	return is_wp_error( $res ) ? '' : (string) wp_remote_retrieve_body( $res );
};
$seoTags = static function ( string $html ): array {
	preg_match_all( '/<meta name=[\'"]robots[\'"] content=[\'"]([^\'"]*)[\'"]/', $html, $robots );
	preg_match_all( '/<link rel=[\'"]canonical[\'"] href=[\'"]([^\'"]*)[\'"]/', $html, $canonical );

	return array( implode( '|', $robots[1] ), $canonical[1] );
};
$seoPath = static function ( string $url ): string {
	return (string) wp_parse_url( $url, PHP_URL_PATH ) . ( wp_parse_url( $url, PHP_URL_QUERY ) ? '?' . wp_parse_url( $url, PHP_URL_QUERY ) : '' );
};

$mbkCheck( 'seo: mbk-lang-switch element registered', ! defined( 'BRICKS_VERSION' ) || isset( \Bricks\Elements::$elements[ array_key_first( Loader::CHROME_ELEMENTS ) ] ) );

// Funnel pages: noindex, follow + the clean page URL as canonical (Rank Math prints no canonical on noindex pages).
$seoRankMath    = defined( 'RANK_MATH_VERSION' );
$seoCanonicalOk = static fn( array $canonical, string $expected ): bool => ( $seoRankMath && ! $canonical ) || ( 1 === count( $canonical ) && $expected === $seoPath( $canonical[0] ) );
foreach ( array( 'rezervacija' => '?apartman=apartman-101&dolazak=2027-07-12&odlazak=2027-07-19', 'hvala' => '', 'placanje' => '', 'pretraga' => '?dolazak=2027-07-12&odlazak=2027-07-19' ) as $key => $query ) {
	$path = $seoPath( (string) get_permalink( Settings::pageId( $key ) ) );
	[ $robots, $canonical ] = $seoTags( $seoGet( $path . $query ) );
	$mbkCheck(
		"seo: /{$key}/ noindex,follow + clean canonical",
		str_contains( $robots, 'noindex' ) && str_contains( $robots, 'follow' ) && ! str_contains( $robots, 'nofollow' ) && $seoCanonicalOk( $canonical, $path ),
		$robots . ' ' . implode( ',', $canonical )
	);
}

// Apartment page: indexable, one canonical, JSON-LD, price in the initial HTML.
$seoUnit = get_page_by_path( 'apartman-101', OBJECT, 'property' );
$seoUrl  = $seoPath( (string) get_permalink( $seoUnit ) );
$seoHtml = $seoGet( $seoUrl );
[ $robots, $canonical ] = $seoTags( $seoHtml );
$mbkCheck( 'seo: apartment indexable + 1 canonical', ! str_contains( $robots, 'noindex' ) && 1 === count( $canonical ) && $seoUrl === $seoPath( $canonical[0] ), $robots . ' ' . implode( ',', $canonical ) );
$mbkCheck( 'seo: apartment HTML has „od 40 € / osoba / noć”', str_contains( $seoHtml, '/ osoba / noć' ) && str_contains( $seoHtml, 'od 40 €' ) );

preg_match_all( '#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', $seoHtml, $ld );
$seoGraph = 1 === count( $ld[1] ) ? json_decode( $ld[1][0], true ) : null;
$seoNodes = is_array( $seoGraph ) ? (array) ( $seoGraph['@graph'] ?? array() ) : array();
$seoTypes = array_column( $seoNodes, '@type' );
$seoNode  = static fn( string $type ): array => (array) ( array_values( array_filter( $seoNodes, static fn( $n ): bool => ( $n['@type'] ?? '' ) === $type ) )[0] ?? array() );
$mbkCheck( 'seo: one JSON-LD graph, valid JSON', is_array( $seoGraph ), (string) json_last_error_msg() );
$mbkCheck( 'seo: JSON-LD has LodgingBusiness + Apartment + BreadcrumbList', ! array_diff( array( 'LodgingBusiness', 'Apartment', 'BreadcrumbList' ), $seoTypes ), implode( ',', $seoTypes ) );
$seoApartment = $seoNode( 'Apartment' );
// The offer is the lowest apartment price per night: 40 € per person × 4 minimum guests (test table).
$mbkCheck( 'seo: Apartment offer minPrice 160 EUR / night, no pets', 160 == ( $seoApartment['offers']['priceSpecification']['minPrice'] ?? null ) && 'night' === ( $seoApartment['offers']['priceSpecification']['unitText'] ?? '' ) && in_array( $seoApartment['petsAllowed'] ?? null, array( false, 'https://schema.org/False' ), true ), wp_json_encode( $seoApartment['offers'] ?? null ) ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- int|float|numeric string.
$mbkCheck( 'seo: no AggregateRating / Review', ! preg_match( '/AggregateRating|"Review"|"review"/', implode( '', $ld[1] ) ) );

// `?lang=en`: noindex + canonical without lang; the switch keeps the query.
$seoHtml = $seoGet( $seoUrl . '?dolazak=2027-07-12&lang=en' );
[ $robots, $canonical ] = $seoTags( $seoHtml );
$mbkCheck( 'seo: ?lang=en noindex + canonical without lang', str_contains( $robots, 'noindex' ) && $seoCanonicalOk( $canonical, $seoUrl ), $robots . ' ' . implode( ',', $canonical ) );
$mbkCheck(
	'seo: lang switch keeps the query, EN current',
	(bool) preg_match( '#href="' . preg_quote( esc_url( $seoUrl . '?dolazak=2027-07-12&lang=hr' ), '#' ) . '" lang="hr" hreflang="hr" rel="nofollow"(?: class="(?![^"]*is-active)[^"]*")?>HR</a>#', $seoHtml )
		&& (bool) preg_match( '#lang="en" hreflang="en" rel="nofollow" class="(?:[^"]* )?is-active" aria-current="true">EN</a>#', $seoHtml )
);

$archive = $seoPath( (string) get_post_type_archive_link( 'property' ) );
[ $robots, $canonical ] = $seoTags( $seoGet( $archive . '?lang=en' ) );
$mbkCheck( 'seo: /apartmani/?lang=en noindex + canonical without lang', str_contains( $robots, 'noindex' ) && $seoCanonicalOk( $canonical, $archive ), implode( ',', $canonical ) );

// Sitemap without funnel pages (Rank Math's sitemaps when it runs, else core's).
$seoSitemap = $seoGet( $seoRankMath ? '/page-sitemap.xml' : '/wp-sitemap-posts-page-1.xml' );
$seoLeaks    = array_filter( Seo::funnelPageIds(), static fn( int $id ): bool => str_contains( $seoSitemap, '<loc>' . esc_url( (string) get_permalink( $id ) ) . '</loc>' ) || str_contains( $seoSitemap, $seoPath( (string) get_permalink( $id ) ) . '</loc>' ) );
$mbkCheck( 'seo: page sitemap excludes funnel pages', str_contains( $seoSitemap, '<urlset' ) && ! $seoLeaks, implode( ',', $seoLeaks ) );
$mbkCheck( 'seo: no bricks_template sitemap', ! str_contains( $seoGet( $seoRankMath ? '/sitemap_index.xml' : '/wp-sitemap.xml' ), 'bricks_template' ) );

// JSON-LD breadcrumb matches the page breadcrumb.
$mbkCheck( 'seo: JSON-LD breadcrumb starts with „Naslovnica”', 'Naslovnica' === ( $seoNode( 'BreadcrumbList' )['itemListElement'][0]['name'] ?? '' ) );

// EN widgets on the HR page carry lang="en" (WCAG 3.1.2); HR widgets carry none.
$mbkCheck( 'seo: EN widget roots have lang="en"', str_contains( $seoHtml, 'class="w-unit-body__booking" id="rezerviraj" lang="en"' ) && str_contains( $seoHtml, 'class="w-unit-body__calendar" lang="en"' ) );
$mbkCheck( 'seo: HR widget roots have no lang', ! str_contains( $seoGet( $seoUrl ), 'class="w-unit-body__booking" id="rezerviraj" lang=' ) );

// Header search dialog: no plugin script until it is used (lazy bundle in a <template>).
$seoHtml   = $seoGet( $seoPath( (string) get_permalink( (int) get_page_by_path( 'kontakt' )?->ID ) ) );
$seoInert  = (string) preg_replace( '#<template id="mbk-lazy-assets">.*?</template>#s', '', $seoHtml );
$mbkCheck( 'seo: /kontakt/ loads no plugin JS, search bundle is lazy', ! preg_match( '#<script[^>]+src="[^"]*marclie-booking/assets/js/#', $seoInert ) && str_contains( $seoHtml, '<template id="mbk-lazy-assets">' ) && str_contains( $seoHtml, 'search.js' ) && str_contains( $seoHtml, 'data-mbk-lazy' ) );
$mbkCheck( 'seo: / (hero search) loads search.js normally', (bool) preg_match( '#<script[^>]+src="[^"]*marclie-booking/assets/js/search\.js#', $seoGet( '/' ) ) );

// Titles + descriptions (Rank Math, or the site's former ACF SEO fields) on pages, archives, `za` terms.
preg_match( '#<title>(.*?)</title>#s', $seoHtml, $seoTitle );
$mbkCheck( 'seo: /kontakt/ title + description from content/kontakt.md', str_starts_with( html_entity_decode( $seoTitle[1] ?? '' ), 'Kontakt · ' ) && str_contains( $seoHtml, '<meta name="description" content="Nazovite nas' ) );
$seoHtml = $seoGet( $archive );
$mbkCheck( 'seo: /apartmani/ has meta description', str_contains( $seoHtml, '<meta name="description" content="17 apartmana' ) );
$seoHtml = $seoGet( '/za/dvoje/' );
[ , $canonical ] = $seoTags( $seoHtml );
$mbkCheck( 'seo: /za/dvoje/ title, description, 1 canonical', str_contains( $seoHtml, 'Za dvoje' ) && str_contains( $seoHtml, '<meta name="description" content="Za dvoje:' ) && 1 === count( $canonical ) );
