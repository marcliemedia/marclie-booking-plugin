<?php
/**
 * End-to-end payment checks against Stripe TEST mode (real API calls).
 *
 * Prerequisites
 * 1. wp-config.php defines MBK_STRIPE_PUBLISHABLE_KEY (pk_test_…),
 *    MBK_STRIPE_SECRET_KEY (sk_test_… / rk_test_…) and MBK_STRIPE_WEBHOOK_SECRET
 *    (whsec_…; for a local run the secret printed by `stripe listen` works).
 *    `studio wp mbk doctor` shows Stripe OK. Live keys are refused.
 * 2. Seed data (`studio wp mbk seed`); April 2028 must be free on some unit.
 * 3. Optional — real webhooks as well (the script also signs and posts its own
 *    events, duplicates are deduped):
 *        .tools/stripe/stripe.exe listen --api-key sk_test_… \
 *          --forward-to https://localhost/wp-json/marclie-booking/v1/stripe/webhook --skip-verify
 *
 * Run
 *     studio wp eval-file wp-content/plugins/marclie-booking/tests/integration/stripe-e2e.php
 *
 * What it does (guests @mbk-e2e.test, removed at the end with their Stripe customers):
 * - POST /bookings → PaymentIntent; confirms it server-side with pm_card_visa,
 *   posts a signed payment_intent.succeeded → confirmed, card saved; GET → succeeded;
 * - a deposit with pm_card_authenticationRequired → GET reports requires_action;
 * - balance due today → BalanceCharger charges off-session → paid;
 * - saved card swapped for pm_card_authenticationRequired / pm_card_chargeDeclined →
 *   off-session charge fails → balance_failed; `charge-balances --dry-run` listing;
 * - admin cancel → Stripe refund; signed charge.refunded → refund succeeded, net 0;
 * - hold reaper cancels an unpaid intent at Stripe.
 * Exits 1 on any failure.
 */

use Marclie\Booking\Container;
use Marclie\Booking\Payment\BalanceCharger;
use Marclie\Booking\Payment\StripeConfig;
use Marclie\Booking\Payment\StripePaymentGateway;
use Marclie\Booking\Payment\WebhookController;
use Marclie\Booking\Repository\Db;

defined( 'ABSPATH' ) || exit( 1 );

global $wpdb;

if ( ! StripeConfig::configured() || 'test' !== StripeConfig::mode() ) {
	fwrite( STDERR, "Stripe TEST keys are not configured (MBK_STRIPE_* in wp-config.php); nothing to do.\n" );
	exit( 1 );
}

$e2eResults = array();
$e2eCheck   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$e2eResults ): void {
	$e2eResults[] = $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $name, '' !== $detail ? ' — ' . $detail : '' );
};

$stripe   = new \Stripe\StripeClient(
	array(
		'api_key'        => StripeConfig::secretKey(),
		'stripe_version' => StripePaymentGateway::API_VERSION,
	)
);
$domain   = '@mbk-e2e.test';
$t        = static fn( string $name ): string => Db::table( $name );
$tz       = wp_timezone();
$created  = array();
$track    = static function ( array $booking ) use ( &$created ): void {
	$created[] = (int) $booking['id'];
};
add_action( 'mbk_booking_created', $track );

