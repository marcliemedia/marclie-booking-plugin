<?php

namespace Marclie\Booking\Tests\Unit\Payment;

use Marclie\Booking\Payment\WebhookHandler;

final class WebhookHandlerTest extends PaymentTestCase {

	/** @param array<string, mixed> $object */
	private static function event( string $id, string $type, array $object ): array {
		return array(
			'id'   => $id,
			'type' => $type,
			'data' => array( 'object' => $object ),
		);
	}

	public function testSucceededEventIsAppliedOnce(): void {
		$c  = $this->checkout();
		$pi = $this->gateway->succeed( $c['intent'] );
		// Webhook payloads carry the payment method as an ID only.
		$payload                   = $pi;
		$payload['payment_method'] = 'pm_card_visa';

		$this->assertSame( WebhookHandler::PROCESSED, $this->webhooks()->handle( self::event( 'evt_1', 'payment_intent.succeeded', $payload ) ) );
		$this->assertSame( WebhookHandler::DUPLICATE, $this->webhooks()->handle( self::event( 'evt_1', 'payment_intent.succeeded', $payload ) ) );
		// Same intent, another event ID (e.g. resent from the Dashboard): still applied once.
		$this->assertSame( WebhookHandler::PROCESSED, $this->webhooks()->handle( self::event( 'evt_2', 'payment_intent.succeeded', $payload ) ) );

		$this->assertSame( 'confirmed', $this->ledger->booking( 1 )['status'] );
		$this->assertCount( 1, $this->ledger->payments( 1 ) );
		$this->assertSame( '4242', $this->ledger->payment( $c['payment_id'] )['card_last4'], 'card details fetched' );
		$this->assertCount( 1, $this->actions( 'mbk_payment_succeeded' ) );
		$this->assertCount( 1, $this->ledger->transitions );
		$this->assertSame( array( 'evt_1', 'evt_2' ), array_keys( $this->ledger->webhooks ) );
	}

	public function testPaymentFailedEvent(): void {
		$c = $this->checkout();

		$this->webhooks()->handle( self::event( 'evt_f', 'payment_intent.payment_failed', $this->gateway->fail( $c['intent'] ) ) );

		$this->assertSame( 'failed', $this->ledger->payment( $c['payment_id'] )['status'] );
		$this->assertSame( 'pending_payment', $this->ledger->booking( 1 )['status'] );
		$this->assertCount( 1, $this->actions( 'mbk_payment_failed' ) );
	}

	public function testChargeRefundedEvent(): void {
		$c = $this->confirmedBooking();
		// Refunded in the Stripe Dashboard: the event's charge does not embed the refunds.
		$this->gateway->refund( $c['intent'], 40950, array(), 'dashboard' );

		$this->webhooks()->handle(
			self::event(
				'evt_r',
				'charge.refunded',
				array(
					'id'              => 'ch_1',
					'payment_intent'  => $c['intent'],
					'amount'          => 40950,
					'amount_refunded' => 40950,
					'refunded'        => true,
				)
			)
		);

		$this->assertSame( 0, $this->ledger->net( 1 ) );
		$this->assertSame( 'refunded', $this->ledger->payment( $c['payment_id'] )['status'] );
	}

	public function testRefundFailedEventMakesTheMoneyRefundableAgain(): void {
		$c = $this->confirmedBooking();
		$this->refunds()->send( $this->refunds()->plan( 1, 40950, 'x' ), 'test' );
		$refund = array_values( $this->gateway->refunds )[0];
		$this->assertSame( 0, $this->ledger->net( 1 ) );

		$this->webhooks()->handle( self::event( 'evt_rf', 'refund.failed', array( 'status' => 'failed' ) + $refund ) );

		$this->assertSame( 40950, $this->ledger->net( 1 ) );
		$this->assertSame( 'succeeded', $this->ledger->payment( $c['payment_id'] )['status'] );
	}

	public function testDisputeIsLoggedAndAnnounced(): void {
		$c = $this->confirmedBooking();

		$this->webhooks()->handle(
			self::event(
				'evt_d',
				'charge.dispute.created',
				array(
					'id'             => 'dp_1',
					'payment_intent' => $c['intent'],
					'amount'         => 40950,
					'reason'         => 'fraudulent',
					'status'         => 'needs_response',
				)
			)
		);

		$dispute = array_values( array_filter( $this->ledger->events, static fn( array $e ): bool => 'dispute_created' === $e['type'] ) );
		$this->assertCount( 1, $dispute );
		$this->assertSame( array( 1, 'dp_1', 'fraudulent' ), array( $dispute[0]['booking_id'], $dispute[0]['payload']['dispute_id'], $dispute[0]['payload']['reason'] ) );
		$this->assertCount( 1, $this->actions( 'mbk_dispute_created' ) );
	}

	public function testOtherEventsAreIgnoredButRecorded(): void {
		$this->assertSame( WebhookHandler::IGNORED, $this->webhooks()->handle( self::event( 'evt_x', 'customer.created', array( 'id' => 'cus_1' ) ) ) );
		$this->assertSame( WebhookHandler::DUPLICATE, $this->webhooks()->handle( self::event( 'evt_x', 'customer.created', array( 'id' => 'cus_1' ) ) ) );
		$this->assertSame( WebhookHandler::IGNORED, $this->webhooks()->handle( array( 'type' => 'payment_intent.succeeded' ) ) );
	}

	public function testFailedProcessingIsNotMarkedSoStripeRetries(): void {
		$c                   = $this->checkout();
		$pi                  = $this->gateway->succeed( $c['intent'] );
		$pi['payment_method'] = 'pm_card_visa';
		$pi['payment_method'] = null;
		$this->ledger->bookings[1]['status'] = 'bogus';

		try {
			$this->webhooks()->handle( self::event( 'evt_e', 'payment_intent.succeeded', $pi ) );
			$this->fail( 'no exception' );
		} catch ( \ValueError $e ) {
			$this->assertArrayNotHasKey( 'evt_e', $this->ledger->webhooks );
			$this->assertSame( 'pending', $this->ledger->payment( $c['payment_id'] )['status'], 'rolled back' );
		}
	}
}
