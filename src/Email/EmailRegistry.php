<?php
/**
 * Email templates known to the plugin: recipient, trigger, allowed and
 * required merge tags, dynamic blocks, call-to-action and preview sample.
 *
 * WordPress-free apart from __() (labels are translated in the current
 * language), so the validation helpers are unit-tested.
 */

namespace Marclie\Booking\Email;

defined( 'ABSPATH' ) || exit;

final class EmailRegistry {

	public const GUEST = 'guest';
	public const ADMIN = 'admin';

	public const BLOCKS = array( 'booking_summary', 'payment_table', 'cta_button' );

	/** Account email templates (R2): sent to an address without a booking. */
	public const ACCOUNT_CODES = array( 'account_verify', 'account_exists', 'account_password_reset', 'account_welcome', 'account_tier_up' );

	/** Merge tags of a booking (guest and admin templates). */
	private const BOOKING_TAGS = array(
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
	);

	private const BUSINESS_TAGS = array( 'business_name', 'business_phone', 'business_email', 'business_address' );

	/**
	 * code => [recipient, required tags, blocks, cta (merge tag of the button URL or null), extra tags, sample status].
	 */
	private const DEFINITIONS = array(
		'guest_confirmed'        => array( self::GUEST, array( 'booking_ref' ), array( 'booking_summary', 'payment_table', 'cta_button', 'account_invite' ), 'manage_link', array(), 'confirmed' ),
		'admin_new_booking'      => array( self::ADMIN, array( 'booking_ref' ), self::BLOCKS, 'admin_link', array( 'admin_link' ), 'confirmed' ),
		'guest_balance_reminder' => array( self::GUEST, array( 'balance', 'balance_due_date' ), self::BLOCKS, 'manage_link', array(), 'confirmed' ),
		'guest_balance_paid'     => array( self::GUEST, array( 'booking_ref' ), self::BLOCKS, 'manage_link', array(), 'paid' ),
		'guest_balance_failed'   => array( self::GUEST, array( 'booking_ref', 'payment_link' ), self::BLOCKS, 'payment_link', array(), 'balance_failed' ),
		'guest_cancelled'        => array( self::GUEST, array( 'booking_ref' ), self::BLOCKS, 'manage_link', array(), 'cancelled' ),
		'guest_pre_arrival'      => array( self::GUEST, array( 'arrival_date' ), self::BLOCKS, 'directions_link', array(), 'paid' ),
		'guest_hold_lost'        => array( self::GUEST, array( 'booking_ref' ), self::BLOCKS, 'search_link', array(), 'cancelled' ),
		'guest_payment_link'     => array( self::GUEST, array( 'payment_link' ), self::BLOCKS, 'payment_link', array(), 'confirmed' ),
		'admin_alert'            => array( self::ADMIN, array( 'alert_message' ), array( 'booking_summary', 'cta_button' ), 'admin_link', array( 'admin_link', 'alert_title', 'alert_message' ), 'balance_failed' ),
		'guest_message'          => array( self::GUEST, array(), array(), null, array(), null ),
		'account_verify'         => array( self::GUEST, array( 'verify_link' ), array( 'cta_button' ), 'verify_link', array( 'verify_link' ), null ),
		'account_exists'         => array( self::GUEST, array(), array( 'cta_button' ), 'login_link', array( 'login_link', 'reset_link' ), null ),
		'account_password_reset' => array( self::GUEST, array( 'reset_link' ), array( 'cta_button' ), 'reset_link', array( 'reset_link' ), null ),
		'account_welcome'        => array( self::GUEST, array(), array( 'cta_button' ), 'account_link', array( 'account_link', 'coupon_code', 'coupon_discount', 'coupon_min_nights', 'coupon_valid_until' ), null ),
		'account_tier_up'        => array( self::GUEST, array( 'tier_name' ), array( 'cta_button' ), 'account_link', array( 'account_link', 'tier_name', 'coupon_code', 'coupon_discount', 'coupon_min_nights', 'coupon_valid_until' ), null ),
	);

