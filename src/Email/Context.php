<?php
/**
 * Data of one email: "facts" (raw booking values, from the DB or the
 * registry sample) turned into merge-tag values and block data, formatted in
 * the email language. Runs inside LanguageAdapter::withLanguage() so labels
 * come from the right translation.
 *
 * Guest links carry fresh tokens (`access` for the booking page, `pay` for
 * the payment page, 7 days) issued only when the template uses them; previews
 * and test sends get inert `t=preview` links and never create tokens.
 */

namespace Marclie\Booking\Email;

use Marclie\Booking\Account\AccountService;
use Marclie\Booking\Admin\AppPage;
use Marclie\Booking\Container;
use Marclie\Booking\Domain\Quote;
use Marclie\Booking\Frontend\AccountPages;
use Marclie\Booking\Frontend\Renderer as FrontendRenderer;
use Marclie\Booking\I18n\Formatter;
use Marclie\Booking\Repository\PaymentRepository;
use Marclie\Booking\Repository\TokenRepository;
use Marclie\Booking\Service\QuoteDisplay;

defined( 'ABSPATH' ) || exit;

final class Context {

	public const PAY_LINK_DAYS = 7;

	/** Placeholder token of preview/test links. */
	private const PREVIEW_TOKEN = 'preview';

	private const LINK_TAGS = array( 'manage_link', 'payment_link', 'search_link', 'directions_link', 'admin_link' );

	private const ACCOUNT_LINK_TAGS = array( 'verify_link', 'login_link', 'reset_link', 'account_link' );

	/**
	 * Facts of a stored booking. `$needed` = merge tags the rendered template
	 * uses (links are only built for those); `$preview` = no tokens.
	 *
	 * @param array<string, mixed> $booking Booking row.
	 * @param list<string>         $needed
	 * @param array<string, mixed> $extra   Overrides (payment_link, alert_title, alert_message…).
	 * @return array<string, mixed>
	 */
	public static function bookingFacts( array $booking, string $lang, array $needed, bool $preview, array $extra = array() ): array {
		$id       = (int) $booking['id'];
		$unit     = Container::units()->get( (int) $booking['unit_id'] );
		$payments = Container::paymentRepository()->forBooking( $id );
		$quote    = json_decode( (string) ( $booking['quote_json'] ?? '' ), true );
		$policy   = json_decode( (string) ( $booking['policy_json'] ?? '' ), true );
		$paid     = PaymentRepository::net( $payments );

		$facts = array(
			'booking_id'        => $id,
			'status'            => (string) $booking['status'],
			'ref'               => (string) $booking['ref'],
			'first_name'        => (string) $booking['first_name'],
			'last_name'         => (string) $booking['last_name'],
			'email'             => (string) $booking['email'],
			'unit_title'        => null !== $unit ? $unit->title : (string) get_the_title( (int) $booking['unit_id'] ),
			'house_name'        => null !== $unit ? self::houseName( $unit->houseSlug ) : '',
			'arrival'           => (string) $booking['arrival'],
			'departure'         => (string) $booking['departure'],
			'nights'            => (int) $booking['nights'],
			'adults'            => (int) $booking['adults'],
			'children'          => (int) $booking['children'],
			'infants'           => (int) $booking['infants'],
			'checkin_from'      => null !== $unit ? $unit->checkinFrom : Container::policy()->checkinFrom,
			'checkin_to'        => null !== $unit ? $unit->checkinTo : Container::policy()->checkinTo,
			'checkout_until'    => null !== $unit ? $unit->checkoutUntil : Container::policy()->checkoutUntil,
			'total_cents'       => (int) $booking['total_cents'],
			'paid_cents'        => $paid,
			'deposit_cents'     => (int) $booking['deposit_cents'],
			'balance_due_on'    => $booking['balance_due_on'] ? (string) $booking['balance_due_on'] : null,
			'free_cancel_until' => $booking['free_cancel_until'] ? (string) $booking['free_cancel_until'] : null,
			'card_last4'        => self::cardLast4( $payments ),
			'refund_cents'      => self::refunded( $payments ),
			'saved_card'        => ! empty( $booking['stripe_customer_id'] ) && ! empty( $booking['stripe_payment_method_id'] ),
			'deposit_pct'       => is_array( $policy ) && isset( $policy['deposit_pct'] ) ? (int) $policy['deposit_pct'] : Container::policy()->depositPct,
			'quote'             => is_array( $quote ) ? $quote : null,
			'alert_title'       => '',
			'alert_message'     => '',
			// {account_invite}: only for a guest whose email has no account yet.
			'has_account'       => ! empty( $booking['user_id'] ) || false !== email_exists( (string) $booking['email'] ),
		);

		$facts = array_merge( $facts, $extra );

		return self::withLinks( $facts, $lang, $needed, $preview, $id > 0 ? $booking : null );
	}

