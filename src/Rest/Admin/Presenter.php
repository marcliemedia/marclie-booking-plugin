<?php
/**
 * Maps rows and domain objects to the admin app's JSON shapes
 * (reports/admin-api-contract.md → Types). Money in cents, dates Y-m-d,
 * timestamps ISO 8601 in the site timezone.
 */

namespace Marclie\Booking\Rest\Admin;

use Marclie\Booking\Account\AccountService;
use Marclie\Booking\Container;
use Marclie\Booking\Coupons\Coupon;
use Marclie\Booking\Domain\BookingStatus;
use Marclie\Booking\Domain\Quote;
use Marclie\Booking\Email\EmailRegistry;
use Marclie\Booking\Ical\FeedRepository;
use Marclie\Booking\Payment\StripeConfig;
use Marclie\Booking\Repository\PaymentRepository;
use Marclie\Booking\Repository\CouponRepository;
use Marclie\Booking\Units\Unit;

defined( 'ABSPATH' ) || exit;

final class Presenter {

	/** @var array<string, string> */
	private static array $actors = array();

	/** @var array<string, string> */
	private static array $houses = array();

	/* Scalars ---------------------------------------------------------------- */

	/** UTC DATETIME → ISO 8601 in the site timezone. */
	public static function iso( ?string $utc ): ?string {
		if ( null === $utc || '' === $utc || str_starts_with( $utc, '0000-00-00' ) ) {
			return null;
		}

		return ( new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() )->format( DATE_ATOM );
	}

	/** Empty string → null. */
	public static function nullable( mixed $value ): ?string {
		return null === $value || '' === $value ? null : (string) $value;
	}

	/** Cents as "1.365,00 €" for event summaries. */
	public static function money( int $cents ): string {
		return number_format_i18n( $cents / 100, 2 ) . ' €';
	}

	/* Units ------------------------------------------------------------------ */

	/** @return array<string, mixed> */
	public static function unit( Unit $unit ): array {
		$thumb = $unit->thumbnailId ? wp_get_attachment_image_url( $unit->thumbnailId, 'thumbnail' ) : false;

		return array(
			'id'            => $unit->id,
			'code'          => $unit->code,
			'title'         => $unit->title,
			'house'         => self::houseName( $unit->houseSlug ),
			'houseSlug'     => $unit->houseSlug,
			'capacity'      => $unit->capacity,
			'maxChildren'   => $unit->maxChildren,
			'thumb'         => $thumb ? (string) $thumb : null,
			'permalink'     => $unit->permalink,
			'checkinFrom'   => $unit->checkinFrom,
			'checkinTo'     => $unit->checkinTo,
			'checkoutUntil' => $unit->checkoutUntil,
		);
	}

	public static function unitTitle( int $unitId ): string {
		return Container::units()->get( $unitId )?->title ?? sprintf( '#%d', $unitId );
	}

	private static function houseName( ?string $slug ): string {
		if ( null === $slug ) {
			return '';
		}
		if ( ! isset( self::$houses[ $slug ] ) ) {
			$term                 = get_term_by( 'slug', $slug, 'kuca' );
			self::$houses[ $slug ] = $term instanceof \WP_Term ? $term->name : $slug;
		}

		return self::$houses[ $slug ];
	}

	/* Bookings --------------------------------------------------------------- */

	/**
	 * @param list<array<string, mixed>> $rows Booking rows (with or without `paid_cents`).
	 * @return list<array<string, mixed>>
	 */
	public static function bookingSummaries( array $rows ): array {
		$missing = array();
		foreach ( $rows as $row ) {
			if ( ! array_key_exists( 'paid_cents', $row ) ) {
				$missing[] = (int) $row['id'];
			}
		}
		$paid = Container::paymentRepository()->netPaidByBooking( $missing );

		return array_map(
			static function ( array $row ) use ( $paid ): array {
				$row['paid_cents'] ??= $paid[ (int) $row['id'] ] ?? 0;

				return self::bookingSummary( $row );
			},
			$rows
		);
	}

