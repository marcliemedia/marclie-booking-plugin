<?php
/**
 * Integration checks against the local database with the real services.
 *
 *     wp eval-file wp-content/plugins/marclie-booking/tests/integration/run.php
 *
 * Requires seed data (`wp mbk seed`). Every unit gets a temporary 12-month price
 * table (40 € per person every month, minimum 3 nights; the real table is
 * restored in `finally`). Uses October–December 2027 stays and guests at
 * @mbk-integration.test; everything it creates (bookings, guests, payments,
 * blocks, events) is removed in `finally`, the DIRECT5 used_count is restored
 * and the public calendar cache is dropped.
 * Every email is captured through `pre_wp_mail` (nothing is sent; `$mbkMails`,
 * `$mbkMailsOf()`); emails.php covers phase 07, accounts.php phase 10 (guest
 * accounts; removes its users and member coupons itself). Exits 1 on any failure.
 */

use Marclie\Booking\Container;
use Marclie\Booking\Domain\BookingHorizon;
use Marclie\Booking\Domain\BookingStatus;
use Marclie\Booking\Domain\Exception\CouponException;
use Marclie\Booking\Domain\Exception\DuplicatePaymentException;
use Marclie\Booking\Domain\Exception\GuestHasActiveBookingsException;
use Marclie\Booking\Domain\Exception\InvalidTransitionException;
use Marclie\Booking\Domain\Exception\PaymentException;
use Marclie\Booking\Domain\Exception\UnavailableException;
use Marclie\Booking\Domain\Exception\ValidationException;
use Marclie\Booking\Domain\Stay;
use Marclie\Booking\Repository\Db;
use Marclie\Booking\Service\AvailabilityCache;
use Marclie\Booking\Service\ReferenceGenerator;
use Marclie\Booking\Settings;

defined( 'ABSPATH' ) || exit( 1 );

global $wpdb;

$mbkResults = array();
$mbkCheck   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$mbkResults ): void {
	$mbkResults[] = $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $name, '' !== $detail ? ' — ' . $detail : '' );
};

$mbkBookingIds = array();
$mbkGuestIds   = array();
$mbkBlockIds   = array();
$mbkCouponId   = 0;
$mbkTrack      = static function ( array $booking ) use ( &$mbkBookingIds, &$mbkGuestIds ): void {
	$mbkBookingIds[] = (int) $booking['id'];
	if ( ! empty( $booking['guest_id'] ) ) {
		$mbkGuestIds[] = (int) $booking['guest_id'];
	}
};
add_action( 'mbk_booking_created', $mbkTrack );

// Every email of the run is captured (pre_wp_mail short-circuits wp_mail: nothing is sent).
// `mbk_email_args` adds the template code, language and text alternative of each one.
$mbkMails    = array();
$mbkMailArgs = null;
$mbkMailMeta = static function ( array $args ) use ( &$mbkMailArgs ): array {
	$mbkMailArgs = $args;

	return $args;
};
$mbkMailCapture = static function ( $short, array $atts ) use ( &$mbkMails, &$mbkMailArgs ): bool {
	if ( false === $short ) {
		$mbkMailArgs = null;

		return false; // A check simulated a transport failure (earlier pre_wp_mail filter).
	}
	$mbkMails[]  = array(
		'to'         => array_map( 'strtolower', (array) $atts['to'] ),
		'subject'    => (string) $atts['subject'],
		'html'       => (string) $atts['message'],
		'headers'    => (array) $atts['headers'],
		'code'       => $mbkMailArgs['code'] ?? null,
		'lang'       => $mbkMailArgs['lang'] ?? null,
		'text'       => $mbkMailArgs['text'] ?? null,
		'booking_id' => $mbkMailArgs['booking_id'] ?? null,
	);
	$mbkMailArgs = null;

	return true;
};
add_filter( 'mbk_email_args', $mbkMailMeta, 999 );
add_filter( 'pre_wp_mail', $mbkMailCapture, 999, 2 );
/** Captured emails of a booking (optionally one template code). */
$mbkMailsOf = static function ( int $bookingId, ?string $code = null ) use ( &$mbkMails ): array {
	return array_values( array_filter( $mbkMails, static fn( array $m ): bool => (int) $m['booking_id'] === $bookingId && ( null === $code || $m['code'] === $code ) ) );
};

$t          = static fn( string $name ): string => Db::table( $name );
$domain     = '@mbk-integration.test';
$couponRow  = Container::couponRepository()->findRowByCode( 'DIRECT5' );
$usedBefore = $couponRow ? (int) $couponRow['used_count'] : null;
$tz         = wp_timezone();

