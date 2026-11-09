<?php

namespace Marclie\Booking\Tests\Unit\Payment;

use Marclie\Booking\Domain\FixedClock;
use Marclie\Booking\Payment\Intent;
use Marclie\Booking\Payment\Refund;
use Marclie\Booking\Repository\PaymentRepository;

final class RefundServiceTest extends PaymentTestCase {

	/** Confirmed booking 1 with its deposit and a paid 955,50 € balance (two Stripe charges). */
	private function paidTwice(): array {
		$c       = $this->confirmedBooking();
		$balance = $this->cardPayment( 1, 95550, 'balance', 'k-balance' );
		$this->reconciler()->apply( Intent::fromArray( $this->gateway->succeed( $balance['intent'], 'pm_card_visa', '1111' ) ), 'test' );
		$GLOBALS['mbk_test_actions'] = array();

		return array( $c['intent'], $balance['intent'] );
	}

	public function testPlanSplitsNewestChargeFirst(): void {
		[ $deposit, $balance ] = $this->paidTwice();

		$ids = $this->ledger->transaction( fn() => $this->refunds()->plan( 1, 100000, 'Cancelled' ) );

		$rows = $this->refundRows();
		$this->assertCount( 2, $ids );
		$this->assertSame( array( $balance, 95550, '1111' ), array( $rows[0]['stripe_intent_id'], $rows[0]['amount_cents'], $rows[0]['card_last4'] ) );
		$this->assertSame( array( $deposit, 4450 ), array( $rows[1]['stripe_intent_id'], $rows[1]['amount_cents'] ) );
		$this->assertSame( 'pending', $rows[0]['status'] );
		$this->assertSame( 136500 - 100000, $this->ledger->net( 1 ), 'pending refunds already count' );
		$this->assertSame( array(), $this->gateway->refunds, 'nothing sent before send()' );
	}

	public function testPlanNeverExceedsWhatIsLeft(): void {
		$this->paidTwice();
		$this->refunds()->plan( 1, 136500, 'All' );

		$this->assertSame( array(), $this->refunds()->plan( 1, 100, 'Again' ) );
	}

	public function testSendStoresRefundIdsAndMarksChargesRefunded(): void {
		[ $deposit, $balance ] = $this->paidTwice();
		$ids                   = $this->refunds()->plan( 1, 136500, 'All' );

		$this->refunds()->send( $ids, 'test' );
		$this->refunds()->send( $ids, 'test' );

		$this->assertCount( 2, $this->gateway->refunds );
		foreach ( $this->refundRows() as $row ) {
			$this->assertSame( 'succeeded', $row['status'] );
			$this->assertStringStartsWith( 're_fake_', (string) $row['stripe_refund_id'] );
		}
		$this->assertSame( 'refunded', $this->ledger->paymentByIntent( $deposit )['status'] );
		$this->assertSame( 'refunded', $this->ledger->paymentByIntent( $balance )['status'] );
		$this->assertSame( 0, $this->ledger->net( 1 ) );
		$this->assertCount( 2, $this->actions( 'mbk_refund_created' ) );
		$first = array_values( $this->gateway->refunds )[0];
		$this->assertSame( array( '1', 'refund' ), array( $first['metadata']['booking_id'], $first['metadata']['kind'] ) );
	}

	public function testPendingRefundAtStripeStaysPendingUntilTheWebhook(): void {
		$deposit                     = $this->confirmedBooking()['intent'];
		$this->gateway->refundStatus = 'pending';
		$ids                         = $this->refunds()->plan( 1, 40950, 'Deposit back' );
		$this->refunds()->send( $ids, 'test' );
		$this->assertSame( 'pending', $this->refundRows()[0]['status'] );
		$this->assertSame( 'succeeded', $this->ledger->paymentByIntent( $deposit )['status'] );

		// charge.refunded: the refunds are listed from Stripe (the charge object no longer embeds them).
		$this->gateway->refunds[ $this->refundRows()[0]['stripe_refund_id'] ]['status'] = 'succeeded';
		$this->refunds()->onChargeRefunded(
			array(
				'id'              => 'ch_x',
				'payment_intent'  => $deposit,
				'amount'          => 40950,
				'amount_refunded' => 40950,
				'refunded'        => true,
			),
			'test'
		);

		$this->assertSame( 'succeeded', $this->refundRows()[0]['status'] );
		$this->assertSame( 'refunded', $this->ledger->paymentByIntent( $deposit )['status'] );
		$this->assertCount( 1, $this->refundRows(), 'no extra row' );
	}