	/** @return list<string> */
	public static function codes(): array {
		return array_keys( self::DEFINITIONS );
	}

	public static function exists( string $code ): bool {
		return isset( self::DEFINITIONS[ $code ] );
	}

	/**
	 * Registry entry with translated label and trigger description.
	 *
	 * @return array{code:string, recipient:string, label:string, description:string, merge_tags:list<string>, required_tags:list<string>, blocks:list<string>, cta:?string}|null
	 */
	public static function get( string $code ): ?array {
		if ( ! self::exists( $code ) ) {
			return null;
		}
		[ $recipient, $required, $blocks, $cta ] = self::DEFINITIONS[ $code ];

		return array(
			'code'          => $code,
			'recipient'     => $recipient,
			'label'         => self::label( $code ),
			'description'   => self::description( $code ),
			'merge_tags'    => self::mergeTags( $code ),
			'required_tags' => $required,
			'blocks'        => $blocks,
			'cta'           => $cta,
		);
	}

	/** @return list<array<string, mixed>> */
	public static function all(): array {
		return array_map( static fn( string $code ): array => (array) self::get( $code ), self::codes() );
	}

	public static function recipient( string $code ): string {
		return self::DEFINITIONS[ $code ][0] ?? self::GUEST;
	}

	/** Merge tag carrying the URL of the {cta_button}, or null (no button). */
	public static function ctaTag( string $code ): ?string {
		return self::DEFINITIONS[ $code ][3] ?? null;
	}

	/** @return list<string> */
	public static function requiredTags( string $code ): array {
		return self::DEFINITIONS[ $code ][1] ?? array();
	}

	/** @return list<string> */
	public static function blocks( string $code ): array {
		return self::DEFINITIONS[ $code ][2] ?? array();
	}

	/** @return list<string> Allowed merge tags (without braces). */
	public static function mergeTags( string $code ): array {
		if ( ! self::exists( $code ) ) {
			return array();
		}
		if ( 'guest_message' === $code ) {
			return array_merge( array( 'guest_first_name', 'guest_name' ), self::BUSINESS_TAGS );
		}
		if ( self::isAccountTemplate( $code ) ) {
			return array_values( array_unique( array_merge( array( 'guest_first_name', 'guest_name' ), self::DEFINITIONS[ $code ][4], self::BUSINESS_TAGS ) ) );
		}

		return array_values( array_unique( array_merge( self::BOOKING_TAGS, self::DEFINITIONS[ $code ][4], self::BUSINESS_TAGS ) ) );
	}

	/** Templates sent for one booking (resend from the booking sheet). */
	public static function isBookingTemplate( string $code ): bool {
		return self::exists( $code ) && ! in_array( $code, array( 'admin_alert', 'guest_message' ), true ) && ! self::isAccountTemplate( $code );
	}

	/** Account emails (verification, password reset, membership): no booking. */
	public static function isAccountTemplate( string $code ): bool {
		return in_array( $code, self::ACCOUNT_CODES, true );
	}

	/**
	 * Required tags missing from a template version (subject, preheader, heading and body together).
	 *
	 * @param array{subject?:string, preheader?:string, heading?:string, body_html?:string} $fields
	 * @return list<string>
	 */
	public static function missingRequiredTags( string $code, array $fields ): array {
		$text    = implode( "\n", array_map( 'strval', array_intersect_key( $fields, array_flip( array( 'subject', 'preheader', 'heading', 'body_html' ) ) ) ) );
		$used    = MergeTags::used( $text );
		$missing = array();
		foreach ( self::requiredTags( $code ) as $tag ) {
			if ( ! in_array( $tag, $used, true ) ) {
				$missing[] = $tag;
			}
		}

		return $missing;
	}

