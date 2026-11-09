<?php
/**
 * Integration checks of the payment flow with a fake Stripe gateway (no keys
 * needed): POST /bookings, signed webhooks, reconcile, late payments, admin
 * cancel with refunds, balances, payment links and dashboard alerts.
 *
 * Included by run.php inside its try block (uses $mbkCheck, $mbkMailsOf, $units, $tz,
 * $domain, $t, $adminId, $free; bookings/guests are cleaned up there). Uses
 * February–March 2028 stays (run.php's test price table). Webhook events are signed with a
 * test secret through the `mbk_stripe_webhook_secret` filter.
 */

use Marclie\Booking\Container;
use Marclie\Booking\Domain\BookingStatus;
use Marclie\Booking\Domain\Stay;
use Marclie\Booking\Payment\BalanceCharger;
use Marclie\Booking\Payment\StripeStatus;
use Marclie\Booking\Repository\Db;
use Marclie\Booking\Tests\Fakes\FakePaymentGateway;

defined( 'ABSPATH' ) || exit( 1 );

require_once dirname( __DIR__ ) . '/phpunit/Fakes/FakePaymentGateway.php';

$payNs         = '/marclie-booking/v1';
$secret        = 'whsec_mbk_integration_test';
$stripeStatus  = get_option( StripeStatus::OPTION, null );
$fake          = new FakePaymentGateway();
$useFake       = static fn() => $fake;
$noGateway     = static fn() => null;
$secretFilter  = static fn(): string => $secret;
$eventSeq      = 0;
$hookLog       = array();
$recordHook    = static function ( string $hook ) use ( &$hookLog ): callable {
	return static function ( ...$args ) use ( $hook, &$hookLog ): void {
		$hookLog[] = $hook;
	};
};
$hookCallbacks = array();
foreach ( array( 'mbk_payment_succeeded', 'mbk_payment_failed', 'mbk_hold_lost', 'mbk_refund_created', 'mbk_payment_link_created', 'mbk_dispute_created' ) as $hook ) {
	$hookCallbacks[ $hook ] = $recordHook( $hook );
	add_action( $hook, $hookCallbacks[ $hook ], 10, 3 );
}
$hookCount = static function ( string $hook ) use ( &$hookLog ): int {
	return count( array_keys( $hookLog, $hook, true ) );
};

$api = static function ( string $method, string $route, ?array $body = null, array $query = array(), array $headers = array() ) use ( $payNs ): WP_REST_Response {
	$req = new WP_REST_Request( $method, $payNs . $route );
	if ( $query ) {
		$req->set_query_params( $query );
	}
	if ( null !== $body ) {
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( (string) wp_json_encode( $body ) );
	}
	foreach ( $headers as $name => $value ) {
		$req->set_header( $name, $value );
	}

	return rest_do_request( $req );
};
// The public booking limit (5/hour/client) would stop the script: reset between calls.
$resetLimits = static function () use ( $wpdb ): void {
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_mbk\\_rl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_mbk\\_rl\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	wp_cache_flush();
};
$webhook = static function ( string $type, array $object, ?string $signature = null, ?string $id = null ) use ( $api, $secret, &$eventSeq ): WP_REST_Response {
	$event   = array(
		'id'       => $id ?? 'evt_mbkit_' . ( ++$eventSeq ) . '_' . wp_generate_password( 6, false ),
		'object'   => 'event',
		'type'     => $type,
		'livemode' => false,
		'data'     => array( 'object' => $object ),
	);
	$payload = (string) wp_json_encode( $event );
	$time    = time();
	$req     = new WP_REST_Request( 'POST', '/marclie-booking/v1/stripe/webhook' );
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_header( 'Stripe-Signature', $signature ?? 't=' . $time . ',v1=' . hash_hmac( 'sha256', $time . '.' . $payload, $secret ) );
	$req->set_body( $payload );
	$res = rest_do_request( $req );
	$res->header( 'X-Test-Event-Id', $event['id'] );

	return $res;
};
$swapGateway = static function ( callable $filter ) use ( $useFake, $noGateway ): void {
	remove_filter( 'mbk_payment_gateway', $useFake, 99 );
	remove_filter( 'mbk_payment_gateway', $noGateway, 99 );
	add_filter( 'mbk_payment_gateway', $filter, 99 );
	Container::reset();
};
$checkoutBody = static function ( int $unitId, string $from, string $to, string $email, ?string $coupon = null ) use ( $domain ): array {
	return array(
		'unit_id'      => $unitId,
		'arrival'      => $from,
		'departure'    => $to,
		'adults'       => 2,
		'children'     => 0,
		'coupon'       => $coupon,
		'guest'        => array(
			'first_name' => 'Pia',
			'last_name'  => 'Payment',
			'email'      => $email . $domain,
			'phone'      => '+385 91 111 2222',
			'country'    => 'HR',
		),
		'arrival_time' => '',
		'message'      => 'Integration',
		'terms'        => true,
		'website'      => '',
	);
};
$paymentsOf = static fn( int $bookingId ): array => Container::paymentRepository()->forBooking( $bookingId );
$refundsOf  = static fn( int $bookingId ): array => array_values( array_filter( Container::paymentRepository()->forBooking( $bookingId ), static fn( array $p ): bool => 'refund' === $p['kind'] ) );

add_filter( 'mbk_stripe_webhook_secret', $secretFilter );
wp_set_current_user( 0 );

