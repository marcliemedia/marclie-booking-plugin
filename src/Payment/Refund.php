<?php
/**
 * Stripe Refund, normalized (status: pending | succeeded | failed | canceled |
 * requires_action).
 */

namespace Marclie\Booking\Payment;

defined( 'ABSPATH' ) || exit;

final class Refund {

	/**
	 * @param array<string, string> $metadata
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $status,
		public readonly int $amount,
		public readonly array $metadata = array(),
		public readonly ?string $paymentIntent = null
	) {}

	/** @param array<string, mixed> $refund */
	public static function fromArray( array $refund ): self {
		$metadata = array();
		foreach ( (array) ( $refund['metadata'] ?? array() ) as $key => $value ) {
			$metadata[ (string) $key ] = (string) $value;
		}
		$intent = $refund['payment_intent'] ?? null;
		$intent = is_array( $intent ) ? ( $intent['id'] ?? null ) : $intent;

		return new self(
			(string) ( $refund['id'] ?? '' ),
			(string) ( $refund['status'] ?? '' ),
			(int) ( $refund['amount'] ?? 0 ),
			$metadata,
			is_string( $intent ) && '' !== $intent ? $intent : null
		);
	}

	/** Plugin refund row this refund was created for (metadata.payment_id), or 0. */
	public function paymentId(): int {
		return (int) ( $this->metadata['payment_id'] ?? 0 );
	}

	/** Status of the plugin's refund payment row for this refund. */
	public function rowStatus(): string {
		return match ( $this->status ) {
			'succeeded' => 'succeeded',
			'failed', 'canceled' => 'failed',
			default => 'pending',
		};
	}
}
