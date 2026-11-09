<?php
/**
 * mbk_payments: kind deposit|balance|full|refund, status
 * scheduled|pending|succeeded|failed|refunded, method stripe|cash|bank|other
 * (anything but stripe was handled outside the system and recorded by an admin).
 * Amounts are positive cents.
 */

namespace Marclie\Booking\Repository;

defined( 'ABSPATH' ) || exit;

final class PaymentRepository {

	public const CHARGE_KINDS = array( 'deposit', 'balance', 'full' );

	public const KINDS = array( 'deposit', 'balance', 'full', 'refund' );

	public const STATUSES = array( 'scheduled', 'pending', 'succeeded', 'failed', 'refunded' );

	public const METHOD_STRIPE = 'stripe';

	/** Methods an admin can record for money handled outside the system. */
	public const OUTSIDE_METHODS = array( 'cash', 'bank', 'other' );

	/** Refund rows that reduce the amount received: done, or requested and not failed yet. */
	public const REFUND_COUNTED = array( 'succeeded', 'pending' );

	/**
	 * SQL expression (payments aliased `p`) of a row's contribution to the net
	 * amount received: charges that were received (succeeded, or refunded later)
	 * count positive, succeeded and pending refunds negative (a pending refund is
	 * already promised: never refund it twice). Mirrors netPaidCents().
	 */
	public const NET_SQL = "CASE WHEN p.kind = 'refund' AND p.status IN ('succeeded', 'pending') THEN -p.amount_cents WHEN p.kind <> 'refund' AND p.status IN ('succeeded', 'refunded') THEN p.amount_cents ELSE 0 END";

	private function table(): string {
		return Db::table( 'mbk_payments' );
	}

	/** @param array<string, mixed> $row */
	public function insert( array $row ): int {
		global $wpdb;

		$row['created_at'] ??= Db::nowUtc();

		$ok = $wpdb->insert( $this->table(), $row );

		return false === $ok ? 0 : (int) $wpdb->insert_id;
	}

	/** @param array<string, mixed> $row */
	public function update( int $id, array $row ): bool {
		global $wpdb;

		return false !== $wpdb->update( $this->table(), $row, array( 'id' => $id ) );
	}