	/**
	 * Preview data (Context::fromFacts format): Apartman 101, 12–19 July 2027,
	 * 7 × 195 € = 1.365 €, 30 % deposit 409,50 € (architecture §5 example).
	 * Account templates: Lena Hofer, Srebrni coupon 5 % for 7+ nights
	 * (links are added by Context::sampleFacts()).
	 *
	 * @return array<string, mixed>
	 */
	public static function sample( string $code ): array {
		if ( self::isAccountTemplate( $code ) ) {
			$welcome = 'account_welcome' === $code;

			return array(
				'booking_id' => 0,
				'first_name' => 'Lena',
				'last_name'  => 'Hofer',
				'email'      => 'lena.hofer@example.com',
				'account'    => array(
					'tier'   => $welcome ? 'clan' : 'srebrni',
					'coupon' => in_array( $code, array( 'account_welcome', 'account_tier_up' ), true )
						? array(
							'code'       => $welcome ? 'WELCOME-4F2A9C' : 'SILVER-7B31D0',
							'amount'     => 5,
							'min_nights' => $welcome ? 0 : 7,
							'book_to'    => '2027-10-28',
						)
						: null,
				),
			);
		}

		$status = self::DEFINITIONS[ $code ][5] ?? 'confirmed';
		$paid   = 31500;
		$refund = 0;
		if ( 'guest_balance_paid' === $code || 'guest_pre_arrival' === $code ) {
			$paid = 105000;
		} elseif ( 'guest_cancelled' === $code ) {
			$refund = 31500;
			$paid   = 0;
		} elseif ( 'guest_hold_lost' === $code ) {
			$refund = 31500;
			$paid   = 0;
		} elseif ( 'guest_payment_link' === $code ) {
			$paid = 31500;
		}

		return array(
			'booking_id'        => 0,
			'status'            => $status,
			'ref'               => \Marclie\Booking\Settings::refPrefix() . '-27-0712-101',
			'first_name'        => 'Lena',
			'last_name'         => 'Hofer',
			'email'             => 'lena.hofer@example.com',
			'unit_title'        => 'Apartman 101',
			'house_name'        => BusinessInfo::get()['name'],
			'arrival'           => '2027-07-12',
			'departure'         => '2027-07-19',
			'nights'            => 7,
			'adults'            => 2,
			'children'          => 1,
			'infants'           => 0,
			'checkin_from'      => '16:00',
			'checkin_to'        => '21:00',
			'checkout_until'    => '10:00',
			'total_cents'       => 105000,
			'paid_cents'        => $paid,
			'deposit_cents'     => 31500,
			'balance_due_on'    => '2027-06-28',
			// Free cancellation ends before the balance date: only the confirmation still shows it.
			'free_cancel_until' => 'guest_confirmed' === $code ? '2027-06-12' : null,
			'card_last4'        => '4242',
			'refund_cents'      => $refund,
			'saved_card'        => true,
			'deposit_pct'       => 30,
			'quote'             => array(
				'unit_id'           => 0,
				'arrival'           => '2027-07-12',
				'departure'         => '2027-07-19',
				'nights'            => 7,
				'adults'            => 2,
				'children'          => 1,
				'infants'           => 0,
				// 60 € per person, 2 adults × 60 € + 1 child 5–10 × 30 €: 7 × 150 €.
				'lines'             => array(
					array(
						'type'         => 'nights',
						'nights'       => 7,
						'unit_cents'   => 15000,
						'total_cents'  => 105000,
						'person_cents' => 6000,
						'adults'       => 2,
						'children'     => 1,
						'child_cents'  => 3000,
						'min_guests'   => 0,
					),
					array(
						'type'        => 'cleaning',
						'nights'      => 0,
						'unit_cents'  => 0,
						'total_cents' => 0,
					),
					array(
						'type'        => 'tourist_tax',
						'nights'      => 0,
						'unit_cents'  => 0,
						'total_cents' => 0,
					),
				),
				'subtotal_cents'    => 105000,
				'discount_cents'    => 0,
				'total_cents'       => 105000,
				'deposit_cents'     => 31500,
				'balance_cents'     => 73500,
				'balance_due_on'    => '2027-06-28',
				'free_cancel_until' => '2027-06-12',
				'pay_in_full'       => false,
			),
			'alert_title'       => 'admin_alert' === $code ? __( 'Balance payment failed', 'marclie-booking' ) : '',
			'alert_message'     => 'admin_alert' === $code ? __( 'The card was declined (insufficient_funds). The guest received a payment link.', 'marclie-booking' ) : '',
		);
	}

