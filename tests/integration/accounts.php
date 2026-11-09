<?php
/**
 * Integration checks of guest accounts (phase 10) through the public REST
 * routes: register → verify with the registration password (token from the
 * captured email; wrong passwords, voided token) → earlier bookings linked,
 * duplicate registration without enumeration, login rate limits (per client
 * and per email over many IPs), forgotten password, profile, cancellation
 * request (admin email + dashboard alert), card change (503 without Stripe,
 * fake SetupIntent), membership cron (Srebrni + one coupon even when run
 * twice), a signed-in checkout linked to the user, manage-link reuse, the
 * security review H1 (no email change through /wp/v2/users, a changed email
 * voids the verification and links nothing), masked personal coupons, deletion.
 *
 * Account emails go through a cron job (AccountMail): the test intercepts the
 * scheduling (`pre_schedule_event`) and runs the jobs itself, so nothing is
 * ever left for a real WP-Cron run to send.
 *
 * Included by run.php inside its try block (uses $mbkCheck, $mbkMails, $free,
 * $domain, $t, $tz). Bookings and guests of $domain are removed by run.php;
 * users, member coupons, transients and filters are removed here. Uses
 * May 2028 stays (run.php's test price table). Auth cookies are not sent (send_auth_cookies).
 */

use Marclie\Booking\Account\AccountMail;
use Marclie\Booking\Account\Roles;
use Marclie\Booking\Container;
use Marclie\Booking\Domain\BookingStatus;
use Marclie\Booking\Domain\Stay;
use Marclie\Booking\Repository\Db;
use Marclie\Booking\Rest\Admin\Presenter;
use Marclie\Booking\Tests\Fakes\FakePaymentGateway;

defined( 'ABSPATH' ) || exit( 1 );

require_once dirname( __DIR__ ) . '/phpunit/Fakes/FakePaymentGateway.php';

$acctEmail   = 'acct.a' . $domain;
$acctFake    = new FakePaymentGateway();
$acctUseFake = static fn() => $acctFake;
$acctNoPay   = static fn() => null;
$acctGateway = static function ( callable $filter ) use ( $acctUseFake, $acctNoPay ): void {
	remove_filter( 'mbk_payment_gateway', $acctUseFake, 99 );
	remove_filter( 'mbk_payment_gateway', $acctNoPay, 99 );
	add_filter( 'mbk_payment_gateway', $filter, 99 );
	Container::reset();
};
$acctApi = static function ( string $method, string $route, ?array $body = null, array $query = array() ): WP_REST_Response {
	$req = new WP_REST_Request( $method, '/marclie-booking/v1' . $route );
	$req->set_header( 'X-MBK-Lang', 'en' );
	if ( $query ) {
		$req->set_query_params( $query );
	}
	if ( null !== $body ) {
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( (string) wp_json_encode( $body ) );
	}

	return rest_do_request( $req );
};
$acctLimits = static function () use ( $wpdb ): void {
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_mbk\\_rl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_mbk\\_rl\\_%' OR option_name LIKE '\\_transient\\_mbk\\_acct\\_exists\\_%' OR option_name LIKE '\\_transient\\_timeout\\_mbk\\_acct\\_exists\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	wp_cache_flush();
};
$acctMails = static function ( string $code, ?string $to = null ) use ( &$mbkMails ): array {
	return array_values( array_filter( $mbkMails, static fn( array $m ): bool => $m['code'] === $code && ( null === $to || in_array( strtolower( $to ), $m['to'], true ) ) ) );
};
$acctUserId = static fn(): int => (int) email_exists( $acctEmail );
$acctJobs   = array();
$acctQueue  = static function ( $pre, $event ) use ( &$acctJobs ) {
	if ( is_object( $event ) && AccountMail::HOOK === $event->hook ) {
		$acctJobs[] = $event->args[0];

		return true;
	}

	return $pre;
};
/** Runs the intercepted account email jobs; returns how many there were. */
$acctRunMail = static function () use ( &$acctJobs ): int {
	$jobs     = $acctJobs;
	$acctJobs = array();
	foreach ( $jobs as $job ) {
		AccountMail::handle( $job );
	}

	return count( $jobs );
};
add_filter( 'pre_schedule_event', $acctQueue, 10, 2 );

add_filter( 'send_auth_cookies', '__return_false' );
wp_set_current_user( 0 );
$acctLimits();