try {
	$unitP = $free( '2028-02-07', '2028-02-11' );
	$unitQ = $free( '2028-02-14', '2028-02-18' );
	if ( null === $unitP || null === $unitQ ) {
		throw new RuntimeException( 'No free unit in February 2028 for the payment checks.' );
	}

	// 26. Checkout without a payment gateway → 503, nothing held.
	$swapGateway( $noGateway );
	$resetLimits();
	$res = $api( 'POST', '/bookings', $checkoutBody( $unitP, '2028-02-07', '2028-02-11', 'pay.a' ) );
	$mbkCheck( 'POST /bookings without Stripe → 503 payments_unavailable, nights free', 503 === $res->get_status() && 'payments_unavailable' === ( $res->get_data()['code'] ?? '' ) && array() === Container::availability()->occupiedNights( $unitP, '2028-02-07', '2028-02-11' ), wp_json_encode( $res->get_data() ) );

	$swapGateway( $useFake );
	$body            = $checkoutBody( $unitP, '2028-02-07', '2028-02-11', 'pay.a' );
	$body['website'] = 'http://spam.example';
	$res             = $api( 'POST', '/bookings', $body );
	$mbkCheck( 'POST /bookings honeypot → 400 invalid_request', 400 === $res->get_status() && 'invalid_request' === ( $res->get_data()['code'] ?? '' ) );

	$body                     = $checkoutBody( $unitP, '2028-02-07', '2028-02-11', 'pay.a' );
	$body['guest']['email']   = 'not-an-email';
	$body['guest']['country'] = 'XX';
	$body['terms']            = false;
	$body['arrival_time']     = '03:00';
	$res                      = $api( 'POST', '/bookings', $body, array(), array( 'X-MBK-Lang' => 'en' ) );
	$fields                   = (array) ( $res->get_data()['data']['fields'] ?? array() );
	$mbkCheck( 'POST /bookings invalid guest → 422 validation with fields', 422 === $res->get_status() && 'validation' === ( $res->get_data()['code'] ?? '' ) && isset( $fields['guest.email'], $fields['guest.country'], $fields['terms'], $fields['arrival_time'] ), wp_json_encode( $fields ) );

	// The checkout has no country field: a guest without one books (the country stays empty).
	$noCountryUnit = $free( '2028-04-03', '2028-04-07' );
	$resetLimits();
	$body = $checkoutBody( $noCountryUnit, '2028-04-03', '2028-04-07', 'pay.nc' );
	unset( $body['guest']['country'] );
	$res = $api( 'POST', '/bookings', $body );
	$nc  = Container::bookings()->findByRef( (string) ( $res->get_data()['ref'] ?? '' ) );
	$mbkCheck( 'POST /bookings without a country → 201, country empty', 201 === $res->get_status() && null !== $nc && '' === (string) $nc['country'], wp_json_encode( $res->get_data() ) );

	// 27. Checkout with the fake gateway: pending, nights held, intent per contract.
	$resetLimits();
	$res  = $api( 'POST', '/bookings', $checkoutBody( $unitP, '2028-02-07', '2028-02-11', 'pay.a', 'DIRECT5' ) );
	$data = $res->get_data();
	$p1   = Container::bookings()->findByRef( (string) ( $data['ref'] ?? '' ) );
	$pi1  = null === $p1 ? null : $fake->intentByKey( 'mbk-deposit-' . $p1['id'] );
	$mbkCheck(
		'POST /bookings → 201 pending_payment, 4 nights held, client secret, return URL with ref+t',
		201 === $res->get_status() && 'pending_payment' === ( $data['status'] ?? '' ) && null !== $p1 && 4 === count( Container::availability()->occupiedNights( $unitP, '2028-02-07', '2028-02-11' ) )
			&& str_ends_with( (string) $data['client_secret'], '_secret_fake' ) && str_contains( (string) $data['return_url'], 'ref=' . rawurlencode( $data['ref'] ) ) && str_contains( (string) $data['return_url'], 't=' . $data['token'] )
			&& (int) $data['amount_cents'] === (int) $p1['deposit_cents'] && false === $data['pay_in_full'] && null !== $data['hold_expires_at'],
		wp_json_encode( $data )
	);
	$mbkCheck(
		'deposit intent: amount, off_session, card only, metadata, description, saved customer',
		null !== $pi1 && $pi1['amount'] === (int) $p1['deposit_cents'] && 'off_session' === $pi1['params']['setup_future_usage'] && array( 'card' ) === $pi1['params']['allowed_payment_method_types']
			&& (string) $p1['id'] === $pi1['metadata']['booking_id'] && 'deposit' === $pi1['metadata']['kind'] && str_ends_with( $pi1['description'], ' · akontacija' )
			&& $pi1['customer'] === Container::guestRepository()->find( (int) $p1['guest_id'] )['stripe_customer_id'] && $pi1['customer'] === $p1['stripe_customer_id']
			&& $pi1['id'] === $paymentsOf( (int) $p1['id'] )[0]['stripe_intent_id'],
		wp_json_encode( $pi1 )
	);

	$res = $api( 'GET', '/bookings/' . $data['ref'], null, array( 't' => $data['token'] ), array( 'X-MBK-Lang' => 'en' ) );
	$got = $res->get_data();
	$mbkCheck(
		'GET /bookings/{ref} → pending, payment_state none, totals and display, no-store',
		200 === $res->get_status() && 'pending_payment' === $got['status'] && 'none' === $got['payment_state'] && (int) $p1['total_cents'] === $got['totals']['total']['cents'] && 0 === $got['totals']['paid']['cents']
			&& is_array( $got['display']['lines'] ) && '' !== $got['display']['deposit_pct'] && 'no-store' === ( $res->get_headers()['Cache-Control'] ?? '' ) && 'Pia' === $got['guest_first_name'] && false === $got['ga_sent'],
		wp_json_encode( $got )
	);
	$res = $api( 'GET', '/bookings/' . $data['ref'], null, array( 't' => str_repeat( 'a', 64 ) ) );
	$mbkCheck( 'GET /bookings/{ref} with a wrong token → 404 not_found', 404 === $res->get_status() && 'not_found' === ( $res->get_data()['code'] ?? '' ) );

	// 28. Re-submit of the same stay: without proof the guest's own hold blocks it; with `supersede:{ref,t}`
	//     (here also with a corrected email) the old hold is expired and its intent cancelled.
	$resetLimits();
	$res = $api( 'POST', '/bookings', $checkoutBody( $unitP, '2028-02-07', '2028-02-11', 'pay.a', 'DIRECT5' ) );
	$mbkCheck( 'second POST without supersede token → 409, first hold kept', 409 === $res->get_status() && 'pending_payment' === Container::bookings()->find( (int) $p1['id'] )['status'] );
	$resetLimits();
	$body              = $checkoutBody( $unitP, '2028-02-07', '2028-02-11', 'pay.a.fixed', 'DIRECT5' );
	$body['supersede'] = array(
		'ref' => $data['ref'],
		't'   => str_repeat( 'b', 64 ),
	);
	$res = $api( 'POST', '/bookings', $body );
	$mbkCheck( 'supersede with a wrong token → ignored (409)', 409 === $res->get_status() );
	$resetLimits();
	$body['supersede']['t'] = $data['token'];
	$again                  = $api( 'POST', '/bookings', $body )->get_data();
	$old                    = Container::bookings()->find( (int) $p1['id'] );
	$p1                     = Container::bookings()->findByRef( (string) ( $again['ref'] ?? '' ) );
	$token1                 = (string) ( $again['token'] ?? '' );
	$pi1                    = null === $p1 ? null : $fake->intentByKey( 'mbk-deposit-' . $p1['id'] );
	$mbkCheck( 'POST with supersede {ref,t} (email corrected) → old hold expired + intent cancelled, new hold', 'expired' === $old['status'] && 'canceled' === $fake->intentByKey( 'mbk-deposit-' . $old['id'] )['status'] && null !== $p1 && 'pending_payment' === $p1['status'] && 'pay.a.fixed' . $domain === $p1['email'], wp_json_encode( $again ) );
	$got = $api( 'GET', '/bookings/' . $old['ref'], null, array( 't' => $data['token'] ) )->get_data();
	$mbkCheck( 'GET superseded hold → cancel_reason superseded, not payable', 'superseded' === $got['cancel_reason'] && false === $got['payable'] && 'not_payable' === $got['not_payable_reason'], wp_json_encode( array( $got['cancel_reason'] ?? null, $got['not_payable_reason'] ?? null ) ) );

	// Unverified checkouts never overwrite the guest's CRM data (the booking keeps what was typed).
	$crmUnit = $free( '2028-03-27', '2028-03-31' );
	$resetLimits();
	$body                         = $checkoutBody( $crmUnit, '2028-03-27', '2028-03-31', 'pay.a' );
	$body['guest']['first_name']  = 'Mallory';
	$body['guest']['phone']       = '+1 555 000 0000';
	$crm                          = $api( 'POST', '/bookings', $body )->get_data();
	$crmBooking                   = Container::bookings()->findByRef( (string) ( $crm['ref'] ?? '' ) );
	$crmGuest                     = Container::guestRepository()->findByEmail( 'pay.a' . $domain );
	$mbkCheck( 'checkout with an existing email → guest CRM fields kept, booking has the submitted name', null !== $crmBooking && 'Mallory' === $crmBooking['first_name'] && 'Pia' === $crmGuest['first_name'] && '+385 91 111 2222' === $crmGuest['phone'] );

	$intentsBefore = count( $fake->intents );
	$resetLimits();
	$res = $api( 'POST', '/bookings', $checkoutBody( $unitP, '2028-02-08', '2028-02-12', 'pay.b' ) );
	$mbkCheck( 'another guest on held nights → 409 unavailable, no PaymentIntent', 409 === $res->get_status() && 'unavailable' === ( $res->get_data()['code'] ?? '' ) && count( $fake->intents ) === $intentsBefore );

	// 29. Signed webhook payment_intent.succeeded → confirmed once, coupon redeemed once.
	$res = $webhook( 'payment_intent.succeeded', $fake->succeed( $pi1['id'] ), 't=' . time() . ',v1=' . str_repeat( '0', 64 ) );
	$mbkCheck( 'webhook with a bad signature → 400, nothing applied', 400 === $res->get_status() && 'pending_payment' === Container::bookings()->find( (int) $p1['id'] )['status'] );

	$succeeded = $fake->intents[ $pi1['id'] ];
	$res       = $webhook( 'payment_intent.succeeded', $succeeded );
	$eventId   = $res->get_headers()['X-Test-Event-Id'];
	$replay    = $webhook( 'payment_intent.succeeded', $succeeded, null, $eventId );
	$webhook( 'payment_intent.succeeded', $succeeded ); // Same intent, new event ID.
	$p1        = Container::bookings()->find( (int) $p1['id'] );
	$rows      = $paymentsOf( (int) $p1['id'] );
	$redeemed  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t( 'mbk_coupon_redemptions' )} WHERE booking_id = %d", $p1['id'] ) );
	$mbkCheck(
		'webhook succeeded → confirmed, one succeeded deposit with card + saved PM; replay → duplicate; redemption once',
		200 === $res->get_status() && 'processed' === $res->get_data()['result'] && 'duplicate' === $replay->get_data()['result'] && 'confirmed' === $p1['status']
			&& 1 === count( $rows ) && 'succeeded' === $rows[0]['status'] && '4242' === $rows[0]['card_last4'] && 'pm_card_visa' === $p1['stripe_payment_method_id']
			&& 1 === $redeemed && 1 === $hookCount( 'mbk_payment_succeeded' ) && null !== StripeStatus::get()['webhook']['last_received_at'],
		wp_json_encode( array( $res->get_data(), $replay->get_data(), $p1['status'], count( $rows ), $redeemed ) )
	);
	$confirmedMails = $mbkMailsOf( (int) $p1['id'], 'guest_confirmed' );
	$mbkCheck(
		'emails: webhook deposit → guest_confirmed (HR, to the guest) + admin_new_booking, once despite replays',
		1 === count( $confirmedMails ) && 'hr' === $confirmedMails[0]['lang'] && array( strtolower( (string) $p1['email'] ) ) === $confirmedMails[0]['to'] && 1 === count( $mbkMailsOf( (int) $p1['id'], 'admin_new_booking' ) ),
		wp_json_encode( array_map( static fn( array $m ): array => array( $m['code'], $m['lang'], $m['to'] ), $mbkMailsOf( (int) $p1['id'] ) ) )
	);

	$got = $api( 'GET', '/bookings/' . $p1['ref'], null, array( 't' => $token1 ) )->get_data();
	$mbkCheck( 'GET after payment → confirmed, succeeded, paid = deposit, payable (balance open)', 'confirmed' === $got['status'] && 'succeeded' === $got['payment_state'] && (int) $p1['deposit_cents'] === $got['totals']['paid']['cents'] && true === $got['payable'] && null === $got['cancel_reason'] );

	// Intents of another site on the same Stripe account are ignored (metadata.site, ref, own payment row).
	$foreign                       = $fake->createIntent(
		array(
			'amount'   => 100,
			'metadata' => array(
				'booking_id' => (string) $p1['id'],
				'ref'        => $p1['ref'],
				'kind'       => 'balance',
				'site'       => 'another-install',
			),
		),
		'foreign-' . $p1['id']
	);
	$res                           = $webhook( 'payment_intent.succeeded', $fake->succeed( $foreign->id ) );
	$foreignLogged                 = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t( 'mbk_events' )} WHERE type = 'foreign_intent' AND payload_json LIKE %s", '%' . $wpdb->esc_like( $foreign->id ) . '%' ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$t( 'mbk_events' )} WHERE type = 'foreign_intent' AND payload_json LIKE %s", '%' . $wpdb->esc_like( $foreign->id ) . '%' ) );
	$mbkCheck( 'foreign intent (other site) webhook → ignored, no payment row, foreign_intent logged', 200 === $res->get_status() && 1 === count( $paymentsOf( (int) $p1['id'] ) ) && 1 === $foreignLogged );
	$ga1 = $api( 'POST', '/bookings/' . $p1['ref'] . '/ga', null, array( 't' => $token1 ) )->get_data();
	$ga2 = $api( 'POST', '/bookings/' . $p1['ref'] . '/ga', null, array( 't' => $token1 ) )->get_data();
	$mbkCheck( 'POST /ga → send once', true === $ga1['send'] && false === $ga2['send'] && true === $api( 'GET', '/bookings/' . $p1['ref'], null, array( 't' => $token1 ) )->get_data()['ga_sent'] );

	// 30. payment_intent.payment_failed on another checkout; GET reconciles a late webhook.
	$resetLimits();
	$q   = $api( 'POST', '/bookings', $checkoutBody( $unitQ, '2028-02-14', '2028-02-18', 'pay.c' ) )->get_data();
	$q1  = Container::bookings()->findByRef( (string) $q['ref'] );
	$piQ = $fake->intentByKey( 'mbk-deposit-' . $q1['id'] );
	$webhook( 'payment_intent.payment_failed', $fake->fail( $piQ['id'] ) );
	$rowQ = $paymentsOf( (int) $q1['id'] )[0];
	$mbkCheck( 'webhook payment_failed → payment failed (decline code), booking still pending', 'failed' === $rowQ['status'] && 'generic_decline' === $rowQ['error_code'] && 'pending_payment' === Container::bookings()->find( (int) $q1['id'] )['status'] && 1 === $hookCount( 'mbk_payment_failed' ) );

	$fake->succeed( $piQ['id'] );
	$got = $api( 'GET', '/bookings/' . $q['ref'], null, array( 't' => $q['token'] ) )->get_data();
	$mbkCheck( 'GET reconciles a retried payment without webhook → confirmed', 'confirmed' === $got['status'] && 'succeeded' === $got['payment_state'] && 'confirmed' === Container::bookings()->find( (int) $q1['id'] )['status'] );

	// 31. Admin cancel with a Stripe refund (pending → charge.refunded webhook → succeeded).
	wp_set_current_user( $adminId );
	$fake->refundStatus = 'pending';
	$dry                = rest_do_request( ( static function () use ( $q1 ): WP_REST_Request {
		$r = new WP_REST_Request( 'POST', '/marclie-booking/v1/admin/bookings/' . $q1['id'] . '/cancel' );
		$r->set_body_params( array( 'refund_mode' => 'policy', 'dry_run' => true ) );
		return $r;
	} )() )->get_data();
	$mbkCheck( 'cancel dry run → refunds plan with the card', 1 === count( $dry['decision']['refunds'] ?? array() ) && 'stripe' === $dry['decision']['refunds'][0]['method'] && '4242' === $dry['decision']['refunds'][0]['card_last4'] && null === $dry['decision']['refunds'][0]['payment_id'] && ! isset( $dry['decision']['requires_stripe'] ), wp_json_encode( $dry['decision'] ?? null ) );
	$req = new WP_REST_Request( 'POST', '/marclie-booking/v1/admin/bookings/' . $q1['id'] . '/cancel' );
	$req->set_body_params( array( 'refund_mode' => 'policy' ) );
	$res     = rest_do_request( $req );
	$refunds = $refundsOf( (int) $q1['id'] );
	$mbkCheck(
		'admin cancel → cancelled, Stripe refund sent (pending), net 0, refund in decision',
		200 === $res->get_status() && 'cancelled' === Container::bookings()->find( (int) $q1['id'] )['status'] && 1 === count( $refunds ) && 'pending' === $refunds[0]['status'] && str_starts_with( (string) $refunds[0]['stripe_refund_id'], 're_fake_' )
			&& 0 === Container::paymentRepository()->netPaidCents( (int) $q1['id'] ) && (int) $refunds[0]['id'] === (int) ( $res->get_data()['decision']['refunds'][0]['payment_id'] ?? 0 ) && 1 === $hookCount( 'mbk_refund_created' ),
		wp_json_encode( $res->get_data()['decision'] ?? $res->get_data() )
	);
	$refundObj = $fake->refunds[ $refunds[0]['stripe_refund_id'] ];
	// Stripe finishes the refund; charge.refunded no longer embeds refunds: the handler lists them.
	$fake->refunds[ $refundObj['id'] ]['status'] = 'succeeded';
	$webhook(
		'charge.refunded',
		array(
			'id'              => 'ch_' . substr( $piQ['id'], 3 ),
			'object'          => 'charge',
			'payment_intent'  => $piQ['id'],
			'amount'          => $piQ['amount'],
			'amount_refunded' => $piQ['amount'],
			'refunded'        => true,
		)
	);
	$refunds = $refundsOf( (int) $q1['id'] );
	$detail  = rest_do_request( new WP_REST_Request( 'GET', '/marclie-booking/v1/admin/bookings/' . $q1['id'] ) )->get_data();
	$charge  = array_values( array_filter( $detail['payments'], static fn( array $p ): bool => 'refund' !== $p['kind'] ) )[0];
	$refundP = array_values( array_filter( $detail['payments'], static fn( array $p ): bool => 'refund' === $p['kind'] ) )[0];
	$mbkCheck(
		'charge.refunded → refund succeeded, charge refunded; detail has refund id, dashboard URL, refund_of_id',
		1 === count( $refunds ) && 'succeeded' === $refunds[0]['status'] && 'refunded' === $charge['status'] && $refundP['stripe_refund_id'] === $refunds[0]['stripe_refund_id']
			&& str_contains( (string) $charge['stripe_dashboard_url'], $piQ['id'] ) && $refundP['refund_of_id'] === $charge['id'] && true === $detail['saved_card'],
		wp_json_encode( $detail['payments'] )
	);
	$got = $api( 'GET', '/bookings/' . $q['ref'], null, array( 't' => $q['token'] ) )->get_data();
	$mbkCheck( 'GET admin-cancelled booking → cancel_reason admin, payment_state refunded', 'admin' === $got['cancel_reason'] && 'refunded' === $got['payment_state'], wp_json_encode( array( $got['cancel_reason'], $got['payment_state'] ) ) );

	// refund.failed (bank rejected the refund): the row fails, the money counts as received again.
	$webhook( 'refund.failed', array_merge( $fake->refunds[ $refundObj['id'] ], array( 'status' => 'failed' ) ) );
	$refunds = $refundsOf( (int) $q1['id'] );
	$mbkCheck( 'refund.failed webhook → refund row failed, charge succeeded again, net = deposit', 'failed' === $refunds[0]['status'] && (int) $piQ['amount'] === Container::paymentRepository()->netPaidCents( (int) $q1['id'] ) && 'succeeded' === Container::paymentRepository()->findByIntent( $piQ['id'] )['status'] );
	$fake->refundStatus = 'succeeded';

	// 32. Late payment after expiry: nights still free → re-held and confirmed.
	wp_set_current_user( 0 );
	$lateUnit = $free( '2028-02-21', '2028-02-25' );
	$resetLimits();
	$l   = $api( 'POST', '/bookings', $checkoutBody( $lateUnit, '2028-02-21', '2028-02-25', 'pay.d' ) )->get_data();
	$l1  = Container::bookings()->findByRef( (string) $l['ref'] );
	$piL = $fake->intentByKey( 'mbk-deposit-' . $l1['id'] );
	Container::bookings()->transition( (int) $l1['id'], BookingStatus::Expired, 'test' );
	$webhook( 'payment_intent.succeeded', $fake->succeed( $piL['id'] ) );
	$mbkCheck( 'late payment, nights free → confirmed, nights held again, no refund', 'confirmed' === Container::bookings()->find( (int) $l1['id'] )['status'] && 4 === count( Container::availability()->occupiedNights( $lateUnit, '2028-02-21', '2028-02-25' ) ) && array() === $refundsOf( (int) $l1['id'] ) );

	// 33. Late payment after expiry, nights taken meanwhile → cancelled, refunded 100 %, mbk_hold_lost.
	$lostUnit = $free( '2028-02-28', '2028-03-03' );
	$resetLimits();
	$m   = $api( 'POST', '/bookings', $checkoutBody( $lostUnit, '2028-02-28', '2028-03-03', 'pay.e' ) )->get_data();
	$m1  = Container::bookings()->findByRef( (string) $m['ref'] );
	$piM = $fake->intentByKey( 'mbk-deposit-' . $m1['id'] );
	Container::bookings()->transition( (int) $m1['id'], BookingStatus::Expired, 'test' );
	$taker = Stay::fromStrings( $lostUnit, '2028-02-28', '2028-03-03', $tz );
	Container::bookings()->create( $taker, Container::pricing()->quote( $taker, 2, 0 ), array( 'email' => 'taker' . $domain ), 'hr' );
	$webhook( 'payment_intent.succeeded', $fake->succeed( $piM['id'] ) );
	$webhook( 'payment_intent.succeeded', $fake->intents[ $piM['id'] ] );
	$lost    = Container::bookings()->find( (int) $m1['id'] );
	$refunds = $refundsOf( (int) $m1['id'] );
	$mbkCheck(
		'late payment, nights taken → cancelled (hold_lost), one full refund, mbk_hold_lost',
		'cancelled' === $lost['status'] && 1 === count( $refunds ) && (int) $refunds[0]['amount_cents'] === $piM['amount'] && 'succeeded' === $refunds[0]['status'] && 0 === Container::paymentRepository()->netPaidCents( (int) $m1['id'] )
			&& 1 === $hookCount( 'mbk_hold_lost' ) && 1 === count( array_filter( $fake->refunds, static fn( array $r ): bool => $r['payment_intent'] === $piM['id'] ) ),
		wp_json_encode( array( $lost['status'], $refunds ) )
	);
	$got = $api( 'GET', '/bookings/' . $m['ref'], null, array( 't' => $m['token'] ) )->get_data();
	$mbkCheck( 'GET hold_lost booking → cancel_reason hold_lost, payment_state refunded', 'hold_lost' === $got['cancel_reason'] && 'refunded' === $got['payment_state'] && false === $got['payable'] );
	$mbkCheck(
		'emails: hold lost → guest_hold_lost + admin_alert, no guest_cancelled',
		1 === count( $mbkMailsOf( (int) $m1['id'], 'guest_hold_lost' ) ) && 1 === count( $mbkMailsOf( (int) $m1['id'], 'admin_alert' ) ) && array() === $mbkMailsOf( (int) $m1['id'], 'guest_cancelled' ) && array() === $mbkMailsOf( (int) $m1['id'], 'guest_confirmed' ),
		wp_json_encode( array_column( $mbkMailsOf( (int) $m1['id'] ), 'code' ) )
	);

	// 34. HoldReaper asks Stripe: a paid hold is confirmed, an unpaid one cancelled + expired.
	$reapUnit = $free( '2028-03-06', '2028-03-10' );
	$resetLimits();
	$r   = $api( 'POST', '/bookings', $checkoutBody( $reapUnit, '2028-03-06', '2028-03-10', 'pay.f' ) )->get_data();
	$r1  = Container::bookings()->findByRef( (string) $r['ref'] );
	$piR = $fake->intentByKey( 'mbk-deposit-' . $r1['id'] );
	$wpdb->update( $t( 'mbk_bookings' ), array( 'hold_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $r1['id'] ) );
	$fake->succeed( $piR['id'] );
	Container::holdReaper()->run();
	$mbkCheck( 'HoldReaper: payment succeeded at Stripe → confirmed, not expired', 'confirmed' === Container::bookings()->find( (int) $r1['id'] )['status'] );

	$reapUnit2 = $free( '2028-03-13', '2028-03-17' );
	$resetLimits();
	$u   = $api( 'POST', '/bookings', $checkoutBody( $reapUnit2, '2028-03-13', '2028-03-17', 'pay.g' ) )->get_data();
	$u1  = Container::bookings()->findByRef( (string) $u['ref'] );
	$piU = $fake->intentByKey( 'mbk-deposit-' . $u1['id'] );
	$wpdb->update( $t( 'mbk_bookings' ), array( 'hold_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $u1['id'] ) );
	Container::holdReaper()->run();
	$mbkCheck( 'HoldReaper: unpaid → intent cancelled, booking expired, nights free', 'expired' === Container::bookings()->find( (int) $u1['id'] )['status'] && 'canceled' === $fake->intents[ $piU['id'] ]['status'] && array() === Container::availability()->occupiedNights( $reapUnit2, '2028-03-13', '2028-03-17' ) );

	// 35. Balances: off-session charge on the due date → paid; decline → balance_failed, link, on-session intent, admin charge 402.
	$today = Container::clock()->today()->format( 'Y-m-d' );
	$wpdb->update( $t( 'mbk_bookings' ), array( 'balance_due_on' => $today ), array( 'id' => $r1['id'] ) );
	$dry   = array_values( array_filter( Container::balanceCharger()->run( null, true ), static fn( array $x ): bool => (int) $x['booking_id'] === (int) $r1['id'] ) );
	$mbkCheck( 'charge-balances --dry-run lists the due booking, charges nothing', 1 === count( $dry ) && 'dry_run' === $dry[0]['result'] && 'confirmed' === Container::bookings()->find( (int) $r1['id'] )['status'] );
	// Only the test bookings are charged (run() would also charge other due bookings of this database).
	Container::balanceCharger()->charge( (int) $r1['id'], BalanceCharger::ACTOR, false );
	$r1     = Container::bookings()->find( (int) $r1['id'] );
	$charge = $fake->intentByKey( 'mbk-balance-' . $r1['id'] . '-1' );
	$mbkCheck( 'charge-balances → off-session balance with the saved card → paid', 'paid' === $r1['status'] && null !== $charge && $charge['params']['payment_method'] === $r1['stripe_payment_method_id'] && str_ends_with( $charge['description'], ' · ostatak' ) && Container::paymentRepository()->netPaidCents( (int) $r1['id'] ) === (int) $r1['total_cents'] );

	$fake->offSession = 'card_declined';
	Container::balanceCharger()->charge( (int) $l1['id'], BalanceCharger::ACTOR, false );
	$l1 = Container::bookings()->find( (int) $l1['id'] );
	$mbkCheck( 'declined off-session balance → balance_failed, mbk_payment_failed', 'balance_failed' === $l1['status'] && $hookCount( 'mbk_payment_failed' ) >= 2 );
	$failedMails = $mbkMailsOf( (int) $l1['id'], 'guest_balance_failed' );
	preg_match( '#href="([^"]*/placanje/[^"]*)"#', $failedMails[0]['html'] ?? '', $failedLink );
	parse_str( (string) wp_parse_url( html_entity_decode( $failedLink[1] ?? '' ), PHP_URL_QUERY ), $failedQuery );
	$mbkCheck(
		'emails: declined balance → guest_balance_failed with a working 7-day pay link + admin_alert',
		1 === count( $failedMails ) && null !== Container::bookings()->verifyToken( $l1['ref'], (string) ( $failedQuery['t'] ?? '' ), array( 'pay' ) ) && 1 === count( $mbkMailsOf( (int) $l1['id'], 'admin_alert' ) ),
		wp_json_encode( array( array_column( $mbkMailsOf( (int) $l1['id'] ), 'code' ), $failedLink[1] ?? null ) )
	);

	wp_set_current_user( $adminId );
	$res = rest_do_request( new WP_REST_Request( 'POST', '/marclie-booking/v1/admin/bookings/' . $l1['id'] . '/charge' ) );
	$mbkCheck( 'admin charge now with a declining card → 402 card_declined', 402 === $res->get_status() && 'card_declined' === ( $res->get_data()['code'] ?? '' ), wp_json_encode( $res->get_data() ) );
	$earlierText = \Marclie\Booking\I18n\LanguageAdapter::withLanguage( \Marclie\Booking\Email\Mailer::adminLang(), static fn(): string => __( 'A payment link was emailed to the guest earlier; send a new one from the booking if needed.', 'marclie-booking' ) );
	$failAlerts  = $mbkMailsOf( (int) $l1['id'], 'admin_alert' );
	$mbkCheck(
		'emails: second balance failure → admin alerted again ("link emailed earlier"), guest email not repeated',
		1 === count( $mbkMailsOf( (int) $l1['id'], 'guest_balance_failed' ) ) && 2 === count( $failAlerts ) && ! str_contains( (string) $failAlerts[0]['text'], $earlierText ) && str_contains( (string) $failAlerts[1]['text'], $earlierText ),
		(string) ( $failAlerts[1]['text'] ?? '' )
	);

	$req = new WP_REST_Request( 'POST', '/marclie-booking/v1/admin/bookings/' . $l1['id'] . '/payment-link' );
	$req->set_body_params( array( 'expires_days' => 3 ) );
	$link = rest_do_request( $req )->get_data();
	parse_str( (string) wp_parse_url( (string) ( $link['url'] ?? '' ), PHP_URL_QUERY ), $linkQuery );
	$mbkCheck( 'payment link → URL with ref + pay token, expiry, mbk_payment_link_created', isset( $linkQuery['t'] ) && $linkQuery['ref'] === $l1['ref'] && null !== Container::bookings()->verifyToken( $l1['ref'], (string) $linkQuery['t'], array( 'pay' ) ) && 1 === $hookCount( 'mbk_payment_link_created' ) && '' !== (string) ( $link['expires_at'] ?? '' ), wp_json_encode( $link ) );

	// An off-session charge whose outcome is unknown (response lost) blocks the payment page.
	$fake->offSession   = 'card_declined';
	$fake->loseResponse = 'chargeOffSession';
	$fake->searchable   = false;
	try {
		Container::balanceCharger()->charge( (int) $l1['id'], 'test' );
	} catch ( \Marclie\Booking\Domain\Exception\PaymentException $e ) {
		$unknown = ! empty( $e->context()['outcome_unknown'] );
	}
	wp_set_current_user( 0 );
	$res = $api( 'POST', '/bookings/' . $l1['ref'] . '/balance-intent', null, array( 't' => (string) $linkQuery['t'] ) );
	$got = $api( 'GET', '/bookings/' . $l1['ref'], null, array( 't' => (string) $linkQuery['t'] ) )->get_data();
	$mbkCheck( 'off-session charge with unknown outcome → balance-intent 409 in_progress, GET not payable', ! empty( $unknown ) && 409 === $res->get_status() && 'in_progress' === ( $res->get_data()['code'] ?? '' ) && false === $got['payable'] && 'in_progress' === $got['not_payable_reason'], wp_json_encode( $res->get_data() ) );
	$fake->searchable = true; // Stripe search finds the (declined) intent: resolved on the next call.
	wp_set_current_user( 0 );
	$intent1 = $api( 'POST', '/bookings/' . $l1['ref'] . '/balance-intent', null, array( 't' => (string) $linkQuery['t'] ) );
	$intent2 = $api( 'POST', '/bookings/' . $l1['ref'] . '/balance-intent', null, array( 't' => (string) $linkQuery['t'] ) );
	$outstanding = (int) $l1['total_cents'] - Container::paymentRepository()->netPaidCents( (int) $l1['id'] );
	$mbkCheck( 'POST balance-intent (pay token) → client secret for the outstanding amount, reused on reload', 200 === $intent1->get_status() && $outstanding === $intent1->get_data()['amount_cents'] && $intent1->get_data()['client_secret'] === $intent2->get_data()['client_secret'], wp_json_encode( $intent1->get_data() ) );
	$payPi = $fake->intentByKey( 'mbk-pay-' . $l1['id'] . '-1' );
	$webhook( 'payment_intent.succeeded', $fake->succeed( $payPi['id'] ) );
	$mbkCheck( 'balance paid on the payment page → paid', 'paid' === Container::bookings()->find( (int) $l1['id'] )['status'] );
	$res = $api( 'POST', '/bookings/' . $l1['ref'] . '/balance-intent', null, array( 't' => (string) $linkQuery['t'] ) );
	$mbkCheck( 'balance-intent on a paid booking → 409 not_payable', 409 === $res->get_status() && 'not_payable' === ( $res->get_data()['code'] ?? '' ) );

	// Two card payments racing (payment page vs off-session): the excess is refunded automatically.
	$wpdb->insert( $t( 'mbk_payments' ), array( 'booking_id' => $r1['id'], 'kind' => 'balance', 'method' => 'stripe', 'amount_cents' => 1000, 'status' => 'pending', 'idempotency_key' => 'mbk-pay-' . $r1['id'] . '-9', 'created_at' => Db::nowUtc() ) );
	$raceRow = (int) $wpdb->insert_id;
	$race    = $fake->createIntent(
		array(
			'amount'   => 1000,
			'metadata' => array(
				'booking_id' => (string) $r1['id'],
				'ref'        => $r1['ref'],
				'kind'       => 'balance',
				'payment_id' => (string) $raceRow,
				'site'       => \Marclie\Booking\Payment\StripeConfig::siteId(),
			),
		),
		'race-' . $r1['id']
	);
	$webhook( 'payment_intent.succeeded', $fake->succeed( $race->id ) );
	$over = array_values( array_filter( $refundsOf( (int) $r1['id'] ), static fn( array $p ): bool => 'overpayment' === $p['note'] ) );
	$mbkCheck( 'overpayment → excess refunded on the newest charge, net = total', 1 === count( $over ) && 1000 === (int) $over[0]['amount_cents'] && $race->id === $over[0]['stripe_intent_id'] && Container::paymentRepository()->netPaidCents( (int) $r1['id'] ) === (int) $r1['total_cents'] );

	// 36. Dispute webhook → dashboard alert with a Stripe link.
	$webhook(
		'charge.dispute.created',
		array(
			'id'             => 'dp_mbkit',
			'object'         => 'dispute',
			'payment_intent' => $piR['id'],
			'amount'         => $piR['amount'],
			'reason'         => 'fraudulent',
			'status'         => 'needs_response',
		)
	);
	wp_set_current_user( $adminId );
	$alerts   = (array) ( rest_do_request( new WP_REST_Request( 'GET', '/marclie-booking/v1/admin/dashboard' ) )->get_data()['alerts'] ?? array() );
	$disputes = array_values( array_filter( $alerts, static fn( $a ): bool => 'dispute' === ( $a['type'] ?? '' ) && (int) ( $a['booking_id'] ?? 0 ) === (int) $r1['id'] ) );
	$mbkCheck( 'dispute webhook → dashboard alert `dispute` with Stripe URL, mbk_dispute_created', 1 === count( $disputes ) && str_contains( (string) $disputes[0]['url'], $piR['id'] ) && 1 === $hookCount( 'mbk_dispute_created' ) );
	$alertMails = $mbkMailsOf( (int) $r1['id'], 'admin_alert' );
	$mbkCheck(
		'emails: overpayment + dispute → two admin_alert emails to the admin recipients',
		2 === count( $alertMails ) && \Marclie\Booking\Email\Mailer::adminRecipients() === $alertMails[1]['to'] && str_contains( $alertMails[1]['html'], 'fraudulent' ),
		wp_json_encode( array_column( $mbkMailsOf( (int) $r1['id'] ), 'subject' ) )
	);
	$overAlerts = array_filter( $alerts, static fn( $a ): bool => 'overpayment' === ( $a['type'] ?? '' ) && (int) ( $a['booking_id'] ?? 0 ) === (int) $r1['id'] );
	$mbkCheck( 'dashboard alert `overpayment`', 1 === count( $overAlerts ) );
	$mbkCheck( 'cron mbk_hourly scheduled (refund retries)', false !== wp_next_scheduled( \Marclie\Booking\Cron::HOURLY ) );
	wp_set_current_user( 0 );

	// 37. Booking rate limit: 5 per hour per client.
	$resetLimits();
	$codes = array();
	for ( $i = 0; $i < 6; $i++ ) {
		$codes[] = $api( 'POST', '/bookings', $checkoutBody( $unitP, '2028-03-20', '2028-03-24', 'pay.h', 'NO-SUCH-COUPON' ) )->get_status();
	}
	$mbkCheck( 'POST /bookings: 6th request within the hour → 429', 429 === end( $codes ) && 422 === $codes[0], implode( ',', $codes ) );
} finally {
	remove_filter( 'mbk_payment_gateway', $useFake, 99 );
	remove_filter( 'mbk_payment_gateway', $noGateway, 99 );
	remove_filter( 'mbk_stripe_webhook_secret', $secretFilter );
	foreach ( $hookCallbacks as $hook => $callback ) {
		remove_action( $hook, $callback, 10 );
	}
	Container::reset();
	$resetLimits();
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$t( 'mbk_events' )} WHERE external_id LIKE %s", 'evt\\_mbkit\\_%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	delete_transient( 'mbk_stripe_webhook_error' );
	if ( null === $stripeStatus ) {
		delete_option( StripeStatus::OPTION );
	} else {
		update_option( StripeStatus::OPTION, $stripeStatus, false );
	}
}