	/**
	 * Facts of a guest without a booking (guest_message).
	 *
	 * @param array<string, mixed> $guest Guest row.
	 * @return array<string, mixed>
	 */
	public static function guestFacts( array $guest ): array {
		return array(
			'booking_id' => 0,
			'first_name' => (string) $guest['first_name'],
			'last_name'  => (string) $guest['last_name'],
			'email'      => (string) $guest['email'],
		);
	}

	/**
	 * Facts of an account email (no booking).
	 *
	 * @param array{first_name?: string, last_name?: string, email: string} $person
	 * @param array{tier?: ?string, coupon?: ?array<string, mixed>}       $account Tier key and member coupon row (code, amount, min_nights, book_to).
	 * @param array<string, string>                                        $links   verify_link, login_link, reset_link, account_link.
	 * @return array<string, mixed>
	 */
	public static function accountFacts( array $person, array $account, array $links ): array {
		return array(
			'booking_id' => 0,
			'first_name' => (string) ( $person['first_name'] ?? '' ),
			'last_name'  => (string) ( $person['last_name'] ?? '' ),
			'email'      => (string) $person['email'],
			'account'    => $account,
			'links'      => $links,
		);
	}

	/**
	 * Registry sample with inert links.
	 *
	 * @return array<string, mixed>
	 */
	public static function sampleFacts( string $code, string $lang ): array {
		$sample = EmailRegistry::sample( $code );
		if ( EmailRegistry::isAccountTemplate( $code ) ) {
			$inert           = AccountService::pageUrl( 'moj_racun', $lang );
			$sample['links'] = array_fill_keys( self::ACCOUNT_LINK_TAGS, $inert );

			return $sample;
		}

		return self::withLinks( $sample, $lang, self::LINK_TAGS, true, null );
	}

