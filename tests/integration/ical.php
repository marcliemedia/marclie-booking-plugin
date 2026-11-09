<?php
/**
 * Integration checks of the iCal sync (phase 11) with mocked OTA feeds: every
 * request to https://ical.mbk-test.invalid/… is answered by a `pre_http_request`
 * filter from tests/fixtures/ical/*.ics or generated calendars (ETag → 304,
 * HTTP errors, network errors, redirects, gzip, delays). The host "resolves"
 * through the `mbk_ical_resolve_host` filter. Nothing leaves the machine.
 *
 * Included by run.php inside its try block (uses $mbkCheck, $mbkMails,
 * $mbkMailsOf, $mbkBlockIds, $units, $tz, $domain, $t, $adminId; bookings and
 * guests are cleaned up there). Uses one unit without feeds, September 2027 and
 * April 2028 (inside the export window today −30 days … +18 months). Removes
 * its feeds, imported blocks, events, locks and follow-up cron events, and
 * restores the unit's export secret and export-fetch times.
 */

use Marclie\Booking\Container;
use Marclie\Booking\Cron;
use Marclie\Booking\Domain\BookingStatus;
use Marclie\Booking\Domain\Stay;
use Marclie\Booking\Ical\ConflictDetector;
use Marclie\Booking\Ical\Exporter;
use Marclie\Booking\Ical\Parser;
use Marclie\Booking\Ical\SyncService;
use Marclie\Booking\Repository\Db;
use Marclie\Booking\Service\AvailabilityCache;
use Marclie\Booking\Tests\Fakes\FakePaymentGateway;

defined( 'ABSPATH' ) || exit( 1 );

require_once dirname( __DIR__ ) . '/phpunit/Fakes/FakePaymentGateway.php';

