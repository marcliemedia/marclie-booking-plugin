<?php
/**
 * Plugin settings stored in the `mbk_settings` option.
 *
 * Stripe keys are never stored here (they live in wp-config.php constants).
 */

namespace Marclie\Booking;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'mbk_settings';

	/**
	 * Page-map keys: the booking flow (slugs of its HR pages) and the guest
	 * account pages (R2: /prijava/, /registracija/, /moj-racun/ and its child
	 * /moj-racun/zaboravljena-lozinka/).
	 */
	public const PAGE_KEYS = array( 'rezervacija', 'hvala', 'placanje', 'pretraga', 'uvjeti', 'prijava', 'registracija', 'moj_racun', 'zaboravljena_lozinka' );

	/**
	 * Schema + defaults, grouped by section.
	 */
	public const DEFAULTS = array(
		'policy'        => array(
			'deposit_pct'      => 30,
			'balance_days'     => 14,
			'free_cancel_days' => 30,
			'hold_minutes'     => 20,
			'default_coupon'   => 'DIRECT5',
			'checkin_from'     => '15:00',
			'checkin_to'       => '22:00',
			'checkout_until'   => '10:00',
			// Booking reference prefix: {PREFIX}-{YY}-{MMDD}-{unit code}.
			'ref_prefix'       => 'BK',
		),
		'pages'         => array(
			'rezervacija'          => 0,
			'hvala'                => 0,
			'placanje'             => 0,
			'pretraga'             => 0,
			'uvjeti'               => 0,
			'prijava'              => 0,
			'registracija'         => 0,
			'moj_racun'            => 0,
			'zaboravljena_lozinka' => 0,
		),
		'notifications' => array(
			'admin_emails'   => array(),
			'from_name'      => '',
			'from_email'     => '',
			'reply_to'       => '',
			// Email template codes switched off in the admin app (EmailRegistry codes).
			'disabled_codes' => array(),
		),
		'advanced'      => array(
			'delete_data_on_uninstall' => false,
			'debug_log'                => false,
		),
	);

	public static function hooks(): void {
		add_filter( 'sanitize_option_' . self::OPTION, array( self::class, 'sanitize' ) );
	}

	/**
	 * Full settings array, defaults merged in.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$settings = array();
		foreach ( self::DEFAULTS as $section => $defaults ) {
			$values               = isset( $stored[ $section ] ) && is_array( $stored[ $section ] ) ? $stored[ $section ] : array();
			$settings[ $section ] = array_merge( $defaults, array_intersect_key( $values, $defaults ) );
		}

		return $settings;
	}

	/**
	 * Merges a (partial) patch into the stored settings. Sanitized through the option filter.
	 *
	 * @param array<string, array<string, mixed>> $patch Section => key => value.
	 */
	public static function update( array $patch ): bool {
		$settings = self::all();
		foreach ( $patch as $section => $values ) {
			if ( isset( $settings[ $section ] ) && is_array( $values ) ) {
				$settings[ $section ] = array_merge( $settings[ $section ], $values );
			}
		}

		return update_option( self::OPTION, $settings );
	}

	/**
	 * Normalizes a full settings array to the schema. Unknown keys are dropped,
	 * invalid values fall back to defaults.
	 *
	 * @param mixed $input Raw option value.
	 * @return array<string, array<string, mixed>>
	 */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$out   = self::DEFAULTS;

		$section = static function ( string $name ) use ( $input ): array {
			return isset( $input[ $name ] ) && is_array( $input[ $name ] ) ? $input[ $name ] : array();
		};

		$policy = $section( 'policy' );
		$p      = self::DEFAULTS['policy'];
		$out['policy'] = array(
			'deposit_pct'      => self::intInRange( $policy['deposit_pct'] ?? null, 0, 100, $p['deposit_pct'] ),
			'balance_days'     => self::intInRange( $policy['balance_days'] ?? null, 0, 365, $p['balance_days'] ),
			'free_cancel_days' => self::intInRange( $policy['free_cancel_days'] ?? null, 0, 365, $p['free_cancel_days'] ),
			'hold_minutes'     => self::intInRange( $policy['hold_minutes'] ?? null, 5, 120, $p['hold_minutes'] ),
			'default_coupon'   => self::couponCode( $policy['default_coupon'] ?? $p['default_coupon'] ),
			'checkin_from'     => self::sanitizeTime( $policy['checkin_from'] ?? null, $p['checkin_from'] ),
			'checkin_to'       => self::sanitizeTime( $policy['checkin_to'] ?? null, $p['checkin_to'] ),
			'checkout_until'   => self::sanitizeTime( $policy['checkout_until'] ?? null, $p['checkout_until'] ),
			'ref_prefix'       => self::refPrefixCode( $policy['ref_prefix'] ?? null, $p['ref_prefix'] ),
		);

		$pages = $section( 'pages' );
		foreach ( self::PAGE_KEYS as $key ) {
			$out['pages'][ $key ] = isset( $pages[ $key ] ) ? absint( $pages[ $key ] ) : 0;
		}

		$notifications        = $section( 'notifications' );
		$out['notifications'] = array(
			'admin_emails'   => self::sanitizeEmails( $notifications['admin_emails'] ?? array() ),
			'from_name'      => sanitize_text_field( (string) ( $notifications['from_name'] ?? '' ) ),
			'from_email'     => sanitize_email( (string) ( $notifications['from_email'] ?? '' ) ),
			'reply_to'       => sanitize_email( (string) ( $notifications['reply_to'] ?? '' ) ),
			'disabled_codes' => array_values(
				array_unique(
					array_filter(
						array_map( 'strval', is_array( $notifications['disabled_codes'] ?? null ) ? $notifications['disabled_codes'] : array() ),
						array( Email\EmailRegistry::class, 'exists' )
					)
				)
			),
		);

		$advanced        = $section( 'advanced' );
		$out['advanced'] = array(
			'delete_data_on_uninstall' => rest_sanitize_boolean( $advanced['delete_data_on_uninstall'] ?? false ),
			'debug_log'                => rest_sanitize_boolean( $advanced['debug_log'] ?? false ),
		);

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Typed getters
	 * ------------------------------------------------------------------ */

	public static function depositPct(): int {
		return (int) self::all()['policy']['deposit_pct'];
	}

	public static function balanceDays(): int {
		return (int) self::all()['policy']['balance_days'];
	}

	public static function freeCancelDays(): int {
		return (int) self::all()['policy']['free_cancel_days'];
	}

	public static function holdMinutes(): int {
		return (int) self::all()['policy']['hold_minutes'];
	}

	public static function defaultCoupon(): string {
		return (string) self::all()['policy']['default_coupon'];
	}

	public static function checkinFrom(): string {
		return (string) self::all()['policy']['checkin_from'];
	}

	public static function checkinTo(): string {
		return (string) self::all()['policy']['checkin_to'];
	}

	public static function checkoutUntil(): string {
		return (string) self::all()['policy']['checkout_until'];
	}

	public static function refPrefix(): string {
		return (string) self::all()['policy']['ref_prefix'];
	}

	/**
	 * @return array<string, int> Page key => page ID (0 = not mapped).
	 */
	public static function pages(): array {
		return array_map( 'intval', self::all()['pages'] );
	}

	public static function pageId( string $key ): int {
		return self::pages()[ $key ] ?? 0;
	}

	/**
	 * @return list<string>
	 */
	public static function adminEmails(): array {
		return array_values( (array) self::all()['notifications']['admin_emails'] );
	}

	public static function fromName(): string {
		return (string) self::all()['notifications']['from_name'];
	}

	public static function fromEmail(): string {
		return (string) self::all()['notifications']['from_email'];
	}

	public static function replyTo(): string {
		return (string) self::all()['notifications']['reply_to'];
	}

	/** @return list<string> Email template codes that are switched off. */
	public static function disabledEmailCodes(): array {
		return array_values( (array) self::all()['notifications']['disabled_codes'] );
	}

	public static function deleteDataOnUninstall(): bool {
		return (bool) self::all()['advanced']['delete_data_on_uninstall'];
	}

	public static function debugLog(): bool {
		return (bool) self::all()['advanced']['debug_log'];
	}

	/* ---------------------------------------------------------------------
	 * Sanitizers
	 * ------------------------------------------------------------------ */

	/**
	 * @param mixed $value
	 */
	private static function intInRange( $value, int $min, int $max, int $default ): int {
		if ( ! is_numeric( $value ) ) {
			return $default;
		}

		return max( $min, min( $max, (int) $value ) );
	}

	/**
	 * @param mixed $value
	 */
	private static function couponCode( $value ): string {
		return (string) preg_replace( '/[^A-Z0-9_-]/', '', strtoupper( sanitize_text_field( (string) $value ) ) );
	}

	/**
	 * 1–6 letters/digits, uppercased; anything else falls back to the default.
	 *
	 * @param mixed $value
	 */
	private static function refPrefixCode( $value, string $default ): string {
		$code = strtoupper( trim( is_string( $value ) ? $value : '' ) );

		return preg_match( '/^[A-Z0-9]{1,6}$/', $code ) ? $code : $default;
	}

	/**
	 * @param mixed $value
	 */
	private static function sanitizeTime( $value, string $default ): string {
		$value = is_string( $value ) ? trim( $value ) : '';

		return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value ) ? $value : $default;
	}

	/**
	 * Accepts a list or a comma/newline separated string.
	 *
	 * @param mixed $value
	 * @return list<string>
	 */
	private static function sanitizeEmails( $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\s,;]+/', $value ) ?: array();
		}

		$emails = array();
		foreach ( (array) $value as $email ) {
			$email = sanitize_email( (string) $email );
			if ( '' !== $email && is_email( $email ) ) {
				$emails[] = strtolower( $email );
			}
		}

		return array_values( array_unique( $emails ) );
	}
}