	/**
	 * Merge-tag values (unescaped) and block data for the facts.
	 *
	 * @param array<string, mixed> $facts
	 * @param array<string, string> $business BusinessInfo::get().
	 * @return array{vars: array<string, string>, blocks: array<string, mixed>}
	 */
	public static function build( string $code, array $facts, string $lang, array $business ): array {
		$vars = array(
			'guest_first_name' => (string) ( $facts['first_name'] ?? '' ),
			'guest_name'       => trim( ( $facts['first_name'] ?? '' ) . ' ' . ( $facts['last_name'] ?? '' ) ),
			'business_name'    => $business['name'],
			'business_phone'   => $business['phone'],
			'business_email'   => $business['email'],
			'business_address' => $business['address'],
		);

		if ( ! isset( $facts['ref'] ) ) {
			if ( 'admin_alert' === $code ) {
				// An alert without a booking (e.g. a failing iCal feed): `subject_ref` replaces {booking_ref}.
				$vars += array(
					'booking_ref'   => (string) ( $facts['subject_ref'] ?? '' ),
					'alert_title'   => (string) ( $facts['alert_title'] ?? '' ),
					'alert_message' => (string) ( $facts['alert_message'] ?? '' ),
					'admin_link'    => (string) ( $facts['links']['admin_link'] ?? '' ),
				);

				return array(
					'vars'   => $vars,
					'blocks' => array( 'cta_button' => self::cta( $code, $vars ) ),
				);
			}
			if ( ! EmailRegistry::isAccountTemplate( $code ) ) {
				return array(
					'vars'   => $vars,
					'blocks' => array(),
				);
			}
			$vars += self::accountVars( $facts, $lang );

			return array(
				'vars'   => $vars,
				'blocks' => array( 'cta_button' => self::cta( $code, $vars ) ),
			);
		}

		$total   = (int) $facts['total_cents'];
		$paid    = (int) $facts['paid_cents'];
		$balance = max( 0, $total - $paid );
		$date    = static fn( $ymd ): string => empty( $ymd ) ? '' : Formatter::date( (string) $ymd, $lang );

		$vars += array(
			'booking_ref'       => (string) $facts['ref'],
			'unit_name'         => self::unitName( (string) $facts['unit_title'], $lang ),
			'house_name'        => (string) $facts['house_name'],
			'arrival_date'      => $date( $facts['arrival'] ),
			'departure_date'    => $date( $facts['departure'] ),
			'nights'            => (string) (int) $facts['nights'],
			'adults'            => (string) (int) $facts['adults'],
			'children'          => (string) (int) $facts['children'],
			'checkin_window'    => $facts['checkin_from'] . '–' . $facts['checkin_to'],
			'checkout_until'    => (string) $facts['checkout_until'],
			'total'             => Formatter::price( $total, $lang ),
			'paid'              => Formatter::price( $paid, $lang ),
			'deposit'           => Formatter::price( (int) $facts['deposit_cents'], $lang ),
			'balance'           => Formatter::price( $balance, $lang ),
			'balance_due_date'  => $date( $facts['balance_due_on'] ),
			'free_cancel_until' => $date( $facts['free_cancel_until'] ),
			'card_last4'        => (string) ( $facts['card_last4'] ?? '' ),
			'refund_amount'     => Formatter::price( (int) $facts['refund_cents'], $lang ),
			'directions_link'   => '' !== $business['maps_url'] ? $business['maps_url'] : home_url( '/' ),
			'alert_title'       => (string) ( $facts['alert_title'] ?? '' ),
			'alert_message'     => (string) ( $facts['alert_message'] ?? '' ),
		);
		foreach ( self::LINK_TAGS as $tag ) {
			if ( isset( $facts['links'][ $tag ] ) ) {
				$vars[ $tag ] = (string) $facts['links'][ $tag ];
			}
		}
		foreach ( self::LINK_TAGS as $tag ) {
			$vars[ $tag ] ??= '';
		}

		return array(
			'vars'   => $vars,
			'blocks' => array(
				'booking_summary' => self::summaryRows( $facts, $vars, $lang ),
				'payment_table'   => self::paymentRows( $facts, $vars, $balance, $lang ),
				'cta_button'      => self::cta( $code, $vars ),
				'account_invite'  => empty( $facts['has_account'] ) ? self::accountInvite( $facts, $lang ) : null,
			),
		);
	}

	/**
	 * EN emails use the glossary name of a unit ("Apartman 1" → "Apartment 1").
	 */
	public static function unitName( string $title, string $lang ): string {
		return 'en' === $lang ? (string) preg_replace( '/^Apartman\b/u', 'Apartment', $title ) : $title;
	}

	/* Facts helpers ------------------------------------------------------- */

