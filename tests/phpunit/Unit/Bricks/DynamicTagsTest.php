<?php

namespace Marclie\Booking\Tests\Unit\Bricks;

use Marclie\Booking\Bricks\DynamicTags;
use PHPUnit\Framework\TestCase;

final class DynamicTagsTest extends TestCase {

	public function testReplaceKeepsHtmlAndForeignTags(): void {
		$resolve = static fn( string $name, string $modifier ): ?string => match ( $name . ':' . $modifier ) {
			'mbk_checkin_window:'      => '15:00–22:00',
			'mbk_policy:deposit_pct'   => '30',
			'mbk_price_from:raw'       => '115',
			default                    => null,
		};

		$content = '<p class="x">{mbk_checkin_window} · {mbk_policy:deposit_pct} % · {post_title} · {mbk_price_from:raw}</p>';
		$this->assertSame( '<p class="x">15:00–22:00 · 30 % · {post_title} · 115</p>', DynamicTags::replace( $content, $resolve ) );

		// Unknown tags/modifiers and malformed tags stay as written; nested braces keep the outer pair.
		$this->assertSame( '{mbk_nope} {mbk_policy:nope} {mbk_checkin_window', DynamicTags::replace( '{mbk_nope} {mbk_policy:nope} {mbk_checkin_window', $resolve ) );
		$this->assertSame( '{30}', DynamicTags::replace( '{{mbk_policy:deposit_pct}}', $resolve ) );
	}

	public function testUnknownModifierIsNotResolved(): void {
		$this->assertNull( DynamicTags::value( 'mbk_policy', 'hold_minutes', null ) );
		$this->assertNull( DynamicTags::value( 'mbk_unknown', '', null ) );
		$this->assertNull( DynamicTags::value( 'mbk_checkin_window', 'raw', null ) );
	}

	public function testAmount(): void {
		$this->assertSame( '115', DynamicTags::amount( 11500 ) );
		$this->assertSame( '409.5', DynamicTags::amount( 40950 ) );
		$this->assertSame( '1296.75', DynamicTags::amount( 129675 ) );
	}
}
