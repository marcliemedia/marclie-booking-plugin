<?php

namespace Marclie\Booking\Tests\Unit\Payment;

use Marclie\Booking\Domain\FixedClock;
use Marclie\Booking\Payment\Intent;

final class PaymentReconcilerTest extends PaymentTestCase {

	public function testDepositSucceededConfirmsOnceAndSavesTheCard(): void {
		$c  = $this->checkout();
		$pi = Intent::fromArray( $this->gateway->succeed( $c['intent'] ) );

		$booking = $this->reconciler()->apply( $pi, 'test' );
		$this->reconciler()->apply( $pi, 'test' ); // Replay (webhook + page poll).

		$this->assertSame( 'confirmed', $booking['status'] );
		$this->assertSame( 'pm_card_visa', $booking['stripe_payment_method_id'] );
		$this->assertSame( 'cus_1', $booking['stripe_customer_id'] );
		$this->assertCount( 1, $this->ledger->payments( 1 ), 'the pending row is adopted, no second row' );
		$row = $this->ledger->payment( $c['payment_id'] );
		$this->assertSame( array( 'succeeded', $c['intent'], 'visa', '4242', 40950 ), array( $row['status'], $row['stripe_intent_id'], $row['card_brand'], $row['card_last4'], $row['amount_cents'] ) );
		$this->assertCount( 1, $this->ledger->transitions );
		$this->assertCount( 1, $this->actions( 'mbk_payment_succeeded' ) );
	}

	public function testWebhookBeforeTheIntentIdWasStoredAdoptsTheRow(): void {
		$c = $this->checkout();
		$this->ledger->updatePayment( $c['payment_id'], array( 'stripe_intent_id' => null ) );

		$this->reconciler()->apply( Intent::fromArray( $this->gateway->succeed( $c['intent'] ) ), 'test' );

		$this->assertCount( 1, $this->ledger->payments( 1 ) );
		$this->assertSame( array( $c['intent'], 'succeeded' ), array( $this->ledger->payment( $c['payment_id'] )['stripe_intent_id'], $this->ledger->payment( $c['payment_id'] )['status'] ) );
	}

	public function testFullPaymentMarksPaid(): void {
		$c = $this->checkout( array( 'deposit_cents' => 136500, 'balance_due_on' => null ), 'full' );

		$booking = $this->reconciler()->apply( Intent::fromArray( $this->gateway->succeed( $c['intent'] ) ), 'test' );

		$this->assertSame( 'paid', $booking['status'] );
	}

	public function testDeclineThenSuccessOnTheSameIntent(): void {
		$c = $this->checkout();

		$booking = $this->reconciler()->apply( Intent::fromArray( $this->gateway->fail( $c['intent'] ) ), 'test' );
		$this->assertSame( 'pending_payment', $booking['status'] );
		$this->assertSame( array( 'failed', 'generic_decline' ), array( $this->ledger->payment( $c['payment_id'] )['status'], $this->ledger->payment( $c['payment_id'] )['error_code'] ) );
		$this->assertCount( 1, $this->actions( 'mbk_payment_failed' ) );

		$this->reconciler()->apply( Intent::fromArray( $this->gateway->fail( $c['intent'] ) ), 'test' );
		$this->assertCount( 1, $this->actions( 'mbk_payment_failed' ), 'a replayed failure is ignored' );

		$booking = $this->reconciler()->apply( Intent::fromArray( $this->gateway->succeed( $c['intent'] ) ), 'test' );
		$this->assertSame( 'confirmed', $booking['status'] );
		$this->assertSame( 'succeeded', $this->ledger->payment( $c['payment_id'] )['status'] );
	}

	public function testIntentWithoutPaymentRowIsForeignEvenWithBookingMetadata(): void {
		$this->ledger->addBooking( array( 'id' => 1 ) );
		$pi = new Intent( 'pi_external', 'succeeded', 40950, 40950, 'cus_1', 'pm_1', null, 'ch_1', array( 'booking_id' => '1', 'ref' => 'BK-27-0712-1', 'kind' => 'deposit', 'site' => self::SITE ), 'visa', '4242' );

		$this->assertNull( $this->reconciler()->apply( $pi, 'test' ) );

		$this->assertSame( array(), $this->ledger->payments );
		$this->assertSame( 'pending_payment', $this->ledger->booking( 1 )['status'] );
		$this->assertSame( array( 'foreign_intent' ), $this->ledger->eventTypes() );
		$this->assertSame( 'no_payment_row', $this->ledger->events[0]['payload']['reason'] );
	}

	/** @return iterable<string, array{0: array<string, string>, 1: string, 2: string}> */
	public static function foreignMetadata(): iterable {
		yield 'other site' => array( array( 'site' => 'another-install' ), 'eur', 'site_mismatch' );
		yield 'other ref' => array( array( 'ref' => 'BK-27-0101-999' ), 'eur', 'ref_mismatch' );
		yield 'other currency' => array( array(), 'usd', 'currency' );
	}

