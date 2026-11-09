<?php

namespace Marclie\Booking\Tests\Unit;

use Marclie\Booking\Domain\Money;
use Marclie\Booking\Domain\Quote;
use Marclie\Booking\Domain\QuoteLine;
use Marclie\Booking\Domain\Stay;
use Marclie\Booking\I18n\Formatter;
use Marclie\Booking\Service\QuoteDisplay;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuoteDisplayTest extends TestCase {

	/** HR msgstr of languages/marclie-booking-hr.po used by QuoteDisplay. */
	private const HR = array(
		'%1$s × %2$d night (%3$s)'      => array( '%1$s × %2$d noćenje (%3$s)', '%1$s × %2$d noćenja (%3$s)', '%1$s × %2$d noćenja (%3$s)' ),
		'%1$d adult × %2$s'             => array( '%1$d odrasli × %2$s', '%1$d odrasla × %2$s', '%1$d odraslih × %2$s' ),
		'%1$d child × %2$s'             => array( '%1$d dijete × %2$s', '%1$d djece × %2$s', '%1$d djece × %2$s' ),
		'at least %1$d guest × %2$s'    => array( 'najmanje %1$d osoba × %2$s', 'najmanje %1$d osobe × %2$s', 'najmanje %1$d osoba × %2$s' ),
		'%1$s + %2$s'                   => '%1$s + %2$s',
		'Final cleaning'    => 'Završno čišćenje',
		'Included'          => 'Uključeno',
		'Tourist tax'       => 'Boravišna pristojba',
		'Paid on site'      => 'Plaća se na licu mjesta',
		'Coupon %s'         => 'Kupon %s',
		'You pay the full amount today; nothing is charged later.' => 'Danas plaćate puni iznos; kasnije ništa ne naplaćujemo.',
		// HR dates end with a period ("28. lipnja 2027."), so the sentence has none of its own.
		'We charge the balance automatically to the same card on %s.' => 'Ostatak iznosa automatski naplaćujemo s iste kartice %s',
		'%d child up to 4 years' => array( '%d dijete do 4 godine', '%d djeteta do 4 godine', '%d djece do 4 godine' ),
		'Free'              => 'Besplatno',
	);

	protected function tearDown(): void {
		unset( $GLOBALS['mbk_test_l10n'] );
	}

	/**
	 * Apartman 101, 12–19 July 2027 at 70 € per person; default party 4 adults: 7 × 280 € = 1 960 €, deposit 588 €.
	 *
	 * @param array{int, int, int} $party Adults, children 5–10, unit minimum applied (0 = the party pays).
	 */
	private static function quote( int $nights = 7, int $discountCents = 0, bool $payInFull = false, array $party = array( 4, 0, 0 ), int $infants = 0 ): Quote {
		[ $adults, $children, $minimum ] = $party;
		$tz        = new \DateTimeZone( 'Europe/Zagreb' );
		$stay      = new Stay( 21, new \DateTimeImmutable( '2027-07-12', $tz ), ( new \DateTimeImmutable( '2027-07-12', $tz ) )->modify( "+{$nights} days" ) );
		$nightly   = $minimum > 0 ? $minimum * 7000 : $adults * 7000 + $children * 3500;
		$subtotal  = new Money( $nights * $nightly );
		$discount  = new Money( $discountCents );
		$total     = $subtotal->sub( $discount );
		$deposit   = $payInFull ? $total : $total->percent( 30 );
		$lines     = array(
			$minimum > 0
				? new QuoteLine( QuoteLine::NIGHTS, $nights, $nightly, $subtotal->cents(), 7000, minGuests: $minimum )
				: new QuoteLine( QuoteLine::NIGHTS, $nights, $nightly, $subtotal->cents(), 7000, $adults, $children, 3500 ),
			new QuoteLine( QuoteLine::CLEANING, 0, 0, 0 ),
			new QuoteLine( QuoteLine::TOURIST_TAX, 0, 0, 0 ),
		);
		if ( $discountCents ) {
			$lines[] = new QuoteLine( QuoteLine::COUPON, 0, -$discountCents, -$discountCents );
		}

		return new Quote(
			$stay,
			2,
			0,
			$infants,
			$lines,
			$subtotal,
			$discount,
			$total,
			$deposit,
			$total->sub( $deposit ),
			$payInFull ? null : new \DateTimeImmutable( '2027-06-28', $tz ),
			new \DateTimeImmutable( '2027-06-12', $tz ),
			$payInFull,
			$discountCents ? 'DIRECT5' : null,
			$discountCents ? 1 : null,
			3
		);
	}

	public function testCroatian(): void {
		$GLOBALS['mbk_test_l10n'] = self::HR;

		$d = QuoteDisplay::build( self::quote(), 'hr', 30 );

		$this->assertSame(
			array(
				array( 'type' => 'nights', 'label' => '280 € × 7 noćenja (4 odrasla × 70 €)', 'amount' => '1.960 €' ),
				array( 'type' => 'cleaning', 'label' => 'Završno čišćenje', 'amount' => 'Uključeno' ),
				array( 'type' => 'tourist_tax', 'label' => 'Boravišna pristojba', 'amount' => 'Plaća se na licu mjesta' ),
			),
			$d['lines']
		);
		$this->assertSame( '1.960 €', $d['total'] );
		$this->assertSame( '588 €', $d['deposit'] );
		$this->assertSame( '1.372 €', $d['balance'] );
		$this->assertSame( '28. lipnja 2027.', $d['balance_due_on'] );
		$this->assertSame( '12. lipnja 2027.', $d['free_cancel_until'] );
		$this->assertSame( 'Ostatak iznosa automatski naplaćujemo s iste kartice 28. lipnja 2027.', $d['deposit_note'] );
	}

	public function testEnglish(): void {
		$d = QuoteDisplay::build( self::quote(), 'en', 30 );

		$this->assertSame( '€280 × 7 nights (4 adults × €70)', $d['lines'][0]['label'] );
		$this->assertSame( '€1,960', $d['lines'][0]['amount'] );
		$this->assertSame( array( 'Final cleaning', 'Included' ), array( $d['lines'][1]['label'], $d['lines'][1]['amount'] ) );
		$this->assertSame( array( 'Tourist tax', 'Paid on site' ), array( $d['lines'][2]['label'], $d['lines'][2]['amount'] ) );
		$this->assertSame( '€1,960', $d['total'] );
		$this->assertSame( '€588', $d['deposit'] );
		$this->assertSame( '€1,372', $d['balance'] );
		$this->assertSame( '28 June 2027', $d['balance_due_on'] );
		$this->assertSame( 'We charge the balance automatically to the same card on 28 June 2027.', $d['deposit_note'] );
	}

	/** @return array<string, array{int, string, string}> */
	public static function pluralNights(): array {
		return array(
			'1'  => array( 1, '280 € × 1 noćenje (4 odrasla × 70 €)', '€280 × 1 night (4 adults × €70)' ),
			'3'  => array( 3, '280 € × 3 noćenja (4 odrasla × 70 €)', '€280 × 3 nights (4 adults × €70)' ),
			'5'  => array( 5, '280 € × 5 noćenja (4 odrasla × 70 €)', '€280 × 5 nights (4 adults × €70)' ),
			'11' => array( 11, '280 € × 11 noćenja (4 odrasla × 70 €)', '€280 × 11 nights (4 adults × €70)' ),
			'21' => array( 21, '280 € × 21 noćenje (4 odrasla × 70 €)', '€280 × 21 nights (4 adults × €70)' ),
			'22' => array( 22, '280 € × 22 noćenja (4 odrasla × 70 €)', '€280 × 22 nights (4 adults × €70)' ),
		);
	}

	#[DataProvider( 'pluralNights' )]
	public function testNightPlurals( int $nights, string $hr, string $en ): void {
		$this->assertSame( $en, QuoteDisplay::build( self::quote( $nights ), 'en', 30 )['lines'][0]['label'] );

		$GLOBALS['mbk_test_l10n'] = self::HR;
		$this->assertSame( $hr, QuoteDisplay::build( self::quote( $nights ), 'hr', 30 )['lines'][0]['label'] );
	}

	public function testAdultsAndChildrenAreListedSeparately(): void {
		$GLOBALS['mbk_test_l10n'] = self::HR;
		$d = QuoteDisplay::build( self::quote( 7, 0, false, array( 2, 1, 0 ) ), 'hr', 30 );
		$this->assertSame( array( 'type' => 'nights', 'label' => '175 € × 7 noćenja (2 odrasla × 70 € + 1 dijete × 35 €)', 'amount' => '1.225 €' ), $d['lines'][0] );
		$this->assertSame( '70 € × 7 noćenja (1 odrasli × 70 €)', QuoteDisplay::build( self::quote( 7, 0, false, array( 1, 0, 0 ) ), 'hr', 30 )['lines'][0]['label'] );

		unset( $GLOBALS['mbk_test_l10n'] );
		$this->assertSame( '€210 × 7 nights (2 adults × €70 + 2 children × €35)', QuoteDisplay::build( self::quote( 7, 0, false, array( 2, 2, 0 ) ), 'en', 30 )['lines'][0]['label'] );
	}

	public function testBelowTheMinimumShowsTheMinimum(): void {
		$GLOBALS['mbk_test_l10n'] = self::HR;
		$this->assertSame( '140 € × 7 noćenja (najmanje 2 osobe × 70 €)', QuoteDisplay::build( self::quote( 7, 0, false, array( 1, 0, 2 ) ), 'hr', 30 )['lines'][0]['label'] );
		$this->assertSame( '420 € × 7 noćenja (najmanje 6 osoba × 70 €)', QuoteDisplay::build( self::quote( 7, 0, false, array( 2, 0, 6 ) ), 'hr', 30 )['lines'][0]['label'] );

		unset( $GLOBALS['mbk_test_l10n'] );
		$this->assertSame( '€140 × 7 nights (at least 2 guests × €70)', QuoteDisplay::build( self::quote( 7, 0, false, array( 1, 0, 2 ) ), 'en', 30 )['lines'][0]['label'] );
	}

	public function testCouponLineAndPayInFull(): void {
		$GLOBALS['mbk_test_l10n'] = self::HR;

		$d = QuoteDisplay::build( self::quote( 7, 9800, true ), 'hr', 30 );

		$this->assertSame( array( 'type' => 'coupon', 'label' => 'Kupon DIRECT5', 'amount' => "\u{2212}98 €" ), $d['lines'][3] );
		$this->assertSame( '1.862 €', $d['total'] );
		$this->assertSame( '0 €', $d['balance'] );
		$this->assertNull( $d['balance_due_on'] );
		$this->assertSame( 'Danas plaćate puni iznos; kasnije ništa ne naplaćujemo.', $d['deposit_note'] );
	}

	public function testYoungChildrenAreListedAsFreeAfterTheNights(): void {
		$GLOBALS['mbk_test_l10n'] = self::HR;
		$d = QuoteDisplay::build( self::quote( 7, 0, false, array( 2, 0, 0 ), 2 ), 'hr', 30 );
		$this->assertSame( array( 'nights', 'infants', 'cleaning', 'tourist_tax' ), array_column( $d['lines'], 'type' ) );
		$this->assertSame( array( 'type' => 'infants', 'label' => '2 djeteta do 4 godine', 'amount' => 'Besplatno' ), $d['lines'][1] );
		$this->assertSame( '980 €', $d['total'], 'free children add nothing' );

		unset( $GLOBALS['mbk_test_l10n'] );
		$this->assertSame( '1 child up to 4 years', QuoteDisplay::build( self::quote( 7, 0, false, array( 2, 0, 0 ), 1 ), 'en', 30 )['lines'][1]['label'] );
	}

	public function testPriceFormat(): void {
		$this->assertSame( '195 €', Formatter::price( 19500, 'hr' ) );
		$this->assertSame( '1.365 €', Formatter::price( 136500, 'hr' ) );
		$this->assertSame( '409,50 €', Formatter::price( 40950, 'hr' ) );
		$this->assertSame( '1.234.567,05 €', Formatter::price( 123456705, 'hr' ) );
		$this->assertSame( '€195', Formatter::price( 19500, 'en' ) );
		$this->assertSame( '€1,365', Formatter::price( 136500, 'en' ) );
		$this->assertSame( '€409.50', Formatter::price( 40950, 'en' ) );
		$this->assertSame( "\u{2212}€68.25", Formatter::price( -6825, 'en' ) );
		$this->assertSame( '195 €', Formatter::price( 19500, 'de' ), 'unsupported language falls back to HR' );
		$this->assertSame( '30 %', Formatter::percent( 30, 'hr' ) );
		$this->assertSame( '30%', Formatter::percent( 30, 'en' ) );
	}
}