try {
	$unitA = $free( '2028-05-08', '2028-05-15' );
	$unitK = $free( '2028-05-22', '2028-05-26' );
	if ( null === $unitA || null === $unitK ) {
		throw new RuntimeException( 'No free unit in May 2028 for the account checks.' );
	}

	// A1. A booking made without an account (same email as the later registration).
	$stayA    = Stay::fromStrings( $unitA, '2028-05-08', '2028-05-15', $tz );
	$createdA = Container::bookings()->create(
		$stayA,
		Container::pricing()->quote( $stayA, 2, 0 ),
		array(
			'email'      => $acctEmail,
			'first_name' => 'Ana',
			'last_name'  => 'Account',
			'phone'      => '+385 91 000 1111',
		),
		'en'
	);
	$bookingA = Container::bookings()->transition( (int) $createdA['booking']['id'], BookingStatus::Confirmed, 'test' );
	$mbkCheck( 'accounts: booking without account has no user', null === $bookingA['user_id'] );

	// A2. Registration: validation, honeypot, verification email.
	$res = $acctApi( 'POST', '/account/register', array( 'first_name' => 'Ana', 'last_name' => 'Account', 'email' => $acctEmail, 'password' => 'short', 'terms' => false ) );
	$mbkCheck( 'accounts: register invalid → 422 validation (password, terms)', 422 === $res->get_status() && isset( $res->get_data()['data']['fields']['password'], $res->get_data()['data']['fields']['terms'] ), wp_json_encode( $res->get_data() ) );

	$before = count( $mbkMails );
	$res    = $acctApi( 'POST', '/account/register', array( 'first_name' => 'Bot', 'last_name' => 'Bot', 'email' => 'bot' . $domain, 'password' => 'secret-pass-1', 'terms' => true, 'website' => 'spam' ) );
	$mbkCheck( 'accounts: honeypot → 201 pending, no job, no email', 201 === $res->get_status() && 0 === $acctRunMail() && count( $mbkMails ) === $before && false === email_exists( 'bot' . $domain ) );

	$register = array(
		'first_name' => 'Ana',
		'last_name'  => 'Account',
		'email'      => strtoupper( $acctEmail ),
		'password'   => 'secret-pass-1',
		'terms'      => true,
		'newsletter' => true,
		'lang'       => 'en',
	);
	$res    = $acctApi( 'POST', '/account/register', $register );
	$queued = count( $acctMails( 'account_verify', $acctEmail ) );
	$jobs   = $acctRunMail();
	$verify = $acctMails( 'account_verify', $acctEmail );
	preg_match( '/verify=([a-f0-9]{64})/', (string) ( $verify[0]['html'] ?? '' ), $m );
	$token = $m[1] ?? '';
	$mbkCheck( 'accounts: register → 201 pending, email sent by the queued job (not in the request), no user yet', 201 === $res->get_status() && array( 'status' => 'pending' ) === $res->get_data() && 0 === $queued && 1 === $jobs && 1 === count( $verify ) && 'en' === $verify[0]['lang'] && '' !== $token && 0 === $acctUserId(), wp_json_encode( $res->get_data() ) );

	// A3. Verification needs the registration password; a GET-style check consumes nothing.
	$mbkCheck( 'accounts: isPending(token) is read-only', Container::verification()->isPending( $token ) && Container::verification()->isPending( $token ) && ! Container::verification()->isPending( str_repeat( 'a', 64 ) ) );
	$res = $acctApi( 'POST', '/account/verify', array( 'token' => $token, 'password' => 'not-my-pass' ) );
	$mbkCheck( 'accounts: verify with a wrong password → 422 fields.password, 4 attempts left, no user', 422 === $res->get_status() && isset( $res->get_data()['data']['fields']['password'] ) && 4 === ( $res->get_data()['data']['attempts_left'] ?? null ) && 0 === $acctUserId(), wp_json_encode( $res->get_data() ) );

	// A3. Verification: user, link, membership start, sign-in.
	$res    = $acctApi( 'POST', '/account/verify', array( 'token' => $token, 'password' => 'secret-pass-1' ) );
	$userId = $acctUserId();
	$user   = get_userdata( $userId );
	$guest  = Container::guestRepository()->findByEmail( $acctEmail );
	$mbkCheck(
		'accounts: verify → signed in, mbk_guest, email verified, newsletter consent',
		200 === $res->get_status() && $userId > 0 && get_current_user_id() === $userId && $user instanceof WP_User && Roles::isGuestOnly( $user ) && '' !== (string) get_user_meta( $userId, 'mbk_email_verified_at', true ) && $acctEmail === get_user_meta( $userId, 'mbk_email_verified', true ) && '' !== (string) get_user_meta( $userId, 'mbk_marketing_consent_at', true ) && '' !== (string) ( $res->get_data()['nonce'] ?? '' ),
		wp_json_encode( $res->get_data() )
	);
	$mbkCheck( 'accounts: password chosen at registration works', $user instanceof WP_User && wp_check_password( 'secret-pass-1', $user->user_pass, $userId ) );
	$mbkCheck( 'accounts: guest + earlier booking linked to the user', null !== $guest && $userId === (int) $guest['user_id'] && $userId === (int) Container::bookingRepository()->find( (int) $bookingA['id'] )['user_id'] );
	$welcome = $acctMails( 'account_welcome', $acctEmail );
	$coupons = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t( 'mbk_coupons' )} WHERE guest_id = %d", (int) $guest['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	$mbkCheck( 'accounts: tier Član + one welcome coupon (5 %, single use, 80-bit code) + account_welcome email', 'clan' === get_user_meta( $userId, 'mbk_tier', true ) && 1 === count( $coupons ) && 1 === preg_match( '/^WELCOME-[0-9A-HJKMNP-TV-Z]{4}(-[0-9A-HJKMNP-TV-Z]{4}){3}$/', $coupons[0]['code'] ) && 5 === (int) $coupons[0]['amount'] && 1 === (int) $coupons[0]['usage_limit'] && 1 === count( $welcome ) && str_contains( $welcome[0]['html'], $coupons[0]['code'] ), (string) ( $coupons[0]['code'] ?? '' ) );
	$res = $acctApi( 'POST', '/account/verify', array( 'token' => $token, 'password' => 'secret-pass-1' ) );
	$mbkCheck( 'accounts: verification link is single use → 400 invalid_token', 400 === $res->get_status() && 'invalid_token' === ( $res->get_data()['code'] ?? '' ) );

	// A3b. Someone else registers an address and the owner gets the link: without the password it cannot be activated.
	wp_set_current_user( 0 );
	$acctApi( 'POST', '/account/register', array( 'first_name' => 'Eve', 'last_name' => 'Attacker', 'email' => 'acct.b' . $domain, 'password' => 'attacker-pass-1', 'terms' => true ) );
	$acctRunMail();
	preg_match( '/verify=([a-f0-9]{64})/', (string) ( $acctMails( 'account_verify', 'acct.b' . $domain )[0]['html'] ?? '' ), $mb );
	$left = array();
	for ( $i = 0; $i < 5; $i++ ) {
		$left[] = $acctApi( 'POST', '/account/verify', array( 'token' => $mb[1] ?? '', 'password' => 'guess-' . $i ) )->get_data()['data']['attempts_left'] ?? null;
	}
	$res = $acctApi( 'POST', '/account/verify', array( 'token' => $mb[1] ?? '', 'password' => 'attacker-pass-1' ) );
	$mbkCheck( 'accounts: 5 wrong passwords void the token (even the right one is refused after)', array( 4, 3, 2, 1, 0 ) === $left && 400 === $res->get_status() && 'invalid_token' === ( $res->get_data()['code'] ?? '' ) && false === email_exists( 'acct.b' . $domain ) && 0 === get_current_user_id(), wp_json_encode( $left ) );
	wp_set_current_user( $userId );
	$mbkCheck( 'accounts: users_can_register stays off', '0' === (string) get_option( 'users_can_register' ) );

	// A4. Duplicate registration: same answer, account_exists instead of a new account.
	wp_set_current_user( 0 );
	$before = count( $acctMails( 'account_verify' ) );
	$res    = $acctApi( 'POST', '/account/register', $register );
	$jobs   = $acctRunMail();
	$mbkCheck( 'accounts: duplicate register → same 201 body, one job, account_exists email, no new verify', 201 === $res->get_status() && array( 'status' => 'pending' ) === $res->get_data() && 1 === $jobs && 1 === count( $acctMails( 'account_exists', $acctEmail ) ) && count( $acctMails( 'account_verify' ) ) === $before );

	// A5. Signed-in account data.
	wp_set_current_user( $userId );
	$res      = $acctApi( 'GET', '/account/bookings' );
	$upcoming = $res->get_data()['upcoming'] ?? array();
	$mbkCheck( 'accounts: GET bookings → booking A upcoming with manage link and cancel action', 200 === $res->get_status() && 1 === count( $upcoming ) && $bookingA['ref'] === $upcoming[0]['ref'] && str_contains( (string) $upcoming[0]['actions']['manage_url'], 't=' ) && true === $upcoming[0]['actions']['can_request_cancel'] && null !== Container::bookings()->verifyToken( $bookingA['ref'], (string) wp_parse_args( (string) wp_parse_url( $upcoming[0]['actions']['manage_url'], PHP_URL_QUERY ) )['t'] ), wp_json_encode( $res->get_data() ) );
	$again   = $acctApi( 'GET', '/account/bookings' )->get_data()['upcoming'][0]['actions']['manage_url'] ?? '';
	$manages = static fn(): int => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t( 'mbk_booking_tokens' )} WHERE booking_id = %d AND purpose = 'manage'", (int) $bookingA['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	$mbkCheck( 'accounts: manage link reused across views (one token per booking)', $again === $upcoming[0]['actions']['manage_url'] && 1 === $manages() );
	$res   = $acctApi( 'GET', '/account/coupons' );
	$kinds = array_column( $res->get_data()['items'] ?? array(), 'kind' );
	$mbkCheck( 'accounts: GET coupons → welcome + direct', 200 === $res->get_status() && in_array( 'welcome', $kinds, true ) && in_array( 'direct', $kinds, true ), wp_json_encode( $kinds ) );
	wp_set_current_user( 0 );
	$res = $acctApi( 'POST', '/coupons/validate', array( 'unit_id' => $unitK, 'arrival' => '2028-05-22', 'departure' => '2028-05-26', 'adults' => 2, 'code' => $coupons[0]['code'] ) );
	$mbkCheck( 'accounts: someone else\'s personal coupon reads as unknown (not_found) to visitors', false === ( $res->get_data()['valid'] ?? null ) && 'not_found' === ( $res->get_data()['reason'] ?? '' ), wp_json_encode( $res->get_data() ) );
	wp_set_current_user( $userId );
	$res = $acctApi( 'GET', '/account/overview' );
	$mbkCheck( 'accounts: GET overview → next stay, membership clan, 2 coupons', 200 === $res->get_status() && $bookingA['ref'] === ( $res->get_data()['next_stay']['ref'] ?? '' ) && 'clan' === $res->get_data()['membership']['tier'] && 2 === $res->get_data()['coupons_available'] );
	$res = $acctApi( 'GET', '/account/payments' );
	$mbkCheck( 'accounts: GET payments → items + card keys', 200 === $res->get_status() && is_array( $res->get_data()['items'] ?? null ) && array_key_exists( 'card', $res->get_data() ) );

	// A6. Profile.
	$res = $acctApi( 'PATCH', '/account/profile', array( 'country' => 'XX' ) );
	$mbkCheck( 'accounts: PATCH profile invalid country → 422', 422 === $res->get_status() && isset( $res->get_data()['data']['fields']['country'] ) );
	$res   = $acctApi( 'PATCH', '/account/profile', array( 'first_name' => 'Anna', 'phone' => '+49 30 1234567', 'country' => 'DE', 'lang' => 'hr', 'newsletter' => false ) );
	$guest = Container::guestRepository()->find( (int) $guest['id'] );
	$mbkCheck( 'accounts: PATCH profile → user, guest and consent updated', 200 === $res->get_status() && 'Anna' === $res->get_data()['first_name'] && 'DE' === $res->get_data()['country'] && 'hr' === $res->get_data()['lang'] && false === $res->get_data()['newsletter'] && 'Anna' === get_userdata( $userId )->first_name && '+49 30 1234567' === $guest['phone'] && null === $guest['marketing_consent_at'], wp_json_encode( $res->get_data() ) );

	// A7. Admin shapes.
	$adminGuest = Presenter::guest( $guest );
	$adminAcct  = Presenter::account( $userId );
	$detail     = Presenter::bookingDetail( Container::bookingRepository()->find( (int) $bookingA['id'] ) );
	$mbkCheck( 'accounts: admin Guest.user_id/tier, account, BookingDetail.user_id', $userId === $adminGuest['user_id'] && 'clan' === $adminGuest['tier'] && null !== $adminAcct && null !== $adminAcct['email_verified_at'] && $userId === $detail['user_id'] && $userId === ( $detail['account']['id'] ?? 0 ) );
	$found = Container::guestRepository()->search( array( 'tier' => 'clan', 'search' => 'acct.a' ), 1, 10 );
	$mbkCheck( 'accounts: admin guests filter tier=clan finds the guest', 1 === $found['total'] );

	// A8. Cancellation request → admin email + dashboard alert, once.
	$res    = $acctApi( 'POST', '/account/bookings/' . $bookingA['ref'] . '/cancel-request', array( 'reason' => 'Plans changed' ) );
	$alerts = array_filter( Container::dashboard()->build( gmdate( 'Y-m' ) )['alerts'], static fn( array $a ): bool => 'cancel_request' === $a['type'] && (int) $a['booking_id'] === (int) $bookingA['id'] );
	$admin  = array_values( array_filter( $mbkMailsOf( (int) $bookingA['id'], 'admin_alert' ) ) );
	$mbkCheck( 'accounts: cancel request → 200, admin_alert email, dashboard alert', 200 === $res->get_status() && 1 === count( $admin ) && str_contains( $admin[0]['html'], 'Plans changed' ) && 1 === count( $alerts ) && 'Plans changed' === array_values( $alerts )[0]['reason'], wp_json_encode( $res->get_data() ) );
	$res = $acctApi( 'POST', '/account/bookings/' . $bookingA['ref'] . '/cancel-request', array( 'reason' => 'Again' ) );
	$mbkCheck( 'accounts: second cancel request → 422, no second email', 422 === $res->get_status() && 1 === count( $mbkMailsOf( (int) $bookingA['id'], 'admin_alert' ) ) );
	$res = $acctApi( 'GET', '/account/bookings' );
	$mbkCheck( 'accounts: booking shows cancel_requested, no cancel action', true === ( $res->get_data()['upcoming'][0]['cancel_requested'] ?? null ) && false === $res->get_data()['upcoming'][0]['actions']['can_request_cancel'] );

	// A9. Card change.
	$acctGateway( $acctNoPay );
	$res = $acctApi( 'POST', '/account/card/setup-intent' );
	$mbkCheck( 'accounts: card setup without Stripe → 503 payments_unavailable', 503 === $res->get_status() && 'payments_unavailable' === ( $res->get_data()['code'] ?? '' ) );
	$acctGateway( $acctUseFake );
	$res   = $acctApi( 'POST', '/account/card/setup-intent' );
	$setup = (string) ( $res->get_data()['setup_intent'] ?? '' );
	$mbkCheck( 'accounts: card setup → client secret', 200 === $res->get_status() && '' !== (string) ( $res->get_data()['client_secret'] ?? '' ) && '' !== $setup, wp_json_encode( $res->get_data() ) );
	$res = $acctApi( 'POST', '/account/card/confirm', array( 'setup_intent' => $setup ) );
	$mbkCheck( 'accounts: card confirm before the browser confirmed → 422', 422 === $res->get_status() );
	$acctFake->succeedSetup( $setup );
	$res = $acctApi( 'POST', '/account/card/confirm', array( 'setup_intent' => $setup ) );
	$row = Container::bookingRepository()->find( (int) $bookingA['id'] );
	$mbkCheck( 'accounts: card confirm → card on the running booking', 200 === $res->get_status() && array( $bookingA['ref'] ) === $res->get_data()['bookings'] && 'pm_card_mastercard' === $row['stripe_payment_method_id'] && '4444' === $res->get_data()['card']['last4'] && 2029 === $res->get_data()['card']['exp_year'], wp_json_encode( $res->get_data() ) );

	// A10. Signed-in checkout → booking linked to the user immediately.
	$acctLimits();
	$res = $acctApi(
		'POST',
		'/bookings',
		array(
			'unit_id'   => $unitK,
			'arrival'   => '2028-05-22',
			'departure' => '2028-05-26',
			'adults'    => 2,
			'children'  => 0,
			'guest'     => array(
				'first_name' => 'Anna',
				'last_name'  => 'Account',
				'email'      => $acctEmail,
				'phone'      => '+49 30 1234567',
				'country'    => 'DE',
			),
			'terms'     => true,
		)
	);
	$checkout = Container::bookingRepository()->findByRef( (string) ( $res->get_data()['ref'] ?? '' ) );
	$mbkCheck( 'accounts: signed-in checkout → booking.user_id set', 201 === $res->get_status() && null !== $checkout && $userId === (int) $checkout['user_id'] && (int) $guest['id'] === (int) $checkout['guest_id'], wp_json_encode( $res->get_data() ) );
	$status = $acctApi( 'GET', '/bookings/' . $createdA['booking']['ref'], null, array( 't' => $createdA['token'] ) );
	$mbkCheck( 'accounts: GET /bookings/{ref} → account.linked', true === ( $status->get_data()['account']['linked'] ?? null ) && null === $status->get_data()['account']['invite_email'] );

	// A11. Membership cron: a completed stay → Srebrni + one coupon, even when run twice.
	Container::bookings()->transition( (int) $bookingA['id'], BookingStatus::Paid, 'test' );
	Container::bookings()->transition( (int) $bookingA['id'], BookingStatus::Completed, 'test' );
	Container::accounts()->runMembership();
	Container::accounts()->runMembership();
	$silver = (array) $wpdb->get_col( $wpdb->prepare( "SELECT code FROM {$t( 'mbk_coupons' )} WHERE guest_id = %d AND code LIKE 'SILVER-%%'", (int) $guest['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	$tierUp = $acctMails( 'account_tier_up', $acctEmail );
	$mbkCheck( 'accounts: membership cron ×2 → srebrni, one SILVER coupon, one tier email (HR)', 'srebrni' === get_user_meta( $userId, 'mbk_tier', true ) && 1 === count( $silver ) && 1 === count( $tierUp ) && 'hr' === $tierUp[0]['lang'] && str_contains( $tierUp[0]['html'], $silver[0] ), wp_json_encode( $silver ) );
	$res = $acctApi( 'GET', '/account/membership' );
	$mbkCheck( 'accounts: GET membership → srebrni, 1 stay, 2 to Zlatni', 'srebrni' === $res->get_data()['tier'] && 1 === $res->get_data()['completed_stays'] && 'zlatni' === $res->get_data()['next_tier'] && 2 === $res->get_data()['stays_to_next'] );

	// A12. Logout, login rate limit, login.
	$res = $acctApi( 'POST', '/account/logout' );
	$mbkCheck( 'accounts: logout → signed out; account routes 401', 200 === $res->get_status() && 0 === get_current_user_id() && 401 === $acctApi( 'GET', '/account/overview' )->get_status() );
	$acctLimits();
	$res = $acctApi( 'POST', '/account/login', array( 'email' => 'nobody' . $domain, 'password' => 'whatever-123' ) );
	$wrong = array();
	for ( $i = 0; $i < 5; $i++ ) {
		$wrong[] = $acctApi( 'POST', '/account/login', array( 'email' => $acctEmail, 'password' => 'wrong-pass-' . $i ) )->get_status();
	}
	$limited = $acctApi( 'POST', '/account/login', array( 'email' => $acctEmail, 'password' => 'secret-pass-1' ) );
	$mbkCheck( 'accounts: unknown email → 401 invalid_login (same as wrong password)', 401 === $res->get_status() && 'invalid_login' === ( $res->get_data()['code'] ?? '' ) );
	$mbkCheck( 'accounts: 5 wrong passwords → 401, then 429 even with the right one', array( 401, 401, 401, 401, 401 ) === $wrong && 429 === $limited->get_status() && 0 === get_current_user_id(), wp_json_encode( $wrong ) );
	$acctLimits();
	$remote = $_SERVER['REMOTE_ADDR'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$spread = array();
	for ( $i = 0; $i < 10; $i++ ) {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.' . ( 10 + $i );
		$spread[]               = $acctApi( 'POST', '/account/login', array( 'email' => $acctEmail, 'password' => 'stuffing-' . $i ) )->get_status();
	}
	$_SERVER['REMOTE_ADDR'] = '203.0.113.99';
	$locked                 = $acctApi( 'POST', '/account/login', array( 'email' => $acctEmail, 'password' => 'secret-pass-1' ) );
	if ( null === $remote ) {
		unset( $_SERVER['REMOTE_ADDR'] );
	} else {
		$_SERVER['REMOTE_ADDR'] = $remote;
	}
	$mbkCheck( 'accounts: 10 failures for one email from 10 IPs → locked (429) from any IP', array_fill( 0, 10, 401 ) === $spread && 429 === $locked->get_status() && 0 === get_current_user_id(), wp_json_encode( $spread ) );
	$acctLimits();
	$res = $acctApi( 'POST', '/account/login', array( 'email' => $acctEmail, 'password' => 'secret-pass-1', 'remember' => true ) );
	$mbkCheck( 'accounts: login → user + nonce, signed in', 200 === $res->get_status() && $userId === ( $res->get_data()['user']['id'] ?? 0 ) && get_current_user_id() === $userId && str_contains( (string) $res->get_data()['redirect'], 'moj-racun' ) );

	// A13. Forgotten password.
	wp_set_current_user( 0 );
	$before = count( $acctMails( 'account_password_reset' ) );
	$res    = $acctApi( 'POST', '/account/password/forgot', array( 'email' => 'nobody' . $domain ) );
	$jobs   = $acctRunMail();
	$mbkCheck( 'accounts: forgot unknown email → 200, one job (same as known), no email', 200 === $res->get_status() && true === $res->get_data()['sent'] && 1 === $jobs && count( $acctMails( 'account_password_reset' ) ) === $before );
	$res   = $acctApi( 'POST', '/account/password/forgot', array( 'email' => $acctEmail ) );
	$jobs  = $acctRunMail();
	$reset = $acctMails( 'account_password_reset', $acctEmail );
	preg_match( '/[?&](?:amp;|#038;)?login=([^&"]+)&(?:amp;|#038;)?key=([A-Za-z0-9]+)/', (string) ( end( $reset )['html'] ?? '' ), $rm );
	$mbkCheck( 'accounts: forgot → 200, one job → reset email with login & key', 200 === $res->get_status() && 1 === $jobs && count( $reset ) === $before + 1 && isset( $rm[2] ) );
	$res = $acctApi( 'POST', '/account/password/reset', array( 'login' => rawurldecode( $rm[1] ?? '' ), 'key' => $rm[2] ?? '', 'password' => 'new-pass-123' ) );
	$mbkCheck( 'accounts: reset → new password works', 200 === $res->get_status() && wp_check_password( 'new-pass-123', get_userdata( $userId )->user_pass, $userId ), wp_json_encode( $res->get_data() ) );
	$res = $acctApi( 'POST', '/account/password/reset', array( 'login' => rawurldecode( $rm[1] ?? '' ), 'key' => $rm[2] ?? '', 'password' => 'other-pass-123' ) );
	$mbkCheck( 'accounts: reset key is single use → 400 invalid_key', 400 === $res->get_status() && 'invalid_key' === ( $res->get_data()['code'] ?? '' ) );

	// A14. Password change while signed in.
	wp_set_current_user( $userId );
	$res = $acctApi( 'POST', '/account/password', array( 'current' => 'wrong', 'new' => 'third-pass-1' ) );
	$mbkCheck( 'accounts: change password with wrong current → 422', 422 === $res->get_status() && isset( $res->get_data()['data']['fields']['current'] ) );
	$res = $acctApi( 'POST', '/account/password', array( 'current' => 'new-pass-123', 'new' => 'third-pass-1' ) );
	$mbkCheck( 'accounts: change password → 200 + nonce, still signed in', 200 === $res->get_status() && '' !== (string) $res->get_data()['nonce'] && get_current_user_id() === $userId && wp_check_password( 'third-pass-1', get_userdata( $userId )->user_pass, $userId ) );

	// A15. Security review H1: the email cannot be switched; a switched email voids the verification.
	$victim = 'victim' . $domain;
	Container::guests()->upsertFromBooking(
		array(
			'email'      => $victim,
			'first_name' => 'Vic',
			'last_name'  => 'Tim',
			'phone'      => '+385 91 999 9999',
		),
		'hr'
	);
	$req = new WP_REST_Request( 'POST', '/wp/v2/users/me' );
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_body( (string) wp_json_encode( array( 'email' => $victim ) ) );
	$res = rest_do_request( $req );
	$mbkCheck( 'accounts: H1 — POST /wp/v2/users/me {email} as a guest → 403, email unchanged', 403 === $res->get_status() && $acctEmail === get_userdata( $userId )->user_email, $res->get_status() . ' ' . wp_json_encode( $res->get_data() ) );
	wp_update_user(
		array(
			'ID'         => $userId,
			'user_email' => $victim,
		)
	);
	$mbkCheck( 'accounts: H1 — a guest cannot change the email through wp_update_user either', $acctEmail === get_userdata( $userId )->user_email );
	$mbkCheck( 'accounts: H1 — no application passwords for guests', false === wp_is_application_passwords_available_for_user( get_userdata( $userId ) ) );

	// Staff changes the email: the verification no longer holds, nothing links to the new address.
	wp_set_current_user( $adminId );
	wp_update_user(
		array(
			'ID'         => $userId,
			'user_email' => $victim,
		)
	);
	wp_set_current_user( $userId );
	$acctLimits();
	$unitV = $free( '2028-05-29', '2028-06-02' );
	$res   = $acctApi(
		'POST',
		'/bookings',
		array(
			'unit_id'   => $unitV,
			'arrival'   => '2028-05-29',
			'departure' => '2028-06-02',
			'adults'    => 2,
			'children'  => 0,
			'guest'     => array(
				'first_name' => 'Anna',
				'last_name'  => 'Account',
				'email'      => $victim,
				'phone'      => '+49 30 1234567',
				'country'    => 'DE',
			),
			'terms'     => true,
		)
	);
	$hold        = Container::bookingRepository()->findByRef( (string) ( $res->get_data()['ref'] ?? '' ) );
	$victimGuest = Container::guestRepository()->findByEmail( $victim );
	$mbkCheck(
		'accounts: H1 — after an email change: unverified, checkout with that email links neither booking nor guest, no guest data',
		false === Container::accounts()->summary( get_userdata( $userId ) )['email_verified'] && null === Container::accounts()->checkoutUserId( $victim ) && 201 === $res->get_status() && null !== $hold && null === $hold['user_id'] && null === $victimGuest['user_id'] && null === Container::accounts()->guestFor( get_userdata( $userId ) ) && '' === $acctApi( 'GET', '/account/profile' )->get_data()['phone'],
		wp_json_encode( $res->get_data() )
	);
	Container::accounts()->runMembership();
	$mbkCheck( 'accounts: H1 — membership cron skips the account whose email changed', 1 === count( $acctMails( 'account_tier_up', $acctEmail ) ) && 0 === count( $acctMails( 'account_tier_up', $victim ) ) );

	// A16. Account deletion: the user goes, guest and bookings stay unlinked, manage links revoked.
	$res = $acctApi( 'POST', '/account/delete', array( 'password' => 'third-pass-1', 'confirm' => 'nope' ) );
	$mbkCheck( 'accounts: delete without DELETE → 422', 422 === $res->get_status() );
	$res    = $acctApi( 'POST', '/account/delete', array( 'password' => 'third-pass-1', 'confirm' => 'DELETE' ) );
	$guest  = Container::guestRepository()->find( (int) $guest['id'] );
	$linked = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t( 'mbk_bookings' )} WHERE user_id = %d", $userId ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	$mbkCheck( 'accounts: delete → user gone, guest + bookings kept without user, manage links revoked', 200 === $res->get_status() && false === get_userdata( $userId ) && null !== $guest && null === $guest['user_id'] && 0 === $linked && null !== Container::bookingRepository()->find( (int) $bookingA['id'] ) && 0 === get_current_user_id() && 0 === $manages(), wp_json_encode( $res->get_data() ) );
} finally {
	remove_filter( 'send_auth_cookies', '__return_false' );
	remove_filter( 'pre_schedule_event', $acctQueue, 10 );
	$acctJobs = array();
	remove_filter( 'mbk_payment_gateway', $acctUseFake, 99 );
	remove_filter( 'mbk_payment_gateway', $acctNoPay, 99 );
	wp_set_current_user( 0 );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( get_users( array( 'search' => '*' . $domain, 'search_columns' => array( 'user_email' ), 'fields' => 'ID' ) ) as $acctId ) {
		wp_delete_user( (int) $acctId );
	}
	$acctGuests = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t( 'mbk_guests' )} WHERE email LIKE %s", '%' . $wpdb->esc_like( $domain ) ) ) );
	if ( $acctGuests ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t( 'mbk_coupons' )} WHERE guest_id IN (" . Db::placeholders( $acctGuests ) . ')', $acctGuests ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_mbk\\_reg\\_%' OR option_name LIKE '\\_transient\\_timeout\\_mbk\\_reg\\_%' OR option_name LIKE 'mbk\\_reg\\_lock\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	// Account emails without a guest (verify, exists, reset) are logged without booking/guest.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$t( 'mbk_events' )} WHERE booking_id IS NULL AND guest_id IS NULL AND type IN ('email_sent', 'email_failed') AND payload_json LIKE %s", '%' . $wpdb->esc_like( $domain ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	$acctLimits();
	Container::reset();
}
