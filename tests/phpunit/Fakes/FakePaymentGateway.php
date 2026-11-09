<?php
/**
 * In-memory PaymentGateway: intents, customers and refunds kept in arrays,
 * idempotent by key like Stripe. Used by the unit tests and (required by
 * path) by the integration script. Tests drive intent states with succeed(),
 * fail() and the off-session / refund outcome switches.
 */

namespace Marclie\Booking\Tests\Fakes;

use Marclie\Booking\Payment\GatewayException;
use Marclie\Booking\Payment\Intent;
use Marclie\Booking\Payment\PaymentGateway;
use Marclie\Booking\Payment\Refund;

final class FakePaymentGateway implements PaymentGateway {

	/** @var array<string, array<string, mixed>> PaymentIntent arrays by ID. */
	public array $intents = array();

	/** @var array<string, array<string, mixed>> Refund arrays by ID. */
	public array $refunds = array();

	/** @var array<string, array<string, mixed>> SetupIntent arrays by ID. */
	public array $setupIntents = array();

	/** @var array<string, string> Idempotency key => created object ID. */
	public array $keys = array();

	/** @var list<string> Method names in call order. */
	public array $calls = array();

	/** Outcome of the next off-session charges: succeed | card_declined | authentication_required. */
	public string $offSession = 'succeed';

	/** Status of new refunds: succeeded | pending. */
	public string $refundStatus = 'succeeded';

	/** When set, every call throws a GatewayException with this Stripe code. */
	public ?string $down = null;

	/**
	 * Method (chargeOffSession | refund) whose next call is carried out at
	 * "Stripe" but whose response is lost (GatewayException api_connection_error).
	 */
	public ?string $loseResponse = null;

	/** When false, findIntentByPaymentId() finds nothing (search index lag). */
	public bool $searchable = true;

	private int $seq = 0;

	public function mode(): string {
		return 'test';
	}

	public function createCustomer( array $params, string $idempotencyKey ): string {
		$this->call( __FUNCTION__ );

		return $this->keys[ $idempotencyKey ] ??= 'cus_fake_' . ( ++$this->seq );
	}

	public function createIntent( array $params, string $idempotencyKey ): Intent {
		$this->call( __FUNCTION__ );
		if ( ! isset( $this->keys[ $idempotencyKey ] ) ) {
			$id                             = 'pi_fake_' . ( ++$this->seq );
			$this->keys[ $idempotencyKey ] = $id;
			$this->intents[ $id ]           = array(
				'id'              => $id,
				'object'          => 'payment_intent',
				'status'          => 'requires_payment_method',
				'amount'          => (int) $params['amount'],
				'amount_received' => 0,
				'currency'        => (string) ( $params['currency'] ?? 'eur' ),
				'customer'        => $params['customer'] ?? null,
				'payment_method'  => $params['payment_method'] ?? null,
				'client_secret'   => $id . '_secret_fake',
				'metadata'        => (array) ( $params['metadata'] ?? array() ),
				'description'     => (string) ( $params['description'] ?? '' ),
				'params'          => $params,
			);
		}

		return Intent::fromArray( $this->intents[ $this->keys[ $idempotencyKey ] ] );
	}

	public function chargeOffSession( array $params, string $idempotencyKey ): Intent {
		$known  = isset( $this->keys[ $idempotencyKey ] );
		$intent = $this->createIntent( $params, $idempotencyKey );
		if ( ! $known ) {
			if ( 'succeed' === $this->offSession ) {
				$this->succeed( $intent->id, (string) ( $params['payment_method'] ?? 'pm_fake' ) );
			} else {
				$this->fail( $intent->id, $this->offSession );
			}
		}

		$this->loseResponseOf( __FUNCTION__ );

		return Intent::fromArray( $this->intents[ $intent->id ] );
	}

	public function retrieveIntent( string $intentId ): Intent {
		$this->call( __FUNCTION__ );

		return Intent::fromArray( $this->intent( $intentId ) );
	}

	public function cancelIntent( string $intentId ): Intent {
		$this->call( __FUNCTION__ );
		$status = $this->intent( $intentId )['status'];
		if ( in_array( $status, array( 'succeeded', 'processing', 'canceled' ), true ) ) {
			throw new GatewayException( 'You cannot cancel this PaymentIntent because it has a status of ' . $status . '.', 'payment_intent_unexpected_state' );
		}
		$this->intents[ $intentId ]['status'] = 'canceled';

		return Intent::fromArray( $this->intents[ $intentId ] );
	}

	public function refund( string $intentId, int $amountCents, array $metadata, string $idempotencyKey ): Refund {
		$this->call( __FUNCTION__ );
		$this->intent( $intentId );
		if ( ! isset( $this->keys[ $idempotencyKey ] ) ) {
			$id                             = 're_fake_' . ( ++$this->seq );
			$this->keys[ $idempotencyKey ] = $id;
			$this->refunds[ $id ]           = array(
				'id'             => $id,
				'status'         => $this->refundStatus,
				'amount'         => $amountCents,
				'payment_intent' => $intentId,
				'metadata'       => $metadata,
			);
		}

		$this->loseResponseOf( __FUNCTION__ );

		return Refund::fromArray( $this->refunds[ $this->keys[ $idempotencyKey ] ] );
	}

