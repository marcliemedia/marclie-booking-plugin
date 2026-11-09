<?php
/**
 * Server-side helper of the Playwright E2E suite (test data only).
 *
 * Run from `wordpress-local`:
 *   studio wp eval-file wp-content/plugins/marclie-booking/tests/e2e/helpers/wp-helper.php <action> [args…]
 *
 * Actions (each prints one JSON line):
 *   reset-limits                     clear the public REST rate-limit transients
 *   bookings <email>                 bookings of a guest email (newest first)
 *   expire-hold <email>              move pending holds of the email into the past, run the HoldReaper
 *   seed-paid <email> <from> <to>    POST /bookings on a free unit + confirm with pm_card_visa → confirmed
 *   template <code> <lang>           subject of an email template + whether it is customized (saved row)
 *   cleanup                          delete every `@mbk-e2e-pw.test` booking, guest, WP user and Stripe
 *                                    customer, `PWE2E…` coupons and `[PW-E2E]` email template edits
 *
 * Only touches data of the E2E domain / prefixes below.
 */

use Marclie\Booking\Container;
use Marclie\Booking\Domain\Stay;
use Marclie\Booking\Payment\StripeConfig;
use Marclie\Booking\Repository\Db;

defined( 'ABSPATH' ) || exit( 1 );

const MBK_PW_DOMAIN        = '@mbk-e2e-pw.test';
const MBK_PW_COUPON_PREFIX = 'PWE2E';
const MBK_PW_MARKER        = '[PW-E2E]';

global $wpdb;

$mbkPwArgs   = array_values( (array) ( $args ?? array() ) );
$mbkPwAction = (string) ( $mbkPwArgs[0] ?? '' );
$mbkPwT      = static fn( string $name ): string => Db::table( $name );

$mbkPwOut = static function ( array $data ): void {
	echo wp_json_encode( $data ), "\n";
};

$mbkPwEmail = static function ( string $email ): string {
	$email = strtolower( trim( $email ) );
	if ( ! str_ends_with( $email, MBK_PW_DOMAIN ) ) {
		fwrite( STDERR, "Refusing a non-E2E email: {$email}\n" );
		exit( 2 );
	}

	return $email;
};

$mbkPwResetLimits = static function () use ( $wpdb ): int {
	$n = (int) $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_mbk\\_rl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_mbk\\_rl\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	wp_cache_flush();

	return $n;
};

$mbkPwBookings = static function ( string $email ) use ( $wpdb, $mbkPwT ): array {
	$rows = (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT b.id, b.ref, b.unit_id, b.status, b.arrival, b.departure, b.total_cents, b.deposit_cents, b.discount_cents, b.coupon_code, b.hold_expires_at, b.source
			FROM {$mbkPwT( 'mbk_bookings' )} b
			WHERE b.email = %s ORDER BY b.id DESC",
			$email
		),
		ARRAY_A
	); // phpcs:ignore WordPress.DB.PreparedSQL
	foreach ( $rows as &$row ) {
		$row['payments'] = array_map(
			static fn( array $p ): array => array(
				'kind'   => $p['kind'],
				'status' => $p['status'],
				'amount' => (int) $p['amount_cents'],
				'refund' => (string) ( $p['stripe_refund_id'] ?? '' ),
			),
			Container::paymentRepository()->forBooking( (int) $row['id'] )
		);
	}

	return $rows;
};

$mbkPwStripe = static function (): ?\Stripe\StripeClient {
	if ( ! StripeConfig::configured() || 'test' !== StripeConfig::mode() ) {
		return null;
	}

	return new \Stripe\StripeClient( array( 'api_key' => MBK_STRIPE_SECRET_KEY ) );
};

