<?php
/**
 * Persistence port of the payment services (reconciler, refunds, balance
 * charger, webhook handler): bookings, payment rows, audit events and webhook
 * dedupe. WpPaymentLedger is the database implementation; unit tests use an
 * in-memory fake. Rows use the mbk_bookings / mbk_payments column names.
 */

namespace Marclie\Booking\Payment;

use Marclie\Booking\Domain\BookingStatus;

defined( 'ABSPATH' ) || exit;

interface PaymentLedger {

	/**
	 * Runs `$fn` in one transaction (nested calls join the outer one).
	 *
	 * @template T
	 * @param callable(): T $fn
	 * @return T
	 */
	public function transaction( callable $fn );

	/** @return array<string, mixed>|null */
	public function booking( int $id ): ?array;

	/**
	 * Locks the booking row for the current transaction and returns it fresh.
	 *
	 * @return array<string, mixed>|null
	 */
	public function lockBooking( int $id ): ?array;

	/** @param array<string, mixed> $row */
	public function updateBooking( int $id, array $row ): void;

	/**
	 * BookingService::transition().
	 *
	 * @param array<string, mixed> $context
	 * @return array<string, mixed> The updated booking.
	 */
	public function transition( int $id, BookingStatus $to, string $actor, array $context = array(), bool $latePayment = false ): array;

	/**
	 * BookingService::reviveExpired(): re-holds the nights of an expired booking
	 * and moves it to `$to`; false when a night is taken.
	 *
	 * @param array<string, mixed> $context
	 */
	public function reviveExpired( int $id, BookingStatus $to, string $actor, array $context = array() ): bool;

	/**
	 * Confirmed / balance_failed bookings with balance_due_on ≤ `$todayYmd` and money outstanding.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function balanceCandidates( string $todayYmd ): array;

	public function unitTitle( int $unitId ): string;

	/** @return array<string, mixed>|null */
	public function payment( int $id ): ?array;

	/** @return list<array<string, mixed>> Oldest first. */
	public function payments( int $bookingId ): array;

	/**
	 * Charge row (not a refund) of a PaymentIntent.
	 *
	 * @return array<string, mixed>|null
	 */
	public function paymentByIntent( string $intentId ): ?array;

	/** @return array<string, mixed>|null */
	public function paymentByRefund( string $refundId ): ?array;

	/** @param array<string, mixed> $row */
	public function insertPayment( array $row ): int;

	/** @param array<string, mixed> $row */
	public function updatePayment( int $id, array $row ): void;

	/**
	 * Stripe refund rows not sent yet (pending, no refund id).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function unsentRefunds(): array;

	/** @param array<string, mixed> $payload */
	public function log( string $type, string $actor, ?int $bookingId, array $payload = array() ): void;

	/** Whether a webhook event was already processed. */
	public function webhookSeen( string $eventId ): bool;

	/** Records a processed webhook event (no-op when recorded concurrently). */
	public function markWebhook( string $eventId, string $type, ?int $bookingId ): void;
}
