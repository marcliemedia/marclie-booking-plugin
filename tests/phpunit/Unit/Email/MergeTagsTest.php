<?php

namespace Marclie\Booking\Tests\Unit\Email;

use Marclie\Booking\Email\EmailRegistry;
use Marclie\Booking\Email\MergeTags;
use PHPUnit\Framework\TestCase;

final class MergeTagsTest extends TestCase {

	public function testHtmlContextEscapesValues(): void {
		$out = MergeTags::replace(
			'<p>Hello {guest_first_name}, ref <strong>{booking_ref}</strong></p>',
			array(
				'guest_first_name' => '<script>alert("x")</script> O\'Brien & Co',
				'booking_ref'      => 'BK-27-0712-101',
			),
			true
		);

		self::assertSame( '<p>Hello &lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; O&#039;Brien &amp; Co, ref <strong>BK-27-0712-101</strong></p>', $out );
	}

	public function testHtmlContextEncodesBracesSoValuesNeverBecomeBlocks(): void {
		self::assertSame( '<p>&#123;cta_button&#125; Ana</p>', MergeTags::replace( '<p>{guest_name}</p>', array( 'guest_name' => '{cta_button} Ana' ), true ) );
		self::assertSame( '{cta_button} Ana', MergeTags::replace( '{guest_name}', array( 'guest_name' => '{cta_button} Ana' ), false ) );
	}

	public function testPlainContextKeepsValuesRaw(): void {
		self::assertSame( 'Booking BK-1 · Ana & Ivo', MergeTags::replace( 'Booking {booking_ref} · {guest_name}', array( 'booking_ref' => 'BK-1', 'guest_name' => 'Ana & Ivo' ), false ) );
	}

	public function testLinkValueIsEscapedInsideAttributes(): void {
		$out = MergeTags::replace( '<a href="{manage_link}">x</a>', array( 'manage_link' => 'https://example.com/hvala/?ref=BK-1&t=abc"onmouseover="x' ), true );

		self::assertSame( '<a href="https://example.com/hvala/?ref=BK-1&amp;t=abc&quot;onmouseover=&quot;x">x</a>', $out );
	}

	public function testUnknownTagsAreLeftUntouchedAndKnownEmptyTagsBecomeEmpty(): void {
		$out = MergeTags::replace( '{guest_first_name} {not_a_tag} {card_last4}{Booking_Ref} { spaced }', array( 'guest_first_name' => 'Lena', 'card_last4' => '' ), true );

		self::assertSame( 'Lena {not_a_tag} {Booking_Ref} { spaced }', $out );
	}

	public function testValuesAreNotReparsed(): void {
		// A guest typing "{booking_ref}" as their name must not pull in other values.
		self::assertSame( '{booking_ref} / BK-1', MergeTags::replace( '{guest_name} / {booking_ref}', array( 'guest_name' => '{booking_ref}', 'booking_ref' => 'BK-1' ), false ) );
	}

	public function testUsedListsTagsOnce(): void {
		self::assertSame( array( 'booking_ref', 'booking_summary', 'manage_link' ), MergeTags::used( '{booking_ref} {booking_summary} <a href="{manage_link}">{booking_ref}</a> {Bad}' ) );
	}

	public function testRequiredTagsAreSearchedInAllFields(): void {
		self::assertSame( array(), EmailRegistry::missingRequiredTags( 'guest_confirmed', array( 'subject' => 'Booking {booking_ref}', 'body_html' => '<p>Hi</p>' ) ) );
		self::assertSame( array( 'booking_ref' ), EmailRegistry::missingRequiredTags( 'guest_confirmed', array( 'subject' => 'Booking', 'body_html' => '<p>{booking_summary}</p>' ) ) );
		self::assertSame(
			array( 'payment_link' ),
			EmailRegistry::missingRequiredTags( 'guest_balance_failed', array( 'subject' => '{booking_ref}', 'preheader' => '', 'heading' => '', 'body_html' => '<p>{cta_button}</p>' ) )
		);
		self::assertSame( array( 'balance', 'balance_due_date' ), EmailRegistry::missingRequiredTags( 'guest_balance_reminder', array( 'subject' => 'Reminder {booking_ref}', 'body_html' => '' ) ) );
		self::assertSame( array(), EmailRegistry::missingRequiredTags( 'guest_message', array( 'subject' => 'Hi', 'body_html' => '<p>x</p>' ) ) );
	}

	public function testRegistryTagsAreKnownMergeTags(): void {
		foreach ( EmailRegistry::codes() as $code ) {
			self::assertSame( array(), array_diff( EmailRegistry::mergeTags( $code ), MergeTags::ALL ), $code );
			self::assertSame( array(), array_diff( EmailRegistry::requiredTags( $code ), EmailRegistry::mergeTags( $code ) ), $code );
			$cta = EmailRegistry::ctaTag( $code );
			self::assertTrue( null === $cta || in_array( $cta, EmailRegistry::mergeTags( $code ), true ), $code );
		}
	}

	public function testDefaultTemplatesContainTheirRequiredTagsAndOnlyAllowedTags(): void {
		$dir = dirname( __DIR__, 4 ) . '/templates/emails/defaults/';
		foreach ( EmailRegistry::codes() as $code ) {
			foreach ( array( 'hr', 'en' ) as $lang ) {
				$file = $dir . $code . '.' . $lang . '.json';
				self::assertFileExists( $file );
				$data = json_decode( (string) file_get_contents( $file ), true );
				self::assertIsArray( $data, $file );
				self::assertSame( array( 'subject', 'preheader', 'heading', 'body_html' ), array_keys( $data ), $file );
				self::assertSame( array(), EmailRegistry::missingRequiredTags( $code, $data ), $file );
				$used = MergeTags::used( implode( "\n", $data ) );
				self::assertSame( array(), array_values( array_diff( $used, EmailRegistry::mergeTags( $code ), EmailRegistry::blocks( $code ) ) ), $file );
			}
		}
	}
}