$resetLimits = static function () use ( $wpdb ): void {
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_mbk\\_rl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_mbk\\_rl\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	wp_cache_flush();
};
$api = static function ( string $method, string $route, ?array $body = null, array $query = array() ): WP_REST_Response {
	$req = new WP_REST_Request( $method, '/marclie-booking/v1' . $route );
	$req->set_query_params( $query );
	if ( null !== $body ) {
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( (string) wp_json_encode( $body ) );
	}

	return rest_do_request( $req );
};
$webhook = static function ( string $type, array $object ): WP_REST_Response {
	$payload = (string) wp_json_encode(
		array(
			'id'       => 'evt_mbke2e_' . wp_generate_password( 12, false ),
			'object'   => 'event',
			'type'     => $type,
			'livemode' => false,
			'data'     => array( 'object' => $object ),
		)
	);
	$time    = time();
	$req     = new WP_REST_Request( 'POST', '/marclie-booking/v1/stripe/webhook' );
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_header( 'Stripe-Signature', 't=' . $time . ',v1=' . hash_hmac( 'sha256', $time . '.' . $payload, WebhookController::secret() ) );
	$req->set_body( $payload );

	return rest_do_request( $req );
};
$freeUnit = static function ( string $from, string $to ) use ( $tz ): ?int {
	foreach ( Container::units()->all() as $unit ) {
		if ( Container::availability()->isAvailable( \Marclie\Booking\Domain\Stay::fromStrings( $unit->id, $from, $to, $tz ) ) ) {
			return $unit->id;
		}
	}

	return null;
};
$checkout = static function ( string $from, string $to, string $email ) use ( $api, $resetLimits, $freeUnit, $domain ): array {
	$unitId = $freeUnit( $from, $to );
	if ( null === $unitId ) {
		throw new RuntimeException( "No free unit {$from} → {$to}." );
	}
	$resetLimits();
	$res = $api(
		'POST',
		'/bookings',
		array(
			'unit_id'   => $unitId,
			'arrival'   => $from,
			'departure' => $to,
			'adults'    => 2,
			'children'  => 0,
			'guest'     => array(
				'first_name' => 'Eva',
				'last_name'  => 'E2E',
				'email'      => $email . $domain,
				'phone'      => '+385 91 333 4444',
				'country'    => 'HR',
			),
			'terms'     => true,
			'website'   => '',
		)
	);
	if ( 201 !== $res->get_status() ) {
		throw new RuntimeException( 'POST /bookings failed: ' . wp_json_encode( $res->get_data() ) );
	}
	$data    = $res->get_data();
	$booking = Container::bookings()->findByRef( $data['ref'] );
	$intent  = (string) Container::paymentRepository()->forBooking( (int) $booking['id'] )[0]['stripe_intent_id'];

	return array( $data, $booking, $intent );
};
$intentArray = static fn( string $id ): array => $stripe->paymentIntents->retrieve( $id )->toArray();

Container::reset();

