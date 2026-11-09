<?php
/**
 * In-memory PaymentLedger for the payment service tests. Transactions roll
 * the arrays back on exceptions; transitions follow BookingStatus; whether an
 * expired booking's nights are still free is set with `$nightsFree`.
 */

namespace Marclie\Booking\Tests\Fakes;

use Marclie\Booking\Domain\BookingStatus;
use Marclie\Booking\Domain\Exception\InvalidTransitionException;
use Marclie\Booking\Payment\PaymentLedger;
use Marclie\Booking\Repository\PaymentRepository;

final class ArrayPaymentLedger implements PaymentLedger {

	/** @var array<int, array<string, mixed>> */
	public array $bookings = array();

	/** @var array<int, array<string, mixed>> */
	public array $payments = array();

	/** @var list<array{type:string, actor:string, booking_id:?int, payload:array<string, mixed>}> */
	public array $events = array();

	/** @var array<string, string> Webhook event ID => type. */
	public array $webhooks = array();

	/** @var list<array{id:int, from:string, to:string}> */
	public array $transitions = array();

	public bool $nightsFree = true;

	private int $paymentSeq = 0;

	/** @param array<string, mixed> $row */
	public function addBooking( array $row ): array {
		$id                    = (int) $row['id'];
		$this->bookings[ $id ] = $row + array(
			'ref'                      => 'BK-27-0712-' . $id,
			'unit_id'                  => 10,
			'guest_id'                 => 1,
			'status'                   => 'pending_payment',
			'total_cents'              => 136500,
			'deposit_cents'            => 40950,
			'balance_due_on'           => '2027-06-28',
			'hold_expires_at'          => null,
			'stripe_customer_id'       => null,
			'stripe_payment_method_id' => null,
		);

		return $this->bookings[ $id ];
	}

	public function transaction( callable $fn ) {
		$snapshot = array( $this->bookings, $this->payments, $this->events, $this->webhooks, $this->transitions, $this->paymentSeq );
		try {
			return $fn();
		} catch ( \Throwable $e ) {
			[ $this->bookings, $this->payments, $this->events, $this->webhooks, $this->transitions, $this->paymentSeq ] = $snapshot;
			throw $e;
		}
	}

	public function booking( int $id ): ?array {
		return $this->bookings[ $id ] ?? null;
	}

	public function lockBooking( int $id ): ?array {
		return $this->booking( $id );
	}

	public function updateBooking( int $id, array $row ): void {
		$this->bookings[ $id ] = $row + $this->bookings[ $id ];
	}

	public function transition( int $id, BookingStatus $to, string $actor, array $context = array(), bool $latePayment = false ): array {
		$from = BookingStatus::from( (string) $this->bookings[ $id ]['status'] );
		if ( ! $from->canTransitionTo( $to ) && ! ( $latePayment && in_array( $to, $from->latePaymentTargets(), true ) ) ) {
			throw new InvalidTransitionException( 'invalid', array( 'from' => $from->value ) );
		}
		$this->bookings[ $id ]['status'] = $to->value;
		if ( in_array( $to, array( BookingStatus::Confirmed, BookingStatus::Paid ), true ) ) {
			$this->bookings[ $id ]['hold_expires_at'] = null;
		}
		$this->transitions[] = array(
			'id'   => $id,
			'from' => $from->value,
			'to'   => $to->value,
		);

		return $this->bookings[ $id ];
	}

	public function reviveExpired( int $id, BookingStatus $to, string $actor, array $context = array() ): bool {
		if ( ! $this->nightsFree ) {
			return false;
		}
		$this->transition( $id, $to, $actor, $context, true );

		return true;
	}

	public function balanceCandidates( string $todayYmd ): array {
		return array_values(
			array_filter(
				$this->bookings,
				fn( array $b ): bool => in_array( $b['status'], array( 'confirmed', 'balance_failed' ), true )
					&& null !== $b['balance_due_on'] && $b['balance_due_on'] <= $todayYmd
					&& PaymentRepository::net( $this->payments( (int) $b['id'] ) ) < (int) $b['total_cents']
			)
		);
	}

	public function unitTitle( int $unitId ): string {
		return 'Apartman 1';
	}

	public function payment( int $id ): ?array {
		return $this->payments[ $id ] ?? null;
	}

	public function payments( int $bookingId ): array {
		return array_values( array_filter( $this->payments, static fn( array $p ): bool => (int) $p['booking_id'] === $bookingId ) );
	}

	public function paymentByIntent( string $intentId ): ?array {
		foreach ( $this->payments as $row ) {
			if ( 'refund' !== $row['kind'] && $intentId === $row['stripe_intent_id'] ) {
				return $row;
			}
		}

		return null;
	}

	public function paymentByRefund( string $refundId ): ?array {
		foreach ( $this->payments as $row ) {
			if ( $refundId === $row['stripe_refund_id'] ) {
				return $row;
			}
		}

		return null;
	}

	public function insertPayment( array $row ): int {
		foreach ( $this->payments as $existing ) {
			foreach ( array( 'idempotency_key', 'stripe_refund_id' ) as $unique ) {
				if ( ! empty( $row[ $unique ] ) && ( $existing[ $unique ] ?? null ) === $row[ $unique ] ) {
					throw new \RuntimeException( 'Duplicate ' . $unique );
				}
			}
		}
		$id                    = ++$this->paymentSeq;
		$this->payments[ $id ] = array( 'id' => $id ) + $row + array(
			'method'           => 'stripe',
			'stripe_intent_id' => null,
			'stripe_charge_id' => null,
			'stripe_refund_id' => null,
			'card_brand'       => '',
			'card_last4'       => '',
			'attempts'         => 0,
			'error_code'       => null,
			'note'             => null,
			'idempotency_key'  => null,
			'created_at'       => '2027-06-28 07:00:00',
		);

		return $id;
	}

	public function updatePayment( int $id, array $row ): void {
		$this->payments[ $id ] = $row + $this->payments[ $id ];
	}

	public function unsentRefunds(): array {
		return array_values( array_filter( $this->payments, static fn( array $p ): bool => 'refund' === $p['kind'] && 'stripe' === $p['method'] && 'pending' === $p['status'] && empty( $p['stripe_refund_id'] ) ) );
	}

	public function log( string $type, string $actor, ?int $bookingId, array $payload = array() ): void {
		$this->events[] = array(
			'type'       => $type,
			'actor'      => $actor,
			'booking_id' => $bookingId,
			'payload'    => $payload,
		);
	}

	public function webhookSeen( string $eventId ): bool {
		return isset( $this->webhooks[ $eventId ] );
	}

	public function markWebhook( string $eventId, string $type, ?int $bookingId ): void {
		$this->webhooks[ $eventId ] = $type;
	}

	/** @return list<string> Event types in order. */
	public function eventTypes(): array {
		return array_map( static fn( array $e ): string => $e['type'], $this->events );
	}

	public function net( int $bookingId ): int {
		return PaymentRepository::net( $this->payments( $bookingId ) );
	}
}
