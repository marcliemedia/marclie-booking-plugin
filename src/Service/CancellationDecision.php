<?php
/**
 * Outcome of a cancellation: how much of the received money is refunded and why.
 */

namespace Marclie\Booking\Service;

use Marclie\Booking\Domain\Money;

defined( 'ABSPATH' ) || exit;

final class CancellationDecision {

	/**
	 * @param list<array{payment_id:?int, method:string, amount_cents:int, card_brand:?string, card_last4:?string}> $refunds
	 *        Refund rows per payment method (payment_id null until recorded).
	 */
	public function __construct(
		public readonly int $bookingId,
		public readonly Money $paid,
		public readonly Money $refund,
		public readonly bool $withinFreeCancel,
		public readonly ?string $freeCancelUntil,
		public readonly bool $overridden = false,
		public readonly string $reason = '',
		public readonly array $refunds = array()
	) {}

	/**
	 * @param list<array{payment_id:?int, method:string, amount_cents:int, card_brand:?string, card_last4:?string}> $refunds
	 */
	public function withRefunds( array $refunds ): self {
		return new self( $this->bookingId, $this->paid, $this->refund, $this->withinFreeCancel, $this->freeCancelUntil, $this->overridden, $this->reason, $refunds );
	}

	/** @return array<string, mixed> */
	public function toArray(): array {
		return array(
			'booking_id'         => $this->bookingId,
			'paid_cents'         => $this->paid->cents(),
			'refund_cents'       => $this->refund->cents(),
			'within_free_cancel' => $this->withinFreeCancel,
			'free_cancel_until'  => $this->freeCancelUntil,
			'overridden'         => $this->overridden,
			'reason'             => $this->reason,
			'refunds'            => $this->refunds,
		);
	}
}