try {
	// 1. Checkout + server-side confirmation with a Visa test card + signed webhook.
	[ $a, $booking, $piA ] = $checkout( '2028-04-03', '2028-04-07', 'e2e.a' );
	$e2eCheck( 'POST /bookings → pending_payment, real PaymentIntent', 'pending_payment' === $a['status'] && str_starts_with( $piA, 'pi_' ) && str_starts_with( (string) $a['client_secret'], $piA . '_secret_' ) );

	$stripe->paymentIntents->confirm( $piA, array( 'payment_method' => 'pm_card_visa', 'return_url' => (string) $a['return_url'] ) );
	$res     = $webhook( 'payment_intent.succeeded', $intentArray( $piA ) );
	$booking = Container::bookings()->find( (int) $booking['id'] );
	$row     = Container::paymentRepository()->findByIntent( $piA );
	$e2eCheck( 'signed payment_intent.succeeded → confirmed, card 4242 saved', 200 === $res->get_status() && 'confirmed' === $booking['status'] && '4242' === $row['card_last4'] && str_starts_with( (string) $booking['stripe_payment_method_id'], 'pm_' ), wp_json_encode( $res->get_data() ) );
	$got = $api( 'GET', '/bookings/' . $a['ref'], null, array( 't' => $a['token'] ) )->get_data();
	$e2eCheck( 'GET /bookings → succeeded, deposit paid', 'succeeded' === $got['payment_state'] && (int) $booking['deposit_cents'] === $got['totals']['paid']['cents'] );

	// 2. 3-D Secure card: the intent waits for the guest.
	[ $b, $bookingB, $piB ] = $checkout( '2028-04-10', '2028-04-14', 'e2e.b' );
	$stripe->paymentIntents->confirm( $piB, array( 'payment_method' => 'pm_card_authenticationRequired', 'return_url' => (string) $b['return_url'] ) );
	$got = $api( 'GET', '/bookings/' . $b['ref'], null, array( 't' => $b['token'] ) )->get_data();
	$e2eCheck( '3-D Secure card → GET reports requires_action, booking pending', 'requires_action' === $got['payment_state'] && 'pending_payment' === $got['status'] );

	// 3. Hold reaper cancels the unpaid intent at Stripe and expires the hold.
	$wpdb->update( $t( 'mbk_bookings' ), array( 'hold_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $bookingB['id'] ) );
	Container::holdReaper()->run();
	$e2eCheck( 'HoldReaper → intent canceled at Stripe, booking expired', 'canceled' === $intentArray( $piB )['status'] && 'expired' === Container::bookings()->find( (int) $bookingB['id'] )['status'] );

	// 4. Balance due today → off-session charge with the saved card.
	$wpdb->update( $t( 'mbk_bookings' ), array( 'balance_due_on' => Container::clock()->today()->format( 'Y-m-d' ) ), array( 'id' => $booking['id'] ) );
	$dry = array_values( array_filter( Container::balanceCharger()->run( null, true ), static fn( array $r ): bool => (int) $r['booking_id'] === (int) $booking['id'] ) );
	$e2eCheck( 'charge-balances --dry-run lists the booking', 1 === count( $dry ) && 'dry_run' === $dry[0]['result'] );
	$pi      = Container::balanceCharger()->charge( (int) $booking['id'], BalanceCharger::ACTOR, false );
	$booking = Container::bookings()->find( (int) $booking['id'] );
	$e2eCheck( 'off-session balance with the saved card → paid', $pi->succeeded() && 'paid' === $booking['status'] && Container::paymentRepository()->netPaidCents( (int) $booking['id'] ) === (int) $booking['total_cents'], $pi->status );

	// 5. Off-session failures: authentication required, declined.
	[ $c, $bookingC, $piC ] = $checkout( '2028-04-17', '2028-04-21', 'e2e.c' );
	$stripe->paymentIntents->confirm( $piC, array( 'payment_method' => 'pm_card_visa', 'return_url' => (string) $c['return_url'] ) );
	Container::reconciler()->reconcile( Container::bookings()->find( (int) $bookingC['id'] ) );
	$bookingC = Container::bookings()->find( (int) $bookingC['id'] );
	$sca      = $stripe->paymentMethods->attach( 'pm_card_authenticationRequired', array( 'customer' => $bookingC['stripe_customer_id'] ) );
	$wpdb->update( $t( 'mbk_bookings' ), array( 'stripe_payment_method_id' => $sca->id, 'balance_due_on' => Container::clock()->today()->format( 'Y-m-d' ) ), array( 'id' => $bookingC['id'] ) );
	$pi = Container::balanceCharger()->charge( (int) $bookingC['id'], BalanceCharger::ACTOR, false );
	$e2eCheck( 'off-session with an SCA card → authentication_required, balance_failed', $pi->failed() && $pi->needsAction() && 'balance_failed' === Container::bookings()->find( (int) $bookingC['id'] )['status'], (string) $pi->errorCode );

	// 4000 0000 0000 0341: attaches to a customer, but every charge is declined (pm_card_chargeDeclined is refused at attach).
	$declined = $stripe->paymentMethods->attach( 'pm_card_chargeCustomerFail', array( 'customer' => $bookingC['stripe_customer_id'] ) );
	$wpdb->update( $t( 'mbk_bookings' ), array( 'stripe_payment_method_id' => $declined->id ), array( 'id' => $bookingC['id'] ) );
	$pi = Container::balanceCharger()->charge( (int) $bookingC['id'], 'test', true );
	$e2eCheck( 'manual off-session with a declining card → declined, still balance_failed', $pi->failed() && ! $pi->needsAction() && 'balance_failed' === Container::bookings()->find( (int) $bookingC['id'] )['status'], (string) $pi->errorCode );

	// 6. Admin cancel → Stripe refund; charge.refunded completes it.
	$admin = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 1 );
	wp_set_current_user( $admin );
	$req = new WP_REST_Request( 'POST', '/marclie-booking/v1/admin/bookings/' . $bookingC['id'] . '/cancel' );
	$req->set_body_params( array( 'refund_mode' => 'policy' ) );
	$res     = rest_do_request( $req );
	$refunds = array_values( array_filter( Container::paymentRepository()->forBooking( (int) $bookingC['id'] ), static fn( array $p ): bool => 'refund' === $p['kind'] ) );
	$e2eCheck( 'admin cancel → refund created at Stripe', 200 === $res->get_status() && 1 === count( $refunds ) && str_starts_with( (string) $refunds[0]['stripe_refund_id'], 're_' ), wp_json_encode( $res->get_data()['decision'] ?? $res->get_data() ) );
	$charge = $stripe->charges->retrieve( (string) $intentArray( $piC )['latest_charge'], array( 'expand' => array( 'refunds' ) ) )->toArray();
	$webhook( 'charge.refunded', $charge );
	$refunds = array_values( array_filter( Container::paymentRepository()->forBooking( (int) $bookingC['id'] ), static fn( array $p ): bool => 'refund' === $p['kind'] ) );
	$e2eCheck( 'charge.refunded → refund succeeded, net 0', 'succeeded' === $refunds[0]['status'] && 0 === Container::paymentRepository()->netPaidCents( (int) $bookingC['id'] ) );
	wp_set_current_user( 0 );
} catch ( \Throwable $e ) {
	$e2eCheck( 'unexpected exception', false, get_class( $e ) . ': ' . $e->getMessage() );
} finally {
	remove_action( 'mbk_booking_created', $track );
	$guestIds = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t( 'mbk_guests' )} WHERE email LIKE %s", '%' . $wpdb->esc_like( $domain ) ) ) );
	$ids      = array_values( array_unique( array_merge( $created, $guestIds ? array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t( 'mbk_bookings' )} WHERE guest_id IN (" . Db::placeholders( $guestIds ) . ')', $guestIds ) ) ) : array() ) ) );
	$customers = $guestIds ? array_filter( (array) $wpdb->get_col( $wpdb->prepare( "SELECT stripe_customer_id FROM {$t( 'mbk_guests' )} WHERE id IN (" . Db::placeholders( $guestIds ) . ')', $guestIds ) ) ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL
	if ( $ids ) {
		$in = Db::placeholders( $ids );
		foreach ( array( 'mbk_nights', 'mbk_coupon_redemptions', 'mbk_payments', 'mbk_events', 'mbk_booking_tokens' ) as $table ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t( $table )} WHERE booking_id IN ({$in})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t( 'mbk_bookings' )} WHERE id IN ({$in})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
	if ( $guestIds ) {
		$in = Db::placeholders( $guestIds );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t( 'mbk_events' )} WHERE guest_id IN ({$in})", $guestIds ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t( 'mbk_guests' )} WHERE id IN ({$in})", $guestIds ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$t( 'mbk_events' )} WHERE external_id LIKE %s", 'evt\\_mbke2e\\_%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	foreach ( $customers as $customer ) {
		try {
			$stripe->customers->delete( (string) $customer );
		} catch ( \Throwable $e ) {
			printf( "note: could not delete Stripe customer %s: %s\n", $customer, $e->getMessage() );
		}
	}
	$resetLimits();
	\Marclie\Booking\Service\AvailabilityCache::invalidate( 0 );
	printf( "cleanup: %d booking(s), %d guest(s), %d Stripe customer(s) removed\n", count( $ids ), count( $guestIds ), count( $customers ) );
}

$e2eFailed = count( array_filter( $e2eResults, static fn( bool $ok ): bool => ! $ok ) );
printf( "%d passed, %d failed\n", count( $e2eResults ) - $e2eFailed, $e2eFailed );
if ( $e2eFailed > 0 ) {
	exit( 1 );
}
