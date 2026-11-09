<?php
/**
 * PaymentIntent as the plugin needs it, normalized from a Stripe API object or
 * webhook payload (array form). `payment_method` / `latest_charge` may be IDs
 * or expanded objects; card brand/last4 are read from whichever is expanded.
 */

namespace Marclie\Booking\Payment;

defined( 'ABSPATH' ) || exit;

final class Intent {

	public const SUCCEEDED       = 'succeeded';
	public const PROCESSING      = 'processing';
	public const REQUIRES_ACTION = 'requires_action';
	public const REQUIRES_PM     = 'requires_payment_method';
	public const CANCELED        = 'canceled';

	/** Payment-state values of the public booking status endpoint. */
	public const STATE_NONE = 'none';

	/**
	 * @param array<string, string> $metadata
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $status,
		public readonly int $amount,
		public readonly int $amountReceived = 0,
		public readonly ?string $customer = null,
		public readonly ?string $paymentMethod = null,
		public readonly ?string $clientSecret = null,
		public readonly ?string $latestCharge = null,
		public readonly array $metadata = array(),
		public readonly ?string $cardBrand = null,
		public readonly ?string $cardLast4 = null,
		public readonly ?string $errorCode = null,
		public readonly ?string $errorMessage = null,
		public readonly string $currency = 'eur',
		public readonly ?int $cardExpMonth = null,
		public readonly ?int $cardExpYear = null
	) {}

	/** @param array<string, mixed> $pi PaymentIntent object as an array. */
	public static function fromArray( array $pi ): self {
		$pm     = $pi['payment_method'] ?? null;
		$charge = $pi['latest_charge'] ?? null;
		$error  = is_array( $pi['last_payment_error'] ?? null ) ? $pi['last_payment_error'] : null;

		$card = null;
		if ( is_array( $pm ) && is_array( $pm['card'] ?? null ) ) {
			$card = $pm['card'];
		} elseif ( is_array( $charge ) && is_array( $charge['payment_method_details']['card'] ?? null ) ) {
			$card = $charge['payment_method_details']['card'];
		} elseif ( null !== $error && is_array( $error['payment_method']['card'] ?? null ) ) {
			$card = $error['payment_method']['card'];
		}

		$errorCode = null;
		if ( null !== $error ) {
			$errorCode = (string) ( $error['decline_code'] ?? '' ) !== '' ? (string) $error['decline_code'] : ( isset( $error['code'] ) ? (string) $error['code'] : 'payment_failed' );
		}

		$metadata = array();
		foreach ( (array) ( $pi['metadata'] ?? array() ) as $key => $value ) {
			$metadata[ (string) $key ] = (string) $value;
		}

		return new self(
			(string) ( $pi['id'] ?? '' ),
			(string) ( $pi['status'] ?? '' ),
			(int) ( $pi['amount'] ?? 0 ),
			(int) ( $pi['amount_received'] ?? 0 ),
			self::id( $pi['customer'] ?? null ),
			self::id( $pm ),
			isset( $pi['client_secret'] ) ? (string) $pi['client_secret'] : null,
			self::id( $charge ),
			$metadata,
			null !== $card && isset( $card['brand'] ) ? (string) $card['brand'] : null,
			null !== $card && isset( $card['last4'] ) ? (string) $card['last4'] : null,
			$errorCode,
			null !== $error && isset( $error['message'] ) ? (string) $error['message'] : null,
			strtolower( (string) ( $pi['currency'] ?? '' ) ),
			null !== $card && isset( $card['exp_month'] ) ? (int) $card['exp_month'] : null,
			null !== $card && isset( $card['exp_year'] ) ? (int) $card['exp_year'] : null
		);
	}

	public function bookingId(): ?int {
		$id = (int) ( $this->metadata['booking_id'] ?? 0 );

		return $id > 0 ? $id : null;
	}

	public function kind(): string {
		return (string) ( $this->metadata['kind'] ?? '' );
	}

	public function succeeded(): bool {
		return self::SUCCEEDED === $this->status;
	}

	/** Declined/failed attempt (the guest may retry on-session) or cancelled intent. */
	public function failed(): bool {
		return self::CANCELED === $this->status || ( self::REQUIRES_PM === $this->status && null !== $this->errorCode );
	}

	/** Strong customer authentication needed (off-session charge or pending 3-D Secure). */
	public function needsAction(): bool {
		return self::REQUIRES_ACTION === $this->status || 'authentication_required' === $this->errorCode;
	}

	/** processing | succeeded | requires_action | failed | none (not attempted yet). */
	public function paymentState(): string {
		if ( $this->succeeded() ) {
			return self::SUCCEEDED;
		}
		if ( self::PROCESSING === $this->status ) {
			return self::PROCESSING;
		}
		if ( self::REQUIRES_ACTION === $this->status ) {
			return self::REQUIRES_ACTION;
		}
		if ( $this->failed() ) {
			return 'failed';
		}

		return self::STATE_NONE;
	}

	private static function id( mixed $value ): ?string {
		if ( is_array( $value ) ) {
			$value = $value['id'] ?? null;
		}

		return is_string( $value ) && '' !== $value ? $value : null;
	}
}
