<?php

namespace Marclie\Booking\Tests\Unit\Bricks;

use Marclie\Booking\Bricks\Preview;
use PHPUnit\Framework\TestCase;

final class PreviewTest extends TestCase {

	public function testSampleQueryStartsThirtyDaysOutForSevenNights(): void {
		$query = Preview::sampleQuery( new \DateTimeImmutable( '2026-10-29 17:45', new \DateTimeZone( 'Europe/Zagreb' ) ) );

		$this->assertSame(
			array(
				'dolazak' => '2026-11-28',
				'odlazak' => '2026-12-05',
				'odrasli' => 2,
				'djeca'   => 0,
			),
			$query
		);
	}

	public function testWithQueryRestoresGet(): void {
		$_GET = array(
			'odrasli' => '4',
			'apartman' => 'apartman-1',
		);

		$seen = Preview::withQuery(
			array(
				'dolazak'  => '2027-07-12',
				'apartman' => null,
			),
			static fn(): array => $_GET
		);

		$this->assertSame( array( 'odrasli' => '4', 'dolazak' => '2027-07-12' ), $seen );
		$this->assertSame( array( 'odrasli' => '4', 'apartman' => 'apartman-1' ), $_GET );
		$_GET = array();
	}

	public function testShowStateTogglesPanelsAndFillsEmptyFields(): void {
		$html = '<div data-x>'
			. '<div class="co__status" data-cf-state="loading" role="status"><p>Loading</p></div>'
			. '<div class="co" data-cf-state="done" hidden><dd data-cf="ref"></dd><span data-cf="title">kept</span><p class="x" aria-hidden="true" data-cf="intro"></p></div>'
			. '<div data-cf-state="error" role="alert" hidden></div>'
			. '</div>';

		$out = Preview::showState( $html, 'data-cf-state', 'done', 'data-cf', array( 'ref' => 'BK-27-0712-001', 'title' => 'X', 'intro' => 'A & B' ) );

		$this->assertStringContainsString( '<div class="co__status" data-cf-state="loading" role="status" hidden>', $out );
		$this->assertStringContainsString( '<div class="co" data-cf-state="done">', $out );
		$this->assertStringContainsString( '<div data-cf-state="error" role="alert" hidden>', $out );
		$this->assertStringContainsString( '<dd data-cf="ref">BK-27-0712-001</dd>', $out );
		$this->assertStringContainsString( '<span data-cf="title">kept</span>', $out, 'non-empty fields stay as rendered' );
		$this->assertStringContainsString( 'aria-hidden="true" data-cf="intro">A &amp; B</p>', $out );
		$this->assertSame( 2, substr_count( $out, ' hidden>' ), 'every other panel hidden' );
	}
}