	private static function label( string $code ): string {
		switch ( $code ) {
			case 'guest_confirmed':
				return __( 'Booking confirmed', 'marclie-booking' );
			case 'admin_new_booking':
				return __( 'New booking (admin)', 'marclie-booking' );
			case 'guest_balance_reminder':
				return __( 'Balance reminder', 'marclie-booking' );
			case 'guest_balance_paid':
				return __( 'Balance paid', 'marclie-booking' );
			case 'guest_balance_failed':
				return __( 'Balance payment failed', 'marclie-booking' );
			case 'guest_cancelled':
				return __( 'Booking cancelled', 'marclie-booking' );
			case 'guest_pre_arrival':
				return __( 'Before arrival', 'marclie-booking' );
			case 'guest_hold_lost':
				return __( 'Dates no longer available', 'marclie-booking' );
			case 'guest_payment_link':
				return __( 'Payment link', 'marclie-booking' );
			case 'admin_alert':
				return __( 'Alert (admin)', 'marclie-booking' );
			case 'account_verify':
				return __( 'Account: confirm email', 'marclie-booking' );
			case 'account_exists':
				return __( 'Account: already registered', 'marclie-booking' );
			case 'account_password_reset':
				return __( 'Account: password reset', 'marclie-booking' );
			case 'account_welcome':
				return __( 'Account: welcome', 'marclie-booking' );
			case 'account_tier_up':
				return __( 'Account: new member tier', 'marclie-booking' );
			default:
				return __( 'Message to a guest', 'marclie-booking' );
		}
	}

	private static function description( string $code ): string {
		switch ( $code ) {
			case 'guest_confirmed':
				return __( 'To the guest when the deposit or full payment is received. Guests without an account see an invitation to register ({account_invite}).', 'marclie-booking' );
			case 'admin_new_booking':
				return __( 'To the admin recipients when an online booking is confirmed.', 'marclie-booking' );
			case 'guest_balance_reminder':
				return __( 'To the guest 3 days before the balance is charged to the saved card.', 'marclie-booking' );
			case 'guest_balance_paid':
				return __( 'To the guest when the booking is paid in full.', 'marclie-booking' );
			case 'guest_balance_failed':
				return __( 'To the guest when the balance charge fails, with a payment link (the admin gets an alert).', 'marclie-booking' );
			case 'guest_cancelled':
				return __( 'To the guest when a confirmed booking is cancelled, with the refund amount.', 'marclie-booking' );
			case 'guest_pre_arrival':
				return __( 'To the guest 3 days before arrival: check-in times, documents, directions.', 'marclie-booking' );
			case 'guest_hold_lost':
				return __( 'To the guest when a payment arrived after the dates were taken; the payment is refunded.', 'marclie-booking' );
			case 'guest_payment_link':
				return __( 'To the guest when an admin creates a payment link and chooses to email it.', 'marclie-booking' );
			case 'admin_alert':
				return __( 'To the admin recipients on disputes, overpayments, failed balances, lost holds, guest cancellation requests, calendar conflicts with Airbnb / Booking.com and calendar imports failing for over 2 hours.', 'marclie-booking' );
			case 'account_verify':
				return __( 'To a new guest account: the link that confirms the email address and activates the account.', 'marclie-booking' );
			case 'account_exists':
				return __( 'When someone registers with an email address that already has an account: sign-in and password reset links.', 'marclie-booking' );
			case 'account_password_reset':
				return __( 'To a guest account that asked for a new password: the link to choose one.', 'marclie-booking' );
			case 'account_welcome':
				return __( 'To the guest once the account is confirmed, with the welcome coupon.', 'marclie-booking' );
			case 'account_tier_up':
				return __( 'To a member who reached the Silver or Gold tier, and every membership year, with the member coupon.', 'marclie-booking' );
			default:
				return __( 'Free email from the guest profile; this template is the starting text.', 'marclie-booking' );
		}
	}
}
