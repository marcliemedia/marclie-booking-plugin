<?php
/**
 * Booking state machine (architecture §4).
 */

namespace Marclie\Booking\Domain;

defined( 'ABSPATH' ) || exit;

enum BookingStatus: string {

	case PendingPayment = 'pending_payment';
	case Confirmed      = 'confirmed';
	case Paid           = 'paid';
	case BalanceFailed  = 'balance_failed';
	case Cancelled      = 'cancelled';
	case Expired        = 'expired';
	case Completed      = 'completed';

	/** @return list<self> */
	public function allowedTargets(): array {
		return match ( $this ) {
			self::PendingPayment => array( self::Expired, self::Confirmed, self::Paid, self::Cancelled ),
			self::Confirmed      => array( self::Paid, self::BalanceFailed, self::Cancelled ),
			self::BalanceFailed  => array( self::Paid, self::Cancelled ),
			self::Paid           => array( self::Cancelled, self::Completed ),
			self::Cancelled, self::Expired, self::Completed => array(),
		};
	}

	public function canTransitionTo( self $to ): bool {
		return in_array( $to, $this->allowedTargets(), true );
	}

	/**
	 * Extra transitions only a payment that arrived after the hold expired may
	 * make: expired → confirmed/paid (nights re-held) or → cancelled (hold lost,
	 * the payment is refunded). Never offered to the admin.
	 *
	 * @return list<self>
	 */
	public function latePaymentTargets(): array {
		return self::Expired === $this ? array( self::Confirmed, self::Paid, self::Cancelled ) : array();
	}

	/** Whether the booking keeps its rows in mbk_nights. */
	public function occupiesNights(): bool {
		return ! in_array( $this, array( self::Cancelled, self::Expired ), true );
	}

	public function isFinal(): bool {
		return array() === $this->allowedTargets();
	}

	/**
	 * Status a booking moves to after receiving money: `$netPaidCents` covering
	 * the total → paid, covering the deposit (pending only) → confirmed; null = unchanged.
	 */
	public function afterPayment( int $netPaidCents, int $totalCents, int $depositCents ): ?self {
		$coversTotal = $netPaidCents >= $totalCents;

		return match ( $this ) {
			self::PendingPayment => $coversTotal ? self::Paid : ( $netPaidCents > 0 && $netPaidCents >= $depositCents ? self::Confirmed : null ),
			self::Confirmed, self::BalanceFailed => $coversTotal ? self::Paid : null,
			default => null,
		};
	}
}
