<?php
/**
 * Integration checks of the emails (phase 07), with every email captured by
 * run.php (`pre_wp_mail`, nothing is sent): lifecycle emails in the booking's
 * language, the daily scheduler (once even when run twice), disabled
 * templates, editor REST (save / 422 / reset / toggle / preview / test),
 * resend, guest email, payment link by email. Writes the rendered sample of
 * every template (HR + EN) to `.tools/email-previews/` for visual review.
 *
 * Included by run.php inside its try block (uses $mbkCheck, $mbkMails,
 * $mbkMailsOf, $units, $tz, $domain, $t, $adminId, $free; bookings and guests
 * are cleaned up there). Uses April 2028 stays (run.php's test price table).
 */

use Marclie\Booking\Container;
use Marclie\Booking\Domain\BookingStatus;
use Marclie\Booking\Domain\Stay;
use Marclie\Booking\Email\EmailRegistry;
use Marclie\Booking\Email\Mailer;
use Marclie\Booking\Email\MergeTags;
use Marclie\Booking\I18n\LanguageAdapter;
use Marclie\Booking\Repository\Db;
use Marclie\Booking\Settings;
use Marclie\Booking\Tests\Fakes\FakePaymentGateway;

defined( 'ABSPATH' ) || exit( 1 );

require_once dirname( __DIR__ ) . '/phpunit/Fakes/FakePaymentGateway.php';