	/** @return array<string, mixed>|null */
	public function find( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Charge rows (not refunds) for a PaymentIntent.
	 *
	 * @return array<string, mixed>|null
	 */
	public function findByIntent( string $intentId ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE stripe_intent_id = %s AND kind <> %s ORDER BY id ASC LIMIT 1", $intentId, 'refund' ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $row ? self::hydrate( $row ) : null;
	}

	/** @return array<string, mixed>|null */
	public function findByRefundId( string $refundId ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE stripe_refund_id = %s", $refundId ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? self::hydrate( $row ) : null;
	}

	/** @return array<string, mixed>|null */
	public function findByIdempotencyKey( string $key ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE idempotency_key = %s", $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Whether an identical succeeded charge was recorded at or after `$sinceUtc`.
	 */
	public function hasRecentDuplicate( int $bookingId, string $kind, int $amountCents, string $method, string $sinceUtc ): bool {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->table()} WHERE booking_id = %d AND kind = %s AND amount_cents = %d AND method = %s AND status = %s AND created_at >= %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$bookingId,
				$kind,
				$amountCents,
				$method,
				'succeeded',
				$sinceUtc
			)
		);
	}

	/**
	 * Clears admin notes of the given bookings' payments (GDPR anonymization).
	 *
	 * @param list<int> $bookingIds
	 */
	public function clearNotes( array $bookingIds ): bool {
		global $wpdb;

		if ( ! $bookingIds ) {
			return true;
		}

		return false !== $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET note = NULL WHERE booking_id IN (" . Db::placeholders( $bookingIds ) . ')', $bookingIds ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** @return list<array<string, mixed>> */
	public function forBooking( int $bookingId ): array {
		global $wpdb;

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE booking_id = %d ORDER BY id ASC", $bookingId ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/**
	 * Net amount received: received charges minus succeeded and pending refunds (see NET_SQL).
	 */
	public function netPaidCents( int $bookingId ): int {
		return self::net( $this->forBooking( $bookingId ) );
	}

	/**
	 * Net of payment rows (see NET_SQL).
	 *
	 * @param list<array<string, mixed>> $payments
	 */
	public static function net( array $payments ): int {
		$net = 0;
		foreach ( $payments as $payment ) {
			$net += self::contribution( $payment );
		}

		return $net;
	}

	/**
	 * Signed contribution of one row to the net amount received.
	 *
	 * @param array<string, mixed> $payment
	 */
	public static function contribution( array $payment ): int {
		if ( 'refund' === $payment['kind'] ) {
			return in_array( $payment['status'], self::REFUND_COUNTED, true ) ? -(int) $payment['amount_cents'] : 0;
		}
		if ( in_array( $payment['kind'], self::CHARGE_KINDS, true ) && in_array( $payment['status'], array( 'succeeded', 'refunded' ), true ) ) {
			return (int) $payment['amount_cents'];
		}

		return 0;
	}

	/**
	 * Net received per booking (see NET_SQL), for many bookings in one query.
	 *
	 * @param list<int> $bookingIds
	 * @return array<int, int> booking_id => cents (missing = 0).
	 */
	public function netPaidByBooking( array $bookingIds ): array {
		global $wpdb;

		if ( ! $bookingIds ) {
			return array();
		}

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT p.booking_id AS booking_id, SUM(' . self::NET_SQL . ") AS net FROM {$this->table()} p WHERE p.booking_id IN (" . Db::placeholders( $bookingIds ) . ') GROUP BY p.booking_id', // phpcs:ignore WordPress.DB.PreparedSQL
				$bookingIds
			),
			ARRAY_A
		);

		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['booking_id'] ] = (int) $row['net'];
		}

		return $out;
	}

	/**
	 * Net received per payment method for one booking (refunds subtract from their method).
	 *
	 * @return array<string, int> method => cents.
	 */
	public function netPaidByMethod( int $bookingId ): array {
		$out = array();
		foreach ( $this->forBooking( $bookingId ) as $payment ) {
			$out[ $payment['method'] ] = ( $out[ $payment['method'] ] ?? 0 ) + self::contribution( $payment );
		}

		return $out;
	}

	/**
	 * Refund rows not yet sent to Stripe (pending, no refund id), oldest first.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function unsentStripeRefunds( int $limit = 50 ): array {
		global $wpdb;

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE kind = 'refund' AND method = %s AND status = 'pending' AND stripe_refund_id IS NULL ORDER BY id ASC LIMIT %d", self::METHOD_STRIPE, $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/**
	 * Created time (UTC) of the oldest succeeded Stripe charge created in [sinceUtc, untilUtc), or null.
	 */
	public function oldestStripeChargeBetween( string $sinceUtc, string $untilUtc ): ?string {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MIN(created_at) FROM {$this->table()} WHERE method = %s AND kind <> 'refund' AND status IN ('succeeded', 'refunded') AND created_at >= %s AND created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::METHOD_STRIPE,
				$sinceUtc,
				$untilUtc
			)
		);

		return null === $value || '' === $value ? null : (string) $value;
	}

	/**
	 * Filtered, paginated rows joined with the booking ref and guest name.
	 *
	 * @param array{status?:string, kind?:string, method?:string, from_utc?:string, to_utc?:string, booking_id?:int} $filters
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function search( array $filters, int $page, int $perPage, string $orderby = 'created_at', string $order = 'DESC' ): array {
		global $wpdb;

		[ $where, $args ] = $this->where( $filters );
		$bookings         = Db::table( 'mbk_bookings' );
		$order            = 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC';
		$column           = 'amount' === $orderby ? 'p.amount_cents' : 'p.created_at';

		$total = (int) $wpdb->get_var( $this->prepare( "SELECT COUNT(*) FROM {$this->table()} p WHERE {$where}", $args ) );
		$rows  = (array) $wpdb->get_results(
			$this->prepare(
				"SELECT p.*, b.ref AS booking_ref, b.first_name AS booking_first_name, b.last_name AS booking_last_name FROM {$this->table()} p LEFT JOIN {$bookings} b ON b.id = p.booking_id WHERE {$where} ORDER BY {$column} {$order}, p.id {$order} LIMIT %d OFFSET %d",
				array_merge( $args, array( $perPage, ( $page - 1 ) * $perPage ) )
			),
			ARRAY_A
		);

		return array(
			'items' => array_map( array( self::class, 'hydrate' ), $rows ),
			'total' => $total,
		);
	}

	/**
	 * Received charges and succeeded refunds among the rows matching the same
	 * filters as search() (status, kind, method, dates).
	 *
	 * @param array{status?:string, kind?:string, method?:string, from_utc?:string, to_utc?:string} $filters
	 * @return array{succeeded_cents:int, refunded_cents:int}
	 */
	public function totals( array $filters ): array {
		global $wpdb;

		[ $where, $args ] = $this->where( $filters );

		$row = $wpdb->get_row(
			$this->prepare(
				"SELECT COALESCE(SUM(CASE WHEN p.kind <> 'refund' AND p.status IN ('succeeded', 'refunded') THEN p.amount_cents ELSE 0 END), 0) AS succeeded, COALESCE(SUM(CASE WHEN p.kind = 'refund' AND p.status = 'succeeded' THEN p.amount_cents ELSE 0 END), 0) AS refunded FROM {$this->table()} p WHERE {$where}",
				$args
			),
			ARRAY_A
		);

		return array(
			'succeeded_cents' => (int) ( $row['succeeded'] ?? 0 ),
			'refunded_cents'  => (int) ( $row['refunded'] ?? 0 ),
		);
	}

	/**
	 * Net received (charges minus refunds) by payments created in [fromUtc, toUtc).
	 */
	public function netReceivedBetween( string $fromUtc, string $toUtc ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(' . self::NET_SQL . "), 0) FROM {$this->table()} p WHERE p.created_at >= %s AND p.created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$fromUtc,
				$toUtc
			)
		);
	}

	/**
	 * Payments of every booking of a guest, newest first, with the booking ref.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function forGuest( int $guestId ): array {
		global $wpdb;

		$bookings = Db::table( 'mbk_bookings' );
		$rows     = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.*, b.ref AS booking_ref, b.first_name AS booking_first_name, b.last_name AS booking_last_name FROM {$this->table()} p INNER JOIN {$bookings} b ON b.id = p.booking_id WHERE b.guest_id = %d ORDER BY p.created_at DESC, p.id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$guestId
			),
			ARRAY_A
		);

		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/**
	 * @param array<string, mixed> $filters
	 * @return array{0: string, 1: list<mixed>}
	 */
	private function where( array $filters ): array {
		$where = array( '1=1' );
		$args  = array();
		foreach ( array( 'status', 'kind', 'method' ) as $column ) {
			if ( ! empty( $filters[ $column ] ) ) {
				$where[] = "p.{$column} = %s";
				$args[]  = (string) $filters[ $column ];
			}
		}
		if ( ! empty( $filters['booking_id'] ) ) {
			$where[] = 'p.booking_id = %d';
			$args[]  = (int) $filters['booking_id'];
		}
		if ( ! empty( $filters['from_utc'] ) ) {
			$where[] = 'p.created_at >= %s';
			$args[]  = (string) $filters['from_utc'];
		}
		if ( ! empty( $filters['to_utc'] ) ) {
			$where[] = 'p.created_at < %s';
			$args[]  = (string) $filters['to_utc'];
		}

		return array( implode( ' AND ', $where ), $args );
	}

	/** @param list<mixed> $args */
	private function prepare( string $sql, array $args ): string {
		global $wpdb;

		return $args ? (string) $wpdb->prepare( $sql, $args ) : $sql; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function deleteForBooking( int $bookingId ): void {
		global $wpdb;

		$wpdb->delete( $this->table(), array( 'booking_id' => $bookingId ), array( '%d' ) );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		foreach ( array( 'id', 'booking_id', 'amount_cents', 'attempts' ) as $column ) {
			$row[ $column ] = (int) $row[ $column ];
		}
		$row['method'] = (string) ( $row['method'] ?? self::METHOD_STRIPE );

		return $row;
	}
}
