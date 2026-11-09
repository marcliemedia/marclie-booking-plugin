<?php
/**
 * PaymentLedger on the plugin tables (repositories + BookingService).
 * Webhook events are recorded in mbk_events (type `stripe_webhook`, the event
 * ID in the UNIQUE external_id column).
 */

namespace Marclie\Booking\Payment;

use Marclie\Booking\Domain\BookingStatus;
use Marclie\Booking\Repository\BookingRepository;
use Marclie\Booking\Repository\Db;
use Marclie\Booking\Repository\EventRepository;
use Marclie\Booking\Repository\PaymentRepository;
use Marclie\Booking\Service\BookingService;
use Marclie\Booking\Units\UnitProvider;

defined( 'ABSPATH' ) || exit;

final class WpPaymentLedger implements PaymentLedger {

	public const WEBHOOK_EVENT = 'stripe_webhook';

	public function __construct(
		private BookingRepository $bookings,
		private PaymentRepository $payments,
		private EventRepository $events,
		private BookingService $service,
		private UnitProvider $units
	) {}

	public function transaction( callable $fn ) {
		return Db::transaction( $fn );
	}

	public function booking( int $id ): ?array {
		return $this->bookings->find( $id );
	}

	public function lockBooking( int $id ): ?array {
		return $this->bookings->lockForUpdate( $id );
	}

	public function updateBooking( int $id, array $row ): void {
		Db::check( $this->bookings->update( $id, $row ) ?: false, 'Updating booking' );
	}

	public function transition( int $id, BookingStatus $to, string $actor, array $context = array(), bool $latePayment = false ): array {
		return $this->service->transition( $id, $to, $actor, $context, $latePayment );
	}

	public function reviveExpired( int $id, BookingStatus $to, string $actor, array $context = array() ): bool {
		return $this->service->reviveExpired( $id, $to, $actor, $context );
	}

	public function balanceCandidates( string $todayYmd ): array {
		return $this->bookings->findAll(
			array(
				'status'         => array( BookingStatus::Confirmed->value, BookingStatus::BalanceFailed->value ),
				'balance_due_to' => $todayYmd,
				'payment_state'  => 'outstanding',
			),
			'arrival',
			'ASC',
			500
		);
	}

	public function unitTitle( int $unitId ): string {
		return $this->units->get( $unitId )?->title ?? sprintf( '#%d', $unitId );
	}

	public function payment( int $id ): ?array {
		return $this->payments->find( $id );
	}

	public function payments( int $bookingId ): array {
		return $this->payments->forBooking( $bookingId );
	}

	public function paymentByIntent( string $intentId ): ?array {
		return $this->payments->findByIntent( $intentId );
	}

	public function paymentByRefund( string $refundId ): ?array {
		return $this->payments->findByRefundId( $refundId );
	}

	public function insertPayment( array $row ): int {
		return (int) Db::check( $this->payments->insert( $row ) ?: false, 'Recording payment' );
	}

	public function updatePayment( int $id, array $row ): void {
		Db::check( $this->payments->update( $id, $row ), 'Updating payment' );
	}

	public function unsentRefunds(): array {
		return $this->payments->unsentStripeRefunds();
	}

	public function log( string $type, string $actor, ?int $bookingId, array $payload = array() ): void {
		$guestId = null === $bookingId ? null : ( $this->bookings->find( $bookingId )['guest_id'] ?? null );
		Db::check( $this->events->log( $type, $actor, $bookingId, null === $guestId ? null : (int) $guestId, $payload ) ?: false, 'Logging ' . $type );
	}

	public function webhookSeen( string $eventId ): bool {
		return $this->events->existsExternal( $eventId );
	}

	/** Not attached to the booking (its timeline shows the payment events instead). */
	public function markWebhook( string $eventId, string $type, ?int $bookingId ): void {
		$this->events->logExternal( $eventId, self::WEBHOOK_EVENT, 'stripe', null, null, array_filter( array( 'event_type' => $type, 'booking_id' => $bookingId ) ) );
	}
}