// Temporary price table: every month open at 40 € per person, minimum paying guests = capacity
// (every party pays the full apartment, so totals depend on the unit only); restored in finally.
$mbkPricesBefore = (array) $wpdb->get_results( "SELECT * FROM {$t( 'mbk_prices' )}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$mbkTestPrice    = 4000;
$mbkOpenRow      = static fn( int $unitId, int $minStay = 3, array $closed = array() ): array => array(
	'unit_id'    => $unitId,
	'min_stay'   => $minStay,
	'min_guests' => Container::units()->get( $unitId )->capacity,
	'months'     => array_map( static fn( int $m ): ?int => in_array( $m, $closed, true ) ? null : $mbkTestPrice, range( 1, 12 ) ),
);

try {
	Container::prices()->save( array_map( static fn( $u ): array => $mbkOpenRow( $u->id ), Container::units()->all() ), 'test' );
	$units = Container::units()->all();
	if ( ! $units || null === $couponRow ) {
		throw new RuntimeException( 'Seed data missing: run `wp mbk seed` first.' );
	}
	$unit = $units[0];

	$stay = Stay::fromStrings( $unit->id, '2027-10-04', '2027-10-08', $tz );
	if ( ! Container::availability()->isAvailable( $stay ) ) {
		throw new RuntimeException( sprintf( 'Unit %d is not free on %s → %s; cannot run.', $unit->id, $stay->arrivalString(), $stay->departureString() ) );
	}

	$bookings = Container::bookings();
	$quote    = Container::pricing()->quote( $stay, 2, 0, 'DIRECT5' );
	$guestA   = array(
		'email'      => 'Guest.A' . $domain,
		'first_name' => 'Ana',
		'last_name'  => 'Test',
		'phone'      => '+385 91 000 0000',
		'message'    => 'Late arrival',
	);

	// 1. Create + reference format.
	$created = $bookings->create( $stay, $quote, $guestA, 'hr' );
	$a       = $created['booking'];
	$mbkCheck( 'create → pending_payment with 4 held nights', 'pending_payment' === $a['status'] && 4 === count( Container::availability()->occupiedNights( $unit->id, '2027-10-01', '2027-10-31' ) ) );
	$mbkCheck( 'reference format', 0 === strpos( $a['ref'], ReferenceGenerator::base( Settings::refPrefix(), $stay->arrival, $unit->code ) ), $a['ref'] );
	$mbkCheck( 'access token verifies', null !== $bookings->verifyToken( $a['ref'], $created['token'] ) && null === $bookings->verifyToken( $a['ref'], str_repeat( '0', 64 ) ) );

	// 2. Overlapping create (pre-check path).
	$countRange = static fn(): int => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t( 'mbk_bookings' )} WHERE unit_id = %d AND arrival < %s AND departure > %s", $unit->id, '2027-10-10', '2027-10-01' ) );
	$guestB     = array( 'email' => 'guest.b' . $domain );
	$overlap    = Stay::fromStrings( $unit->id, '2027-10-06', '2027-10-10', $tz );
	try {
		$bookings->create( $overlap, Container::pricing()->quote( $overlap, 2, 0 ), $guestB, 'hr' );
		$mbkCheck( 'overlap (pre-check) → UnavailableException', false, 'created' );
	} catch ( UnavailableException $e ) {
		$mbkCheck( 'overlap (pre-check) → UnavailableException', 1 === $countRange() );
	}

	// 3. Overlapping create where only the UNIQUE hold can catch it (no sources).
	Container::reset();
	$noSources = static fn(): array => array();
	add_filter( 'mbk_availability_sources', $noSources );
	try {
		Container::bookings()->create( $overlap, Container::pricing()->quote( $overlap, 2, 0 ), $guestB, 'hr' );
		$mbkCheck( 'overlap (hold) → UnavailableException', false, 'created' );
	} catch ( UnavailableException $e ) {
		$orphanGuest = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t( 'mbk_guests' )} WHERE email = %s", $guestB['email'] ) );
		$mbkCheck( 'overlap (hold) → UnavailableException, no orphan booking/guest', 1 === $countRange() && 0 === $orphanGuest, sprintf( 'bookings=%d guests=%d', $countRange(), $orphanGuest ) );
	} finally {
		remove_filter( 'mbk_availability_sources', $noSources );
		Container::reset();
		$bookings = Container::bookings();
	}

	// 4. Transition rules.
	$a = $bookings->transition( $a['id'], BookingStatus::Confirmed, 'test' );
	$mbkCheck( 'pending_payment → confirmed', 'confirmed' === $a['status'] && null === $a['hold_expires_at'] );
	try {
		$bookings->transition( $a['id'], BookingStatus::PendingPayment, 'test' );
		$mbkCheck( 'confirmed → pending_payment rejected', false );
	} catch ( InvalidTransitionException $e ) {
		$mbkCheck( 'confirmed → pending_payment rejected', 'invalid_transition' === $e->errorCode() );
	}

	// 5. Coupon counted once across confirmed → paid.
	$a           = $bookings->transition( $a['id'], BookingStatus::Paid, 'test' );
	$redemptions = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t( 'mbk_coupon_redemptions' )} WHERE booking_id = %d", $a['id'] ) );
	$usedAfter   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT used_count FROM {$t( 'mbk_coupons' )} WHERE id = %d", $couponRow['id'] ) );
	$mbkCheck( 'coupon redeemed once (confirmed + paid)', 1 === $redemptions && $usedAfter === $usedBefore + 1, sprintf( 'redemptions=%d used %d→%d', $redemptions, $usedBefore, $usedAfter ) );

	// 6. Cancel releases nights.
	$decision = Container::cancellation()->cancel( $a['id'], 'test' );
	$nights   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t( 'mbk_nights' )} WHERE booking_id = %d", $a['id'] ) );
	$mbkCheck( 'cancel releases nights', 'cancelled' === $bookings->find( $a['id'] )['status'] && 0 === $nights, wp_json_encode( $decision->toArray() ) );

	// 7. HoldReaper expires a stale hold.
	$c = $bookings->create( $stay, Container::pricing()->quote( $stay, 2, 0 ), $guestA, 'en' )['booking'];
	$mbkCheck( 'rebooking a cancelled stay gets a -2 reference', str_ends_with( $c['ref'], '-2' ), $c['ref'] );
	$wpdb->update( $t( 'mbk_bookings' ), array( 'hold_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $c['id'] ) );
	Container::holdReaper()->run();
	$c      = $bookings->find( $c['id'] );
	$nights = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t( 'mbk_nights' )} WHERE booking_id = %d", $c['id'] ) );
	$mbkCheck( 'HoldReaper expires stale hold and frees nights', 'expired' === $c['status'] && 0 === $nights );

	// 8. Anonymize clears PII.
	$guestId = (int) $a['guest_id'];
	$wpdb->update( $t( 'mbk_bookings' ), array( 'internal_notes' => 'VIP' ), array( 'id' => $a['id'] ) );
	Container::eventRepository()->log( 'email_sent', 'test', $a['id'], null, array( 'to_email' => $guestA['email'], 'template' => 'confirmation' ) );
	Container::guests()->anonymize( $guestId, 'test' );
	$guest    = Container::guestRepository()->find( $guestId );
	$pii      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t( 'mbk_bookings' )} WHERE guest_id = %d AND (email <> '' OR first_name <> '' OR phone <> '' OR message IS NOT NULL OR internal_notes IS NOT NULL)", $guestId ) );
	$leaks    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t( 'mbk_events' )} WHERE payload_json LIKE %s", '%' . $wpdb->esc_like( 'guest.a' ) . '%' ) );
	$amountOk = (int) $wpdb->get_var( $wpdb->prepare( "SELECT total_cents FROM {$t( 'mbk_bookings' )} WHERE id = %d", $a['id'] ) ) === $quote->total->cents();
	$mbkCheck( 'anonymize clears guest, booking and event PII; keeps amounts', null !== $guest['anonymized_at'] && '' === $guest['first_name'] && 0 === $pii && 0 === $leaks && $amountOk, sprintf( 'pii rows=%d event leaks=%d', $pii, $leaks ) );

	// 9. Admin booking without payment: held without expiry, never reaped.
	$adminId = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 1 );
	$unitB   = $units[1];
	$unitC   = $units[2];
	$guestM  = array(
		'email'      => 'manual' . $domain,
		'first_name' => 'Mia',
		'last_name'  => 'Manual',
		'country'    => 'de',
		'lang'       => 'en',
	);
	$stayM   = Stay::fromStrings( $unitB->id, '2027-10-11', '2027-10-15', $tz );
	$m       = $bookings->createManual( $stayM, 2, 0, $guestM, null, array( 'mode' => 'none' ), 'Phone booking', $adminId );
	Container::holdReaper()->run();
	$m       = $bookings->find( $m['id'] );
	$mbkCheck( 'manual (none) → admin hold without expiry, nights held, not reaped', 'pending_payment' === $m['status'] && 'admin' === $m['source'] && null === $m['hold_expires_at'] && 'Phone booking' === $m['internal_notes'] && 4 === count( Container::availability()->occupiedNights( $unitB->id, '2027-10-11', '2027-10-15' ) ) );

	// 10. Outside payments move the status by amount.
	$m = $bookings->recordPayment( $m['id'], 'deposit', $m['deposit_cents'], 'cash', 'Paid at reception', $adminId );
	$mbkCheck( 'record deposit (cash) → confirmed', 'confirmed' === $m['status'] && Container::paymentRepository()->netPaidCents( $m['id'] ) === $m['deposit_cents'] );
	try {
		$bookings->recordPayment( $m['id'], 'balance', 0, 'cash', null, $adminId );
		$mbkCheck( 'zero payment rejected', false );
	} catch ( ValidationException $e ) {
		$mbkCheck( 'zero payment rejected', isset( $e->fieldErrors()['amount_cents'] ) );
	}
	$stayP = Stay::fromStrings( $unitB->id, '2027-10-18', '2027-10-22', $tz );
	$quoteP = Container::pricing()->quote( $stayP, 2, 0 );
	$p      = $bookings->createManual( $stayP, 2, 0, $guestM, null, array( 'mode' => 'outside', 'amount_cents' => $quoteP->total->cents(), 'method' => 'bank' ), null, $adminId );
	$payP   = Container::paymentRepository()->forBooking( $p['id'] );
	$mbkCheck( 'manual (outside, full amount) → paid with a bank payment row', 'paid' === $p['status'] && 1 === count( $payP ) && 'bank' === $payP[0]['method'] && 'full' === $payP[0]['kind'] && (int) $p['guest_id'] === (int) $m['guest_id'] );

	// 11. Move: overlapping its own nights, to another unit; old nights released atomically.
	$moveTo = Stay::fromStrings( $unitC->id, '2027-10-12', '2027-10-17', $tz );
	$dry    = $bookings->move( $m['id'], $moveTo, true, false, $adminId );
	$mbkCheck( 'move dry run → available, diff = new − old total', true === $dry['available'] && $dry['diff_cents'] === $dry['quote_new']->total->cents() - (int) $m['total_cents'] );
	$selfShift = $bookings->move( $m['id'], Stay::fromStrings( $unitB->id, '2027-10-12', '2027-10-16', $tz ), true, false, $adminId );
	$mbkCheck( 'move dry run onto its own nights → available', true === $selfShift['available'] );
	$moved = $bookings->move( $m['id'], $moveTo, false, true, $adminId );
	$mbkCheck(
		'move (keep price) → new unit/dates held, old nights free, amounts kept, ref kept',
		(int) $moved['unit_id'] === $unitC->id && '2027-10-12' === $moved['arrival'] && 5 === (int) $moved['nights']
			&& array() === Container::availability()->occupiedNights( $unitB->id, '2027-10-11', '2027-10-15' )
			&& 5 === count( Container::availability()->occupiedNights( $unitC->id, '2027-10-12', '2027-10-17' ) )
			&& (int) $moved['total_cents'] === (int) $m['total_cents'] && $moved['ref'] === $m['ref']
	);
	try {
		$bookings->move( $p['id'], $moveTo, false, false, $adminId );
		$mbkCheck( 'move onto taken nights → UnavailableException, booking unchanged', false, 'moved' );
	} catch ( UnavailableException $e ) {
		$still = $bookings->find( $p['id'] );
		$mbkCheck( 'move onto taken nights → UnavailableException, booking unchanged', (int) $still['unit_id'] === $unitB->id && 4 === count( Container::availability()->occupiedNights( $unitB->id, '2027-10-18', '2027-10-22' ) ) );
	}

	// 12. Blocks: conflict with a booking, then block/unblock free nights.
	try {
		Container::blocks()->create( Stay::fromStrings( $unitC->id, '2027-10-16', '2027-10-19', $tz ), 'Maintenance', $adminId );
		$mbkCheck( 'block over a booking → UnavailableException', false, 'blocked' );
	} catch ( UnavailableException $e ) {
		$mbkCheck( 'block over a booking → UnavailableException, no block row', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t( 'mbk_blocks' )} WHERE unit_id = %d AND reason = %s", $unitC->id, 'Maintenance' ) ) );
	}
	$block         = Container::blocks()->create( Stay::fromStrings( $unitC->id, '2027-10-17', '2027-10-20', $tz ), 'Maintenance', $adminId );
	$mbkBlockIds[] = (int) $block['id'];
	try {
		$blocked = Stay::fromStrings( $unitC->id, '2027-10-18', '2027-10-21', $tz );
		$bookings->create( $blocked, Container::pricing()->quote( $blocked, 2, 0 ), $guestM, 'en' );
		$mbkCheck( 'booking over a block → UnavailableException', false, 'created' );
	} catch ( UnavailableException $e ) {
		$mbkCheck( 'booking over a block → UnavailableException', true );
	}
	Container::blocks()->delete( (int) $block['id'], $adminId );
	$mbkCheck( 'deleting a block frees its nights', array() === Container::availability()->occupiedNights( $unitC->id, '2027-10-17', '2027-10-20' ) && null === Container::blockRepository()->find( (int) $block['id'] ) );

	// 13. Cancel with an outside refund: refund row with the original method, nights released.
	$decision = Container::cancellation()->cancelWithRefunds( $m['id'], 'user:' . $adminId );
	$rows     = Container::paymentRepository()->forBooking( $m['id'] );
	$refunds  = array_values( array_filter( $rows, static fn( array $r ): bool => 'refund' === $r['kind'] ) );
	$mbkCheck(
		'cancel (policy) → cash refund row = deposit, net 0, nights released',
		'cancelled' === $bookings->find( $m['id'] )['status'] && 1 === count( $refunds ) && 'cash' === $refunds[0]['method'] && $refunds[0]['amount_cents'] === (int) $m['deposit_cents']
			&& $decision->refund->cents() === (int) $m['deposit_cents'] && 0 === Container::paymentRepository()->netPaidCents( $m['id'] )
			&& array() === Container::availability()->occupiedNights( $unitC->id, '2027-10-12', '2027-10-17' ),
		wp_json_encode( $decision->toArray() )
	);

	// 14. Refunding card money needs Stripe: without a gateway nothing changes.
	$wpdb->insert( $t( 'mbk_payments' ), array( 'booking_id' => $p['id'], 'kind' => 'deposit', 'method' => 'stripe', 'amount_cents' => 100, 'status' => 'succeeded', 'stripe_intent_id' => 'pi_integration_test', 'created_at' => Db::nowUtc() ) );
	$noGateway = static fn() => null;
	add_filter( 'mbk_payment_gateway', $noGateway, 99 );
	Container::reset();
	try {
		Container::cancellation()->cancelWithRefunds( $p['id'], 'user:' . $adminId );
		$mbkCheck( 'refund including Stripe money without Stripe → payments_unavailable', false, 'cancelled' );
	} catch ( PaymentException $e ) {
		$mbkCheck( 'refund including Stripe money without Stripe → payments_unavailable, booking kept', PaymentException::UNAVAILABLE === $e->errorCode() && 'paid' === $bookings->find( $p['id'] )['status'] );
	} finally {
		remove_filter( 'mbk_payment_gateway', $noGateway, 99 );
		Container::reset();
		$bookings = Container::bookings();
	}

	// 15. An admin hold can be cancelled (pending_payment → cancelled).
	$h = $bookings->createManual( Stay::fromStrings( $unitC->id, '2027-11-08', '2027-11-12', $tz ), 1, 0, $guestM, null, array( 'mode' => 'none' ), null, $adminId );
	Container::cancellation()->cancelWithRefunds( $h['id'], 'user:' . $adminId, 0, 'Guest changed plans' );
	$mbkCheck( 'admin hold cancelled, nights released', 'cancelled' === $bookings->find( $h['id'] )['status'] && array() === Container::availability()->occupiedNights( $unitC->id, '2027-11-08', '2027-11-12' ) );

	// 16. Move (new price) close to arrival: deposit-only booking keeps a due balance.
	$free = static function ( string $from, string $to ) use ( $units, $tz ): ?int {
		foreach ( array_reverse( $units ) as $candidate ) {
			if ( Container::availability()->isAvailable( Stay::fromStrings( $candidate->id, $from, $to, $tz ) ) ) {
				return $candidate->id;
			}
		}
		return null;
	};
	$today    = Container::clock()->today();
	$nearFrom = $today->modify( '+8 days' )->format( 'Y-m-d' );
	$nearTo   = $today->modify( '+12 days' )->format( 'Y-m-d' );
	$farUnit  = $free( '2027-11-15', '2027-11-19' );
	$nearUnit = $free( $nearFrom, $nearTo );
	if ( null === $farUnit || null === $nearUnit ) {
		throw new RuntimeException( 'No free unit for the move checks.' );
	}
	$x = $bookings->createManual( Stay::fromStrings( $farUnit, '2027-11-15', '2027-11-19', $tz ), 2, 0, $guestM, null, array( 'mode' => 'none' ), null, $adminId );
	$x = $bookings->recordPayment( $x['id'], 'deposit', $x['deposit_cents'], 'cash', null, $adminId );
	$x = $bookings->move( $x['id'], Stay::fromStrings( $nearUnit, $nearFrom, $nearTo, $tz ), false, false, $adminId );
	$scheduled = array_column( Container::bookingRepository()->findAll( array( 'status' => array( 'confirmed', 'paid', 'balance_failed' ), 'balance_due_to' => $today->modify( '+30 days' )->format( 'Y-m-d' ), 'payment_state' => 'outstanding' ) ), 'id' );
	$mbkCheck( 'move into balance window → balance due today, still scheduled', 'confirmed' === $x['status'] && $today->format( 'Y-m-d' ) === $x['balance_due_on'] && in_array( (int) $x['id'], array_map( 'intval', $scheduled ), true ), (string) $x['balance_due_on'] );

	// 17. Move to a cheaper stay already covered by the money received → paid.
	$cheapUnit = $free( '2027-11-22', '2027-11-27' );
	$y         = $bookings->createManual( Stay::fromStrings( $cheapUnit, '2027-11-22', '2027-11-27', $tz ), 2, 0, $guestM, null, array( 'mode' => 'none' ), null, $adminId );
	$y         = $bookings->recordPayment( $y['id'], 'deposit', (int) $y['total_cents'] - 100, 'bank', null, $adminId );
	$y         = $bookings->move( $y['id'], Stay::fromStrings( $cheapUnit, '2027-11-22', '2027-11-25', $tz ), false, false, $adminId );
	$mbkCheck( 'move to a cheaper stay covered by payments → paid, no due date', 'paid' === $y['status'] && null === $y['balance_due_on'], $y['status'] );

	// 18. Double submit / idempotency of outside payments.
	$countPayments = static fn( int $id ): int => count( Container::paymentRepository()->forBooking( $id ) );
	$before        = $countPayments( $x['id'] );
	$bookings->recordPayment( $x['id'], 'balance', 100, 'cash', null, $adminId );
	try {
		$bookings->recordPayment( $x['id'], 'balance', 100, 'cash', null, $adminId );
		$mbkCheck( 'identical payment within 10 s → DuplicatePaymentException', false, 'recorded twice' );
	} catch ( DuplicatePaymentException $e ) {
		$mbkCheck( 'identical payment within 10 s → DuplicatePaymentException', $countPayments( $x['id'] ) === $before + 1 );
	}
	$key = 'itest-' . wp_generate_uuid4();
	$bookings->recordPayment( $x['id'], 'balance', 200, 'bank', null, $adminId, $key );
	$bookings->recordPayment( $x['id'], 'balance', 200, 'bank', null, $adminId, $key );
	$mbkCheck( 'repeated idempotency_key records one payment', $countPayments( $x['id'] ) === $before + 2 );

	// 19. Status re-checked under the lock: no payment on a booking cancelled meanwhile.
	// Simulates a concurrent cancel between the pre-check and the locked re-check.
	$yPayments    = $countPayments( $y['id'] );
	$cancelOnLock = null;
	$cancelOnLock = static function ( string $sql ) use ( $wpdb, $t, $y, &$cancelOnLock ): string {
		if ( str_contains( $sql, 'SET updated_at = updated_at' ) || str_contains( $sql, 'FOR UPDATE' ) ) {
			remove_filter( 'query', $cancelOnLock );
			$wpdb->update( $t( 'mbk_bookings' ), array( 'status' => 'cancelled' ), array( 'id' => $y['id'] ) );
		}
		return $sql;
	};
	add_filter( 'query', $cancelOnLock );
	try {
		$bookings->recordPayment( $y['id'], 'balance', 300, 'cash', null, $adminId );
		$mbkCheck( 'payment on a booking cancelled meanwhile → rejected under the lock', false );
	} catch ( InvalidTransitionException $e ) {
		$mbkCheck( 'payment on a booking cancelled meanwhile → rejected under the lock', $countPayments( $y['id'] ) === $yPayments );
	} finally {
		remove_filter( 'query', $cancelOnLock );
	}

	// 20. Admin holds count against a coupon's usage limit.
	$couponCode    = 'MBKITEST' . strtoupper( wp_generate_password( 4, false ) );
	$mbkCouponId   = Container::couponRepository()->insert( array( 'code' => $couponCode, 'type' => 'pct', 'amount' => 10, 'usage_limit' => 1, 'active' => true ) );
	$holdUnit      = $free( '2027-12-06', '2027-12-10' );
	$bookings->createManual( Stay::fromStrings( $holdUnit, '2027-12-06', '2027-12-10', $tz ), 2, 0, $guestM, $couponCode, array( 'mode' => 'none' ), null, $adminId );
	$guestStayUnit = $free( '2027-12-13', '2027-12-17' );
	$guestStay     = Stay::fromStrings( $guestStayUnit, '2027-12-13', '2027-12-17', $tz );
	try {
		$bookings->create( $guestStay, Container::pricing()->quote( $guestStay, 2, 0, $couponCode ), array( 'email' => 'other' . $domain ), 'hr' );
		$mbkCheck( 'coupon limit counts admin holds', false, 'second use accepted' );
	} catch ( CouponException $e ) {
		$mbkCheck( 'coupon limit counts admin holds', 'limit' === ( $e->context()['reason'] ?? '' ) );
	}

	// 21. A coupon carried by an active booking is only deactivated.
	wp_set_current_user( $adminId );
	$deleted = rest_do_request( new WP_REST_Request( 'DELETE', '/marclie-booking/v1/admin/coupons/' . $mbkCouponId ) )->get_data();
	$row     = Container::couponRepository()->findRow( $mbkCouponId );
	$mbkCheck( 'DELETE coupon in use by a hold → deactivated, not deleted', ! empty( $deleted['deactivated'] ) && null !== $row && 0 === (int) $row['active'] );

	// 22. Invalid price rows are refused as a whole.
	try {
		Container::prices()->save( array( array( 'unit_id' => $unit->id, 'min_stay' => 0, 'months' => array_fill( 0, 12, -1 ) ) ), 'test' );
		$mbkCheck( 'invalid prices → 422', false );
	} catch ( ValidationException $e ) {
		$mbkCheck( 'invalid prices → 422, table unchanged', isset( $e->fieldErrors()['changes.0.min_stay'], $e->fieldErrors()['changes.0.months.0'] ) && 3 === Container::priceRepository()->table()->minStay( $unit->id ) );
	}

	// 23. Anonymize refuses a guest with upcoming bookings unless forced.
	try {
		Container::guests()->anonymize( (int) $x['guest_id'], 'test' );
		$mbkCheck( 'anonymize with upcoming bookings → refused', false );
	} catch ( GuestHasActiveBookingsException $e ) {
		$mbkCheck( 'anonymize with upcoming bookings → refused, PII kept', '' !== Container::guestRepository()->find( (int) $x['guest_id'] )['first_name'] );
	}

	// 24. Public calendar cache (January 2028): primed, then invalidated by create / block / prices / cancel.
	$unitS = $units[ count( $units ) - 1 ];
	$range = static fn(): array => Container::availabilityCache()->range( $unitS->id, '2028-01', 1 );
	$jan   = $range();
	$mbkCheck( 'availability: January free, min stay 3, range [2028-01-01, 2028-02-01)', array() === $jan['occupied'] && array() === $jan['closed'] && 3 === ( $jan['min_stay']['2028-01-10'] ?? null ) && 31 === count( $jan['min_stay'] ) && '2028-02-01' === $jan['to'] );

	$stayS = Stay::fromStrings( $unitS->id, '2028-01-10', '2028-01-14', $tz );
	$s     = $bookings->create( $stayS, Container::pricing()->quote( $stayS, 2, 0, null, null, 2 ), array( 'email' => 'guest.s' . $domain ), 'hr' )['booking'];
	$mbkCheck( 'children 0–4 stored on the booking and its quote, not charged', 2 === (int) $s['infants'] && 2 === (int) json_decode( (string) $s['quote_json'], true )['infants'] && (int) $s['total_cents'] === Container::pricing()->quote( $stayS, 2, 0 )->total->cents() );
	$mbkCheck( 'availability cache invalidated by create → held nights listed', array( '2028-01-10', '2028-01-11', '2028-01-12', '2028-01-13' ) === $range()['occupied'] );
	$mbkCheck( 'mbk_is_available false on held nights, true next to them', ! mbk_is_available( $unitS->id, '2028-01-12', '2028-01-16' ) && mbk_is_available( $unitS->id, '2028-01-14', '2028-01-17' ) );

	$found  = Container::search()->search( Stay::fromStrings( 0, '2028-01-10', '2028-01-14', $tz ), 2, 0, 'hr' );
	$byId   = array_column( $found, null, 'id' );
	$freeId = $units[0]->id;
	$mbkCheck(
		'search: held unit unavailable, a free unit priced, every unit listed',
		count( $found ) === count( $units ) && false === $byId[ $unitS->id ]['available'] && 'unavailable' === $byId[ $unitS->id ]['reason']
			&& true === $byId[ $freeId ]['available'] && null === $byId[ $freeId ]['reason'] && $byId[ $freeId ]['total_cents'] === Container::pricing()->quote( Stay::fromStrings( $freeId, '2028-01-10', '2028-01-14', $tz ), 2, 0 )->total->cents()
	);
	$short = array_column( Container::search()->search( Stay::fromStrings( 0, '2028-01-17', '2028-01-19', $tz ), 2, 0, 'hr' ), null, 'id' );
	$mbkCheck( 'search: 2 nights → min_stay, no total', 'min_stay' === $short[ $freeId ]['reason'] && null === $short[ $freeId ]['total_cents'] && $short[ $freeId ]['nightly_from_cents'] === $mbkTestPrice * $units[0]->capacity );
	$mbkCheck( 'mbk_get_price_from = lowest apartment price per night', mbk_get_price_from( $freeId ) instanceof \Marclie\Booking\Domain\Money && mbk_get_price_from( $freeId )->cents() === $short[ $freeId ]['nightly_from_cents'] );

	$blockS        = Container::blocks()->create( Stay::fromStrings( $unitS->id, '2028-01-24', '2028-01-26', $tz ), 'Integration', $adminId );
	$mbkBlockIds[] = (int) $blockS['id'];
	$mbkCheck( 'availability cache invalidated by block create', in_array( '2028-01-25', $range()['occupied'], true ) );
	Container::blocks()->delete( (int) $blockS['id'], $adminId );
	$mbkCheck( 'availability cache invalidated by block delete', ! in_array( '2028-01-25', $range()['occupied'], true ) );
	try {
		Container::blocks()->createMany( array( Stay::fromStrings( $freeId, '2028-01-12', '2028-01-15', $tz ), Stay::fromStrings( $unitS->id, '2028-01-12', '2028-01-15', $tz ) ), 'Closing', $adminId );
		$mbkCheck( 'block several units, one taken → UnavailableException', false );
	} catch ( UnavailableException $e ) {
		$mbkCheck( 'block several units, one taken → nothing blocked', $unitS->id === (int) $e->context()['unit_id'] && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t( 'mbk_blocks' )} WHERE reason = %s", 'Closing' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
	$closing = Container::blocks()->createMany( array( Stay::fromStrings( $freeId, '2028-01-20', '2028-01-23', $tz ), Stay::fromStrings( $unitS->id, '2028-01-20', '2028-01-23', $tz ) ), 'Closing', $adminId );
	foreach ( $closing as $block ) {
		$mbkBlockIds[] = (int) $block['id'];
	}
	$mbkCheck( 'block several units → one block each, nights occupied', 2 === count( $closing ) && in_array( '2028-01-21', $range()['occupied'], true ) && ! mbk_is_available( $freeId, '2028-01-21', '2028-01-24' ) );
	foreach ( $closing as $block ) {
		Container::blocks()->delete( (int) $block['id'], $adminId );
	}

	try {
		Container::prices()->save( array( $mbkOpenRow( $unitS->id, 4 ) ), 'test' );
		$mbkCheck( 'availability cache invalidated by a price change (min stay 4)', 4 === ( $range()['min_stay']['2028-01-17'] ?? null ) );
		Container::prices()->save( array( $mbkOpenRow( $unitS->id, 3, array( 1 ) ) ), 'test' );
		$closedJan = $range();
		$mbkCheck( 'closed month: every January night closed, no arrival', 31 === count( $closedJan['closed'] ) && array() === $closedJan['min_stay'] );
		try {
			Container::pricing()->quote( Stay::fromStrings( $unitS->id, '2027-12-30', '2028-01-02', $tz ), 2, 0 );
			$mbkCheck( 'closed month: a stay into it → no_rate', false );
		} catch ( \Marclie\Booking\Domain\Exception\NoRateException $e ) {
			$mbkCheck( 'closed month: a stay into it → no_rate', '2028-01-01' === $e->context()['date'] );
		}
	} finally {
		Container::prices()->save( array( $mbkOpenRow( $unitS->id ) ), 'test' );
	}

	$bookings->transition( (int) $s['id'], BookingStatus::Cancelled, 'test' );
	$mbkCheck( 'availability cache invalidated by cancel → nights free again', array() === $range()['occupied'] && 3 === ( $range()['min_stay']['2028-01-17'] ?? null ) );

	// 25. Public REST: headers, error shape, language.
	wp_set_current_user( 0 );
	$req = new WP_REST_Request( 'GET', '/marclie-booking/v1/availability' );
	$req->set_query_params( array( 'unit' => $unitS->id, 'from' => '2027-12', 'months' => 1 ) );
	$res = rest_do_request( $req );
	$mbkCheck( 'GET /availability → 200, public max-age=60', 200 === $res->get_status() && 'public, max-age=60' === ( $res->get_headers()['Cache-Control'] ?? '' ) );
	$req = new WP_REST_Request( 'GET', '/marclie-booking/v1/search' );
	$req->set_query_params( array( 'dolazak' => '2020-01-01', 'odlazak' => '2020-01-05' ) );
	$req->set_header( 'X-MBK-Lang', 'en' );
	$res = rest_do_request( $req );
	$mbkCheck( 'GET /search past dates → 422 past_date, EN message, not cached', 422 === $res->get_status() && 'past_date' === ( $res->get_data()['code'] ?? '' ) && 'The arrival date is in the past.' === ( $res->get_data()['message'] ?? '' ) && 'no-store' === ( $res->get_headers()['Cache-Control'] ?? '' ) );
	$req = new WP_REST_Request( 'POST', '/marclie-booking/v1/quote' );
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_body( (string) wp_json_encode( array( 'unit_id' => 999999, 'arrival' => '2027-12-06', 'departure' => '2027-12-10', 'adults' => 2 ) ) );
	$res = rest_do_request( $req );
	$mbkCheck( 'POST /quote unknown unit → 404 unit_not_found', 404 === $res->get_status() && 'unit_not_found' === ( $res->get_data()['code'] ?? '' ) );

	// 25b. Booking horizon: arrival ≤ last day of (current month + 24) everywhere.
	$lastArrival  = BookingHorizon::lastArrival( Container::clock()->today() );
	$afterHorizon = ( new DateTimeImmutable( $lastArrival ) )->modify( '+1 day' )->format( 'Y-m-d' );
	$plus4        = static fn( string $date ): string => ( new DateTimeImmutable( $date ) )->modify( '+4 days' )->format( 'Y-m-d' );
	$quoteAt      = static function ( string $arrival, string $lang ) use ( $unitS, $plus4 ): WP_REST_Response {
		$req = new WP_REST_Request( 'POST', '/marclie-booking/v1/quote' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_header( 'X-MBK-Lang', $lang );
		$req->set_body( (string) wp_json_encode( array( 'unit_id' => $unitS->id, 'arrival' => $arrival, 'departure' => $plus4( $arrival ), 'adults' => 2 ) ) );
		return rest_do_request( $req );
	};
	$res = $quoteAt( $lastArrival, 'hr' );
	$mbkCheck( 'POST /quote arriving on the last horizon day → 200', 200 === $res->get_status(), wp_json_encode( $res->get_data() ) );
	$res = $quoteAt( $afterHorizon, 'hr' );
	$mbkCheck( 'POST /quote one day after the horizon → 422 beyond_horizon, HR message', 422 === $res->get_status() && 'beyond_horizon' === ( $res->get_data()['code'] ?? '' ) && 'Rezervacije su moguće najviše 24 mjeseca unaprijed.' === ( $res->get_data()['message'] ?? '' ), wp_json_encode( $res->get_data() ) );
	$res = $quoteAt( $afterHorizon, 'en' );
	$mbkCheck( 'POST /quote after the horizon → EN message', 'Bookings are possible up to 24 months ahead.' === ( $res->get_data()['message'] ?? '' ), wp_json_encode( $res->get_data() ) );
	$req = new WP_REST_Request( 'GET', '/marclie-booking/v1/search' );
	$req->set_query_params( array( 'dolazak' => $afterHorizon, 'odlazak' => $plus4( $afterHorizon ) ) );
	$horizonUnits = (array) ( rest_do_request( $req )->get_data()['units'] ?? array() );
	$mbkCheck( 'GET /search after the horizon → every unit reason beyond_horizon, no total', array() !== $horizonUnits && array() === array_filter( $horizonUnits, static fn( array $u ): bool => 'beyond_horizon' !== $u['reason'] || null !== $u['total_cents'] ) );
	wp_set_current_user( $adminId );
	$req = new WP_REST_Request( 'POST', '/marclie-booking/v1/admin/bookings/quote' );
	$req->set_body_params( array( 'unit_id' => $unitS->id, 'arrival' => $afterHorizon, 'departure' => $plus4( $afterHorizon ), 'adults' => 2 ) );
	$res = rest_do_request( $req );
	$mbkCheck( 'admin quote after the horizon → 422 beyond_horizon (same rule)', 422 === $res->get_status() && 'beyond_horizon' === ( $res->get_data()['code'] ?? '' ), wp_json_encode( $res->get_data() ) );
	wp_set_current_user( 0 );
	$edge = Container::availabilityCache()->range( $unitS->id, substr( $lastArrival, 0, 7 ), 2 );
	$mbkCheck( 'availability: min stay up to the last horizon day, none after (no check-in)', isset( $edge['min_stay'][ $lastArrival ] ) && ! isset( $edge['min_stay'][ $afterHorizon ] ) );
	[ , $windowLast ] = Container::availabilityCache()->window();
	$mbkCheck( 'availability window ends in the horizon month', substr( $lastArrival, 0, 7 ) === $windowLast );

	// 26+. Checkout, webhooks, balances and refunds with a fake Stripe gateway.
	require __DIR__ . '/payments.php';

	// 38+. Emails: lifecycle, scheduler, editor REST, resend, previews.
	require __DIR__ . '/emails.php';

	// Bricks elements, dynamic tags and query type (phase 08).
	if ( defined( 'BRICKS_VERSION' ) ) {
		require __DIR__ . '/bricks.php';
	}

	// SEO over HTTP: robots, canonical, JSON-LD, lang switch, sitemap (phase 09).
	require __DIR__ . '/seo.php';

	// Guest accounts: registration, verification, membership, card change (phase 10).
	require __DIR__ . '/accounts.php';

	// iCal busy/free sync with mocked Airbnb / Booking.com feeds (phase 11).
	require __DIR__ . '/ical.php';
} catch ( \Throwable $e ) {
	$mbkCheck( 'unexpected exception', false, get_class( $e ) . ': ' . $e->getMessage() );
} finally {
	remove_action( 'mbk_booking_created', $mbkTrack );
	remove_filter( 'mbk_email_args', $mbkMailMeta, 999 );
	remove_filter( 'pre_wp_mail', $mbkMailCapture, 999 );

	$guestIds = array_merge(
		$mbkGuestIds,
		array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t( 'mbk_guests' )} WHERE email LIKE %s", '%' . $wpdb->esc_like( $domain ) ) ) )
	);
	$bookingIds = array_merge(
		$mbkBookingIds,
		$guestIds ? array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t( 'mbk_bookings' )} WHERE guest_id IN (" . Db::placeholders( $guestIds ) . ')', $guestIds ) ) ) : array()
	);
	$bookingIds = array_values( array_unique( $bookingIds ) );
	$guestIds   = array_values( array_unique( $guestIds ) );

	if ( $mbkCouponId ) {
		$wpdb->delete( $t( 'mbk_coupon_redemptions' ), array( 'coupon_id' => $mbkCouponId ) );
		$wpdb->delete( $t( 'mbk_coupons' ), array( 'id' => $mbkCouponId ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t( 'mbk_events' )} WHERE booking_id IS NULL AND type LIKE %s AND payload_json LIKE %s", 'coupon\\_%', '%"coupon_id":' . $mbkCouponId . ',%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
	foreach ( $mbkBlockIds as $blockId ) {
		$wpdb->delete( $t( 'mbk_nights' ), array( 'block_id' => $blockId ) );
		$wpdb->delete( $t( 'mbk_blocks' ), array( 'id' => $blockId ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t( 'mbk_events' )} WHERE booking_id IS NULL AND type IN ('block_created', 'block_deleted') AND payload_json LIKE %s", '%"block_id":' . $blockId . ',%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	if ( $bookingIds ) {
		$in = Db::placeholders( $bookingIds );
		foreach ( array( 'mbk_nights', 'mbk_coupon_redemptions', 'mbk_payments', 'mbk_events', 'mbk_booking_tokens' ) as $table ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t( $table )} WHERE booking_id IN ({$in})", $bookingIds ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t( 'mbk_bookings' )} WHERE id IN ({$in})", $bookingIds ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
	if ( $guestIds ) {
		$in = Db::placeholders( $guestIds );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t( 'mbk_events' )} WHERE guest_id IN ({$in})", $guestIds ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t( 'mbk_guests' )} WHERE id IN ({$in})", $guestIds ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
	if ( null !== $usedBefore ) {
		$wpdb->update( $t( 'mbk_coupons' ), array( 'used_count' => $usedBefore ), array( 'id' => (int) $couponRow['id'] ) );
	}
	// The real price table comes back (rows written directly: no event, memo flushed).
	$wpdb->query( "DELETE FROM {$t( 'mbk_prices' )}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	foreach ( $mbkPricesBefore as $row ) {
		$wpdb->insert( $t( 'mbk_prices' ), $row );
	}
	$wpdb->query( "DELETE FROM {$t( 'mbk_events' )} WHERE booking_id IS NULL AND type = 'prices_changed' AND actor = 'test'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	Container::priceRepository()->flush();
	// Rows above were deleted directly: drop the cached public calendars.
	AvailabilityCache::invalidate( 0 );
	printf( "cleanup: %d booking(s), %d guest(s), %d block(s) removed\n", count( $bookingIds ), count( $guestIds ), count( $mbkBlockIds ) );
}

$failed = count( array_filter( $mbkResults, static fn( bool $ok ): bool => ! $ok ) );
printf( "%d passed, %d failed\n", count( $mbkResults ) - $failed, $failed );
if ( $failed > 0 ) {
	exit( 1 );
}
