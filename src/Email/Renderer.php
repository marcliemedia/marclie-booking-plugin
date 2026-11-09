<?php
/**
 * Renders a template version with its context into subject, HTML document
 * (templates/emails/layout.php + blocks) and a plain-text alternative.
 *
 * Order for the body: merge tags (values HTML-escaped) → wp_kses with the
 * editor allowlist (drops any unsafe URL a value produced) → blocks (trusted
 * PHP templates). Templates can be overridden in the theme under
 * `marclie-booking/emails/` (`mbk_template_path` filters the final path).
 */

namespace Marclie\Booking\Email;

defined( 'ABSPATH' ) || exit;

final class Renderer {

	/** Font stack set on every text cell (Outlook desktop falls back to Times otherwise). */
	public const FONT = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";

	/**
	 * @param array{subject: string, preheader: string, heading: string, body_html: string} $template
	 * @param array{vars: array<string, string>, blocks: array<string, mixed>}             $context
	 * @param array<string, string>                                                        $business BusinessInfo::get().
	 * @return array{subject: string, html: string, text: string}
	 */
	public static function render( string $code, string $lang, array $template, array $context, array $business ): array {
		$vars      = $context['vars'];
		$subject   = trim( (string) preg_replace( '/\s+/u', ' ', MergeTags::replace( $template['subject'], $vars, false ) ) );
		$preheader = trim( MergeTags::replace( $template['preheader'], $vars, false ) );
		$heading   = trim( MergeTags::replace( $template['heading'], $vars, false ) );

		$body = TemplateRepository::sanitizeBody( MergeTags::replace( $template['body_html'], $vars, true ) );
		$body = self::insertBlocks( self::inlineStyles( $body, $business['accent'] ), EmailRegistry::blocks( $code ), $context['blocks'], $lang, $business['accent'] );

		$html = self::template(
			'layout',
			array(
				'lang'      => $lang,
				'subject'   => $subject,
				'preheader' => $preheader,
				'heading'   => $heading,
				'body'      => $body,
				'business'  => $business,
			)
		);

		$footer = array_filter( array( $business['name'], $business['address'], $business['phone'], $business['email'] ) );
		$text   = trim( ( '' !== $heading ? $heading . "\n\n" : '' ) . self::toText( $body ) . ( $footer ? "\n\n--\n" . implode( "\n", $footer ) : '' ) );

		return array(
			'subject' => $subject,
			'html'    => $html,
			'text'    => $text,
		);
	}

	/**
	 * Plain-text alternative of an HTML fragment: paragraphs and headings
	 * separated by blank lines, list items as "- …", table rows on one line,
	 * links as "text (url)" (just the URL when both are the same).
	 */
	public static function toText( string $html ): string {
		$html = (string) preg_replace( '#<(style|script|head)\b[^>]*>.*?</\1>#is', '', $html );
		$html = (string) preg_replace( '#<span[^>]*display:\s*none[^>]*>.*?</span>#is', '', $html );
		// Source whitespace is insignificant in HTML; line breaks come from the tags below.
		$html = (string) preg_replace( '/\s+/u', ' ', $html );

		$html = (string) preg_replace_callback(
			'#<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
			static function ( array $m ): string {
				$url  = html_entity_decode( $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$text = trim( html_entity_decode( strip_tags( $m[3] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				if ( '' === $text || $text === $url || 'mailto:' . $text === $url || 'tel:' . preg_replace( '/[^\d+]/', '', $text ) === $url ) {
					return '' === $text ? $url : $text;
				}

				return $text . ' (' . $url . ')';
			},
			$html
		);

		$html = (string) preg_replace( '#<br\s*/?>#i', "\n", $html );
		$html = (string) preg_replace( '#<li\b[^>]*>#i', "\n- ", $html );
		$html = (string) preg_replace( '#</(p|h[1-6]|ul|ol|table|div)>#i', "\n\n", $html );
		$html = (string) preg_replace( '#</tr>#i', "\n", $html );
		// Table rows read "label: value".
		$html = (string) preg_replace( '#\s*</t[dh]>\s*(?=<t[dh]\b)#i', ': ', $html );
		$html = (string) preg_replace( '#</t[dh]>#i', ' ', $html );

		$text = html_entity_decode( strip_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( "\u{00A0}", ' ', $text );
		$text = (string) preg_replace( '/[ \t]+/u', ' ', $text );
		$text = (string) preg_replace( '/ *\n */u', "\n", $text );
		$text = (string) preg_replace( "/\n{3,}/", "\n\n", $text );

		return trim( $text );
	}

	/**
	 * Inline styles for the editor tags (kses left them without attributes, links with href only).
	 */
	private static function inlineStyles( string $html, string $accent ): string {
		return strtr(
			$html,
			array(
				'<p>'      => '<p style="margin:0 0 16px;">',
				'<h3>'     => '<h3 style="margin:28px 0 12px;font-size:18px;line-height:1.35;font-weight:700;color:#1D1D1F;">',
				'<ul>'     => '<ul style="margin:0 0 16px;padding:0 0 0 22px;">',
				'<ol>'     => '<ol style="margin:0 0 16px;padding:0 0 0 22px;">',
				'<li>'     => '<li style="margin:0 0 8px;">',
				'<a href=' => '<a style="color:' . $accent . ';text-decoration:underline;" href=',
			)
		);
	}

	/**
	 * Swaps `{block}` placeholders (also wrapped alone in a paragraph) for the
	 * rendered block; blocks the template does not allow are left as text.
	 *
	 * @param list<string>         $allowed
	 * @param array<string, mixed> $blocks
	 */
	private static function insertBlocks( string $body, array $allowed, array $blocks, string $lang, string $accent ): string {
		foreach ( $allowed as $name ) {
			if ( ! str_contains( $body, '{' . $name . '}' ) ) {
				continue;
			}
			$data = $blocks[ $name ] ?? null;
			$html = null === $data ? '' : self::template(
				'blocks/' . $name,
				array(
					'lang'   => $lang,
					'data'   => $data,
					'font'   => self::FONT,
					'accent' => $accent,
				)
			);
			$body = (string) preg_replace( '#<p\b[^>]*>\s*\{' . $name . '\}\s*</p>#', addcslashes( $html, '\\$' ), $body );
			$body = str_replace( '{' . $name . '}', $html, $body );
		}

		return $body;
	}

	/**
	 * @param array<string, mixed> $vars Available to the template as `$args`.
	 */
	private static function template( string $name, array $vars ): string {
		$tpl  = 'emails/' . $name;
		$path = locate_template( array( 'marclie-booking/' . $tpl . '.php' ) );
		if ( '' === $path ) {
			$path = MBK_DIR . 'templates/' . $tpl . '.php';
		}
		$path = (string) apply_filters( 'mbk_template_path', $path, $tpl );
		if ( ! is_readable( $path ) ) {
			return '';
		}

		ob_start();
		( static function ( string $mbkTemplate, array $args ): void {
			include $mbkTemplate;
		} )( $path, $vars );

		return (string) ob_get_clean();
	}
}
