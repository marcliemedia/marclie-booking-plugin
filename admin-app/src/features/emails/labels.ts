import { __ } from '@wordpress/i18n';

/**
 * Client-side names for template codes and merge tags. The template list API
 * (manage_settings) has its own labels; these cover the booking sheet, which
 * booking managers without settings access also use.
 */
export function emailCodeLabel( code: string ): string {
	switch ( code ) {
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
		case 'guest_message':
			return __( 'Message to a guest', 'marclie-booking' );
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
			return code;
	}
}

/** `{booking_ref}` / `booking_ref` → `booking_ref`. */
export function bareTag( tag: string ): string {
	return tag.replace( /^\{|\}$/g, '' );
}

export function mergeTagLabel( tag: string ): string {
	switch ( bareTag( tag ) ) {
		case 'guest_first_name':
			return __( 'Guest first name', 'marclie-booking' );
		case 'guest_name':
			return __( 'Guest full name', 'marclie-booking' );
		case 'booking_ref':
			return __( 'Booking reference', 'marclie-booking' );
		case 'unit_name':
			return __( 'Apartment', 'marclie-booking' );
		case 'house_name':
			return __( 'House', 'marclie-booking' );
		case 'arrival_date':
			return __( 'Arrival date', 'marclie-booking' );
		case 'departure_date':
			return __( 'Departure date', 'marclie-booking' );
		case 'nights':
			return __( 'Nights', 'marclie-booking' );
		case 'adults':
			return __( 'Adults', 'marclie-booking' );
		case 'children':
			return __( 'Children', 'marclie-booking' );
		case 'checkin_window':
			return __( 'Check-in hours', 'marclie-booking' );
		case 'checkout_until':
			return __( 'Check-out until', 'marclie-booking' );
		case 'total':
			return __( 'Total price', 'marclie-booking' );
		case 'paid':
			return __( 'Amount paid', 'marclie-booking' );
		case 'deposit':
			return __( 'Deposit', 'marclie-booking' );
		case 'balance':
			return __( 'Balance due', 'marclie-booking' );
		case 'balance_due_date':
			return __( 'Balance due date', 'marclie-booking' );
		case 'free_cancel_until':
			return __( 'Free cancellation until', 'marclie-booking' );
		case 'card_last4':
			return __( 'Card last 4 digits', 'marclie-booking' );
		case 'refund_amount':
			return __( 'Refund amount', 'marclie-booking' );
		case 'manage_link':
			return __( 'Booking page link', 'marclie-booking' );
		case 'payment_link':
			return __( 'Payment page link', 'marclie-booking' );
		case 'search_link':
			return __( 'Search page link', 'marclie-booking' );
		case 'directions_link':
			return __( 'Directions link', 'marclie-booking' );
		case 'business_name':
			return __( 'Business name', 'marclie-booking' );
		case 'business_phone':
			return __( 'Business phone', 'marclie-booking' );
		case 'business_email':
			return __( 'Business e-mail', 'marclie-booking' );
		case 'business_address':
			return __( 'Business address', 'marclie-booking' );
		case 'admin_link':
			return __( 'Admin link', 'marclie-booking' );
		case 'alert_title':
			return __( 'Alert title', 'marclie-booking' );
		case 'alert_message':
			return __( 'Alert message', 'marclie-booking' );
		case 'booking_summary':
			return __( 'Booking summary', 'marclie-booking' );
		case 'payment_table':
			return __( 'Payment table', 'marclie-booking' );
		case 'cta_button':
			return __( 'Call-to-action button', 'marclie-booking' );
		case 'account_invite':
			return __( 'Account invitation', 'marclie-booking' );
		case 'verify_link':
			return __( 'E-mail confirmation link', 'marclie-booking' );
		case 'login_link':
			return __( 'Login page link', 'marclie-booking' );
		case 'reset_link':
			return __( 'Password reset link', 'marclie-booking' );
		case 'account_link':
			return __( 'Account page link', 'marclie-booking' );
		case 'tier_name':
			return __( 'Membership tier', 'marclie-booking' );
		case 'coupon_code':
			return __( 'Coupon code', 'marclie-booking' );
		case 'coupon_discount':
			return __( 'Coupon discount', 'marclie-booking' );
		case 'coupon_min_nights':
			return __( 'Coupon minimum nights', 'marclie-booking' );
		case 'coupon_valid_until':
			return __( 'Coupon valid until', 'marclie-booking' );
		default:
			return bareTag( tag );
	}
}
