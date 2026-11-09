<?php
/**
 * Payment errors with their own codes:
 * - `payments_unavailable` (503): Stripe is not configured / unreachable;
 * - `card_requires_action` (402): the saved card needs authentication (SCA);
 * - `card_declined` (402): the saved card was declined;
 * - `nothing_due` (409): nothing left to pay;
 * - `no_saved_card` (409): no saved card to charge off-session;
 * - `in_progress` (409): a charge of this booking is still being processed;
 * - `not_payable` (409): the booking cannot be paid online (final status, checkout hold);
 * - `payment_error` (502): the processor rejected or failed the request.
 */

namespace Marclie\Booking\Domain\Exception;

defined( 'ABSPATH' ) || exit;

final class PaymentException extends BookingException {

	public const CODE = 'payment_error';

	public const UNAVAILABLE     = 'payments_unavailable';
	public const REQUIRES_ACTION = 'card_requires_action';
	public const DECLINED        = 'card_declined';
	public const NOTHING_DUE     = 'nothing_due';
	public const NO_SAVED_CARD   = 'no_saved_card';
	public const IN_PROGRESS     = 'in_progress';
	public const NOT_PAYABLE     = 'not_payable';

	/** HTTP status per code. */
	public const STATUS = array(
		self::UNAVAILABLE     => 503,
		self::REQUIRES_ACTION => 402,
		self::DECLINED        => 402,
		self::NOTHING_DUE     => 409,
		self::NO_SAVED_CARD   => 409,
		self::IN_PROGRESS     => 409,
		self::NOT_PAYABLE     => 409,
		self::CODE            => 502,
	);

	/**
	 * @param array<string, mixed> $context
	 */
	public function __construct( private string $paymentCode, string $message = '', array $context = array(), ?\Throwable $previous = null ) {
		parent::__construct( '' !== $message ? $message : $paymentCode, $context, $previous );
	}

	public static function unavailable( ?\Throwable $previous = null ): self {
		return new self( self::UNAVAILABLE, 'Online payments are not available.', array(), $previous );
	}

	public function errorCode(): string {
		return $this->paymentCode;
	}
}