$emailStart     = Db::nowUtc();
$emailSettings  = Settings::all()['notifications']['disabled_codes'];
// Checks run against the shipped defaults, all templates on; admin overrides are restored afterwards.
$emailRows = (array) $wpdb->get_results( "SELECT * FROM {$t( 'mbk_email_templates' )}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
$wpdb->query( "DELETE FROM {$t( 'mbk_email_templates' )}" ); // phpcs:ignore WordPress.DB.PreparedSQL
Settings::update( array( 'notifications' => array( 'disabled_codes' => array() ) ) );
$emailGateway   = static fn() => new FakePaymentGateway();
$adminApi       = static function ( string $method, string $route, array $body = array() ) use ( $adminId ): WP_REST_Response {
	wp_set_current_user( $adminId );
	$req = new WP_REST_Request( $method, '/marclie-booking/v1/admin' . $route );
	if ( $body ) {
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( (string) wp_json_encode( $body ) );
	}
	$res = rest_do_request( $req );
	wp_set_current_user( 0 );

	return $res;
};
$newBooking     = static function ( string $from, string $to, string $lang, string $local ) use ( $free, $tz, $domain ): array {
	$unitId = $free( $from, $to );
	if ( null === $unitId ) {
		throw new RuntimeException( "No unit free {$from} → {$to} for the email checks." );
	}
	$stay = Stay::fromStrings( $unitId, $from, $to, $tz );

	return Container::bookings()->create(
		$stay,
		Container::pricing()->quote( $stay, 2, 1 ),
		array(
			'email'      => $local . $domain,
			'first_name' => 'en' === $lang ? 'Emma' : 'Iva',
			'last_name'  => 'Mailtest',
		),
		$lang
	)['booking'];
};
$linkIn         = static function ( string $html, string $page ): array {
	preg_match( '#href="([^"]*/' . $page . '/[^"]*)"#', $html, $m );
	parse_str( (string) wp_parse_url( html_entity_decode( $m[1] ?? '' ), PHP_URL_QUERY ), $query );

	return $query;
};

try {
	// 38. Lifecycle in the booking language — the HR booking is confirmed while the request runs in EN.
	$hr = $newBooking( '2028-04-03', '2028-04-07', 'hr', 'mail.hr' );
	LanguageAdapter::withLanguage( 'en', static fn() => Container::bookings()->transition( (int) $hr['id'], BookingStatus::Confirmed, 'test' ) );
	$guestMail = $mbkMailsOf( (int) $hr['id'], 'guest_confirmed' )[0] ?? array();
	$adminMail = $mbkMailsOf( (int) $hr['id'], 'admin_new_booking' )[0] ?? array();
	$access    = $linkIn( (string) ( $guestMail['html'] ?? '' ), 'hvala' );
	$mbkCheck(
		'HR booking confirmed (request in EN) → guest_confirmed in HR to the guest, HR long date, working access link',
		'hr' === ( $guestMail['lang'] ?? '' ) && array( 'mail.hr' . $domain ) === $guestMail['to'] && str_starts_with( $guestMail['subject'], 'Potvrda rezervacije ' . $hr['ref'] )
			&& str_contains( $guestMail['html'], '3. travnja 2028.' ) && str_contains( $guestMail['html'], 'Iva' ) && null !== Container::bookings()->verifyToken( $hr['ref'], (string) ( $access['t'] ?? '' ), array( 'access' ) ) && ! isset( $access['lang'] ),
		wp_json_encode( array( $guestMail['subject'] ?? null, $guestMail['lang'] ?? null, $access ) )
	);
	$mbkCheck(
		'… multipart: text alternative with "label: value" rows and links as "text (url)"; From/Reply-To headers',
		str_contains( (string) $guestMail['text'], 'Broj rezervacije: ' . $hr['ref'] ) && (bool) preg_match( '#Pogledaj rezervaciju \(https?://[^)]*hvala[^)]*t=[0-9a-f]{64}\)#', (string) $guestMail['text'] )
			&& ! str_contains( (string) $guestMail['text'], '<' ) && (bool) preg_grep( '/^From: .+ <.+@.+>$/', $guestMail['headers'] ) && (bool) preg_grep( '/^Reply-To: .+@.+$/', $guestMail['headers'] ),
		(string) $guestMail['text']
	);
	$mbkCheck( '… admin_new_booking to the admin recipients (site language)', array() !== $adminMail && Mailer::adminRecipients() === $adminMail['to'] && Mailer::adminLang() === $adminMail['lang'] && str_contains( $adminMail['html'], (string) $hr['ref'] ) );
	$sentEvents = array_values( array_filter( Container::eventRepository()->forBooking( (int) $hr['id'] ), static fn( array $e ): bool => 'email_sent' === $e['type'] ) );
	$mbkCheck(
		'… email_sent events with code, lang, to, subject only (no body)',
		2 === count( $sentEvents ) && array( 'code', 'lang', 'to', 'subject' ) === array_keys( $sentEvents[0]['payload'] ) && 'guest_confirmed' === $sentEvents[0]['payload']['code'] && $sentEvents[0]['guest_id'] === (int) $hr['guest_id'],
		wp_json_encode( array_column( $sentEvents, 'payload' ) )
	);

	$en = $newBooking( '2028-04-10', '2028-04-14', 'en', 'mail.en' );
	Container::bookings()->transition( (int) $en['id'], BookingStatus::Confirmed, 'test' );
	$enMail = $mbkMailsOf( (int) $en['id'], 'guest_confirmed' )[0] ?? array();
	$enLink = $linkIn( (string) ( $enMail['html'] ?? '' ), 'hvala' );
	$mbkCheck(
		'EN booking → guest_confirmed in EN ("10 April 2028", "Apartment …", €), links with lang=en',
		'en' === ( $enMail['lang'] ?? '' ) && str_starts_with( $enMail['subject'], 'Booking confirmed: ' . $en['ref'] ) && str_contains( $enMail['html'], '10 April 2028' ) && str_contains( $enMail['html'], '€' ) && 'en' === ( $enLink['lang'] ?? '' ) && ! str_contains( $enMail['html'], 'travnja' ),
		wp_json_encode( array( $enMail['subject'] ?? null, $enLink ) )
	);

	// 39. Balance paid, disabled template, cancellation with refund.
	$before = count( $mbkMails );
	$res    = $adminApi( 'PATCH', '/email-templates/guest_balance_paid', array( 'enabled' => false ) );
	$mbkCheck( 'PATCH /email-templates/{code} enabled:false → stored in notifications.disabled_codes', 200 === $res->get_status() && false === $res->get_data()['enabled'] && in_array( 'guest_balance_paid', Settings::disabledEmailCodes(), true ) );
	Container::bookings()->transition( (int) $en['id'], BookingStatus::Paid, 'test' );
	$mbkCheck( 'disabled template → not sent (confirmed → paid sends nothing)', $before === count( $mbkMails ) && array() === $mbkMailsOf( (int) $en['id'], 'guest_balance_paid' ) );
	$res = $adminApi( 'POST', '/bookings/' . $en['id'] . '/emails/guest_balance_paid/resend' );
	$mbkCheck( 'resend of a disabled template still sends (explicit admin action)', 200 === $res->get_status() && true === ( $res->get_data()['sent'] ?? null ) && 1 === count( $mbkMailsOf( (int) $en['id'], 'guest_balance_paid' ) ) );
	$adminApi( 'PATCH', '/email-templates/guest_balance_paid', array( 'enabled' => true ) );

	Container::bookings()->transition( (int) $hr['id'], BookingStatus::Paid, 'test' );
	$paidMail = $mbkMailsOf( (int) $hr['id'], 'guest_balance_paid' )[0] ?? array();
	$mbkCheck( 'confirmed → paid → guest_balance_paid (HR)', 'hr' === ( $paidMail['lang'] ?? '' ) && str_contains( (string) $paidMail['subject'], 'u cijelosti plaćena' ) );

	$c = $newBooking( '2028-04-17', '2028-04-21', 'hr', 'mail.cancel' );
	$wpdb->update( $t( 'mbk_bookings' ), array( 'source' => 'admin', 'hold_expires_at' => null ), array( 'id' => $c['id'] ) );
	Container::bookings()->recordPayment( (int) $c['id'], 'deposit', (int) $c['deposit_cents'], 'bank', null, $adminId );
	$res        = $adminApi( 'POST', '/bookings/' . $c['id'] . '/cancel', array( 'refund_mode' => 'policy' ) );
	$cancelMail = $mbkMailsOf( (int) $c['id'], 'guest_cancelled' )[0] ?? array();
	$refundText = \Marclie\Booking\I18n\Formatter::price( (int) $c['deposit_cents'], 'hr' );
	$mbkCheck(
		'admin booking paid by bank → guest_confirmed (no admin_new_booking); cancel with refund → guest_cancelled shows the refund',
		200 === $res->get_status() && 1 === count( $mbkMailsOf( (int) $c['id'], 'guest_confirmed' ) ) && array() === $mbkMailsOf( (int) $c['id'], 'admin_new_booking' )
			&& str_contains( (string) ( $cancelMail['html'] ?? '' ), 'Povrat novca' ) && str_contains( (string) $cancelMail['html'], esc_html( $refundText ) ),
		wp_json_encode( array( $res->get_status(), array_column( $mbkMailsOf( (int) $c['id'] ), 'code' ), $refundText ) )
	);

	// 40. Daily scheduler: reminder 3 days before the balance date (saved card), pre-arrival 3 days before arrival; once.
	$s = $newBooking( '2028-04-24', '2028-04-28', 'en', 'mail.sched' );
	Container::bookings()->transition( (int) $s['id'], BookingStatus::Confirmed, 'test' );
	$wpdb->update(
		$t( 'mbk_bookings' ),
		array(
			'balance_due_on'           => '2028-04-10',
			'stripe_customer_id'       => 'cus_mbkit_mail',
			'stripe_payment_method_id' => 'pm_card_visa',
		),
		array( 'id' => $s['id'] )
	);
	$wpdb->insert(
		$t( 'mbk_payments' ),
		array(
			'booking_id'       => $s['id'],
			'kind'             => 'deposit',
			'method'           => 'stripe',
			'amount_cents'     => $s['deposit_cents'],
			'status'           => 'succeeded',
			'card_brand'       => 'visa',
			'card_last4'       => '4242',
			'stripe_intent_id' => 'pi_mbkit_mail_' . $s['id'],
			'created_at'       => Db::nowUtc(),
		)
	);
	$ids   = array( (int) $s['id'], (int) $hr['id'], (int) $en['id'] );
	$run1  = Container::emailScheduler()->run( '2028-04-07', $ids );
	$run1b = Container::emailScheduler()->run( '2028-04-07', $ids );
	$rem   = $mbkMailsOf( (int) $s['id'], 'guest_balance_reminder' );
	$mbkCheck(
		'scheduler 3 days before balance_due_on → guest_balance_reminder (EN, card •••• 4242) once, even when run twice',
		1 === count( $rem ) && 'en' === $rem[0]['lang'] && str_contains( $rem[0]['subject'], (string) $s['ref'] ) && str_contains( $rem[0]['html'], '4242' ) && str_contains( $rem[0]['html'], '10 April 2028' ) && array() === array_filter( array_column( $run1b, 'sent' ) ),
		wp_json_encode( array( $run1, $run1b ) )
	);
	// Another process holds the one-time claim → nothing is sent.
	$claimKey = Mailer::claimKey( 'guest_pre_arrival', (int) $s['id'] );
	$held     = Container::eventRepository()->claim( $claimKey, 'test_claim', 'test', (int) $s['id'], null, array( 'code' => 'held' ) );
	$heldRun  = Container::emailScheduler()->run( '2028-04-21', $ids );
	$wpdb->delete( $t( 'mbk_events' ), array( 'id' => $held ) );
	// A transport failure turns the claim into email_failed and releases it.
	$failMail = static fn(): bool => false;
	add_filter( 'pre_wp_mail', $failMail, 998 );
	Container::emailScheduler()->run( '2028-04-21', $ids );
	remove_filter( 'pre_wp_mail', $failMail, 998 );
	$failedPre = array_values( array_filter( Container::eventRepository()->forBooking( (int) $s['id'] ), static fn( array $e ): bool => 'email_failed' === $e['type'] && 'guest_pre_arrival' === ( $e['payload']['code'] ?? '' ) ) );
	$mbkCheck(
		'scheduler claim: held by another run → skipped; send failure → email_failed, claim released',
		$held > 0 && array( false ) === array_values( array_column( array_filter( $heldRun, static fn( array $r ): bool => (int) $r['booking_id'] === (int) $s['id'] ), 'sent' ) )
			&& 1 === count( $failedPre ) && null === $failedPre[0]['external_id'] && ! Container::eventRepository()->hasEmailSent( (int) $s['id'], 'guest_pre_arrival' ) && array() === $mbkMailsOf( (int) $s['id'], 'guest_pre_arrival' ),
		wp_json_encode( array( $heldRun, $failedPre ) )
	);
	Container::emailScheduler()->run( '2028-04-21', $ids );
	Container::emailScheduler()->run( '2028-04-21', $ids );
	Container::emailScheduler()->run( '2028-04-22', $ids );
	$pre = $mbkMailsOf( (int) $s['id'], 'guest_pre_arrival' );
	$mbkCheck(
		'scheduler 3 days before arrival → guest_pre_arrival once (three runs), in EN; earlier bookings untouched',
		1 === count( $pre ) && 'en' === $pre[0]['lang'] && str_contains( $pre[0]['html'], '24 April 2028' ) && array() === $mbkMailsOf( (int) $hr['id'], 'guest_pre_arrival' ),
		wp_json_encode( array_column( $mbkMailsOf( (int) $s['id'] ), 'code' ) )
	);
	Container::emailScheduler()->run( '2028-04-01', $ids );
	$mbkCheck( 'scheduler: HR booking arriving in 2 days → guest_pre_arrival in HR', 1 === count( $mbkMailsOf( (int) $hr['id'], 'guest_pre_arrival' ) ) && 'hr' === $mbkMailsOf( (int) $hr['id'], 'guest_pre_arrival' )[0]['lang'] );

	// 41. Resend + guest email + payment link by email.
	$res = $adminApi( 'POST', '/bookings/' . $hr['id'] . '/emails/guest_confirmed/resend' );
	$mbkCheck( 'POST /bookings/{id}/emails/guest_confirmed/resend → sent, second guest_confirmed, event logged', 200 === $res->get_status() && 2 === count( $mbkMailsOf( (int) $hr['id'], 'guest_confirmed' ) ) && Container::eventRepository()->hasEmailSent( (int) $hr['id'], 'guest_confirmed' ) );
	$res = $adminApi( 'POST', '/bookings/' . $hr['id'] . '/emails/admin_alert/resend' );
	$mbkCheck( 'resend of admin_alert → 422 validation', 422 === $res->get_status() && isset( $res->get_data()['data']['fields']['code'] ) );
	$res = $adminApi( 'POST', '/bookings/' . $hr['id'] . '/emails/guest_cancelled/resend' );
	$mbkCheck( 'resend of guest_cancelled on a paid booking → 422 not_applicable', 422 === $res->get_status() && 'not_applicable' === $res->get_data()['code'] );
	$detailC = $adminApi( 'GET', '/bookings/' . $c['id'] )->get_data();
	$detailE = $adminApi( 'GET', '/bookings/' . $en['id'] )->get_data();
	$mbkCheck(
		'BookingDetail.resendable_codes by status (cancelled → guest_cancelled; paid upcoming → confirmed, admin, paid, pre-arrival)',
		array( 'guest_cancelled' ) === $detailC['resendable_codes'] && array( 'guest_confirmed', 'admin_new_booking', 'guest_balance_paid', 'guest_pre_arrival' ) === $detailE['resendable_codes'],
		wp_json_encode( array( $detailC['resendable_codes'], $detailE['resendable_codes'] ) )
	);
	$wpdb->update( $t( 'mbk_bookings' ), array( 'email' => 'anonymized-0@invalid.invalid' ), array( 'id' => $c['id'] ) );
	$res = $adminApi( 'POST', '/bookings/' . $c['id'] . '/emails/guest_cancelled/resend' );
	$mbkCheck( 'resend to an invalid/anonymized address → 422 no_recipient, not in resendable_codes', 422 === $res->get_status() && 'no_recipient' === $res->get_data()['code'] && array() === $adminApi( 'GET', '/bookings/' . $c['id'] )->get_data()['resendable_codes'] );

	$guestId = (int) $hr['guest_id'];
	$res     = $adminApi(
		'POST',
		'/guests/' . $guestId . '/email',
		array(
			'subject'   => 'Pitanje o dolasku, {guest_first_name}',
			'body_html' => '<p>Dobar dan {guest_first_name},</p><p>Javite nam <strong>vrijeme dolaska</strong>.</p><script>x()</script>',
		)
	);
	$gm      = end( $mbkMails );
	$mbkCheck(
		'POST /guests/{id}/email → guest_message in the guest language, layout, kses, logged for the guest',
		200 === $res->get_status() && 'guest_message' === $gm['code'] && 'hr' === $gm['lang'] && 'Pitanje o dolasku, Iva' === $gm['subject'] && str_contains( $gm['html'], 'Dobar dan Iva' ) && ! str_contains( $gm['html'], '<script' )
			&& 1 === count( array_filter( Container::eventRepository()->forGuest( $guestId ), static fn( array $e ): bool => 'email_sent' === $e['type'] && 'guest_message' === $e['payload']['code'] ) ),
		wp_json_encode( $res->get_data() )
	);

	add_filter( 'mbk_payment_gateway', $emailGateway, 99 );
	Container::reset();
	$link = $newBooking( '2028-05-01', '2028-05-05', 'en', 'mail.link' );
	Container::bookings()->recordPayment( (int) $link['id'], 'deposit', (int) $link['deposit_cents'], 'cash', null, $adminId );
	$res  = $adminApi( 'POST', '/bookings/' . $link['id'] . '/payment-link', array( 'send_email' => true ) );
	$lm   = $mbkMailsOf( (int) $link['id'], 'guest_payment_link' )[0] ?? array();
	$mbkCheck(
		'payment link with send_email → email_sent:true, guest_payment_link (EN) carries exactly the created URL',
		200 === $res->get_status() && true === $res->get_data()['email_sent'] && 'en' === ( $lm['lang'] ?? '' ) && str_contains( (string) $lm['html'], esc_html( (string) $res->get_data()['url'] ) ),
		wp_json_encode( $res->get_data() )
	);
	$res = $adminApi( 'POST', '/bookings/' . $link['id'] . '/payment-link', array() );
	$mbkCheck( 'payment link without send_email → email_sent:false, no email', 200 === $res->get_status() && false === $res->get_data()['email_sent'] && 1 === count( $mbkMailsOf( (int) $link['id'], 'guest_payment_link' ) ) );
	remove_filter( 'mbk_payment_gateway', $emailGateway, 99 );
	Container::reset();

	// 42. Editor REST: save with missing tag → 422, save, preview, test, reset.
	$res = $adminApi( 'PUT', '/email-templates/guest_confirmed/en', array( 'subject' => 'Your stay', 'body_html' => '<p>Hello</p>' ) );
	$mbkCheck( 'PUT without {booking_ref} → 422 missing_required_tags (fields.body_html, missing[])', 422 === $res->get_status() && 'missing_required_tags' === $res->get_data()['code'] && array( 'booking_ref' ) === $res->get_data()['data']['missing'] && isset( $res->get_data()['data']['fields']['body_html'] ) );
	$res = $adminApi( 'PUT', '/email-templates/guest_confirmed/en', array( 'subject' => '' ) );
	$mbkCheck( 'PUT without body → 422 validation', 422 === $res->get_status() && 'validation' === $res->get_data()['code'] );

	$res   = $adminApi(
		'PUT',
		'/email-templates/guest_confirmed/en',
		array(
			'subject'   => 'Custom {booking_ref} for {guest_first_name}',
			'preheader' => 'Pre',
			'heading'   => 'Hi {guest_first_name}',
			'body_html' => '<p onclick="x()">Ref {booking_ref}</p><p>{booking_summary}</p><img src="x"><a href="javascript:alert(1)">bad</a>',
		)
	);
	$saved = $res->get_data();
	$mbkCheck(
		'PUT saves the override (kses: no onclick/img/javascript:), detail shows customized',
		200 === $res->get_status() && true === $saved['en']['customized'] && '<p>Ref {booking_ref}</p><p>{booking_summary}</p><a href="alert(1)">bad</a>' === $saved['en']['body_html'] && false === $saved['hr']['customized'],
		wp_json_encode( $saved['en'] ?? $saved )
	);
	$res = $adminApi( 'PUT', '/email-templates/guest_confirmed/hr', array( 'subject' => '{booking_ref}', 'body_html' => '<ul><li><p>Jedan</p></li><li><p>Dva</p></li></ul>' ) );
	$mbkCheck( 'PUT unwraps editor list paragraphs (<li><p>…</p></li> → <li>…</li>)', 200 === $res->get_status() && '<ul><li>Jedan</li><li>Dva</li></ul>' === $res->get_data()['hr']['body_html'], wp_json_encode( $res->get_data()['hr']['body_html'] ?? $res->get_data() ) );
	$adminApi( 'DELETE', '/email-templates/guest_confirmed/hr' );
	$list = $adminApi( 'GET', '/email-templates' )->get_data();
	$row  = array_values( array_filter( $list, static fn( array $r ): bool => 'guest_confirmed' === $r['code'] ) )[0] ?? array();
	$mbkCheck( 'GET /email-templates → 11 codes, langs.en.customized with updated_at', count( EmailRegistry::codes() ) === count( $list ) && true === $row['langs']['en']['customized'] && null !== $row['langs']['en']['updated_at'] && false === $row['langs']['hr']['customized'] );

	$adminApi( 'POST', '/bookings/' . $en['id'] . '/emails/guest_confirmed/resend' );
	$custom = $mbkMailsOf( (int) $en['id'], 'guest_confirmed' );
	$mbkCheck( 'resend uses the saved EN override', 'Custom ' . $en['ref'] . ' for Emma' === ( end( $custom )['subject'] ?? '' ) );

	$tokensBefore = count( Container::tokenRepository()->forBooking( (int) $hr['id'] ) );
	$res          = $adminApi(
		'POST',
		'/email-templates/guest_confirmed/preview',
		array(
			'lang'       => 'hr',
			'booking_id' => (int) $hr['id'],
			'draft'      => array(
				'subject'   => 'Nacrt {booking_ref} · {unit_name}',
				'preheader' => '',
				'heading'   => '{guest_first_name}',
				'body_html' => '<p>Dolazak {arrival_date}, ukupno {total}, {unknown_tag}</p><p>{payment_table}</p><p><a href="{manage_link}">link</a></p>',
			),
		)
	);
	$pv  = $res->get_data();
	$mbkCheck(
		'preview (draft + real booking) contains the merge values, unknown tags untouched, inert link (no token issued)',
		200 === $res->get_status() && 'Nacrt ' . $hr['ref'] . ' · ' . Container::units()->get( (int) $hr['unit_id'] )->title === $pv['subject'] && str_contains( $pv['html'], '3. travnja 2028.' )
			&& str_contains( $pv['html'], '{unknown_tag}' ) && str_contains( $pv['html'], 'Ukupno' ) && str_contains( $pv['html'], 't=preview' ) && str_contains( $pv['text'], 'Iva' ) && $tokensBefore === count( Container::tokenRepository()->forBooking( (int) $hr['id'] ) ),
		wp_json_encode( array( $pv['subject'] ?? $pv, $res->get_status() ) )
	);
	$res = $adminApi( 'POST', '/email-templates/guest_balance_reminder/preview', array( 'lang' => 'en' ) );
	$mbkCheck( 'preview of the saved/default template with the sample booking', 200 === $res->get_status() && str_contains( $res->get_data()['subject'], \Marclie\Booking\Settings::refPrefix() . '-27-0712-101' ) && str_contains( $res->get_data()['html'], '28 June 2027' ) && str_contains( $res->get_data()['html'], '•••• 4242' ) );

	$before = count( $mbkMails );
	$res    = $adminApi( 'POST', '/email-templates/guest_cancelled/test', array( 'lang' => 'en', 'to' => 'qa.test' . $domain ) );
	$test   = end( $mbkMails );
	$mbkCheck(
		'POST /email-templates/{code}/test → sent:true, one email to the given address, "[Test]" subject, not logged on a booking',
		200 === $res->get_status() && true === $res->get_data()['sent'] && $before + 1 === count( $mbkMails ) && array( 'qa.test' . $domain ) === $test['to'] && str_starts_with( $test['subject'], '[Test] Booking ' . \Marclie\Booking\Settings::refPrefix() . '-27-0712-101 has been cancelled' ) && null === $test['booking_id'],
		wp_json_encode( array( $res->get_data(), $test['subject'] ?? null ) )
	);
	$res = $adminApi( 'POST', '/email-templates/nope/test', array( 'lang' => 'en', 'to' => 'qa.test' . $domain ) );
	$mbkCheck( 'unknown template code → 404 not_found', 404 === $res->get_status() );
	$testEvent = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t( 'mbk_events' )} WHERE type = 'email_test_sent' AND created_at >= %s ORDER BY id DESC LIMIT 1", $emailStart ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	$testLoad  = json_decode( (string) ( $testEvent['payload_json'] ?? '' ), true );
	$mbkCheck( 'test-send event logs code, lang, to, booking_id — no subject', is_array( $testLoad ) && array( 'code', 'lang', 'to', 'booking_id' ) === array_keys( $testLoad ) && null === $testLoad['booking_id'], (string) ( $testEvent['payload_json'] ?? '' ) );
	$res = $adminApi( 'POST', '/email-templates/guest_confirmed/test', array( 'lang' => 'hr', 'to' => 'qa.test' . $domain, 'booking_id' => (int) $hr['id'] ) );
	$mbkCheck( 'test with a real booking to an outside address → 422 validation (to)', 422 === $res->get_status() && isset( $res->get_data()['data']['fields']['to'] ) );
	$res = $adminApi( 'POST', '/email-templates/guest_confirmed/test', array( 'lang' => 'hr', 'to' => Mailer::adminRecipients()[0], 'booking_id' => (int) $hr['id'] ) );
	$mbkCheck( 'test with a real booking to an admin recipient → sent', 200 === $res->get_status() && true === $res->get_data()['sent'], wp_json_encode( $res->get_data() ) );

	$res = $adminApi( 'DELETE', '/email-templates/guest_confirmed/en' );
	$mbkCheck( 'DELETE /email-templates/{code}/{lang} → back to the shipped default', 200 === $res->get_status() && false === $res->get_data()['en']['customized'] && 'Booking confirmed: {booking_ref} · {unit_name}' === $res->get_data()['en']['subject'] );

	// 43. Every template renders in HR and EN without leftover known tags; samples saved for visual review.
	$dir = null;
	foreach ( array( dirname( ABSPATH ) . '/.tools/email-previews', wp_upload_dir()['basedir'] . '/mbk-email-previews', sys_get_temp_dir() . '/mbk-email-previews' ) as $candidate ) {
		if ( wp_mkdir_p( $candidate ) && wp_is_writable( $candidate ) ) {
			$dir = $candidate;
			break;
		}
	}
	$texts    = array();
	$problems = array();
	$index    = '';
	foreach ( EmailRegistry::codes() as $code ) {
		foreach ( array( 'hr', 'en' ) as $lang ) {
			$p    = Container::mailer()->preview( $code, $lang, null, null );
			$left = array_intersect( MergeTags::used( $p['subject'] . $p['html'] . $p['text'] ), array_merge( MergeTags::ALL, EmailRegistry::BLOCKS ) );
			if ( $left || '' === $p['subject'] ) {
				$problems[] = $code . '.' . $lang . ': ' . implode( ',', $left );
			}
			$texts[ "{$code}.{$lang}" ] = $p['subject'] . "\n\n" . $p['text'] . "\n";
			if ( null !== $dir ) {
				file_put_contents( "{$dir}/{$code}.{$lang}.html", $p['html'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( "{$dir}/{$code}.{$lang}.txt", $texts[ "{$code}.{$lang}" ] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
			$index .= sprintf( '<li><a href="%1$s.%2$s.html">%1$s · %2$s</a> — %3$s</li>', esc_attr( $code ), $lang, esc_html( $p['subject'] ) );
		}
	}
	if ( null !== $dir ) {
		file_put_contents( "{$dir}/index.html", '<!DOCTYPE html><meta charset="utf-8"><title>Email previews</title><ul>' . $index . '</ul>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
	$mbkCheck( 'all 11 templates × HR/EN render with every merge tag filled (previews in ' . ( $dir ?? 'none: no writable dir' ) . ')', array() === $problems, implode( '; ', $problems ) );
	$freeLabel = static fn( string $lang ): string => LanguageAdapter::withLanguage( $lang, static fn(): string => __( 'Free cancellation', 'marclie-booking' ) );
	$mbkCheck(
		'free-cancellation row only while it applies (sample: confirmation yes, balance reminder / pre-arrival no)',
		str_contains( $texts['guest_confirmed.hr'], $freeLabel( 'hr' ) ) && ! str_contains( $texts['guest_balance_reminder.en'], $freeLabel( 'en' ) ) && ! str_contains( $texts['guest_pre_arrival.hr'], $freeLabel( 'hr' ) )
	);
} finally {
	remove_filter( 'mbk_payment_gateway', $emailGateway, 99 );
	$wpdb->query( "DELETE FROM {$t( 'mbk_email_templates' )}" ); // phpcs:ignore WordPress.DB.PreparedSQL
	foreach ( $emailRows as $row ) {
		$wpdb->insert( $t( 'mbk_email_templates' ), $row );
	}
	Settings::update( array( 'notifications' => array( 'disabled_codes' => $emailSettings ) ) );
	// Events without a booking/guest written by these checks (template edits, test sends).
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$t( 'mbk_events' )} WHERE booking_id IS NULL AND guest_id IS NULL AND type IN ('email_template_changed', 'email_test_sent', 'email_failed') AND created_at >= %s", $emailStart ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	Container::reset();
}