	public function testTransientErrorKeepsTheRefundForTheRetry(): void {
		$this->paidTwice();
		$ids                 = $this->refunds()->plan( 1, 40950, 'Deposit back' );
		$this->gateway->down = 'api_connection_error';

		$this->refunds()->send( $ids, 'test' );
		$this->assertSame( 'pending', $this->refundRows()[0]['status'] );
		$this->assertContains( 'refund_failed', $this->ledger->eventTypes() );
		$this->assertCount( 1, $this->ledger->unsentRefunds() );

		$this->gateway->down = null;
		$this->assertSame( 1, $this->refunds()->retryUnsent( 'cron' ) );
		$this->assertSame( 'succeeded', $this->refundRows()[0]['status'] );
		$this->assertSame( array(), $this->ledger->unsentRefunds() );
	}

	public function testPermanentErrorFailsTheRefundRow(): void {
		$this->paidTwice();
		$ids                 = $this->refunds()->plan( 1, 40950, 'Deposit back' );
		$this->gateway->down = 'charge_already_refunded';

		$this->refunds()->send( $ids, 'test' );

		$this->assertSame( array( 'failed', 'charge_already_refunded' ), array( $this->refundRows()[0]['status'], $this->refundRows()[0]['error_code'] ) );
		$this->assertSame( 136500, $this->ledger->net( 1 ), 'a failed refund no longer counts' );
	}

	public function testWithoutGatewayNothingIsSent(): void {
		$this->paidTwice();
		$ids = $this->refunds()->plan( 1, 100, 'x' );

		$this->refunds( null, false )->send( $ids, 'test' );

		$this->assertSame( 'pending', $this->refundRows()[0]['status'] );
		$this->assertFalse( $this->refunds( null, false )->available() );
		$this->assertSame( 0, $this->refunds( null, false )->retryUnsent( 'cron' ) );
	}

	public function testDashboardRefundIsRecordedOnce(): void {
		[ $deposit ] = $this->paidTwice();
		$this->gateway->refunds['re_dashboard'] = array(
			'id'             => 're_dashboard',
			'amount'         => 10000,
			'status'         => 'succeeded',
			'payment_intent' => $deposit,
			'metadata'       => array(),
		);
		$charge = array(
			'id'              => 'ch_x',
			'payment_intent'  => $deposit,
			'amount'          => 40950,
			'amount_refunded' => 10000,
		);

		$this->assertSame( 1, $this->refunds()->onChargeRefunded( $charge, 'test' ) );
		$this->refunds()->onChargeRefunded( $charge, 'test' );

		$rows = $this->refundRows();
		$this->assertCount( 1, $rows );
		$this->assertSame( array( 10000, 'succeeded', 're_dashboard' ), array( $rows[0]['amount_cents'], $rows[0]['status'], $rows[0]['stripe_refund_id'] ) );
		$this->assertSame( 'succeeded', $this->ledger->paymentByIntent( $deposit )['status'], 'partially refunded charge stays succeeded' );
		$this->assertSame( 126500, $this->ledger->net( 1 ) );
		$this->assertContains( 'refund_external', $this->ledger->eventTypes() );
	}

	public function testUnknownChargeIsIgnored(): void {
		$this->assertNull( $this->refunds()->onChargeRefunded( array( 'payment_intent' => 'pi_unknown' ), 'test' ) );
	}

	public function testLostRefundResponseIsLinkedOnRetryNotSentTwice(): void {
		$this->paidTwice();
		$ids                         = $this->refunds()->plan( 1, 40950, 'Deposit back' );
		$this->gateway->loseResponse = 'refund';

		$this->refunds()->send( $ids, 'test' );
		$this->assertSame( array( 'pending', null ), array( $this->refundRows()[0]['status'], $this->refundRows()[0]['stripe_refund_id'] ) );
		$this->assertCount( 1, $this->gateway->refunds, 'Stripe made the refund' );

		// A day later (idempotency key expired): the retry lists the intent's refunds first.
		$this->assertSame( 1, $this->refunds( new FixedClock( '2027-06-29 12:00:00' ) )->retryUnsent( 'cron' ) );

		$this->assertCount( 1, $this->gateway->refunds, 'not refunded twice' );
		$this->assertSame( 'succeeded', $this->refundRows()[0]['status'] );
		$this->assertSame( array_key_first( $this->gateway->refunds ), $this->refundRows()[0]['stripe_refund_id'] );
	}