	/**
	 * @dataProvider foreignMetadata
	 * @param array<string, string> $metadata
	 */
	public function testIntentOfAnotherSiteRefOrCurrencyIsIgnored( array $metadata, string $currency, string $reason ): void {
		$this->ledger->addBooking( array( 'id' => 1 ) );
		$p = $this->cardPayment( 1, 40950, 'deposit', 'k', $metadata );
		$this->gateway->intents[ $p['intent'] ]['currency'] = $currency;
		// Only metadata.payment_id points at the row (a foreign intent never matches a stored intent ID).
		$this->ledger->payments[ $p['payment_id'] ]['stripe_intent_id'] = null;

		$this->assertNull( $this->reconciler()->apply( Intent::fromArray( $this->gateway->succeed( $p['intent'] ) ), 'test' ) );

		$this->assertSame( 'pending', $this->ledger->payment( $p['payment_id'] )['status'] );
		$this->assertSame( 'pending_payment', $this->ledger->booking( 1 )['status'] );
		$this->assertSame( $reason, end( $this->ledger->events )['payload']['reason'] );
	}

	public function testMetadataPaymentIdOfAnotherBookingIsIgnored(): void {
		$this->ledger->addBooking( array( 'id' => 1 ) );
		$this->ledger->addBooking( array( 'id' => 2 ) );
		$p = $this->cardPayment( 2, 40950, 'deposit', 'k2' );
		$this->ledger->updatePayment( $p['payment_id'], array( 'stripe_intent_id' => null ) );
		$pi = new Intent( 'pi_x', 'succeeded', 40950, 40950, null, null, null, null, array( 'booking_id' => '1', 'ref' => 'BK-27-0712-1', 'payment_id' => (string) $p['payment_id'], 'site' => self::SITE ) );

		$this->assertNull( $this->reconciler()->apply( $pi, 'test' ) );
		$this->assertSame( 'pending_payment', $this->ledger->booking( 1 )['status'] );
	}

	public function testForeignIntentIsIgnored(): void {
		$this->assertNull( $this->reconciler()->apply( new Intent( 'pi_other', 'succeeded', 100 ), 'test' ) );
		$this->assertSame( array(), $this->ledger->payments );
	}

	public function testSecondCardPaymentAboveTheTotalIsRefunded(): void {
		$this->confirmedBooking();
		$this->charger()->run(); // Off-session balance → paid.
		$this->assertSame( 'paid', $this->ledger->booking( 1 )['status'] );
		// The guest's payment-page tab raced it.
		$page = $this->cardPayment( 1, 95550, 'balance', 'mbk-pay-1-1' );

		$this->reconciler()->apply( Intent::fromArray( $this->gateway->succeed( $page['intent'] ) ), 'test' );

		$refunds = $this->refundRows();
		$this->assertCount( 1, $refunds );
		$this->assertSame( array( 95550, $page['intent'], 'succeeded', 'overpayment' ), array( $refunds[0]['amount_cents'], $refunds[0]['stripe_intent_id'], $refunds[0]['status'], $refunds[0]['note'] ) );
		$this->assertSame( 136500, $this->ledger->net( 1 ) );
		$this->assertContains( 'overpayment', $this->ledger->eventTypes() );
		$this->assertSame( 'paid', $this->ledger->booking( 1 )['status'] );
	}

	public function testLatePaymentReholdsFreeNights(): void {
		$c = $this->checkout( array( 'status' => 'expired' ) );

		$booking = $this->reconciler()->apply( Intent::fromArray( $this->gateway->succeed( $c['intent'] ) ), 'test' );

		$this->assertSame( 'confirmed', $booking['status'] );
		$this->assertSame( array(), $this->gateway->refunds );
		$this->assertSame( array(), $this->actions( 'mbk_hold_lost' ) );
	}

	public function testLatePaymentOnTakenNightsIsRefundedInFull(): void {
		$c                        = $this->checkout( array( 'status' => 'expired' ) );
		$this->ledger->nightsFree = false;
		$pi                       = Intent::fromArray( $this->gateway->succeed( $c['intent'] ) );

		$booking = $this->reconciler()->apply( $pi, 'test' );
		$this->reconciler()->apply( $pi, 'test' );

		$this->assertSame( 'cancelled', $booking['status'] );
		$this->assertCount( 1, $this->gateway->refunds, 'refunded once' );
		$refund = array_values( array_filter( $this->ledger->payments( 1 ), static fn( array $p ): bool => 'refund' === $p['kind'] ) );
		$this->assertCount( 1, $refund );
		$this->assertSame( array( 40950, 'succeeded', $c['intent'] ), array( $refund[0]['amount_cents'], $refund[0]['status'], $refund[0]['stripe_intent_id'] ) );
		$this->assertNotEmpty( $refund[0]['stripe_refund_id'] );
		$this->assertSame( 0, $this->ledger->net( 1 ) );
		$this->assertCount( 1, $this->actions( 'mbk_hold_lost' ) );
		$this->assertCount( 1, $this->actions( 'mbk_refund_created' ) );
		$this->assertContains( 'hold_lost', $this->ledger->eventTypes() );
	}