	/**
	 * @param array<string, mixed>      $facts
	 * @param list<string>              $needed
	 * @param array<string, mixed>|null $booking Stored booking (tokens) or null (sample).
	 * @return array<string, mixed>
	 */
	private static function withLinks( array $facts, string $lang, array $needed, bool $preview, ?array $booking ): array {
		$links = (array) ( $facts['links'] ?? array() );
		$ref   = (string) $facts['ref'];
		$id    = (int) $facts['booking_id'];

		if ( in_array( 'manage_link', $needed, true ) && ! isset( $links['manage_link'] ) ) {
			$token                = $preview || null === $booking ? self::PREVIEW_TOKEN : Container::bookings()->issueToken( $id, TokenRepository::ACCESS, null );
			$links['manage_link'] = self::tokenUrl( 'hvala', $ref, $token, $lang );
		}
		if ( in_array( 'payment_link', $needed, true ) && ! isset( $links['payment_link'] ) ) {
			$token                 = $preview || null === $booking
				? self::PREVIEW_TOKEN
				: Container::bookings()->issueToken( $id, TokenRepository::PAY, Container::clock()->now()->modify( sprintf( '+%d days', self::PAY_LINK_DAYS ) ) );
			$links['payment_link'] = self::tokenUrl( 'placanje', $ref, $token, $lang );
		}
		if ( in_array( 'search_link', $needed, true ) && ! isset( $links['search_link'] ) ) {
			$links['search_link'] = add_query_arg(
				array(
					'dolazak' => (string) $facts['arrival'],
					'odlazak' => (string) $facts['departure'],
					'odrasli' => (int) $facts['adults'],
					'djeca'   => (int) $facts['children'],
					'bebe'    => (int) ( $facts['infants'] ?? 0 ) ?: null,
				),
				FrontendRenderer::pageUrl( 'pretraga', $lang )
			);
		}
		if ( in_array( 'admin_link', $needed, true ) && ! isset( $links['admin_link'] ) ) {
			$links['admin_link'] = admin_url( 'admin.php?page=' . AppPage::SLUG . ( $id > 0 ? '#/bookings/' . $id : '#/' ) );
		}

		$facts['links'] = $links;

		return $facts;
	}

	private static function tokenUrl( string $page, string $ref, string $token, string $lang ): string {
		return add_query_arg(
			array(
				'ref' => rawurlencode( $ref ),
				't'   => $token,
			),
			FrontendRenderer::pageUrl( $page, $lang )
		);
	}

	private static function houseName( ?string $slug ): string {
		if ( null === $slug || '' === $slug ) {
			return '';
		}
		$term = get_term_by( 'slug', $slug, 'kuca' );

		return $term instanceof \WP_Term ? $term->name : '';
	}

	/** @param list<array<string, mixed>> $payments */
	private static function cardLast4( array $payments ): string {
		$last4 = '';
		foreach ( $payments as $p ) {
			if ( 'refund' !== $p['kind'] && 'succeeded' === $p['status'] && '' !== (string) ( $p['card_last4'] ?? '' ) ) {
				$last4 = (string) $p['card_last4'];
			}
		}

		return $last4;
	}

	/** Refunds recorded or on their way (failed ones excluded). */
	private static function refunded( array $payments ): int {
		$sum = 0;
		foreach ( $payments as $p ) {
			if ( 'refund' === $p['kind'] && 'failed' !== $p['status'] ) {
				$sum += (int) $p['amount_cents'];
			}
		}

		return $sum;
	}

	/* Blocks -------------------------------------------------------------- */

