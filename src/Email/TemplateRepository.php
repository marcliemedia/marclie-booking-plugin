<?php
/**
 * Email template content per code + language: the admin override
 * (`mbk_email_templates`, EmailTemplateRepository) or the shipped default
 * `templates/emails/defaults/{code}.{lang}.json`.
 *
 * On/off is per code (both languages) in the settings key
 * `notifications.disabled_codes`; the table's `enabled` column is not used,
 * so resetting a language to the default never re-enables a template.
 */

namespace Marclie\Booking\Email;

use Marclie\Booking\Repository\EmailTemplateRepository;
use Marclie\Booking\Settings;

defined( 'ABSPATH' ) || exit;

final class TemplateRepository {

	public const LANGS = array( 'hr', 'en' );

	public const FIELDS = array( 'subject', 'preheader', 'heading', 'body_html' );

	/** Tags allowed in body_html (editor: bold, italic, link, lists, paragraphs, h3). */
	public const ALLOWED_HTML = array(
		'p'      => array(),
		'br'     => array(),
		'strong' => array(),
		'em'     => array(),
		'a'      => array( 'href' => true ),
		'ul'     => array(),
		'ol'     => array(),
		'li'     => array(),
		'h3'     => array(),
	);

	/** @var array<string, array{subject: string, preheader: string, heading: string, body_html: string}> */
	private array $defaults = array();

	public function __construct( private EmailTemplateRepository $rows ) {}

	/**
	 * Effective template (override or default).
	 *
	 * @return array{subject: string, preheader: string, heading: string, body_html: string, customized: bool, updated_at: ?string}
	 */
	public function get( string $code, string $lang ): array {
		$row = $this->rows->find( $code, $lang );
		if ( null !== $row ) {
			return array(
				'subject'    => (string) $row['subject'],
				'preheader'  => (string) $row['preheader'],
				'heading'    => (string) $row['heading'],
				'body_html'  => (string) $row['body_html'],
				'customized' => true,
				'updated_at' => (string) $row['updated_at'],
			);
		}

		return $this->defaults( $code, $lang ) + array(
			'customized' => false,
			'updated_at' => null,
		);
	}

	/**
	 * Override timestamps: code => lang => updated_at (UTC DATETIME).
	 *
	 * @return array<string, array<string, string>>
	 */
	public function customized(): array {
		$out = array();
		foreach ( $this->rows->all() as $row ) {
			$out[ (string) $row['code'] ][ (string) $row['lang'] ] = (string) $row['updated_at'];
		}

		return $out;
	}

	/**
	 * Shipped default of a template.
	 *
	 * @return array{subject: string, preheader: string, heading: string, body_html: string}
	 */
	public function defaults( string $code, string $lang ): array {
		$key = $code . '.' . $lang;
		if ( ! isset( $this->defaults[ $key ] ) ) {
			$file = MBK_DIR . 'templates/emails/defaults/' . sanitize_file_name( $key ) . '.json';
			$data = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$data = is_array( $data ) ? $data : array();

			$this->defaults[ $key ] = array(
				'subject'   => (string) ( $data['subject'] ?? '' ),
				'preheader' => (string) ( $data['preheader'] ?? '' ),
				'heading'   => (string) ( $data['heading'] ?? '' ),
				'body_html' => (string) ( $data['body_html'] ?? '' ),
			);
		}

		return $this->defaults[ $key ];
	}

	/**
	 * Saves an override (fields already validated; body sanitized here).
	 *
	 * @param array{subject: string, preheader: string, heading: string, body_html: string} $fields
	 */
	public function save( string $code, string $lang, array $fields, ?int $userId ): bool {
		return $this->rows->upsert( $code, $lang, self::clean( $fields ), $userId );
	}

	/** Back to the shipped default. */
	public function reset( string $code, string $lang ): bool {
		return $this->rows->delete( $code, $lang );
	}

	public function isEnabled( string $code ): bool {
		return ! in_array( $code, Settings::disabledEmailCodes(), true );
	}

	public function setEnabled( string $code, bool $enabled ): bool {
		$disabled = array_values( array_diff( Settings::disabledEmailCodes(), array( $code ) ) );
		if ( ! $enabled ) {
			$disabled[] = $code;
		}

		return Settings::update( array( 'notifications' => array( 'disabled_codes' => $disabled ) ) ) || $this->isEnabled( $code ) === $enabled;
	}

	/**
	 * Normalized template fields: single-line plain subject/preheader/heading,
	 * body limited to ALLOWED_HTML.
	 *
	 * @param array<string, mixed> $fields
	 * @return array{subject: string, preheader: string, heading: string, body_html: string}
	 */
	public static function clean( array $fields ): array {
		$line = static fn( $v ): string => trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $v ) ) );

		return array(
			'subject'   => $line( $fields['subject'] ?? '' ),
			'preheader' => $line( $fields['preheader'] ?? '' ),
			'heading'   => $line( $fields['heading'] ?? '' ),
			'body_html' => self::sanitizeBody( (string) ( $fields['body_html'] ?? '' ) ),
		);
	}

	public static function sanitizeBody( string $html ): string {
		$html = wp_kses( $html, self::ALLOWED_HTML, array( 'http', 'https', 'mailto', 'tel' ) );
		// The editor wraps list items in a paragraph (<li><p>…</p></li>): keep the item tight.
		$html = (string) preg_replace( '#<li>\s*<p>((?:(?!</?p>).)*?)</p>\s*</li>#s', '<li>$1</li>', $html );

		return trim( $html );
	}
}
