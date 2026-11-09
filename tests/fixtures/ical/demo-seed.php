<?php
/**
 * Demo data for checking the iCal screens in a browser (local only):
 *
 *     studio wp eval-file wp-content/plugins/marclie-booking/tests/fixtures/ical/demo-seed.php
 *     studio wp eval-file wp-content/plugins/marclie-booking/tests/fixtures/ical/demo-cleanup.php
 *
 * Imports mocked feeds (https://ical.mbk-test.invalid/…, answered by a
 * `pre_http_request` filter during this run only) for the first three units
 * that have no feed yet, with blocks 5–40 days from today:
 *  - unit A: Airbnb feed + Booking.com feed, and a confirmed demo booking that
 *    an Airbnb block overlaps (→ ical_conflict event, dashboard + calendar);
 *  - unit B: Airbnb feed;
 *  - unit C: Booking.com feed failing for 3 hours (→ ical_feed_failing alert).
 * Emails are not sent (pre_wp_mail). "Sinkroniziraj sada" in the browser makes
 * the mocked feeds fail (no mock outside this run) — that shows the error state.
 */

use Marclie\Booking\Container;
use Marclie\Booking\Domain\BookingStatus;
use Marclie\Booking\Domain\Stay;
use Marclie\Booking\Repository\Db;

defined( 'ABSPATH' ) || exit( 1 );

global $wpdb;

$host  = 'ical.mbk-test.invalid';
$today = Container::clock()->today();
$day   = static fn( int $offset ): string => $today->modify( sprintf( '%+d days', $offset ) )->format( 'Y-m-d' );
$ics   = static function ( string $prodId, array $rows ): string {
	$out = "BEGIN:VCALENDAR\r\nPRODID:{$prodId}\r\nVERSION:2.0\r\n";
	foreach ( $rows as [ $uid, $from, $to, $summary ] ) {
		$out .= "BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:" . str_replace( '-', '', $from ) . "\r\nDTEND;VALUE=DATE:" . str_replace( '-', '', $to ) . "\r\nUID:{$uid}\r\nSUMMARY:{$summary}\r\nEND:VEVENT\r\n";
	}

	return $out . "END:VCALENDAR\r\n";
};

$mocks = array();
$http  = static function ( $pre, $args, $url ) use ( &$mocks, $host ) {
	if ( wp_parse_url( (string) $url, PHP_URL_HOST ) !== $host ) {
		return $pre;
	}
	$name = basename( (string) wp_parse_url( (string) $url, PHP_URL_PATH ), '.ics' );
	if ( ! isset( $mocks[ $name ] ) ) {
		return new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host: ' . $host );
	}

	return array(
		'headers'  => array( 'content-type' => 'text/calendar' ),
		'body'     => $mocks[ $name ],
		'response' => array(
			'code'    => 200,
			'message' => 'OK',
		),
		'cookies'  => array(),
		'filename' => null,
	);
};
add_filter( 'pre_http_request', $http, 10, 3 );
$resolve = static fn( $ips, $h ) => $host === $h ? array( '93.184.216.34' ) : $ips;
add_filter( 'mbk_ical_resolve_host', $resolve, 10, 2 );
add_filter( 'pre_wp_mail', '__return_true', 999 );

$free = array();
foreach ( Container::units()->all() as $unit ) {
	if ( ! Container::icalFeeds()->forUnit( $unit->id ) && array() === Container::availability()->occupiedNights( $unit->id, $day( 0 ), $day( 45 ) ) ) {
		$free[] = $unit;
	}
	if ( 3 === count( $free ) ) {
		break;
	}
}
if ( count( $free ) < 3 ) {
	WP_CLI::error( 'Need three units without feeds and free for the next 45 days.' );
}
[ $a, $b, $c ] = $free;
$sync          = Container::icalSync();

// Unit A: confirmed direct booking first, then an Airbnb block over it (a conflict).
$stay    = Stay::fromStrings( $a->id, $day( 30 ), $day( 34 ), wp_timezone() );
$booking = Container::bookings()->create( $stay, Container::pricing()->quote( $stay, 2, 0 ), array( 'email' => 'ical.demo@mbk-demo.test', 'first_name' => 'Demo', 'last_name' => 'Konflikt' ), 'hr', 'admin' )['booking'];
Container::bookings()->transition( (int) $booking['id'], BookingStatus::Confirmed, 'system:ical-demo' );

$mocks[ 'demo-airbnb-' . $a->id ]  = $ics(
	'-//Airbnb Inc//Hosting Calendar 0.8.8//EN',
	array(
		array( 'demo-a1@airbnb.com', $day( 5 ), $day( 9 ), 'Reserved' ),
		array( 'demo-a2@airbnb.com', $day( 20 ), $day( 23 ), 'Airbnb (Not available)' ),
		array( 'demo-a3@airbnb.com', $day( 32 ), $day( 35 ), 'Reserved' ),
	)
);
$mocks[ 'demo-booking-' . $a->id ] = $ics( '-//Booking.com//Booking.com Calendar 1.0//EN', array( array( 'demo-b1@booking.com', $day( 12 ), $day( 15 ), 'CLOSED - Not available' ) ) );
$mocks[ 'demo-airbnb-' . $b->id ]  = $ics( '-//Airbnb Inc//Hosting Calendar 0.8.8//EN', array( array( 'demo-a4@airbnb.com', $day( 7 ), $day( 11 ), 'Reserved' ) ) );
$mocks[ 'demo-booking-' . $c->id ] = $ics( '-//Booking.com//Booking.com Calendar 1.0//EN', array( array( 'demo-b2@booking.com', $day( 15 ), $day( 18 ), 'CLOSED - Not available' ) ) );

foreach ( array( array( $a, 'airbnb' ), array( $a, 'booking' ), array( $b, 'airbnb' ), array( $c, 'booking' ) ) as [ $unit, $channel ] ) {
	$feed = $sync->addFeed( $unit->id, $channel, 'https://' . $host . '/demo-' . $channel . '-' . $unit->id . '.ics' );
	WP_CLI::log( sprintf( 'Feed %d: %s %s, %d events', $feed['id'], $unit->code, $channel, $feed['events_count'] ) );
}

// Unit C: its feed now fails, and has been failing for 3 hours.
unset( $mocks[ 'demo-booking-' . $c->id ] );
$failing = Container::icalFeeds()->findByUnitChannel( $c->id, 'booking' );
$sync->syncFeed( $failing );
$wpdb->update( Db::table( 'mbk_ical_feeds' ), array( 'fail_since' => gmdate( 'Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS ) ), array( 'id' => (int) $failing['id'] ) );

remove_filter( 'pre_http_request', $http, 10 );
remove_filter( 'mbk_ical_resolve_host', $resolve, 10 );
WP_CLI::success( sprintf( 'iCal demo: units %s (conflict with %s), %s, %s (failing). Remove with demo-cleanup.php.', $a->code, $booking['ref'], $b->code, $c->code ) );
