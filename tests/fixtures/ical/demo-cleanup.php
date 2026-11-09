<?php
/**
 * Removes the iCal demo data of demo-seed.php: feeds on https://ical.mbk-test.invalid/demo-…,
 * their blocks and events, the demo booking(s) and guest (…@mbk-demo.test).
 *
 *     studio wp eval-file wp-content/plugins/marclie-booking/tests/fixtures/ical/demo-cleanup.php
 */

use Marclie\Booking\Repository\Db;
use Marclie\Booking\Service\AvailabilityCache;

defined( 'ABSPATH' ) || exit( 1 );

global $wpdb;

$t     = static fn( string $name ): string => Db::table( $name );
$feeds = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, unit_id FROM {$t( 'mbk_ical_feeds' )} WHERE url LIKE %s", 'https://ical.mbk-test.invalid/demo-%' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
foreach ( $feeds as $feed ) {
	$wpdb->delete( $t( 'mbk_external_blocks' ), array( 'feed_id' => (int) $feed['id'] ) );
	$wpdb->delete( $t( 'mbk_ical_feeds' ), array( 'id' => (int) $feed['id'] ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$t( 'mbk_events' )} WHERE external_id LIKE %s", 'ical\\_feed\\_failing:' . (int) $feed['id'] . ':%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	delete_option( 'mbk_ical_lock_' . (int) $feed['id'] );
}

$guestIds   = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t( 'mbk_guests' )} WHERE email LIKE %s", '%@mbk-demo.test' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
$bookingIds = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t( 'mbk_bookings' )} WHERE email LIKE %s", '%@mbk-demo.test' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
if ( $bookingIds ) {
	$in = Db::placeholders( $bookingIds );
	foreach ( array( 'mbk_nights', 'mbk_payments', 'mbk_events', 'mbk_booking_tokens', 'mbk_coupon_redemptions' ) as $table ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t( $table )} WHERE booking_id IN ({$in})", $bookingIds ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$t( 'mbk_bookings' )} WHERE id IN ({$in})", $bookingIds ) ); // phpcs:ignore WordPress.DB.PreparedSQL
}
if ( $guestIds ) {
	$in = Db::placeholders( $guestIds );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$t( 'mbk_events' )} WHERE guest_id IN ({$in})", $guestIds ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$t( 'mbk_guests' )} WHERE id IN ({$in})", $guestIds ) ); // phpcs:ignore WordPress.DB.PreparedSQL
}
AvailabilityCache::invalidate( 0 );

WP_CLI::success( sprintf( 'iCal demo removed: %d feed(s), %d booking(s), %d guest(s).', count( $feeds ), count( $bookingIds ), count( $guestIds ) ) );
