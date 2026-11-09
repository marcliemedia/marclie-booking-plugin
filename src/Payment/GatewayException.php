<?php
/**
 * A payment gateway call failed (network, authentication, invalid request).
 * The message never contains a key. Card declines of off-session charges are
 * not exceptions: they come back as a failed Intent.
 */

namespace Marclie\Booking\Payment;

defined( 'ABSPATH' ) || exit;

final class GatewayException extends \RuntimeException {

	/** Stripe codes after which the request may or may not have been carried out. */
	private const AMBIGUOUS = array( 'api_connection_error', 'api_error' );

	public function __construct( string $message, public readonly ?string $stripeCode = null, ?\Throwable $previous = null ) {
		parent::__construct( $message, 0, $previous );
	}

	/**
	 * Network failure / timeout / Stripe server error (no code): Stripe may have
	 * processed the request. Only other errors (invalid request, authentication,
	 * rate limit…) are definite.
	 */
	public function ambiguous(): bool {
		return null === $this->stripeCode || in_array( $this->stripeCode, self::AMBIGUOUS, true );
	}
}