	public function testPaymentOnCancelledBookingIsRefunded(): void {
		$c = $this->checkout( array( 'status' => 'cancelled' ) );

		$this->reconciler()->apply( Intent::fromArray( $this->gateway->succeed( $c['intent'] ) ), 'test' );

		$this->assertSame( 'cancelled', $this->ledger->booking( 1 )['status'] );
		$this->assertCount( 1, $this->gateway->refunds );
		$this->assertSame( array(), $this->actions( 'mbk_hold_lost' ) );
	}

	public function testOffSessionBalanceFailureMovesToBalanceFailedOnSessionDoesNot(): void {
		$this->confirmedBooking();

		$onSession = $this->cardPayment( 1, 95550, 'balance', 'k-on' );
		$this->reconciler()->apply( Intent::fromArray( $this->gateway->fail( $onSession['intent'] ) ), 'test' );
		$this->assertSame( 'confirmed', $this->ledger->booking( 1 )['status'] );

		$offSession = $this->cardPayment( 1, 95550, 'balance', 'k-off', array( 'off_session' => '1' ) );
		$this->reconciler()->apply( Intent::fromArray( $this->gateway->fail( $offSession['intent'], 'authentication_required' ) ), 'test' );
		$this->assertSame( 'balance_failed', $this->ledger->booking( 1 )['status'] );
		$failed = $this->actions( 'mbk_payment_failed' );
		$this->assertCount( 2, $failed );
		$this->assertSame( 'authentication_required', $failed[1][1][2]['code'] );
		$this->assertTrue( $failed[1][1][2]['requires_action'] );
	}

	public function testReaperKeepsAPaidHoldAndConfirmsIt(): void {
		$c = $this->checkout( array( 'hold_expires_at' => '2027-06-28 06:50:00' ) );
		$this->gateway->succeed( $c['intent'] );

		$this->assertFalse( $this->reconciler()->shouldExpire( true, $this->ledger->booking( 1 ) ) );
		$this->assertSame( 'confirmed', $this->ledger->booking( 1 )['status'] );
	}

	public function testReaperKeepsAProcessingHold(): void {
		$c = $this->checkout();
		$this->gateway->setStatus( $c['intent'], 'processing' );

		$this->assertFalse( $this->reconciler()->shouldExpire( true, $this->ledger->booking( 1 ) ) );
		$this->assertSame( 'pending_payment', $this->ledger->booking( 1 )['status'] );
	}

	public function testReaperCancelsAnUnpaidIntentBeforeExpiring(): void {
		$c = $this->checkout();

		$this->assertTrue( $this->reconciler()->shouldExpire( true, $this->ledger->booking( 1 ) ) );
		$this->assertSame( 'canceled', $this->gateway->intents[ $c['intent'] ]['status'] );
		$this->assertSame( array( 'failed', 'canceled' ), array( $this->ledger->payment( $c['payment_id'] )['status'], $this->ledger->payment( $c['payment_id'] )['error_code'] ) );
		$this->assertSame( array(), $this->actions( 'mbk_payment_failed' ), 'a cancelled intent is not a failed payment' );
	}

	public function testReaperWaitsWhileStripeIsUnreachable(): void {
		$this->checkout( array( 'hold_expires_at' => '2027-06-28 06:50:00' ) ); // 09:00 CEST = 07:00 UTC.
		$this->gateway->down = 'api_connection_error';

		$this->assertFalse( $this->reconciler()->shouldExpire( true, $this->ledger->booking( 1 ) ) );
		$later = new FixedClock( '2027-06-28 10:00:00' );
		$this->assertTrue( $this->reconciler( $later )->shouldExpire( true, $this->ledger->booking( 1 ) ) );
	}

	public function testWithoutGatewayTheReaperDecidesAlone(): void {
		$this->checkout();

		$this->assertTrue( $this->reconciler( null, false )->shouldExpire( true, $this->ledger->booking( 1 ) ) );
		$this->assertFalse( $this->reconciler( null, false )->shouldExpire( false, $this->ledger->booking( 1 ) ) );
	}

	public function testReconcileAppliesTheLiveState(): void {
		$c = $this->checkout();
		$this->gateway->setStatus( $c['intent'], 'requires_action' );
		$this->assertSame( 'requires_action', $this->reconciler()->reconcile( $this->ledger->booking( 1 ) )?->paymentState() );

		$this->gateway->succeed( $c['intent'] );
		$this->assertSame( 'succeeded', $this->reconciler()->reconcile( $this->ledger->booking( 1 ) )?->paymentState() );
		$this->assertSame( 'confirmed', $this->ledger->booking( 1 )['status'] );
		$this->assertNull( $this->reconciler()->reconcile( $this->ledger->booking( 1 ) ), 'nothing open any more' );
	}
}
