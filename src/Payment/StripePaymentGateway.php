<?php
/**
 * PaymentGateway on stripe-php (StripeClient) with a pinned API version,
 * short network timeouts, automatic network retries (safe: every write carries
 * an idempotency key) and errors converted to GatewayException without keys.
 */

namespace Marclie\Booking\Payment;

defined( 'ABSPATH' ) || exit;

final class StripePaymentGateway implements PaymentGateway {

	/** Stripe API version the code was written and tested against. */
	public const API_VERSION = '2026-09-30.endive';

	private const TIMEOUT         = 20;
	private const CONNECT_TIMEOUT = 5;
	private const NETWORK_RETRIES = 2;

	private \Stripe\StripeClient $client;

	public function __construct( private string $secretKey ) {
		$http = \Stripe\HttpClient\CurlClient::instance();
		$http->setTimeout( self::TIMEOUT );
		$http->setConnectTimeout( self::CONNECT_TIMEOUT );
		\Stripe\ApiRequestor::setHttpClient( $http );

		$this->client = new \Stripe\StripeClient(
			array(
				'api_key'             => $secretKey,
				'stripe_version'      => self::API_VERSION,
				'max_network_retries' => self::NETWORK_RETRIES,
			)
		);
	}

	public function mode(): string {
		return StripeConfig::modeOf( $this->secretKey ) ?? 'test';
	}

	public function createCustomer( array $params, string $idempotencyKey ): string {
		return (string) $this->call( fn() => $this->client->customers->create( $params, array( 'idempotency_key' => $idempotencyKey ) ) )->id;
	}

	public function createIntent( array $params, string $idempotencyKey ): Intent {
		return self::intent( $this->call( fn() => $this->client->paymentIntents->create( $params, array( 'idempotency_key' => $idempotencyKey ) ) ) );
	}

	public function chargeOffSession( array $params, string $idempotencyKey ): Intent {
		$params['confirm']     = true;
		$params['off_session'] = true;
		// Card brand/last4 for the payment row come from the expanded payment method.
		$params['expand']      = array( 'payment_method' );

		try {
			return self::intent( $this->client->paymentIntents->create( $params, array( 'idempotency_key' => $idempotencyKey ) ) );
		} catch ( \Stripe\Exception\CardException $e ) {
			// Declined or authentication_required: the error carries the failed intent.
			$intent = $e->getError()->payment_intent ?? null;
			if ( $intent instanceof \Stripe\StripeObject ) {
				return Intent::fromArray( $intent->toArray() );
			}
			throw new GatewayException( $this->redact( $e->getMessage() ), $e->getStripeCode(), $e );
		} catch ( \Throwable $e ) {
			throw $this->wrap( $e );
		}
	}

	public function retrieveIntent( string $intentId ): Intent {
		return self::intent( $this->call( fn() => $this->client->paymentIntents->retrieve( $intentId, array( 'expand' => array( 'payment_method' ) ) ) ) );
	}

	public function cancelIntent( string $intentId ): Intent {
		return self::intent( $this->call( fn() => $this->client->paymentIntents->cancel( $intentId ) ) );
	}

	public function refund( string $intentId, int $amountCents, array $metadata, string $idempotencyKey ): Refund {
		$refund = $this->call(
			fn() => $this->client->refunds->create(
				array(
					'payment_intent' => $intentId,
					'amount'         => $amountCents,
					'metadata'       => $metadata,
				),
				array( 'idempotency_key' => $idempotencyKey )
			)
		);

		return Refund::fromArray( $refund->toArray() );
	}

	public function listRefunds( string $intentId ): array {
		$list = $this->call( fn() => $this->client->refunds->all( array( 'payment_intent' => $intentId, 'limit' => 100 ) ) );

		return array_values( array_map( static fn( $refund ): Refund => Refund::fromArray( $refund->toArray() ), $list->data ) );
	}

	public function findIntentByPaymentId( int $paymentId ): ?Intent {
		$found = $this->call( fn() => $this->client->paymentIntents->search( array( 'query' => sprintf( "metadata['payment_id']:'%d'", $paymentId ), 'limit' => 1 ) ) );
		$first = $found->data[0] ?? null;

		return $first instanceof \Stripe\StripeObject ? self::intent( $first ) : null;
	}

	public function createSetupIntent( array $params, string $idempotencyKey ): Intent {
		return self::intent( $this->call( fn() => $this->client->setupIntents->create( $params, array( 'idempotency_key' => $idempotencyKey ) ) ) );
	}

	public function retrieveSetupIntent( string $setupIntentId ): Intent {
		return self::intent( $this->call( fn() => $this->client->setupIntents->retrieve( $setupIntentId, array( 'expand' => array( 'payment_method' ) ) ) ) );
	}

	private static function intent( \Stripe\StripeObject $pi ): Intent {
		return Intent::fromArray( $pi->toArray() );
	}

	/**
	 * @template T
	 * @param callable(): T $fn
	 * @return T
	 */
	private function call( callable $fn ) {
		try {
			return $fn();
		} catch ( \Throwable $e ) {
			throw $this->wrap( $e );
		}
	}

	private function wrap( \Throwable $e ): GatewayException {
		if ( $e instanceof GatewayException ) {
			return $e;
		}
		$code = $e instanceof \Stripe\Exception\ApiErrorException ? $e->getStripeCode() : null;
		if ( $e instanceof \Stripe\Exception\ApiConnectionException ) {
			$code ??= 'api_connection_error';
		} elseif ( $e instanceof \Stripe\Exception\AuthenticationException ) {
			$code ??= 'authentication_error';
		}

		return new GatewayException( $this->redact( $e->getMessage() ), $code, $e );
	}

	/** Never let a key end up in a message that is logged or stored. */
	private function redact( string $message ): string {
		$message = str_replace( $this->secretKey, '[redacted]', $message );

		return (string) preg_replace( '/\b(sk|rk|pk|whsec)_[A-Za-z0-9_]+/', '$1_…', $message );
	}
}