	public function testRetryAfterTheKeyExpiredUsesANewKeyWhenNothingWasRefunded(): void {
		$this->paidTwice();
		$ids                 = $this->refunds()->plan( 1, 40950, 'Deposit back' );
		$this->gateway->down = 'api_connection_error';
		$this->refunds()->send( $ids, 'test' );
		$this->gateway->down = null;

		$this->refunds( new FixedClock( '2027-06-29 12:00:00' ) )->retryUnsent( 'cron' );

		$this->assertArrayHasKey( 'mbk-refund-' . $ids[0] . '-2', $this->gateway->keys );
		$this->assertCount( 1, $this->gateway->refunds );
		$this->assertSame( 'succeeded', $this->refundRows()[0]['status'] );
	}

	public function testRefundEventsUpdateTheRowAndAFailedRefundNoLongerCounts(): void {
		$deposit                     = $this->confirmedBooking()['intent'];
		$this->gateway->refundStatus = 'pending';
		$ids                         = $this->refunds()->plan( 1, 40950, 'Deposit back' );
		$this->refunds()->send( $ids, 'test' );
		$refundId = (string) $this->refundRows()[0]['stripe_refund_id'];
		$object   = $this->gateway->refunds[ $refundId ];

		$this->refunds()->applyRefund( Refund::fromArray( array( 'status' => 'succeeded' ) + $object ), 'webhook' );
		$this->assertSame( 'succeeded', $this->refundRows()[0]['status'] );
		$this->refunds()->applyRefund( Refund::fromArray( $object ), 'webhook' ); // A late "pending" never undoes it.
		$this->assertSame( 'succeeded', $this->refundRows()[0]['status'] );
		$this->assertSame( 'refunded', $this->ledger->paymentByIntent( $deposit )['status'] );

		$this->refunds()->applyRefund( Refund::fromArray( array( 'status' => 'failed' ) + $object ), 'webhook' );
		$this->assertSame( array( 'failed', 'refund_failed' ), array( $this->refundRows()[0]['status'], $this->refundRows()[0]['error_code'] ) );
		$this->assertSame( 'succeeded', $this->ledger->paymentByIntent( $deposit )['status'], 'charge no longer refunded' );
		$this->assertSame( 40950, $this->ledger->net( 1 ) );
		$this->assertContains( 'refund_failed', $this->ledger->eventTypes() );
	}

	public function testRefundEventMatchedByMetadataBeforeTheIdWasStored(): void {
		$this->paidTwice();
		$ids = $this->refunds()->plan( 1, 40950, 'Deposit back' );
		$row = $this->ledger->payment( $ids[0] );

		$this->refunds()->applyRefund( new Refund( 're_early', 'succeeded', 40950, array( 'payment_id' => (string) $ids[0] ), (string) $row['stripe_intent_id'] ), 'webhook' );

		$this->assertSame( array( 'succeeded', 're_early' ), array( $this->ledger->payment( $ids[0] )['status'], $this->ledger->payment( $ids[0] )['stripe_refund_id'] ) );
		$this->refunds()->send( $ids, 'test' );
		$this->assertSame( array(), $this->gateway->refunds, 'already done: not sent' );
	}

	public function testRefundOfAForeignIntentIsIgnored(): void {
		$this->paidTwice();

		$this->assertNull( $this->refunds()->applyRefund( new Refund( 're_x', 'succeeded', 100, array(), 'pi_unknown' ), 'webhook' ) );
		$this->assertSame( array(), $this->refundRows() );
	}

	public function testNetSubtractsPendingButNotFailedRefunds(): void {
		$rows = array(
			array( 'kind' => 'deposit', 'status' => 'succeeded', 'amount_cents' => 1000 ),
			array( 'kind' => 'balance', 'status' => 'refunded', 'amount_cents' => 500 ),
			array( 'kind' => 'balance', 'status' => 'failed', 'amount_cents' => 700 ),
			array( 'kind' => 'refund', 'status' => 'pending', 'amount_cents' => 200 ),
			array( 'kind' => 'refund', 'status' => 'succeeded', 'amount_cents' => 100 ),
			array( 'kind' => 'refund', 'status' => 'failed', 'amount_cents' => 50 ),
		);

		$this->assertSame( 1200, PaymentRepository::net( $rows ) );
	}
}