	/**
	 * @param array<string, mixed>  $facts
	 * @param array<string, string> $vars
	 * @return list<array{label: string, value: string}>
	 */
	private static function summaryRows( array $facts, array $vars, string $lang ): array {
		$unit = $vars['unit_name'] . ( '' !== $vars['house_name'] ? ' · ' . $vars['house_name'] : '' );
		$rows = array(
			array(
				'label' => __( 'Booking reference', 'marclie-booking' ),
				'value' => $vars['booking_ref'],
			),
			array(
				'label' => __( 'Apartment', 'marclie-booking' ),
				'value' => $unit,
			),
			array(
				'label' => __( 'Check-in', 'marclie-booking' ),
				/* translators: 1: arrival date, 2: check-in window, e.g. "15:00–22:00" */
				'value' => sprintf( __( '%1$s, %2$s', 'marclie-booking' ), $vars['arrival_date'], $vars['checkin_window'] ),
			),
			array(
				'label' => __( 'Check-out', 'marclie-booking' ),
				/* translators: 1: departure date, 2: latest check-out time, e.g. "10:00" */
				'value' => sprintf( __( '%1$s, by %2$s', 'marclie-booking' ), $vars['departure_date'], $vars['checkout_until'] ),
			),
			array(
				'label' => __( 'Nights', 'marclie-booking' ),
				'value' => $vars['nights'],
			),
			array(
				'label' => __( 'Guests', 'marclie-booking' ),
				'value' => AccountPages::party( (int) $facts['adults'], (int) $facts['children'], (int) ( $facts['infants'] ?? 0 ) ),
			),
		);

		// Only while the right still exists (it ends before the balance date, so most later emails omit it).
		$active = in_array( (string) ( $facts['status'] ?? '' ), array( 'pending_payment', 'confirmed', 'paid', 'balance_failed' ), true );
		$open   = ! empty( $facts['free_cancel_until'] ) && (string) $facts['free_cancel_until'] >= Container::clock()->today()->format( 'Y-m-d' );
		if ( $active && $open ) {
			$rows[] = array(
				'label' => __( 'Free cancellation', 'marclie-booking' ),
				/* translators: %s: last day of free cancellation */
				'value' => sprintf( __( 'until %s', 'marclie-booking' ), $vars['free_cancel_until'] ),
			);
		}

		return $rows;
	}

	/**
	 * Quote lines (nights, cleaning, tourist tax, coupon) then total, paid,
	 * balance (with how/when it is collected) and refund rows.
	 *
	 * @param array<string, mixed>  $facts
	 * @param array<string, string> $vars
	 * @return array{lines: list<array{label: string, amount: string}>, totals: list<array{label: string, amount: string, note?: string, strong?: bool}>}
	 */
	private static function paymentRows( array $facts, array $vars, int $balance, string $lang ): array {
		$lines = array();
		if ( is_array( $facts['quote'] ?? null ) ) {
			$display = QuoteDisplay::build( Quote::fromArray( $facts['quote'], wp_timezone() ), $lang, (int) $facts['deposit_pct'] );
			foreach ( $display['lines'] as $line ) {
				$lines[] = array(
					'label'  => $line['label'],
					'amount' => $line['amount'],
				);
			}
		}

		$totals = array(
			array(
				'label'  => __( 'Total', 'marclie-booking' ),
				'amount' => $vars['total'],
				'strong' => true,
			),
		);
		if ( (int) $facts['paid_cents'] > 0 ) {
			$totals[] = array(
				'label'  => __( 'Paid', 'marclie-booking' ),
				'amount' => $vars['paid'],
			);
		}

		$status = (string) ( $facts['status'] ?? '' );
		if ( $balance > 0 && in_array( $status, array( 'pending_payment', 'confirmed', 'balance_failed' ), true ) ) {
			$row = array(
				'label'  => __( 'Balance', 'marclie-booking' ),
				'amount' => $vars['balance'],
			);
			if ( 'confirmed' === $status && '' !== $vars['balance_due_date'] ) {
				$row['note'] = ! empty( $facts['saved_card'] ) && '' !== $vars['card_last4']
					/* translators: 1: date, 2: last 4 digits of the card */
					? sprintf( __( 'Charged on %1$s to the card •••• %2$s', 'marclie-booking' ), $vars['balance_due_date'], $vars['card_last4'] )
					/* translators: %s: date */
					: sprintf( __( 'Due on %s', 'marclie-booking' ), $vars['balance_due_date'] );
			}
			$totals[] = $row;
		}
		if ( (int) $facts['refund_cents'] > 0 ) {
			$totals[] = array(
				'label'  => __( 'Refund', 'marclie-booking' ),
				'amount' => $vars['refund_amount'],
				'strong' => true,
			);
		}

		return array(
			'lines'  => $lines,
			'totals' => $totals,
		);
	}

