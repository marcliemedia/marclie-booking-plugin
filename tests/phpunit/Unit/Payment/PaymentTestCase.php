<?php

namespace Marclie\Booking\Tests\Unit\Payment;

use Marclie\Booking\Domain\FixedClock;
use Marclie\Booking\Payment\BalanceCharger;
use Marclie\Booking\Payment\Intent;
use Marclie\Booking\Payment\PaymentReconciler;
use Marclie\Booking\Payment\RefundService;
use Marclie\Booking\Payment\WebhookHandler;
use Marclie\Booking\Tests\Fakes\ArrayPaymentLedger;
use Marclie\Booking\Tests\Fakes\FakePaymentGateway;
use PHPUnit\Framework\TestCase;

/**
 * Payment services on the in-memory ledger and the fake gateway. Booking 1:
 * Apartman 1, 7 nights, total 1 365 €, deposit 409,50 €, balance due 2027-06-28.
 */
abstract class PaymentTestCase extends TestCase {

	protected const SITE = 'testsite';

	protected ArrayPaymentLedger $ledger;

	protected FakePaymentGateway $gateway;

	protected FixedClock $clock;

	protected function setUp(): void {
		$GLOBALS['mbk_test_actions'] = array();
		$this->ledger                = new ArrayPaymentLedger();
		$this->gateway               = new FakePaymentGateway();
		$this->clock                 = new FixedClock( '2027-06-28 09:00:00' );
	}

	protected function refunds( ?FixedClock $clock = null, bool $withGateway = true ): RefundService {
		return new RefundService( $this->ledger, $withGateway ? $this->gateway : null, $clock ?? $this->clock );
	}

	protected function reconciler( ?FixedClock $clock = null, bool $withGateway = true ): PaymentReconciler {
		return new PaymentReconciler( $this->ledger, $withGateway ? $this->gateway : null, $this->refunds( $clock, $withGateway ), $clock ?? $this->clock, self::SITE );
	}

	protected function charger( ?FixedClock $clock = null, bool $withGateway = true ): BalanceCharger {
		return new BalanceCharger( $this->ledger, $withGateway ? $this->gateway : null, $this->reconciler( $clock, $withGateway ), $clock ?? $this->clock, self::SITE );
	}

	protected function webhooks(): WebhookHandler {
		return new WebhookHandler( $this->ledger, $this->gateway, $this->reconciler(), $this->refunds() );
	}

	/**
	 * A card payment row plus its intent, the way CheckoutService creates them
	 * (row first, metadata payment_id/ref/site, intent ID stored afterwards).
	 *
	 * @param array<string, string> $metadata Extra/overriding metadata.
	 * @return array{payment_id: int, intent: string}
	 */
	protected function cardPayment( int $bookingId, int $amount, string $kind, string $key, array $metadata = array() ): array {
		$booking   = (array) $this->ledger->booking( $bookingId );
		$paymentId = $this->ledger->insertPayment(
			array(
				'booking_id'      => $bookingId,
				'kind'            => $kind,
				'method'          => 'stripe',
				'amount_cents'    => $amount,
				'status'          => 'pending',
				'idempotency_key' => $key,
			)
		);
		$intent    = $this->gateway->createIntent(
			array(
				'amount'   => $amount,
				'customer' => 'cus_1',
				'metadata' => $metadata + array(
					'booking_id' => (string) $bookingId,
					'ref'        => (string) $booking['ref'],
					'kind'       => $kind,
					'payment_id' => (string) $paymentId,
					'site'       => self::SITE,
				),
			),
			$key
		);
		$this->ledger->updatePayment( $paymentId, array( 'stripe_intent_id' => $intent->id ) );

		return array(
			'payment_id' => $paymentId,
			'intent'     => $intent->id,
		);
	}

	/**
	 * A checkout booking with its deposit intent.
	 *
	 * @param array<string, mixed> $booking
	 * @return array{booking: array<string, mixed>, payment_id: int, intent: string}
	 */
	protected function checkout( array $booking = array(), string $kind = 'deposit' ): array {
		$booking = $this->ledger->addBooking( $booking + array( 'id' => 1 ) );
		$amount  = 'full' === $kind ? (int) $booking['total_cents'] : (int) $booking['deposit_cents'];

		return array( 'booking' => $booking ) + $this->cardPayment( (int) $booking['id'], $amount, $kind, 'mbk-deposit-' . $booking['id'] );
	}

	/** A confirmed booking whose 409,50 € deposit was paid by card (saved PM). */
	protected function confirmedBooking( int $id = 1 ): array {
		$c = $this->checkout( array( 'id' => $id ) );
		$this->reconciler()->apply( Intent::fromArray( $this->gateway->succeed( $c['intent'] ) ), 'test' );
		$GLOBALS['mbk_test_actions'] = array();

		return $c;
	}

	/** @return list<array{0:string, 1:array<int, mixed>}> */
	protected function actions( string $hook ): array {
		return array_values( array_filter( $GLOBALS['mbk_test_actions'], static fn( array $a ): bool => $hook === $a[0] ) );
	}

	/** @return list<array<string, mixed>> */
	protected function refundRows( int $bookingId = 1 ): array {
		return array_values( array_filter( $this->ledger->payments( $bookingId ), static fn( array $p ): bool => 'refund' === $p['kind'] ) );
	}
}