switch ( $mbkPwAction ) {
	case 'reset-limits':
		$mbkPwOut( array( 'deleted' => $mbkPwResetLimits() ) );
		break;

	case 'bookings':
		$mbkPwOut( array( 'bookings' => $mbkPwBookings( $mbkPwEmail( (string) ( $mbkPwArgs[1] ?? '' ) ) ) ) );
		break;

	case 'expire-hold':
		$email = $mbkPwEmail( (string) ( $mbkPwArgs[1] ?? '' ) );
		foreach ( $mbkPwBookings( $email ) as $row ) {
			if ( 'pending_payment' === $row['status'] ) {
				$wpdb->update( $mbkPwT( 'mbk_bookings' ), array( 'hold_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
		$expired = Container::holdReaper()->run();
		$mbkPwOut( array( 'expired' => $expired, 'bookings' => $mbkPwBookings( $email ) ) );
		break;

	case 'seed-paid':
		$email  = $mbkPwEmail( (string) ( $mbkPwArgs[1] ?? '' ) );
		$from   = (string) ( $mbkPwArgs[2] ?? '' );
		$to     = (string) ( $mbkPwArgs[3] ?? '' );
		$stripe = $mbkPwStripe();
		if ( null === $stripe ) {
			fwrite( STDERR, "Stripe test keys are not configured.\n" );
			exit( 3 );
		}
		$unitId = null;
		foreach ( Container::units()->all() as $unit ) {
			if ( Container::availability()->isAvailable( Stay::fromStrings( $unit->id, $from, $to, wp_timezone() ) ) ) {
				$unitId = $unit->id;
				break;
			}
		}
		if ( null === $unitId ) {
			fwrite( STDERR, "No free unit {$from} → {$to}.\n" );
			exit( 4 );
		}
		$mbkPwResetLimits();
		$req = new WP_REST_Request( 'POST', '/marclie-booking/v1/bookings' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body(
			(string) wp_json_encode(
				array(
					'unit_id'   => $unitId,
					'arrival'   => $from,
					'departure' => $to,
					'adults'    => 2,
					'children'  => 0,
					'guest'     => array(
						'first_name' => 'Pia',
						'last_name'  => 'Playwright',
						'email'      => $email,
						'phone'      => '+385 91 555 0101',
						'country'    => 'HR',
					),
					'terms'     => true,
					'website'   => '',
				)
			)
		);
		$res = rest_do_request( $req );
		if ( 201 !== $res->get_status() ) {
			fwrite( STDERR, 'POST /bookings failed: ' . wp_json_encode( $res->get_data() ) . "\n" );
			exit( 5 );
		}
		$data    = $res->get_data();
		$booking = Container::bookings()->findByRef( (string) $data['ref'] );
		$intent  = (string) Container::paymentRepository()->forBooking( (int) $booking['id'] )[0]['stripe_intent_id'];
		$stripe->paymentIntents->confirm( $intent, array( 'payment_method' => 'pm_card_visa', 'return_url' => (string) $data['return_url'] ) );
		Container::reconciler()->reconcile( Container::bookings()->find( (int) $booking['id'] ) );
		$booking = Container::bookings()->find( (int) $booking['id'] );
		$mbkPwOut(
			array(
				'id'      => (int) $booking['id'],
				'ref'     => (string) $booking['ref'],
				'unit_id' => (int) $booking['unit_id'],
				'status'  => (string) $booking['status'],
			)
		);
		break;

	case 'template':
		$code = (string) ( $mbkPwArgs[1] ?? '' );
		$lang = (string) ( $mbkPwArgs[2] ?? 'en' );
		$tpl  = Container::emailTemplates()->get( $code, $lang );
		$mbkPwOut(
			array(
				'subject'    => (string) ( $tpl['subject'] ?? '' ),
				'customized' => null !== Container::emailTemplateRepository()->find( $code, $lang ),
			)
		);
		break;

	case 'cleanup':
		$like     = '%' . $wpdb->esc_like( MBK_PW_DOMAIN );
		$guestIds = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$mbkPwT( 'mbk_guests' )} WHERE email LIKE %s", $like ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$ids      = $guestIds ? array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$mbkPwT( 'mbk_bookings' )} WHERE guest_id IN (" . Db::placeholders( $guestIds ) . ')', $guestIds ) ) ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL
		$ids      = array_values( array_unique( array_merge( $ids, array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$mbkPwT( 'mbk_bookings' )} WHERE email LIKE %s", $like ) ) ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		$customers = array();
		if ( $guestIds ) {
			$customers = array_merge( $customers, (array) $wpdb->get_col( $wpdb->prepare( "SELECT stripe_customer_id FROM {$mbkPwT( 'mbk_guests' )} WHERE id IN (" . Db::placeholders( $guestIds ) . ')', $guestIds ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		if ( $ids ) {
			$customers = array_merge( $customers, (array) $wpdb->get_col( $wpdb->prepare( "SELECT stripe_customer_id FROM {$mbkPwT( 'mbk_bookings' )} WHERE id IN (" . Db::placeholders( $ids ) . ')', $ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		if ( $ids ) {
			$in = Db::placeholders( $ids );
			foreach ( array( 'mbk_nights', 'mbk_coupon_redemptions', 'mbk_payments', 'mbk_events', 'mbk_booking_tokens' ) as $table ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$mbkPwT( $table )} WHERE booking_id IN ({$in})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$mbkPwT( 'mbk_bookings' )} WHERE id IN ({$in})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		if ( $guestIds ) {
			$in = Db::placeholders( $guestIds );
			foreach ( array( 'mbk_events', 'mbk_coupon_redemptions' ) as $table ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$mbkPwT( $table )} WHERE guest_id IN ({$in})", $guestIds ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$mbkPwT( 'mbk_guests' )} WHERE id IN ({$in})", $guestIds ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		// Coupons created by the admin coupon test (and their redemptions).
		$couponIds = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$mbkPwT( 'mbk_coupons' )} WHERE code LIKE %s", $wpdb->esc_like( MBK_PW_COUPON_PREFIX ) . '%' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $couponIds ) {
			$in = Db::placeholders( $couponIds );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$mbkPwT( 'mbk_coupon_redemptions' )} WHERE coupon_id IN ({$in})", $couponIds ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$mbkPwT( 'mbk_coupons' )} WHERE id IN ({$in})", $couponIds ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		// Email template edits left behind by an interrupted template test.
		$templates = 0;
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT code, lang FROM {$mbkPwT( 'mbk_email_templates' )} WHERE subject LIKE %s", '%' . $wpdb->esc_like( MBK_PW_MARKER ) . '%' ), ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$templates += Container::emailTemplates()->reset( (string) $row['code'], (string) $row['lang'] ) ? 1 : 0;
		}

		// WordPress users of the E2E domain (guest accounts).
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$users = 0;
		foreach ( get_users( array( 'search' => '*' . MBK_PW_DOMAIN, 'search_columns' => array( 'user_email' ), 'fields' => 'ID' ) ) as $userId ) {
			$users += wp_delete_user( (int) $userId ) ? 1 : 0;
		}

		// Stripe test customers: the stored ids plus any orphan found by email.
		$stripeDeleted = 0;
		$stripeErrors  = array();
		$stripe        = $mbkPwStripe();
		if ( null !== $stripe ) {
			try {
				foreach ( $stripe->customers->search( array( 'query' => 'email~"mbk-e2e-pw.test"', 'limit' => 100 ) )->autoPagingIterator() as $customer ) {
					$customers[] = $customer->id;
				}
			} catch ( \Throwable $e ) {
				$stripeErrors[] = 'search: ' . $e->getMessage();
			}
			foreach ( array_unique( array_filter( array_map( 'strval', $customers ) ) ) as $customer ) {
				try {
					$stripe->customers->delete( $customer );
					++$stripeDeleted;
				} catch ( \Stripe\Exception\InvalidRequestException $e ) {
					if ( 'resource_missing' !== $e->getStripeCode() ) {
						$stripeErrors[] = "{$customer}: " . $e->getMessage();
					}
				} catch ( \Throwable $e ) {
					$stripeErrors[] = "{$customer}: " . $e->getMessage();
				}
			}
		}

		$mbkPwResetLimits();
		\Marclie\Booking\Service\AvailabilityCache::invalidate( 0 );
		$mbkPwOut(
			array(
				'bookings'         => count( $ids ),
				'guests'           => count( $guestIds ),
				'coupons'          => count( $couponIds ),
				'templates'        => $templates,
				'users'            => $users,
				'stripe_customers' => $stripeDeleted,
				'stripe_errors'    => $stripeErrors,
			)
		);
		break;

	default:
		fwrite( STDERR, "Unknown action '{$mbkPwAction}'.\n" );
		exit( 1 );
}