	public function listRefunds( string $intentId ): array {
		$this->call( __FUNCTION__ );
		$out = array();
		foreach ( array_reverse( $this->refunds ) as $refund ) {
			if ( $intentId === $refund['payment_intent'] ) {
				$out[] = Refund::fromArray( $refund );
			}
		}

		return $out;
	}

	public function findIntentByPaymentId( int $paymentId ): ?Intent {
		$this->call( __FUNCTION__ );
		foreach ( $this->searchable ? $this->intents : array() as $pi ) {
			if ( (string) $paymentId === (string) ( $pi['metadata']['payment_id'] ?? '' ) ) {
				return Intent::fromArray( $pi );
			}
		}

		return null;
	}

	public function createSetupIntent( array $params, string $idempotencyKey ): Intent {
		$this->call( __FUNCTION__ );
		if ( ! isset( $this->keys[ $idempotencyKey ] ) ) {
			$id                            = 'seti_fake_' . ( ++$this->seq );
			$this->keys[ $idempotencyKey ] = $id;
			$this->setupIntents[ $id ]     = array(
				'id'             => $id,
				'object'         => 'setup_intent',
				'status'         => 'requires_payment_method',
				'customer'       => $params['customer'] ?? null,
				'payment_method' => null,
				'client_secret'  => $id . '_secret_fake',
				'metadata'       => (array) ( $params['metadata'] ?? array() ),
				'params'         => $params,
			);
		}

		return Intent::fromArray( $this->setupIntents[ $this->keys[ $idempotencyKey ] ] );
	}

	public function retrieveSetupIntent( string $setupIntentId ): Intent {
		$this->call( __FUNCTION__ );
		if ( ! isset( $this->setupIntents[ $setupIntentId ] ) ) {
			throw new GatewayException( 'No such setupintent: ' . $setupIntentId, 'resource_missing' );
		}

		return Intent::fromArray( $this->setupIntents[ $setupIntentId ] );
	}

	private function loseResponseOf( string $method ): void {
		if ( $method === $this->loseResponse ) {
			$this->loseResponse = null;
			throw new GatewayException( 'Timed out (fake).', 'api_connection_error' );
		}
	}

	/* Test controls ---------------------------------------------------------- */

	/** The guest paid: succeeded with a saved Visa card. */
	public function succeed( string $intentId, string $paymentMethod = 'pm_card_visa', string $last4 = '4242' ): array {
		$pi                       = $this->intent( $intentId );
		$pi['status']             = 'succeeded';
		$pi['amount_received']    = $pi['amount'];
		$pi['latest_charge']      = 'ch_' . substr( $intentId, 3 );
		$pi['last_payment_error'] = null;
		$pi['payment_method']     = array(
			'id'   => $paymentMethod,
			'card' => array(
				'brand' => 'visa',
				'last4' => $last4,
			),
		);

		return $this->intents[ $intentId ] = $pi;
	}

	/** The guest confirmed the SetupIntent: succeeded with a saved card. */
	public function succeedSetup( string $setupIntentId, string $paymentMethod = 'pm_card_mastercard', string $last4 = '4444', string $brand = 'mastercard' ): array {
		if ( ! isset( $this->setupIntents[ $setupIntentId ] ) ) {
			throw new GatewayException( 'No such setupintent: ' . $setupIntentId, 'resource_missing' );
		}
		$this->setupIntents[ $setupIntentId ]['status']         = 'succeeded';
		$this->setupIntents[ $setupIntentId ]['payment_method'] = array(
			'id'   => $paymentMethod,
			'card' => array(
				'brand'     => $brand,
				'last4'     => $last4,
				'exp_month' => 9,
				'exp_year'  => 2029,
			),
		);

		return $this->setupIntents[ $setupIntentId ];
	}

	/** A declined / authentication-required attempt. */
	public function fail( string $intentId, string $code = 'card_declined' ): array {
		$pi                       = $this->intent( $intentId );
		$pi['status']             = 'requires_payment_method';
		$pi['last_payment_error'] = 'authentication_required' === $code
			? array(
				'code'    => 'authentication_required',
				'message' => 'Your card requires authentication.',
			)
			: array(
				'code'         => 'card_declined',
				'decline_code' => 'generic_decline',
				'message'      => 'Your card was declined.',
			);

		return $this->intents[ $intentId ] = $pi;
	}

	public function setStatus( string $intentId, string $status ): array {
		$this->intent( $intentId );
		$this->intents[ $intentId ]['status'] = $status;

		return $this->intents[ $intentId ];
	}

	/** The intent created with `$idempotencyKey`, or null. */
	public function intentByKey( string $idempotencyKey ): ?array {
		$id = $this->keys[ $idempotencyKey ] ?? null;

		return null === $id ? null : ( $this->intents[ $id ] ?? null );
	}

	/** @return array<string, mixed> */
	private function intent( string $intentId ): array {
		if ( ! isset( $this->intents[ $intentId ] ) ) {
			throw new GatewayException( 'No such payment_intent: ' . $intentId, 'resource_missing' );
		}

		return $this->intents[ $intentId ];
	}

	private function call( string $method ): void {
		$this->calls[] = $method;
		if ( null !== $this->down ) {
			throw new GatewayException( 'Stripe is down (fake).', $this->down );
		}
	}
}