$icalHost    = 'ical.mbk-test.invalid';
$icalPrivate = 'ical-private.mbk-test.invalid';
$icalUrl     = static fn( string $name, string $host = 'ical.mbk-test.invalid' ): string => 'https://' . $host . '/' . $name . '.ics';
$icalFixture = static fn( string $name ): string => (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/ical/' . $name );
/** Calendar from [uid, from, to, summary] rows (CRLF, DATE values). */
$icalCalendar = static function ( array $rows, string $prodId = '-//Airbnb Inc//Hosting Calendar 0.8.8//EN' ): string {
	$out = "BEGIN:VCALENDAR\r\nPRODID:{$prodId}\r\nCALSCALE:GREGORIAN\r\nVERSION:2.0\r\n";
	foreach ( $rows as [ $uid, $from, $to, $summary ] ) {
		$out .= "BEGIN:VEVENT\r\nDTEND;VALUE=DATE:" . str_replace( '-', '', $to ) . "\r\nDTSTART;VALUE=DATE:" . str_replace( '-', '', $from ) . "\r\nUID:{$uid}\r\nSUMMARY:{$summary}\r\nEND:VEVENT\r\n";
	}

	return $out . "END:VCALENDAR\r\n";
};

// DNS for the mock hosts: a public address, and ::1 for the "private" host.
$icalResolve = static function ( $ips, $host ) use ( $icalHost, $icalPrivate ) {
	if ( $icalHost === $host ) {
		return array( '93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946' );
	}

	return $icalPrivate === $host ? array( '93.184.216.34', '::1' ) : $ips;
};
add_filter( 'mbk_ical_resolve_host', $icalResolve, 10, 2 );

/** @var array<string, array{status?:int, body?:string, etag?:string, error?:string, location?:string, delay?:float, gzip?:bool}> $icalMock */
$icalMock = array();
$icalLog  = array();
$icalHttp = static function ( $pre, $args, $url ) use ( &$icalMock, &$icalLog, $icalHost ) {
	if ( wp_parse_url( (string) $url, PHP_URL_HOST ) !== $icalHost ) {
		return $pre;
	}
	$name      = basename( (string) wp_parse_url( (string) $url, PHP_URL_PATH ), '.ics' );
	$headers   = array_change_key_case( (array) ( $args['headers'] ?? array() ) );
	$icalLog[] = array(
		'name'            => $name,
		'timeout'         => (float) ( $args['timeout'] ?? 0 ),
		'redirection'     => (int) ( $args['redirection'] ?? -1 ),
		'if_none_match'   => $headers['if-none-match'] ?? null,
		'accept_encoding' => $headers['accept-encoding'] ?? null,
	);
	$mock = $icalMock[ $name ] ?? array(
		'status' => 404,
		'body'   => 'Not found',
	);
	if ( isset( $mock['delay'] ) ) {
		usleep( (int) ( $mock['delay'] * 1000000 ) );
	}
	if ( isset( $mock['error'] ) ) {
		return new WP_Error( 'http_request_failed', $mock['error'] );
	}
	$etag   = (string) ( $mock['etag'] ?? '' );
	$status = '' !== $etag && ( $headers['if-none-match'] ?? '' ) === $etag ? 304 : (int) ( $mock['status'] ?? 200 );
	$body   = 304 === $status ? '' : (string) ( $mock['body'] ?? '' );

	return array(
		'headers'  => array_filter(
			array(
				'etag'             => $etag,
				'location'         => (string) ( $mock['location'] ?? '' ),
				'content-type'     => 'text/calendar; charset=utf-8',
				'content-encoding' => ! empty( $mock['gzip'] ) ? 'gzip' : '',
			)
		),
		'body'     => ! empty( $mock['gzip'] ) ? (string) gzencode( $body ) : $body,
		'response' => array(
			'code'    => $status,
			'message' => '',
		),
		'cookies'  => array(),
		'filename' => null,
	);
};
add_filter( 'pre_http_request', $icalHttp, 10, 3 );

$icalApi = static function ( string $method, string $route, ?array $body = null, array $query = array(), array $headers = array() ): WP_REST_Response {
	$req = new WP_REST_Request( $method, '/marclie-booking/v1' . $route );
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
$icalResetLimits = static function () use ( $wpdb ): void {
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_mbk\\_rl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_mbk\\_rl\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	wp_cache_flush();
};
$icalFake        = new FakePaymentGateway();
$icalUseFake     = static fn() => $icalFake;
$icalFeedIds     = array();
$icalPrevUser    = get_current_user_id();
$icalUnit        = null;
$icalSecretRow   = null;
$icalFetchRows   = array();
$icalBlocksOf    = static fn( int $feedId ): array => Container::icalFeeds()->blocksOfFeed( $feedId );
$icalBlockId     = static fn( int $feedId, string $blockUid ): int => (int) ( array_column( Container::icalFeeds()->blocksOfFeed( $feedId ), 'id', 'uid' )[ $blockUid ] ?? 0 );
$icalOrigin      = static fn( int $feedId, string $blockUid ): string => (string) ( array_column( Container::icalFeeds()->blocksOfFeed( $feedId ), 'origin', 'uid' )[ $blockUid ] ?? '' );
$icalAdminAlerts = static function () use ( &$mbkMails ): array {
	return array_values( array_filter( $mbkMails, static fn( array $m ): bool => 'admin_alert' === $m['code'] && null === $m['booking_id'] ) );
};
$icalAlerts = static fn( string $type ): array => array_values( array_filter( Container::dashboard()->build( gmdate( 'Y-m' ) )['alerts'], static fn( array $a ): bool => $type === $a['type'] ) );
$icalUtcAgo = static fn( int $seconds ): string => gmdate( 'Y-m-d H:i:s', time() - $seconds );

try {
	// A unit without feeds, free in the test ranges.
	foreach ( array_reverse( $units ) as $candidate ) {
		if ( ! Container::icalFeeds()->forUnit( $candidate->id ) && array() === Container::availability()->occupiedNights( $candidate->id, '2027-09-01', '2027-10-02' ) && array() === Container::availability()->occupiedNights( $candidate->id, '2028-04-01', '2028-04-30' ) ) {
			$icalUnit = $candidate;
			break;
		}
	}
	if ( null === $icalUnit ) {
		throw new RuntimeException( 'iCal: no unit without feeds is free in September 2027 / April 2028.' );
	}
	$uid           = $icalUnit->id;
	$icalSecretRow = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t( 'mbk_ical_exports' )} WHERE unit_id = %d", $uid ), ARRAY_A );
	$icalFetchRows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t( 'mbk_ical_export_fetches' )} WHERE unit_id = %d", $uid ), ARRAY_A );
	$wpdb->delete( $t( 'mbk_ical_export_fetches' ), array( 'unit_id' => $uid ) );
	wp_set_current_user( $adminId );

	// 0. Atomic lock (Db::claimLock): one holder; an expired lease is taken over; release only by the holder.
	$lockName = 'mbk_ical_lock_test_' . wp_generate_password( 6, false );
	$claimA   = Db::claimLock( $lockName, 60 );
	$claimB   = Db::claimLock( $lockName, 60 );
	Db::releaseLock( $lockName, 'not-the-token' );
	$stillHeld = null === Db::claimLock( $lockName, 60 );
	$wpdb->update( $wpdb->options, array( 'option_value' => '1:expired' ), array( 'option_name' => $lockName ) );
	$claimC = Db::claimLock( $lockName, 60 );
	Db::releaseLock( $lockName, (string) $claimC );
	$mbkCheck( 'ical: atomic lock — second claim refused, foreign release ignored, expired lease taken over, released', null !== $claimA && null === $claimB && $stillHeld && null !== $claimC && $claimC !== $claimA && null === $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $lockName ) ) );

	// 1. Adding a feed validates the URL with a test fetch (https, public address, redirects, size).
	$res = $icalApi( 'POST', '/admin/ical/feeds', array( 'unit_id' => $uid, 'channel' => 'airbnb', 'url' => 'http://' . $icalHost . '/airbnb.ics' ) );
	$mbkCheck( 'ical: POST feed with http:// URL → 422 invalid_url', 422 === $res->get_status() && 'mbk_ical_invalid' === ( $res->get_data()['code'] ?? '' ) && 'invalid_url' === ( $res->get_data()['data']['reason'] ?? '' ) );
	$icalMock['login'] = array( 'body' => '<!DOCTYPE html><html><body>Sign in</body></html>' );
	$res               = $icalApi( 'POST', '/admin/ical/feeds', array( 'unit_id' => $uid, 'channel' => 'airbnb', 'url' => $icalUrl( 'login' ) ) );
	$mbkCheck( 'ical: POST feed returning HTML → 422 not_ical, nothing stored', 422 === $res->get_status() && 'not_ical' === ( $res->get_data()['data']['reason'] ?? '' ) && ! Container::icalFeeds()->forUnit( $uid ) );
	$res = $icalApi( 'POST', '/admin/ical/feeds', array( 'unit_id' => $uid, 'channel' => 'airbnb', 'url' => $icalUrl( 'missing' ) ) );
	$mbkCheck( 'ical: POST feed answering 404 → 422 http_status', 422 === $res->get_status() && 'http_status' === ( $res->get_data()['data']['reason'] ?? '' ) );
	$res = $icalApi( 'POST', '/admin/ical/feeds', array( 'unit_id' => $uid, 'channel' => 'airbnb', 'url' => $icalUrl( 'x', $icalPrivate ) ) );
	$mbkCheck( 'ical: host resolving to ::1 (AAAA) → 422 private_address, nothing fetched', 422 === $res->get_status() && 'private_address' === ( $res->get_data()['data']['reason'] ?? '' ) );
	$res = $icalApi( 'POST', '/admin/ical/feeds', array( 'unit_id' => $uid, 'channel' => 'airbnb', 'url' => 'https://nowhere.mbk-test.invalid/x.ics' ) );
	$mbkCheck( 'ical: host that does not resolve → 422 dns', 422 === $res->get_status() && 'dns' === ( $res->get_data()['data']['reason'] ?? '' ) );
	$icalMock['to-http'] = array(
		'status'   => 302,
		'location' => 'http://' . $icalHost . '/airbnb.ics',
	);
	$res                 = $icalApi( 'POST', '/admin/ical/feeds', array( 'unit_id' => $uid, 'channel' => 'airbnb', 'url' => $icalUrl( 'to-http' ) ) );
	$mbkCheck( 'ical: redirect to http:// → 422 insecure_redirect', 422 === $res->get_status() && 'insecure_redirect' === ( $res->get_data()['data']['reason'] ?? '' ) );
	$many                = array();
	for ( $i = 0; $i <= 2000; $i++ ) {
		$day    = ( new DateTimeImmutable( '2028-01-01' ) )->modify( "+{$i} days" );
		$many[] = array( "many-{$i}@example.com", $day->format( 'Y-m-d' ), $day->modify( '+1 day' )->format( 'Y-m-d' ), 'Busy' );
	}
	$icalMock['huge'] = array( 'body' => $icalCalendar( $many ) );
	$res              = $icalApi( 'POST', '/admin/ical/feeds', array( 'unit_id' => $uid, 'channel' => 'airbnb', 'url' => $icalUrl( 'huge' ) ) );
	$mbkCheck( 'ical: more than 2000 events → 422 too_many_events', 422 === $res->get_status() && 'too_many_events' === ( $res->get_data()['data']['reason'] ?? '' ) && ! Container::icalFeeds()->forUnit( $uid ) );
	unset( $icalMock['huge'], $many );

	// 2. Airbnb fixture: 3 future events imported as blocks.
	$icalMock['airbnb'] = array(
		'body' => $icalFixture( 'airbnb.ics' ),
		'etag' => '"airbnb-v1"',
	);
	$icalLog            = array();
	$res                = $icalApi( 'POST', '/admin/ical/feeds', array( 'unit_id' => $uid, 'channel' => 'airbnb', 'url' => $icalUrl( 'airbnb' ) ) );
	$airbnb             = $res->get_data();
	$airbnbId           = (int) ( $airbnb['id'] ?? 0 );
	$icalFeedIds[]      = $airbnbId;
	$mbkCheck(
		'ical: POST airbnb feed → 201, 3 events imported (past one skipped), 10 s deadline, identity encoding, no auto-redirects',
		201 === $res->get_status() && 3 === ( $airbnb['events_count'] ?? null ) && 3 === count( $icalBlocksOf( $airbnbId ) ) && 'airbnb' === $airbnb['channel'] && $icalUrl( 'airbnb' ) === ( $airbnb['url'] ?? '' )
			&& ( $icalLog[0]['timeout'] ?? 0 ) > 9 && ( $icalLog[0]['timeout'] ?? 0 ) <= 10 && 'identity' === ( $icalLog[0]['accept_encoding'] ?? '' ) && 0 === ( $icalLog[0]['redirection'] ?? -1 )
			&& str_starts_with( (string) $airbnb['url_masked'], 'https://' . $icalHost . '/…' ) && null === $airbnb['last_error'] && array( 'external' ) === array_values( array_unique( array_column( $icalBlocksOf( $airbnbId ), 'origin' ) ) ),
		wp_json_encode( array( $airbnb, $icalLog ) )
	);
	$res = $icalApi( 'POST', '/admin/ical/feeds', array( 'unit_id' => $uid, 'channel' => 'airbnb', 'url' => $icalUrl( 'airbnb' ) ) );
	$mbkCheck( 'ical: second airbnb feed for the unit → 409 ical_feed_exists', 409 === $res->get_status() && 'ical_feed_exists' === ( $res->get_data()['code'] ?? '' ) );

	$occupied = Container::availability()->occupiedNights( $uid, '2028-04-01', '2028-04-30' );
	$mbkCheck(
		'ical: imported nights blocked in availability (end exclusive)',
		array( '2028-04-04', '2028-04-05', '2028-04-06', '2028-04-07', '2028-04-18', '2028-04-19', '2028-04-25', '2028-04-26', '2028-04-27' ) === $occupied,
		implode( ',', $occupied )
	);
	$mbkCheck( 'ical: public calendar cache shows the imported nights', in_array( '2028-04-05', Container::availabilityCache()->range( $uid, '2028-04', 1 )['occupied'], true ) );

	// 3. Checkout on imported nights → 409.
	add_filter( 'mbk_payment_gateway', $icalUseFake, 99 );
	Container::reset();
	$checkout = static fn( string $from, string $to, string $email ): array => array(
		'unit_id'      => $uid,
		'arrival'      => $from,
		'departure'    => $to,
		'adults'       => 2,
		'children'     => 0,
		'guest'        => array(
			'first_name' => 'Iva',
			'last_name'  => 'Ical',
			'email'      => $email . $domain,
			'phone'      => '+385 91 333 4444',
			'country'    => 'HR',
		),
		'arrival_time' => '',
		'message'      => '',
		'terms'        => true,
		'website'      => '',
	);
	$icalResetLimits();
	$res = $icalApi( 'POST', '/bookings', $checkout( '2028-04-04', '2028-04-08', 'ical.blocked' ) );
	$mbkCheck( 'ical: POST /bookings on Airbnb-blocked nights → 409 unavailable', 409 === $res->get_status() && 'unavailable' === ( $res->get_data()['code'] ?? '' ), wp_json_encode( $res->get_data() ) );

	// 4. GET /admin/ical shape.
	$res   = $icalApi( 'GET', '/admin/ical' );
	$row   = array_values( array_filter( (array) ( $res->get_data()['units'] ?? array() ), static fn( array $u ): bool => $u['unit_id'] === $uid ) )[0] ?? array();
	$query = array();
	wp_parse_str( (string) wp_parse_url( (string) ( $row['export']['airbnb'] ?? '' ), PHP_URL_QUERY ), $query );
	$mbkCheck(
		'ical: GET /admin/ical → unit with export URLs per channel, feed with url, cron 15 min',
		200 === $res->get_status() && count( $res->get_data()['units'] ) === count( $units ) && $icalUnit->code === $row['code'] && array( 'airbnb', 'booking', 'other' ) === array_keys( $row['export'] )
			&& 'airbnb' === ( $query['for'] ?? '' ) && 43 === strlen( (string) ( $query['k'] ?? '' ) ) && str_contains( $row['export']['airbnb'], '/ical/' . rawurlencode( $icalUnit->code ) . '.ics' )
			&& 1 === count( $row['feeds'] ) && $airbnbId === $row['feeds'][0]['id'] && isset( $row['feeds'][0]['url'] ) && 15 === $res->get_data()['cron']['interval_min'] && array_key_exists( 'airbnb_listing_id', $row ),
		wp_json_encode( $row )
	);
	$secret = (string) $query['k'];
	$route  = '/ical/' . $icalUnit->code . '.ics';
	$export = static function ( string $for, array $headers = array() ) use ( $icalApi, $icalResetLimits, $route, &$secret ): WP_REST_Response {
		$icalResetLimits();

		return $icalApi( 'GET', $route, null, array( 'k' => $secret, 'for' => $for ), $headers );
	};

	// 5. 304: unchanged feed is not re-imported.
	$before  = $icalBlocksOf( $airbnbId );
	$icalLog = array();
	$res     = $icalApi( 'POST', '/admin/ical/feeds/' . $airbnbId . '/sync' );
	$mbkCheck(
		'ical: sync with ETag → If-None-Match sent, 304 not_modified, blocks unchanged',
		200 === $res->get_status() && 'not_modified' === $res->get_data()['status'] && true === $res->get_data()['ok'] && '"airbnb-v1"' === ( $icalLog[0]['if_none_match'] ?? null ) && $before === $icalBlocksOf( $airbnbId ) && 'not_modified' === $res->get_data()['feed']['last_status'],
		wp_json_encode( $res->get_data() )
	);

	// 6. Feed update: a vanished UID is deleted, a moved one updated in place.
	$kept               = array_column( $before, 'id', 'uid' );
	$airbnbRows         = array(
		array( '1418fb94e984-5d3b2a1c9e8f7d6c5b4a3928170f6e5d@airbnb.com', '2028-04-04', '2028-04-08', 'Reserved' ),
		array( '7f3e2d1c0b9a-airbnb-blocked-20280418@airbnb.com', '2028-04-18', '2028-04-21', 'Airbnb (Not available)' ),
	);
	$icalMock['airbnb'] = array(
		'body' => $icalCalendar( $airbnbRows ),
		'etag' => '"airbnb-v2"',
	);
	$res                = $icalApi( 'POST', '/admin/ical/feeds/' . $airbnbId . '/sync' );
	$after              = array_column( $icalBlocksOf( $airbnbId ), null, 'uid' );
	$mbkCheck(
		'ical: re-import deletes vanished UIDs, updates changed dates in place',
		'ok' === $res->get_data()['status'] && 2 === $res->get_data()['events_count'] && 2 === count( $after ) && ! isset( $after['1418fb94e984-a1b2c3d4e5f60718293a4b5c6d7e8f90@airbnb.com'] )
			&& $kept['7f3e2d1c0b9a-airbnb-blocked-20280418@airbnb.com'] === $after['7f3e2d1c0b9a-airbnb-blocked-20280418@airbnb.com']['id'] && '2028-04-21' === $after['7f3e2d1c0b9a-airbnb-blocked-20280418@airbnb.com']['date_to']
			&& ! in_array( '2028-04-26', Container::availability()->occupiedNights( $uid, '2028-04-01', '2028-04-30' ), true ) && ! in_array( '2028-04-26', Container::availabilityCache()->range( $uid, '2028-04', 1 )['occupied'], true ),
		wp_json_encode( $res->get_data() )
	);

	// 7. Booking.com feed (LF fixture), then failing for more than 2 hours → one email.
	$bookingRows         = array();
	$icalMock['booking'] = array( 'body' => $icalFixture( 'booking.ics' ) );
	$res                 = $icalApi( 'POST', '/admin/ical/feeds', array( 'unit_id' => $uid, 'channel' => 'booking', 'url' => $icalUrl( 'booking' ) ) );
	$bookingId           = (int) ( $res->get_data()['id'] ?? 0 );
	$icalFeedIds[]       = $bookingId;
	$mbkCheck( 'ical: POST booking feed → 201, 2 events', 201 === $res->get_status() && 2 === $res->get_data()['events_count'] );

	$icalMock['booking'] = array(
		'status' => 500,
		'body'   => 'Internal error',
	);
	$alertsBefore        = count( $icalAdminAlerts() );
	$res                 = $icalApi( 'POST', '/admin/ical/feeds/' . $bookingId . '/sync' );
	$feed                = Container::icalFeeds()->find( $bookingId );
	$mbkCheck(
		'ical: failing fetch → error stored as a code, translated on read, fail_since set, blocks kept, no email yet',
		false === $res->get_data()['ok'] && 'error' === $res->get_data()['status'] && str_contains( (string) $res->get_data()['error'], '500' ) && 'http_status' === $res->get_data()['error_code']
			&& str_contains( (string) $feed['last_error'], '"reason":"http_status"' ) && 'http_status' === $res->get_data()['feed']['last_error_code'] && str_contains( (string) $res->get_data()['feed']['last_error'], 'HTTP 500' )
			&& null !== $feed['fail_since'] && 2 === count( $icalBlocksOf( $bookingId ) ) && count( $icalAdminAlerts() ) === $alertsBefore,
		wp_json_encode( $res->get_data() )
	);
	$wpdb->update( $t( 'mbk_ical_feeds' ), array( 'fail_since' => $icalUtcAgo( 3 * HOUR_IN_SECONDS ) ), array( 'id' => $bookingId ) );
	$icalMock['booking'] = array( 'error' => 'cURL error 28: Operation timed out' );
	Container::icalSync()->syncFeed( (array) Container::icalFeeds()->find( $bookingId ) );
	Container::icalSync()->syncFeed( (array) Container::icalFeeds()->find( $bookingId ) );
	$failMails = array_slice( $icalAdminAlerts(), $alertsBefore );
	$failEvts  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t( 'mbk_events' )} WHERE type = %s AND external_id LIKE %s", SyncService::EVENT_FAILING, SyncService::EVENT_FAILING . ':' . $bookingId . ':%' ) );
	$failAlert = array_values( array_filter( $icalAlerts( 'ical_feed_failing' ), static fn( array $a ): bool => $bookingId === ( $a['feed_id'] ?? 0 ) ) );
	$mbkCheck(
		'ical: feed failing > 2 h → one admin email per streak, event, dashboard alert',
		1 === count( $failMails ) && str_contains( $failMails[0]['subject'], 'Booking.com' ) && str_contains( $failMails[0]['html'], esc_html( rtrim( \Marclie\Booking\I18n\LanguageAdapter::withLanguage( \Marclie\Booking\Email\Mailer::adminLang(), static fn(): string => ( new \Marclie\Booking\Ical\IcalException( 'timeout' ) )->describe() ), '.' ) ) ) && 1 === $failEvts && 1 === count( $failAlert ) && $uid === $failAlert[0]['unit_id'] && 'booking' === $failAlert[0]['channel'] && false === $failAlert[0]['stalled'] && null !== ( $failAlert[0]['since'] ?? null ),
		sprintf( 'mails=%d events=%d alerts=%d', count( $failMails ), $failEvts, count( $failAlert ) )
	);
	$icalMock['booking'] = array( 'body' => $icalFixture( 'booking.ics' ) );
	Container::icalSync()->syncFeed( (array) Container::icalFeeds()->find( $bookingId ) );
	$feed = Container::icalFeeds()->find( $bookingId );
	$mbkCheck( 'ical: recovered feed → fail_since and error cleared, alert gone', null === $feed['fail_since'] && null === $feed['last_error'] && null !== $feed['last_ok_at'] && ! array_filter( $icalAlerts( 'ical_feed_failing' ), static fn( array $a ): bool => $bookingId === ( $a['feed_id'] ?? 0 ) ) );

	$wpdb->update( $t( 'mbk_ical_feeds' ), array( 'last_ok_at' => $icalUtcAgo( 3 * HOUR_IN_SECONDS ) ), array( 'id' => $airbnbId ) );
	$stalled = array_values( array_filter( $icalAlerts( 'ical_feed_failing' ), static fn( array $a ): bool => $airbnbId === ( $a['feed_id'] ?? 0 ) ) );
	$wpdb->update( $t( 'mbk_ical_feeds' ), array( 'last_ok_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $airbnbId ) );
	$mbkCheck( 'ical: active feed without a successful sync for 2 h (dead cron) → ical_feed_failing, stalled', 1 === count( $stalled ) && true === $stalled[0]['stalled'] && str_contains( $stalled[0]['message'], 'WP-Cron' ) );

	// 8. Provenance. Direct booking C, echo booking B (exported before the OTAs read our feed), then the OTAs read our export.
	$bookings = Container::bookings();
	$book     = static function ( string $from, string $to, string $email, array $extra = array() ) use ( $bookings, $uid, $tz, $domain ): array {
		$stay    = Stay::fromStrings( $uid, $from, $to, $tz );
		$booking = $bookings->create( $stay, Container::pricing()->quote( $stay, 2, 0 ), array( 'email' => $email . $domain ) + $extra, 'hr' )['booking'];

		return $bookings->transition( (int) $booking['id'], BookingStatus::Confirmed, 'test' );
	};
	$conf = $book( '2027-09-06', '2027-09-10', 'ical.conflict', array( 'first_name' => 'Kon', 'last_name' => 'Flikt' ) );
	$echo = $book( '2027-09-20', '2027-09-24', 'ical.echo' );
	$wpdb->update( $t( 'mbk_bookings' ), array( 'created_at' => $icalUtcAgo( 10 * MINUTE_IN_SECONDS ) ), array( 'id' => (int) $echo['id'] ) );
	$res = $export( 'airbnb' );
	$export( 'booking' );
	$mbkCheck(
		'ical export: valid read records the channel fetch; Cache-Control private, no-store',
		200 === $res->get_status() && 'private, no-store' === ( $res->get_headers()['Cache-Control'] ?? '' ) && null !== Container::icalFeeds()->lastFetch( $uid, 'airbnb' ) && null !== Container::icalFeeds()->lastFetch( $uid, 'booking' ) && null === Container::icalFeeds()->lastFetch( $uid, 'other' ),
		wp_json_encode( $res->get_headers() )
	);

	// Both OTAs now list B's dates as "not available" (echo); Airbnb also got a real reservation over C.
	$airbnbRows[]        = array( 'conflict-0001@airbnb.com', '2027-09-08', '2027-09-11', 'Reserved' );
	$airbnbRows[]        = array( 'echo-0001@airbnb.com', '2027-09-20', '2027-09-24', 'Airbnb (Not available)' );
	$icalMock['airbnb']  = array(
		'body' => $icalCalendar( $airbnbRows ),
		'etag' => '"airbnb-v3"',
	);
	$bookingRows         = array(
		array( '9a7c51d6b2e04f1e8d3c2b1a09f8e7d6@booking.com', '2028-04-10', '2028-04-13', 'CLOSED - Not available' ),
		array( 'echo-b-0001@booking.com', '2027-09-20', '2027-09-24', 'CLOSED - Not available' ),
	);
	$icalMock['booking'] = array(
		'body' => $icalCalendar( $bookingRows, '-//Booking.com//Booking.com Calendar 1.0//EN' ),
		'etag' => '"booking-v2"',
	);
	$res                 = $icalApi( 'POST', '/admin/ical/feeds/' . $airbnbId . '/sync' );
	$icalApi( 'POST', '/admin/ical/feeds/' . $bookingId . '/sync' );
	$conflictRows = static fn( int $bookingId ): array => array_values( array_filter( Container::eventRepository()->forBooking( $bookingId ), static fn( array $e ): bool => ConflictDetector::EVENT === $e['type'] ) );
	$extId        = $icalBlockId( $airbnbId, 'conflict-0001@airbnb.com' );
	$echoA        = $icalBlockId( $airbnbId, 'echo-0001@airbnb.com' );
	$echoB        = $icalBlockId( $bookingId, 'echo-b-0001@booking.com' );
	$events       = $conflictRows( (int) $conf['id'] );
	$mbkCheck(
		'ical: external block over a confirmed booking → ical_conflict event (booking + block) + one admin email',
		1 === $res->get_data()['conflicts'] && 1 === count( $events ) && $extId === ( $events[0]['payload']['external_block_id'] ?? 0 ) && '2027-09-08' === ( $events[0]['payload']['date'] ?? '' ) && 'airbnb' === $events[0]['payload']['channel']
			&& 'external' === $icalOrigin( $airbnbId, 'conflict-0001@airbnb.com' ) && 1 === count( $mbkMailsOf( (int) $conf['id'], 'admin_alert' ) ) && str_contains( $mbkMailsOf( (int) $conf['id'], 'admin_alert' )[0]['html'], 'Airbnb' ),
		wp_json_encode( $res->get_data() )
	);
	$mbkCheck(
		'ical: own export echoed back by both OTAs (B existed before their fetch) → origin echo, no conflict, no email',
		'echo' === $icalOrigin( $airbnbId, 'echo-0001@airbnb.com' ) && 'echo' === $icalOrigin( $bookingId, 'echo-b-0001@booking.com' ) && array() === $conflictRows( (int) $echo['id'] ) && array() === $mbkMailsOf( (int) $echo['id'], 'admin_alert' )
	);

	// H2: a real Booking.com reservation with exactly C2's dates, imported before Booking.com read our export again → conflict.
	$conf2                = $book( '2027-09-11', '2027-09-14', 'ical.conflict2' );
	$bookingRows[]        = array( 'real-b-0001@booking.com', '2027-09-11', '2027-09-14', 'CLOSED - Not available' );
	$icalMock['booking']  = array(
		'body' => $icalCalendar( $bookingRows, '-//Booking.com//Booking.com Calendar 1.0//EN' ),
		'etag' => '"booking-v3"',
	);
	$icalApi( 'POST', '/admin/ical/feeds/' . $bookingId . '/sync' );
	$mbkCheck( 'ical: Booking.com reservation with identical dates, imported before Booking.com read our export → external + conflict + email', 'external' === $icalOrigin( $bookingId, 'real-b-0001@booking.com' ) && 1 === count( $conflictRows( (int) $conf2['id'] ) ) && 1 === count( $mbkMailsOf( (int) $conf2['id'], 'admin_alert' ) ) );

	$icalMock['airbnb']['etag'] = '"airbnb-v3b"';
	Container::icalSync()->syncFeed( (array) Container::icalFeeds()->find( $airbnbId ) );
	$mbkCheck( 'ical: re-import keeps one conflict event and one email', 1 === count( $conflictRows( (int) $conf['id'] ) ) && 1 === count( $mbkMailsOf( (int) $conf['id'], 'admin_alert' ) ) );
	$wpdb->delete( $t( 'mbk_external_blocks' ), array( 'id' => $extId ) );
	$icalMock['airbnb']['etag'] = '"airbnb-v3c"';
	Container::icalSync()->syncFeed( (array) Container::icalFeeds()->find( $airbnbId ) );
	$mbkCheck( 'ical: same OTA stay re-imported under a new block ID → no second conflict alert', 1 === count( $conflictRows( (int) $conf['id'] ) ) && 1 === count( $mbkMailsOf( (int) $conf['id'], 'admin_alert' ) ) );
	$extId = $icalBlockId( $airbnbId, 'conflict-0001@airbnb.com' );

	$conflictAlerts = array_values( array_filter( $icalAlerts( 'ical_conflict' ), static fn( array $a ): bool => (int) $conf['id'] === $a['booking_id'] ) );
	$mbkCheck( 'ical: dashboard ical_conflict alert with date, unit, channel', 1 === count( $conflictAlerts ) && '2027-09-08' === $conflictAlerts[0]['date'] && $uid === $conflictAlerts[0]['unit_id'] && 'airbnb' === $conflictAlerts[0]['channel'] && $conf['ref'] === $conflictAlerts[0]['booking_ref'], wp_json_encode( $conflictAlerts ) );

	$res      = $icalApi( 'GET', '/admin/calendar', null, array( 'from' => '2027-09-01', 'to' => '2027-10-01' ) );
	$external = array_column( array_filter( (array) ( $res->get_data()['external'] ?? array() ), static fn( array $e ): bool => $e['unit_id'] === $uid ), null, 'id' );
	$mbkCheck( 'ical: /admin/calendar external[] has channel, origin and conflict flag', true === ( $external[ $extId ]['conflict'] ?? null ) && false === ( $external[ $echoA ]['conflict'] ?? null ) && 'echo' === ( $external[ $echoA ]['origin'] ?? '' ) && 'external' === $external[ $extId ]['origin'] && 'airbnb' === $external[ $extId ]['channel'], wp_json_encode( array_values( $external ) ) );

	// 9. Export.
	$blockX        = Container::blocks()->create( Stay::fromStrings( $uid, '2027-09-15', '2027-09-17', $tz ), 'Owner – Ana Privatna', $adminId );
	$mbkBlockIds[] = (int) $blockX['id'];
	$icalResetLimits();
	$res = $icalApi( 'GET', $route, null, array( 'k' => str_repeat( 'x', 43 ), 'for' => 'airbnb' ) );
	$mbkCheck( 'ical export: wrong key → 404', 404 === $res->get_status() );
	$res = $icalApi( 'GET', '/ical/NOPE.ics', null, array( 'k' => $secret ) );
	$mbkCheck( 'ical export: unknown unit code → 404', 404 === $res->get_status() );

	$res     = $export( 'airbnb' );
	$ics     = (string) $res->get_data();
	$headers = $res->get_headers();
	$bookExt = $icalBlockId( $bookingId, '9a7c51d6b2e04f1e8d3c2b1a09f8e7d6@booking.com' );
	$airExt  = array_column( $icalBlocksOf( $airbnbId ), 'id' );
	$mbkCheck(
		'ical export (for=airbnb): bookings + manual block + Booking.com external blocks; no Airbnb block, no echo',
		200 === $res->get_status() && 'text/calendar; charset=utf-8' === ( $headers['Content-Type'] ?? '' ) && str_starts_with( $ics, "BEGIN:VCALENDAR\r\n" ) && str_ends_with( $ics, "END:VCALENDAR\r\n" )
			&& str_contains( $ics, "UID:{$conf['ref']}" . Exporter::uidDomain() . "\r\n" ) && str_contains( $ics, "UID:{$echo['ref']}" . Exporter::uidDomain() . "\r\n" ) && str_contains( $ics, 'UID:block-' . $blockX['id'] . "" . Exporter::uidDomain() . "\r\n" )
			&& str_contains( $ics, "DTSTART;VALUE=DATE:20270906\r\nDTEND;VALUE=DATE:20270910\r\n" ) && str_contains( $ics, 'UID:ext-' . $bookExt . "" . Exporter::uidDomain() . "\r\n" )
			&& ! str_contains( $ics, 'UID:ext-' . $echoB . '@' ) && ! array_filter( $airExt, static fn( int $id ): bool => str_contains( $ics, 'UID:ext-' . $id . '@' ) ),
		substr( $ics, 0, 400 )
	);
	$mbkCheck(
		'ical export: no PII, one summary "{brand} – busy", events within the window',
		! str_contains( $ics, 'Kon' ) && ! str_contains( $ics, 'Flikt' ) && ! str_contains( $ics, $domain ) && ! str_contains( $ics, 'Privatna' ) && ! str_contains( $ics, '20280501' )
			&& 1 === count( array_unique( array_column( Parser::parse( $ics, $tz, null ), 'summary' ) ) )
	);
	$etag = (string) ( $headers['ETag'] ?? '' );
	$res  = $export( 'airbnb', array( 'If-None-Match' => $etag ) );
	$mbkCheck( 'ical export: If-None-Match with the ETag → 304', '' !== $etag && 304 === $res->get_status() && null === $res->get_data() && $etag === ( $res->get_headers()['ETag'] ?? '' ) );
	$res = $export( 'booking' );
	$ics = (string) $res->get_data();
	$mbkCheck( 'ical export (for=booking): Airbnb external blocks exported, Booking.com ones and echoes not', 200 === $res->get_status() && str_contains( $ics, 'UID:ext-' . $extId . Exporter::uidDomain() ) && ! str_contains( $ics, 'UID:ext-' . $bookExt . '@' ) && ! str_contains( $ics, 'UID:ext-' . $echoA . '@' ) );
	$mbkCheck( 'ical export: ETag differs per channel (other events)', $etag !== ( $res->get_headers()['ETag'] ?? '' ) );

	// 10. H1: B is cancelled while both echoes exist → neither OTA gets the other's echo from us; the loop is broken.
	$bookings->transition( (int) $echo['id'], BookingStatus::Cancelled, 'test' );
	Container::icalSync()->syncAll( $uid );
	$toAirbnb  = (string) $export( 'airbnb' )->get_data();
	$toBooking = (string) $export( 'booking' )->get_data();
	$mbkCheck(
		'ical: B cancelled, echoes still imported → exports carry neither B nor any echo (both OTAs can drop them)',
		! str_contains( $toAirbnb . $toBooking, $echo['ref'] . '@' ) && ! str_contains( $toAirbnb . $toBooking, 'UID:ext-' . $echoA . '@' ) && ! str_contains( $toAirbnb . $toBooking, 'UID:ext-' . $echoB . '@' )
			&& ! str_contains( $toAirbnb, 'DTSTART;VALUE=DATE:20270920' ) && ! str_contains( $toBooking, 'DTSTART;VALUE=DATE:20270920' ) && 0 < $echoA && 0 < $echoB
	);
	// Airbnb read our export after the cancellation and keeps echoing for more than 24 h → info alert, no email.
	Container::icalSync()->syncAll( $uid );
	$block = Container::icalFeeds()->findBlock( $echoA );
	$mbkCheck( 'ical: orphaned echo tracked (own event gone, OTA fetched our export since)', null !== $block && null !== $block['orphaned_at'] && null !== $block['orphan_fetch_at'], wp_json_encode( $block ) );
	$mailsBefore = count( $mbkMails );
	$wpdb->update( $t( 'mbk_external_blocks' ), array( 'orphan_fetch_at' => $icalUtcAgo( 25 * HOUR_IN_SECONDS ) ), array( 'id' => $echoA ) );
	$stale = array_values( array_filter( $icalAlerts( 'ical_stale_echo' ), static fn( array $a ): bool => $echoA === $a['external_block_id'] ) );
	$mbkCheck( 'ical: echo kept > 24 h after the OTA re-read our export → ical_stale_echo info alert, no email', 1 === count( $stale ) && 'info' === $stale[0]['level'] && 'airbnb' === $stale[0]['channel'] && '2027-09-20' === $stale[0]['date'] && count( $mbkMails ) === $mailsBefore, wp_json_encode( $stale ) );
	// The OTAs drop their echoes: the dates are free again.
	$icalMock['airbnb']  = array(
		'body' => $icalCalendar( array_values( array_filter( $airbnbRows, static fn( array $r ): bool => 'echo-0001@airbnb.com' !== $r[0] ) ) ),
		'etag' => '"airbnb-v4"',
	);
	$icalMock['booking'] = array(
		'body' => $icalCalendar( array_values( array_filter( $bookingRows, static fn( array $r ): bool => 'echo-b-0001@booking.com' !== $r[0] ) ), '-//Booking.com//Booking.com Calendar 1.0//EN' ),
		'etag' => '"booking-v4"',
	);
	Container::icalSync()->syncAll( $uid );
	$mbkCheck( 'ical: OTAs drop the echoes → dates free, no stale alert', array() === Container::availability()->occupiedNights( $uid, '2027-09-20', '2027-09-24' ) && array() === $icalAlerts( 'ical_stale_echo' ) );

	// 11. Pre-checkout refresh: one 3 s deadline, no redirects, conflicts deferred to cron.
	$wpdb->query( $wpdb->prepare( "UPDATE {$t( 'mbk_ical_feeds' )} SET last_synced_at = %s WHERE id IN (%d, %d)", $icalUtcAgo( 10 * MINUTE_IN_SECONDS ), $airbnbId, $bookingId ) );
	$airbnbRows[]       = array( 'conflict-0002@airbnb.com', '2027-09-06', '2027-09-07', 'Reserved' );
	$icalMock['airbnb'] = array(
		'body'  => $icalCalendar( array_values( array_filter( $airbnbRows, static fn( array $r ): bool => 'echo-0001@airbnb.com' !== $r[0] ) ) ),
		'etag'  => '"airbnb-v5"',
		'delay' => 2.7,
	);
	$icalLog            = array();
	$icalResetLimits();
	$started = microtime( true );
	$res     = $icalApi( 'POST', '/bookings', $checkout( '2027-09-26', '2027-09-30', 'ical.ondemand' ) );
	$elapsed = microtime( true ) - $started;
	$names   = array_column( $icalLog, 'name' );
	$mbkCheck(
		'ical: POST /bookings refreshes stale feeds within one 3 s deadline (second feed skipped), no redirects',
		201 === $res->get_status() && array( 'airbnb' ) === $names && $icalLog[0]['timeout'] <= 3.0 && $icalLog[0]['timeout'] > 2.0 && 0 === $icalLog[0]['redirection'] && $elapsed < 6,
		wp_json_encode( array( $res->get_status(), $icalLog, round( $elapsed, 2 ) ) )
	);
	$deferred = array_values( array_filter( $conflictRows( (int) $conf['id'] ), static fn( array $e ): bool => '2027-09-06' === ( $e['payload']['date_from'] ?? '' ) ) );
	$mbkCheck( 'ical: conflict found by the pre-checkout refresh is not handled in the request (follow-up cron scheduled)', array() === $deferred && false !== wp_next_scheduled( Cron::ICAL_FOLLOWUP, array( $uid ) ) );
	Container::icalSync()->followUp( $uid );
	$deferred = array_values( array_filter( $conflictRows( (int) $conf['id'] ), static fn( array $e ): bool => '2027-09-06' === ( $e['payload']['date_from'] ?? '' ) ) );
	$mbkCheck( 'ical: follow-up cron logs and mails the deferred conflict', 1 === count( $deferred ) && 2 === count( $mbkMailsOf( (int) $conf['id'], 'admin_alert' ) ) );
	unset( $icalMock['airbnb']['delay'] );

	$wpdb->update( $t( 'mbk_ical_feeds' ), array( 'last_synced_at' => $icalUtcAgo( 10 * MINUTE_IN_SECONDS ) ), array( 'id' => $airbnbId ) );
	$icalMock['airbnb'] = array( 'error' => 'cURL error 28: Operation timed out after 3000 milliseconds' );
	$icalResetLimits();
	$res = $icalApi( 'POST', '/bookings', $checkout( '2028-04-04', '2028-04-08', 'ical.ondemand.fail' ) );
	$mbkCheck( 'ical: refresh failure before checkout → cached blocks still apply (409), error recorded', 409 === $res->get_status() && null !== Container::icalFeeds()->find( $airbnbId )['last_error'], wp_json_encode( $res->get_data() ) );

	// 12. Redirect (https → https) followed, gzip body decoded; locks; inactive feeds never block.
	$icalMock['other-redirect'] = array(
		'status'   => 302,
		'location' => '/other-gz.ics',
	);
	$icalMock['other-gz']       = array(
		'body' => $icalCalendar( array( array( 'other-0001@example.com', '2028-04-14', '2028-04-16', 'Busy' ) ), '-//Google Inc//Google Calendar 70.9054//EN' ),
		'gzip' => true,
	);
	$icalLog                    = array();
	$res                        = $icalApi( 'POST', '/admin/ical/feeds', array( 'unit_id' => $uid, 'channel' => 'other', 'url' => $icalUrl( 'other-redirect' ) ) );
	$otherId                    = (int) ( $res->get_data()['id'] ?? 0 );
	$icalFeedIds[]              = $otherId;
	$mbkCheck( 'ical: https redirect followed (each hop checked), gzip answer decoded', 201 === $res->get_status() && 1 === $res->get_data()['events_count'] && array( 'other-redirect', 'other-gz' ) === array_column( $icalLog, 'name' ), wp_json_encode( array( $res->get_data(), $icalLog ) ) );

	$held = Db::claimLock( SyncService::LOCK_PREFIX . $otherId, 60 );
	$busy = $icalApi( 'PATCH', '/admin/ical/feeds/' . $otherId, array( 'active' => false ) );
	$del  = $icalApi( 'DELETE', '/admin/ical/feeds/' . $otherId );
	$sync = $icalApi( 'POST', '/admin/ical/feeds/' . $otherId . '/sync' );
	Db::releaseLock( SyncService::LOCK_PREFIX . $otherId, (string) $held );
	$mbkCheck( 'ical: feed locked by a running sync → PATCH/DELETE 409 ical_feed_busy, sync busy', 409 === $busy->get_status() && 'ical_feed_busy' === ( $busy->get_data()['code'] ?? '' ) && 409 === $del->get_status() && 'busy' === $sync->get_data()['status'] && null !== Container::icalFeeds()->find( $otherId ) );

	$res      = $icalApi( 'PATCH', '/admin/ical/feeds/' . $otherId, array( 'active' => false ) );
	$replaced = Container::icalFeeds()->replaceBlocks( $otherId, $uid, 'other', array( array( 'uid' => 'late@example.com', 'date_from' => '2028-04-14', 'date_to' => '2028-04-16', 'summary' => 'Late' ) ), gmdate( 'Y-m-d H:i:s' ) );
	$mbkCheck( 'ical: a sync finishing after the feed was switched off writes nothing', 200 === $res->get_status() && null === $replaced && array() === $icalBlocksOf( $otherId ) );
	$wpdb->insert( $t( 'mbk_external_blocks' ), array( 'feed_id' => $otherId, 'unit_id' => $uid, 'uid' => 'stray@example.com', 'date_from' => '2028-04-14', 'date_to' => '2028-04-16', 'summary' => '', 'origin' => 'external', 'first_seen_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	$mbkCheck( 'ical: blocks of an inactive feed never block availability', ! in_array( '2028-04-14', Container::availability()->occupiedNights( $uid, '2028-04-01', '2028-04-30' ), true ) );
	$suppress  = $wpdb->suppress_errors( true );
	$duplicate = $wpdb->insert( $t( 'mbk_external_blocks' ), array( 'feed_id' => $otherId, 'unit_id' => $uid, 'uid' => 'stray@example.com', 'date_from' => '2028-04-20', 'date_to' => '2028-04-21', 'summary' => '' ) );
	$wpdb->suppress_errors( $suppress );
	$mbkCheck( 'ical: (feed_id, uid) is UNIQUE', false === $duplicate );

	// 13. Rotate, URL change validation, deactivate, delete.
	$res    = $icalApi( 'POST', '/admin/ical/units/' . $uid . '/rotate' );
	$query2 = array();
	wp_parse_str( (string) wp_parse_url( (string) ( $res->get_data()['export']['other'] ?? '' ), PHP_URL_QUERY ), $query2 );
	$old    = $export( 'other' );
	$secret = (string) ( $query2['k'] ?? '' );
	$new    = $export( 'other' );
	$mbkCheck( 'ical: rotate → new secret works, old one 404', 200 === $res->get_status() && $uid === $res->get_data()['unit_id'] && '' !== $secret && 404 === $old->get_status() && 200 === $new->get_status() );

	$res = $icalApi( 'PATCH', '/admin/ical/feeds/' . $bookingId, array( 'url' => 'ftp://example.com/x.ics' ) );
	$mbkCheck( 'ical: PATCH invalid URL → 422, feed unchanged', 422 === $res->get_status() && $icalUrl( 'booking' ) === Container::icalFeeds()->find( $bookingId )['url'] );
	$res = $icalApi( 'PATCH', '/admin/ical/feeds/' . $bookingId, array( 'active' => false ) );
	$mbkCheck( 'ical: PATCH active=false → blocks removed, nights free', 200 === $res->get_status() && false === $res->get_data()['active'] && array() === $icalBlocksOf( $bookingId ) && ! in_array( '2028-04-11', Container::availability()->occupiedNights( $uid, '2028-04-01', '2028-04-30' ), true ) );
	$res = $icalApi( 'PATCH', '/admin/ical/feeds/' . $bookingId, array( 'active' => true ) );
	$mbkCheck( 'ical: PATCH active=true → synced again', 200 === $res->get_status() && true === $res->get_data()['active'] && count( $bookingRows ) - 1 === count( $icalBlocksOf( $bookingId ) ) );

	wp_set_current_user( 0 );
	$res = $icalApi( 'GET', '/admin/ical' );
	$mbkCheck( 'ical: admin REST needs a capability', in_array( $res->get_status(), array( 401, 403 ), true ) );
	wp_set_current_user( $adminId );

	$res = $icalApi( 'DELETE', '/admin/ical/feeds/' . $bookingId );
	$mbkCheck( 'ical: DELETE feed → feed and its blocks removed', 200 === $res->get_status() && null === Container::icalFeeds()->find( $bookingId ) && array() === $icalBlocksOf( $bookingId ) );
} catch ( \Throwable $e ) {
	$mbkCheck( 'ical: unexpected exception', false, get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
} finally {
	remove_filter( 'pre_http_request', $icalHttp, 10 );
	remove_filter( 'mbk_ical_resolve_host', $icalResolve, 10 );
	remove_filter( 'mbk_payment_gateway', $icalUseFake, 99 );
	Container::reset();
	foreach ( array_filter( $icalFeedIds ) as $feedId ) {
		$wpdb->delete( $t( 'mbk_external_blocks' ), array( 'feed_id' => $feedId ) );
		$wpdb->delete( $t( 'mbk_ical_feeds' ), array( 'id' => $feedId ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t( 'mbk_events' )} WHERE external_id LIKE %s", SyncService::EVENT_FAILING . ':' . $feedId . ':%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$wpdb->delete( $wpdb->options, array( 'option_name' => SyncService::LOCK_PREFIX . $feedId ) );
	}
	// Feed-failure alerts have no booking: their email events are removed here.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$t( 'mbk_events' )} WHERE booking_id IS NULL AND actor = %s AND type IN ('email_sent', 'email_failed')", SyncService::ACTOR ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	if ( null !== $icalUnit ) {
		$wpdb->delete( $t( 'mbk_ical_exports' ), array( 'unit_id' => $icalUnit->id ) );
		if ( is_array( $icalSecretRow ) ) {
			$wpdb->insert( $t( 'mbk_ical_exports' ), $icalSecretRow );
		}
		$wpdb->delete( $t( 'mbk_ical_export_fetches' ), array( 'unit_id' => $icalUnit->id ) );
		foreach ( $icalFetchRows as $fetchRow ) {
			$wpdb->insert( $t( 'mbk_ical_export_fetches' ), $fetchRow );
		}
		wp_clear_scheduled_hook( Cron::ICAL_FOLLOWUP, array( $icalUnit->id ) );
		AvailabilityCache::invalidate( $icalUnit->id );
	}
	wp_set_current_user( $icalPrevUser );
}
