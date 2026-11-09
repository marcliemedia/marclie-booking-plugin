<?php
/**
 * Port to the card processor (Stripe). StripePaymentGateway is the real one;
 * tests and the integration script swap in a fake through the
 * `mbk_payment_gateway` filter. Every write takes an idempotency key.
 */

namespace Marclie\Booking\Payment;

defined( 'ABSPATH' ) || exit;

interface PaymentGateway {

	/** `test` | `live`. */
	public function mode(): string;

	/**
	 * @param array<string, mixed> $params Customer::create parameters.
	 * @return string Customer ID.
	 * @throws GatewayException
	 */
	public function createCustomer( array $params, string $idempotencyKey ): string;

	/**
	 * Creates an unconfirmed PaymentIntent (the browser confirms it).
	 *
	 * @param array<string, mixed> $params PaymentIntent::create parameters.
	 * @throws GatewayException
	 */
	public function createIntent( array $params, string $idempotencyKey ): Intent;

	/**
	 * Creates and confirms an off-session PaymentIntent with a saved card. A
	 * decline or required authentication returns the failed Intent (errorCode set).
	 *
	 * @param array<string, mixed> $params PaymentIntent::create parameters (without confirm/off_session).
	 * @throws GatewayException
	 */
	public function chargeOffSession( array $params, string $idempotencyKey ): Intent;

	/**
	 * PaymentIntent with the payment method expanded (card brand/last4).
	 *
	 * @throws GatewayException
	 */
	public function retrieveIntent( string $intentId ): Intent;

	/**
	 * @throws GatewayException E.g. when the intent already succeeded.
	 */
	public function cancelIntent( string $intentId ): Intent;

	/**
	 * @param array<string, string> $metadata
	 * @throws GatewayException
	 */
	public function refund( string $intentId, int $amountCents, array $metadata, string $idempotencyKey ): Refund;

	/**
	 * Refunds of a PaymentIntent (newest first).
	 *
	 * @return list<Refund>
	 * @throws GatewayException
	 */
	public function listRefunds( string $intentId ): array;

	/**
	 * The PaymentIntent created for a plugin payment row (metadata.payment_id),
	 * for requests whose response was lost. Null when none exists (yet: the
	 * Stripe search index lags about a minute).
	 *
	 * @throws GatewayException
	 */
	public function findIntentByPaymentId( int $paymentId ): ?Intent;

	/**
	 * Creates a SetupIntent (card saved for later off-session charges; the
	 * browser confirms it). Returned as an Intent: amount 0, `clientSecret`,
	 * `customer`, `metadata`.
	 *
	 * @param array<string, mixed> $params SetupIntent::create parameters.
	 * @throws GatewayException
	 */
	public function createSetupIntent( array $params, string $idempotencyKey ): Intent;

	/**
	 * SetupIntent with the payment method expanded (card brand/last4), as an Intent.
	 *
	 * @throws GatewayException
	 */
	public function retrieveSetupIntent( string $setupIntentId ): Intent;
}
