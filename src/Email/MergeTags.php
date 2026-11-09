<?php
/**
 * `{tag}` replacement in email templates (WordPress-free).
 *
 * - Known tags (keys of `$vars`) are replaced; HTML contexts get the value
 *   escaped (htmlspecialchars, plus `{` `}` as entities), plain contexts
 *   (subject, text) get it raw.
 * - Unknown tags are left untouched, so a typo stays visible in the preview
 *   instead of silently disappearing. Known tags without a value become ''.
 * - Block placeholders ({booking_summary}…) are not merge tags: Renderer
 *   swaps them for rendered HTML.
 */

namespace Marclie\Booking\Email;

defined( 'ABSPATH' ) || exit;

final class MergeTags {

	/** Every merge tag the plugin can fill (EmailRegistry limits them per template). */
	public const ALL = array(
		'guest_first_name',
		'guest_name',
		'booking_ref',
		'unit_name',
		'house_name',
		'arrival_date',
		'departure_date',
		'nights',
		'adults',
		'children',
		'checkin_window',
		'checkout_until',
		'total',
		'paid',
		'deposit',
		'balance',
		'balance_due_date',
		'free_cancel_until',
		'card_last4',
		'refund_amount',
		'manage_link',
		'payment_link',
		'search_link',
		'directions_link',
		'business_name',
		'business_phone',
		'business_email',
		'business_address',
		'admin_link',
		'alert_title',
		'alert_message',
		'verify_link',
		'login_link',
		'reset_link',
		'account_link',
		'tier_name',
		'coupon_code',
		'coupon_discount',
		'coupon_min_nights',
		'coupon_valid_until',
	);

	private const PATTERN = '/\{([a-z][a-z0-9_]*)\}/';

	/**
	 * @param array<string, string|int|null> $vars Tag => value (unescaped).
	 * @param bool                           $html Escape values for HTML.
	 */
	public static function replace( string $text, array $vars, bool $html ): string {
		return (string) preg_replace_callback(
			self::PATTERN,
			static function ( array $m ) use ( $vars, $html ): string {
				if ( ! array_key_exists( $m[1], $vars ) ) {
					return $m[0];
				}
				$value = (string) $vars[ $m[1] ];

				// Braces are encoded too, so a value ("{cta_button}" typed as a name) can never become a block placeholder.
				return $html ? strtr( htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ), array( '{' => '&#123;', '}' => '&#125;' ) ) : $value;
			},
			$text
		);
	}

	/**
	 * Tags (and block placeholders) used in a text, without braces.
	 *
	 * @return list<string>
	 */
	public static function used( string $text ): array {
		preg_match_all( self::PATTERN, $text, $m );

		return array_values( array_unique( $m[1] ) );
	}
}
