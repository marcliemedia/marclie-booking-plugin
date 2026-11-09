<?php

namespace Marclie\Booking\Tests\Unit;

use Marclie\Booking\Frontend\LanguageSwitcher;
use Marclie\Booking\I18n\LangUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LangUrlTest extends TestCase {

	public static function withCases(): array {
		return array(
			'no query'            => array( '/apartmani/', 'en', '/apartmani/?lang=en' ),
			'keeps order'         => array( '/rezervacija/?apartman=apartman-101&dolazak=2027-07-12&odlazak=2027-07-19', 'en', '/rezervacija/?apartman=apartman-101&dolazak=2027-07-12&odlazak=2027-07-19&lang=en' ),
			'replaces lang'       => array( '/rezervacija/?lang=en&apartman=apartman-101&odrasli=2', 'hr', '/rezervacija/?apartman=apartman-101&odrasli=2&lang=hr' ),
			'duplicate lang'      => array( '/?lang=en&lang=hr&x=1', 'en', '/?x=1&lang=en' ),
			'keeps encoding'      => array( '/pretraga/?q=a%20b&tags%5B%5D=1&tags%5B%5D=2', 'en', '/pretraga/?q=a%20b&tags%5B%5D=1&tags%5B%5D=2&lang=en' ),
			'keeps fragment'      => array( 'https://example.test/kontakt/?a=1#upit', 'en', 'https://example.test/kontakt/?a=1&lang=en#upit' ),
			'similar keys stay'   => array( '/?language=de&lang_x=1', 'en', '/?language=de&lang_x=1&lang=en' ),
			'encoded key matched' => array( '/?%6Cang=en&a=1', 'hr', '/?a=1&lang=hr' ),
			'empty pairs dropped' => array( '/?&a=1&&', 'en', '/?a=1&lang=en' ),
		);
	}

	#[DataProvider( 'withCases' )]
	public function testWith( string $url, string $lang, string $expected ): void {
		self::assertSame( $expected, LangUrl::with( $url, $lang ) );
	}

	public static function withoutCases(): array {
		return array(
			'only lang'     => array( 'https://example.test/apartmani/?lang=en', 'https://example.test/apartmani/' ),
			'lang first'    => array( '/rezervacija/?lang=en&apartman=apartman-101', '/rezervacija/?apartman=apartman-101' ),
			'lang middle'   => array( '/a/?x=1&lang=en&y=2#f', '/a/?x=1&y=2#f' ),
			'bare lang key' => array( '/a/?lang&x=1', '/a/?x=1' ),
			'no lang'       => array( '/a/?x=1', '/a/?x=1' ),
			'no query'      => array( '/a/', '/a/' ),
		);
	}

	#[DataProvider( 'withoutCases' )]
	public function testWithout( string $url, string $expected ): void {
		self::assertSame( $expected, LangUrl::without( $url ) );
	}

	public function testHas(): void {
		self::assertTrue( LangUrl::has( '/a/?x=1&lang=en' ) );
		self::assertTrue( LangUrl::has( '/a/?lang=' ) );
		self::assertFalse( LangUrl::has( '/a/?language=en' ) );
		self::assertFalse( LangUrl::has( '/a/#lang=en' ) );
		self::assertFalse( LangUrl::has( '/a/' ) );
	}

	public function testSwitchLinksFromHr(): void {
		$links = LanguageSwitcher::nativeLinks( '/rezervacija/?apartman=apartman-101&dolazak=2027-07-12', 'hr' );

		// HR is current: the clean URL (cookie already HR), EN only changes `lang`.
		self::assertSame( '/rezervacija/?apartman=apartman-101&dolazak=2027-07-12', $links['hr'] );
		self::assertSame( '/rezervacija/?apartman=apartman-101&dolazak=2027-07-12&lang=en', $links['en'] );
	}

	public function testSwitchLinksFromEn(): void {
		$links = LanguageSwitcher::nativeLinks( '/rezervacija/?apartman=apartman-101&lang=en&odrasli=2', 'en' );

		// EN is current (query or cookie): HR must set `lang=hr` to overwrite the cookie.
		self::assertSame( '/rezervacija/?apartman=apartman-101&odrasli=2&lang=hr', $links['hr'] );
		self::assertSame( '/rezervacija/?apartman=apartman-101&odrasli=2&lang=en', $links['en'] );
	}
}