	/**
	 * @param array<string, mixed> $row Booking row; `paid_cents` is loaded when absent.
	 * @return array<string, mixed>
	 */
	public static function bookingSummary( array $row ): array {
		$paid  = array_key_exists( 'paid_cents', $row ) ? (int) $row['paid_cents'] : Container::paymentRepository()->netPaidCents( (int) $row['id'] );
		$total = (int) $row['total_cents'];
		$name  = trim( $row['first_name'] . ' ' . $row['last_name'] );

		return array(
			'id'                => (int) $row['id'],
			'ref'               => (string) $row['ref'],
			'status'            => (string) $row['status'],
			'source'            => (string) $row['source'],
			'unit_id'           => (int) $row['unit_id'],
			'unit_title'        => self::unitTitle( (int) $row['unit_id'] ),
			'arrival'           => (string) $row['arrival'],
			'departure'         => (string) $row['departure'],
			'nights'            => (int) $row['nights'],
			'adults'            => (int) $row['adults'],
			'children'          => (int) $row['children'],
			'infants'           => (int) $row['infants'],
			'guest'             => array(
				'id'      => null === $row['guest_id'] ? null : (int) $row['guest_id'],
				'name'    => $name,
				'email'   => (string) $row['email'],
				'country' => self::nullable( $row['country'] ),
			),
			'total_cents'       => $total,
			'paid_cents'        => $paid,
			// A cancelled or expired booking owes nothing, whatever was refunded or kept.
			'balance_cents'     => in_array( (string) $row['status'], array( BookingStatus::Cancelled->value, BookingStatus::Expired->value ), true ) ? 0 : max( 0, $total - $paid ),
			'balance_due_on'    => self::nullable( $row['balance_due_on'] ),
			'free_cancel_until' => self::nullable( $row['free_cancel_until'] ),
			'created_at'        => self::iso( (string) $row['created_at'] ),
		);
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public static function bookingDetail( array $row ): array {
		$id       = (int) $row['id'];
		$payments = Container::paymentRepository()->forBooking( $id );
		$name     = trim( $row['first_name'] . ' ' . $row['last_name'] );
		$charges  = array();
		foreach ( $payments as $payment ) {
			if ( 'refund' !== $payment['kind'] && ! empty( $payment['stripe_intent_id'] ) ) {
				$charges[ (string) $payment['stripe_intent_id'] ] ??= (int) $payment['id'];
			}
		}
		foreach ( $payments as &$payment ) {
			$payment['booking_ref']  = (string) $row['ref'];
			$payment['guest_name']   = $name;
			$payment['refund_of_id'] = 'refund' === $payment['kind'] && ! empty( $payment['stripe_intent_id'] ) ? ( $charges[ (string) $payment['stripe_intent_id'] ] ?? null ) : null;
		}
		unset( $payment );

		$quote  = json_decode( (string) $row['quote_json'], true );
		$policy = json_decode( (string) $row['policy_json'], true );
		$guest  = null === $row['guest_id'] ? null : Container::guestRepository()->find( (int) $row['guest_id'] );
		$status = BookingStatus::tryFrom( (string) $row['status'] );

		$events = array_map(
			static function ( array $event ) use ( $row ): array {
				$event['booking_ref'] = (string) $row['ref'];

				return self::event( $event );
			},
			array_reverse( Container::eventRepository()->forBooking( $id ) )
		);

		return array_merge(
			self::bookingSummary( $row ),
			array(
				'first_name'          => (string) $row['first_name'],
				'last_name'           => (string) $row['last_name'],
				'email'               => (string) $row['email'],
				'phone'               => (string) $row['phone'],
				'arrival_time'        => self::nullable( $row['arrival_time'] ),
				'message'             => self::nullable( $row['message'] ),
				'internal_notes'      => self::nullable( $row['internal_notes'] ),
				'lang'                => 'en' === $row['lang'] ? 'en' : 'hr',
				'quote'               => is_array( $quote ) ? self::quoteArray( $quote ) : null,
				'policy'              => is_array( $policy ) ? $policy : array(),
				'hold_expires_at'     => self::iso( $row['hold_expires_at'] ),
				'coupon_code'         => self::nullable( $row['coupon_code'] ),
				'payments'            => array_map( array( self::class, 'payment' ), $payments ),
				'saved_card'          => ! empty( $row['stripe_customer_id'] ) && ! empty( $row['stripe_payment_method_id'] ),
				'resendable_codes'    => Container::mailer()->resendableCodes( $row ),
				'events'              => $events,
				'guest_full'          => null === $guest ? null : self::guest( $guest ),
				'user_id'             => empty( $row['user_id'] ) ? null : (int) $row['user_id'],
				'account'             => empty( $row['user_id'] ) ? null : self::account( (int) $row['user_id'] ),
				'allowed_transitions' => null === $status ? array() : array_map( static fn( BookingStatus $s ): string => $s->value, $status->allowedTargets() ),
			)
		);
	}

	/** @return array<string, mixed> */
	public static function quote( Quote $quote ): array {
		return self::quoteArray( $quote->toArray() );
	}

	/**
	 * @param array<string, mixed> $quote Quote::toArray() / quote_json shape.
	 * @return array<string, mixed>
	 */
	public static function quoteArray( array $quote ): array {
		unset( $quote['coupon_id'] );

		return $quote;
	}

	/* Payments & events ------------------------------------------------------ */

	/**
	 * @param array<string, mixed> $row Payment row; `booking_ref` and guest name are
	 *                                  taken from joined columns when present.
	 * @return array<string, mixed>
	 */
	public static function payment( array $row ): array {
		$guestName = $row['guest_name'] ?? trim( ( $row['booking_first_name'] ?? '' ) . ' ' . ( $row['booking_last_name'] ?? '' ) );

		return array(
			'id'                   => (int) $row['id'],
			'booking_id'           => (int) $row['booking_id'],
			'booking_ref'          => (string) ( $row['booking_ref'] ?? '' ),
			'guest_name'           => (string) $guestName,
			'kind'                 => (string) $row['kind'],
			'method'               => (string) $row['method'],
			'amount_cents'         => (int) $row['amount_cents'],
			'status'               => (string) $row['status'],
			'card_brand'           => self::nullable( $row['card_brand'] ?? null ),
			'card_last4'           => self::nullable( $row['card_last4'] ?? null ),
			'stripe_intent_id'     => self::nullable( $row['stripe_intent_id'] ?? null ),
			'stripe_refund_id'     => self::nullable( $row['stripe_refund_id'] ?? null ),
			'stripe_dashboard_url' => PaymentRepository::METHOD_STRIPE === $row['method'] && ! empty( $row['stripe_intent_id'] ) ? StripeConfig::paymentUrl( (string) $row['stripe_intent_id'] ) : null,
			'refund_of_id'         => isset( $row['refund_of_id'] ) ? (int) $row['refund_of_id'] : null,
			'error_code'           => self::nullable( $row['error_code'] ?? null ),
			'created_at'           => self::iso( (string) $row['created_at'] ),
			'note'                 => self::nullable( $row['note'] ?? null ),
		);
	}

	/**
	 * @param array<string, mixed> $row Event row (hydrated, with `payload`; optional `booking_ref`).
	 * @return array<string, mixed>
	 */
	public static function event( array $row ): array {
		$payload = isset( $row['payload'] ) && is_array( $row['payload'] ) ? $row['payload'] : (array) json_decode( (string) ( $row['payload_json'] ?? '' ), true );

		return array(
			'id'          => (int) $row['id'],
			'type'        => (string) $row['type'],
			'actor'       => self::actor( (string) $row['actor'] ),
			'created_at'  => self::iso( (string) $row['created_at'] ),
			'booking_ref' => self::nullable( $row['booking_ref'] ?? null ),
			'summary'     => self::summary( (string) $row['type'], $payload ),
			'payload'     => (object) $payload,
		);
	}

	/** `user:12` → display name; `guest`, `system:*` → readable labels. */
	public static function actor( string $actor ): string {
		if ( isset( self::$actors[ $actor ] ) ) {
			return self::$actors[ $actor ];
		}

		if ( preg_match( '/^user:(\d+)$/', $actor, $m ) ) {
			$user  = get_userdata( (int) $m[1] );
			$label = $user ? $user->display_name : $actor;
		} elseif ( 'guest' === $actor || str_starts_with( $actor, AccountService::ACTOR_PREFIX ) ) {
			$label = str_starts_with( $actor, AccountService::ACTOR_PREFIX ) ? __( 'Guest (account)', 'marclie-booking' ) : __( 'Guest', 'marclie-booking' );
		} elseif ( str_starts_with( $actor, 'system' ) ) {
			$label = __( 'System', 'marclie-booking' );
		} else {
			$label = $actor;
		}

		return self::$actors[ $actor ] = $label;
	}

	/**
	 * One-line English description of an event (translated via __()).
	 *
	 * @param array<string, mixed> $p
	 */
	public static function summary( string $type, array $p ): string {
		switch ( $type ) {
			case 'booking_created':
				/* translators: %s: booking source (direct, admin…) */
				return sprintf( __( 'Booking created (%s)', 'marclie-booking' ), (string) ( $p['source'] ?? '' ) );
			case 'status_changed':
				/* translators: 1: previous status, 2: new status */
				return sprintf( __( 'Status changed: %1$s → %2$s', 'marclie-booking' ), (string) ( $p['from'] ?? '' ), (string) ( $p['to'] ?? '' ) );
			case 'payment_recorded':
				/* translators: 1: amount, 2: payment method */
				return sprintf( __( 'Payment recorded: %1$s (%2$s)', 'marclie-booking' ), self::money( (int) ( $p['amount_cents'] ?? 0 ) ), (string) ( $p['method'] ?? '' ) );
			case 'refund_recorded':
				$total = array_sum( array_map( static fn( $r ): int => (int) ( $r['amount_cents'] ?? 0 ), (array) ( $p['refunds'] ?? array() ) ) );
				/* translators: %s: amount */
				return sprintf( __( 'Refund recorded: %s', 'marclie-booking' ), self::money( $total ) );
			case 'payment_succeeded':
				/* translators: 1: amount, 2: card brand and last digits */
				return sprintf( __( 'Card payment received: %1$s (%2$s)', 'marclie-booking' ), self::money( (int) ( $p['amount_cents'] ?? 0 ) ), trim( ( $p['card_brand'] ?? '' ) . ' ' . ( '' !== (string) ( $p['card_last4'] ?? '' ) ? '•••• ' . $p['card_last4'] : '' ) ) );
			case 'payment_failed':
				/* translators: %s: Stripe error/decline code */
				return sprintf( __( 'Card payment failed (%s)', 'marclie-booking' ), (string) ( $p['code'] ?? '' ) );
			case 'payment_error':
				return __( 'Stripe could not process a card payment', 'marclie-booking' );
			case 'refund_created':
				/* translators: %s: amount */
				return sprintf( __( 'Card refund sent to Stripe: %s', 'marclie-booking' ), self::money( (int) ( $p['amount_cents'] ?? 0 ) ) );
			case 'refund_failed':
				/* translators: %s: amount */
				return sprintf( __( 'Card refund failed: %s', 'marclie-booking' ), self::money( (int) ( $p['amount_cents'] ?? 0 ) ) );
			case 'refund_external':
				/* translators: %s: amount */
				return sprintf( __( 'Refunded in the Stripe Dashboard: %s', 'marclie-booking' ), self::money( (int) ( $p['amount_cents'] ?? 0 ) ) );
			case 'hold_lost':
				return __( 'Payment arrived after the hold expired and the dates were taken: refunded in full', 'marclie-booking' );
			case 'late_payment_refunded':
				return __( 'Payment arrived after the cancellation: refunded in full', 'marclie-booking' );
			case 'dispute_created':
				/* translators: %s: amount */
				return sprintf( __( 'Card payment disputed by the guest’s bank: %s', 'marclie-booking' ), self::money( (int) ( $p['amount_cents'] ?? 0 ) ) );
			case 'payment_link_created':
				return __( 'Payment link created', 'marclie-booking' );
			case 'overpayment':
				/* translators: %s: amount */
				return sprintf( __( 'Paid twice by card: %s refunded automatically', 'marclie-booking' ), self::money( (int) ( $p['amount_cents'] ?? 0 ) ) );
			case 'payment_not_created':
				return __( 'Card payment request did not reach Stripe (nothing was charged)', 'marclie-booking' );
			case 'refund_override':
				/* translators: %s: amount */
				return sprintf( __( 'Refund amount set by admin: %s', 'marclie-booking' ), self::money( (int) ( $p['refund_cents'] ?? 0 ) ) );
			case 'booking_moved':
				/* translators: 1: old arrival, 2: old departure, 3: new arrival, 4: new departure */
				return sprintf( __( 'Moved from %1$s – %2$s to %3$s – %4$s', 'marclie-booking' ), (string) ( $p['from']['arrival'] ?? '' ), (string) ( $p['from']['departure'] ?? '' ), (string) ( $p['to']['arrival'] ?? '' ), (string) ( $p['to']['departure'] ?? '' ) );
			case 'booking_updated':
				/* translators: %s: comma-separated field names */
				return sprintf( __( 'Booking edited: %s', 'marclie-booking' ), implode( ', ', (array) ( $p['fields'] ?? array() ) ) );
			case 'block_created':
				/* translators: 1: unit, 2: from date, 3: to date */
				return sprintf( __( 'Calendar blocked: %1$s, %2$s – %3$s', 'marclie-booking' ), self::unitTitle( (int) ( $p['unit_id'] ?? 0 ) ), (string) ( $p['date_from'] ?? '' ), (string) ( $p['date_to'] ?? '' ) );
			case 'block_deleted':
				/* translators: 1: unit, 2: from date, 3: to date */
				return sprintf( __( 'Calendar block removed: %1$s, %2$s – %3$s', 'marclie-booking' ), self::unitTitle( (int) ( $p['unit_id'] ?? 0 ) ), (string) ( $p['date_from'] ?? '' ), (string) ( $p['date_to'] ?? '' ) );
			case 'prices_changed':
				$units = count( (array) ( $p['changes'] ?? array() ) );
				/* translators: %d: number of apartments whose prices changed */
				return sprintf( _n( 'Prices changed: %d apartment', 'Prices changed: %d apartments', $units, 'marclie-booking' ), $units );
			case 'guest_merged':
				return __( 'Duplicate guest merged', 'marclie-booking' );
			case 'guest_anonymized':
				return __( 'Guest anonymized', 'marclie-booking' );
			case 'guest_updated':
				return __( 'Guest profile edited', 'marclie-booking' );
			case 'coupon_created':
				/* translators: %s: coupon code */
				return sprintf( __( 'Coupon created: %s', 'marclie-booking' ), (string) ( $p['code'] ?? '' ) );
			case 'coupon_updated':
				/* translators: %s: coupon code */
				return sprintf( __( 'Coupon edited: %s', 'marclie-booking' ), (string) ( $p['code'] ?? '' ) );
			case 'coupon_deactivated':
				/* translators: %s: coupon code */
				return sprintf( __( 'Coupon deactivated: %s', 'marclie-booking' ), (string) ( $p['code'] ?? '' ) );
			case 'coupon_deleted':
				/* translators: %s: coupon code */
				return sprintf( __( 'Coupon deleted: %s', 'marclie-booking' ), (string) ( $p['code'] ?? '' ) );
			case 'settings_changed':
				/* translators: %s: settings section */
				return sprintf( __( 'Settings changed: %s', 'marclie-booking' ), self::settingsSection( (string) ( $p['section'] ?? '' ) ) );
			case 'email_sent':
				/* translators: 1: email template name, 2: recipient address */
				return sprintf( __( 'Email sent: %1$s → %2$s', 'marclie-booking' ), self::emailLabel( (string) ( $p['code'] ?? '' ) ), (string) ( $p['to'] ?? '' ) );
			case 'email_failed':
				/* translators: %s: email template name */
				return sprintf( __( 'Email could not be sent: %s', 'marclie-booking' ), self::emailLabel( (string) ( $p['code'] ?? '' ) ) );
			case 'email_test_sent':
				/* translators: 1: email template name, 2: recipient address */
				return sprintf( __( 'Test email sent: %1$s → %2$s', 'marclie-booking' ), self::emailLabel( (string) ( $p['code'] ?? '' ) ), (string) ( $p['to'] ?? '' ) );
			case 'email_template_changed':
				/* translators: 1: email template name, 2: change (saved, reset to default, switched on, switched off), optionally followed by the language */
				return sprintf( __( 'Email template %1$s: %2$s', 'marclie-booking' ), self::emailLabel( (string) ( $p['code'] ?? '' ) ), trim( self::emailAction( (string) ( $p['action'] ?? '' ) ) . ' ' . ( '' !== (string) ( $p['lang'] ?? '' ) ? '(' . strtoupper( (string) $p['lang'] ) . ')' : '' ) ) );
			case 'account_created':
				return __( 'Guest account created (email confirmed)', 'marclie-booking' );
			case 'account_linked':
				$linked = (int) ( $p['bookings'] ?? 0 );
				/* translators: %d: number of bookings linked to the account */
				return sprintf( _n( 'Guest account linked (%d booking)', 'Guest account linked (%d bookings)', $linked, 'marclie-booking' ), $linked );
			case 'account_deleted':
				return __( 'Guest account deleted by the guest (bookings kept)', 'marclie-booking' );
			case 'account_password_changed':
				return __( 'Guest account password changed', 'marclie-booking' );
			case 'membership_coupon':
				/* translators: %s: coupon code */
				return sprintf( __( 'Member coupon issued: %s', 'marclie-booking' ), (string) ( $p['code'] ?? '' ) );
			case 'cancel_requested':
				return '' !== (string) ( $p['reason'] ?? '' )
					/* translators: %s: the guest's message */
					? sprintf( __( 'Guest asked to cancel: “%s”', 'marclie-booking' ), (string) $p['reason'] )
					: __( 'Guest asked to cancel', 'marclie-booking' );
			case 'card_updated':
				/* translators: %s: card brand and last digits */
				return sprintf( __( 'Card for the balance changed by the guest: %s', 'marclie-booking' ), trim( ( $p['card_brand'] ?? '' ) . ' •••• ' . ( $p['card_last4'] ?? '' ) ) );
			case 'ical_conflict':
				/* translators: 1: channel (Airbnb, Booking.com…), 2: first date, 3: end date */
				return sprintf( __( 'Calendar conflict with %1$s (%2$s → %3$s)', 'marclie-booking' ), FeedRepository::label( (string) ( $p['channel'] ?? '' ) ), (string) ( $p['date_from'] ?? '' ), (string) ( $p['date_to'] ?? '' ) );
			case 'ical_feed_failing':
				/* translators: %s: channel (Airbnb, Booking.com…) */
				return sprintf( __( '%s calendar import failing for more than 2 hours', 'marclie-booking' ), FeedRepository::label( (string) ( $p['channel'] ?? '' ) ) );
			default:
				return ucfirst( str_replace( '_', ' ', $type ) );
		}
	}

	private static function emailLabel( string $code ): string {
		$meta = EmailRegistry::get( $code );

		return null !== $meta ? $meta['label'] : $code;
	}

	/** Settings section key → the tab label of the admin app. */
	private static function settingsSection( string $section ): string {
		return match ( $section ) {
			'policy'        => __( 'Payments & cancellation', 'marclie-booking' ),
			'pages'         => __( 'Pages', 'marclie-booking' ),
			'notifications' => __( 'Notifications', 'marclie-booking' ),
			'advanced'      => __( 'Advanced', 'marclie-booking' ),
			default         => $section,
		};
	}

	private static function emailAction( string $action ): string {
		return match ( $action ) {
			'saved'    => __( 'saved', 'marclie-booking' ),
			'reset'    => __( 'reset to default', 'marclie-booking' ),
			'enabled'  => __( 'switched on', 'marclie-booking' ),
			'disabled' => __( 'switched off', 'marclie-booking' ),
			default    => $action,
		};
	}

	/* Guests ----------------------------------------------------------------- */

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public static function guest( array $row ): array {
		return array(
			'id'                => (int) $row['id'],
			'first_name'        => (string) $row['first_name'],
			'last_name'         => (string) $row['last_name'],
			'email'             => (string) $row['email'],
			'phone'             => self::nullable( $row['phone'] ),
			'country'           => self::nullable( $row['country'] ),
			'lang'              => 'en' === $row['lang'] ? 'en' : 'hr',
			'tags'              => self::tags( (string) $row['tags'] ),
			'notes'             => self::nullable( $row['notes'] ),
			'marketing_consent' => null !== $row['marketing_consent_at'],
			'bookings_count'    => (int) $row['bookings_count'],
			'nights_total'      => (int) $row['nights_total'],
			'revenue_cents'     => (int) $row['revenue_cents'],
			'first_booking_at'  => self::iso( $row['first_booking_at'] ),
			'last_stay_at'      => self::nullable( $row['last_stay_at'] ),
			'anonymized'        => null !== $row['anonymized_at'],
			// Guest account (R2): linked WordPress user and its member tier (null = no account).
			'user_id'           => empty( $row['user_id'] ) ? null : (int) $row['user_id'],
			'tier'              => empty( $row['user_id'] ) ? null : AccountService::tierOf( (int) $row['user_id'] ),
		);
	}

	/**
	 * Guest account of a user for the admin app, or null when the user is gone.
	 *
	 * @return array{id: int, email: string, email_verified_at: ?string, tier: string, tier_since: ?string, member_since: ?string}|null
	 */
	public static function account( ?int $userId ): ?array {
		$user = $userId ? get_userdata( $userId ) : false;
		if ( ! $user instanceof \WP_User ) {
			return null;
		}
		// Verified = the confirmed address is still the user's email.
		$verified = AccountService::isVerified( $user ) ? (string) get_user_meta( $user->ID, AccountService::META_VERIFIED, true ) : '';
		$since    = (string) get_user_meta( $user->ID, AccountService::META_TIER_SINCE, true );

		return array(
			'id'                => $user->ID,
			'email'             => (string) $user->user_email,
			'email_verified_at' => '' !== $verified ? self::iso( $verified ) : null,
			'tier'              => AccountService::tierOf( $user->ID ),
			'tier_since'        => '' !== $since ? $since : null,
			'member_since'      => self::iso( (string) $user->user_registered ),
		);
	}

	/** @return list<string> */
	public static function tags( string $csv ): array {
		return array_values( array_filter( array_map( 'trim', explode( ',', $csv ) ), static fn( string $t ): bool => '' !== $t ) );
	}

	/* Coupons ---------------------------------------------------------------- */

	/**
	 * @param array<string, mixed> $row Raw mbk_coupons row.
	 * @return array<string, mixed>
	 */
	public static function coupon( array $row ): array {
		$coupon = CouponRepository::toCoupon( $row );

		return array(
			'id'              => $coupon->id,
			'code'            => $coupon->code,
			'type'            => Coupon::FIXED === $coupon->type ? Coupon::FIXED : Coupon::PCT,
			'amount'          => $coupon->amount,
			'min_nights'      => $coupon->minNights > 0 ? $coupon->minNights : null,
			'book_from'       => $coupon->bookFrom,
			'book_to'         => $coupon->bookTo,
			'stay_from'       => $coupon->stayFrom,
			'stay_to'         => $coupon->stayTo,
			'unit_ids'        => $coupon->unitIds,
			'usage_limit'     => $coupon->usageLimit,
			'per_guest_limit' => $coupon->perGuestLimit,
			'used_count'      => $coupon->usedCount,
			'guest_id'        => $coupon->guestId,
			'active'          => $coupon->active,
			'created_at'      => self::iso( (string) $row['created_at'] ),
		);
	}
}
