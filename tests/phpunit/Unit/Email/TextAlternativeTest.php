<?php

namespace Marclie\Booking\Tests\Unit\Email;

use Marclie\Booking\Email\Renderer;
use PHPUnit\Framework\TestCase;

final class TextAlternativeTest extends TestCase {

	public function testParagraphsHeadingsListsAndBreaks(): void {
		$html = "<h3 style=\"margin:0\">Što slijedi</h3>\n<p style=\"margin:0\">Prvi   red<br>drugi red</p><ul><li>Jedan</li><li><strong>Dva</strong> &amp; tri</li></ul><p>Kraj&nbsp;teksta</p>";

		self::assertSame( "Što slijedi\n\nPrvi red\ndrugi red\n\n- Jedan\n- Dva & tri\n\nKraj teksta", Renderer::toText( $html ) );
	}

	public function testLinksBecomeTextWithUrl(): void {
		$html = '<p>Pogledajte <a style="color:#E0174F" href="https://example.com/hvala/?ref=BK-1&amp;t=abc">rezervaciju</a>.</p>'
			. '<p><a href="https://example.com/placanje/?ref=BK-1&amp;t=x">https://example.com/placanje/?ref=BK-1&amp;t=x</a></p>'
			. '<p><a href="mailto:info@example.com">info@example.com</a> · <a href="tel:+385917809983">+385 91 780 9983</a></p>';

		self::assertSame(
			"Pogledajte rezervaciju (https://example.com/hvala/?ref=BK-1&t=abc).\n\nhttps://example.com/placanje/?ref=BK-1&t=x\n\ninfo@example.com · +385 91 780 9983",
			Renderer::toText( $html )
		);
	}

	public function testTablesReadAsLabelValueRows(): void {
		$html = '<table><tr><td>
				<table>
					<tr>
						<td style="color:#6E6E73"> Dolazak </td>
						<td>12. srpnja 2027., 15:00–22:00</td>
					</tr>
					<tr><td>Ukupno</td><td align="right">1.365 €</td></tr>
				</table>
			</td></tr></table>
			<table><tr><td><a href="https://example.com/x">Pogledaj rezervaciju</a></td></tr></table>';

		self::assertSame( "Dolazak: 12. srpnja 2027., 15:00–22:00\nUkupno: 1.365 €\n\nPogledaj rezervaciju (https://example.com/x)", Renderer::toText( $html ) );
	}

	public function testHiddenPreheaderAndStylesAreDropped(): void {
		$html = '<style>a{color:red}</style><span style="display:none;max-height:0">Preheader</span><p>Body</p>';

		self::assertSame( 'Body', Renderer::toText( $html ) );
	}
}