	/**
	 * @param array<string, string> $vars
	 * @return array{url: string, label: string}|null
	 */
	private static function cta( string $code, array $vars ): ?array {
		$tag = EmailRegistry::ctaTag( $code );
		if ( null === $tag || '' === ( $vars[ $tag ] ?? '' ) ) {
			return null;
		}

		switch ( $tag ) {
			case 'manage_link':
				$label = __( 'View your booking', 'marclie-booking' );
				break;
			case 'payment_link':
				$label = 'guest_balance_failed' === $code ? __( 'Pay the balance', 'marclie-booking' ) : __( 'Pay now', 'marclie-booking' );
				break;
			case 'search_link':
				$label = __( 'Apartments free on these dates', 'marclie-booking' );
				break;
			case 'directions_link':
				$label = __( 'Getting here', 'marclie-booking' );
				break;
			case 'verify_link':
				$label = __( 'Confirm your email', 'marclie-booking' );
				break;
			case 'login_link':
				$label = __( 'Sign in', 'marclie-booking' );
				break;
			case 'reset_link':
				$label = __( 'Choose a new password', 'marclie-booking' );
				break;
			case 'account_link':
				$label = __( 'Go to my account', 'marclie-booking' );
				break;
			default:
				$label = __( 'Open in the booking admin', 'marclie-booking' );
		}

		return array(
			'url'   => $vars[ $tag ],
			'label' => $label,
		);
	}

	/**
	 * Merge-tag values of an account email: links, tier name and member coupon.
	 *
	 * @param array<string, mixed> $facts
	 * @return array<string, string>
	 */
	private static function accountVars( array $facts, string $lang ): array {
		$account = (array) ( $facts['account'] ?? array() );
		$coupon  = is_array( $account['coupon'] ?? null ) ? $account['coupon'] : null;
		$vars    = array(
			'tier_name'          => AccountService::tierLabel( (string) ( $account['tier'] ?? '' ) ),
			'coupon_code'        => null !== $coupon ? (string) $coupon['code'] : '',
			'coupon_discount'    => null !== $coupon ? Formatter::percent( (int) $coupon['amount'], $lang ) : '',
			'coupon_min_nights'  => null !== $coupon ? (string) (int) $coupon['min_nights'] : '',
			'coupon_valid_until' => null !== $coupon && ! empty( $coupon['book_to'] ) ? Formatter::date( (string) $coupon['book_to'], $lang ) : '',
		);
		foreach ( self::ACCOUNT_LINK_TAGS as $tag ) {
			$vars[ $tag ] = (string) ( $facts['links'][ $tag ] ?? '' );
		}

		return $vars;
	}

	/**
	 * {account_invite} of guest_confirmed: registration link with the booking
	 * email prefilled (registering with it links this booking to the account).
	 *
	 * @param array<string, mixed> $facts
	 * @return array{url: string, title: string, text: string, label: string}
	 */
	private static function accountInvite( array $facts, string $lang ): array {
		return array(
			'url'   => add_query_arg( 'email', rawurlencode( (string) ( $facts['email'] ?? '' ) ), AccountService::pageUrl( 'registracija', $lang ) ),
			'title' => __( 'Create your guest account', 'marclie-booking' ),
			'text'  => __( 'Register with this email address: this booking appears in your account right away, you get a 5 % welcome coupon for your next stay and member perks after your first stay.', 'marclie-booking' ),
			'label' => __( 'Create an account', 'marclie-booking' ),
		);
	}
}
