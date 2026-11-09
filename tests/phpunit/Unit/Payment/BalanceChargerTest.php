<?php

namespace Marclie\Booking\Tests\Unit\Payment;

use Marclie\Booking\Domain\Exception\PaymentException;
use Marclie\Booking\Domain\FixedClock;

final class BalanceChargerTest extends PaymentTestCase {

	public function testDueBalanceIsChargedWithTheSavedCard(): void {
		$this->confirmedBooking();

		$result = $this->charger()->run();

		$this->assertSame( 'paid', $this->ledger->booking( 1 )['status'] );
		$this->assertSame( array( 1, 95550, 1, 'charge', 'succeeded' ), array( $result[0]['booking_id'], $result[0]['amount_cents'], $result[0]['attempt'], $result[0]['action'], $result[0]['result'] ) );
		$pi = $this->gateway->intentByKey( 'mbk-balance-1-1' );
		$this->assertNotNull( $pi );
		$this->assertSame( array( 'cus_1', 'pm_card_visa', 'BK-27-0712-1 · Apartman 1 · ostatak', '1' ), array( $pi['params']['customer'], $pi['params']['payment_method'], $pi['params']['description'], $pi['metadata']['off_session'] ) );
		$this->assertSame( 136500, $this->ledger->net( 1 ) );
		$this->assertCount( 1, $this->actions( 'mbk_payment_succeeded' ) );
		$this->assertSame( array(), $this->charger()->run(), 'nothing due any more' );
	}

	public function testNotYetDueIsNotCharged(): void {
		$this->confirmedBooking();
		$this->ledger->updateBooking( 1, array( 'balance_due_on' => '2027-06-29' ) );

		$this->assertSame( array(), $this->charger()->run() );
		$this->assertCount( 1, $this->charger()->run( '2027-06-29', true ) );
	}

	public function testDryRunCallsNoGateway(): void {
		$this->confirmedBooking();
		$calls = count( $this->gateway->calls );

		$result = $this->charger()->run( null, true );

		$this->assertSame( array( 'charge', 'dry_run' ), array( $result[0]['action'], $result[0]['result'] ) );
		$this->assertSame( $calls, count( $this->gateway->calls ) );
		$this->assertSame( 'confirmed', $this->ledger->booking( 1 )['status'] );
	}

	public function testDeclineThenOneRetryAfter48Hours(): void {
		$this->confirmedBooking();
		$this->gateway->offSession = 'card_declined';

		$first = $this->charger()->run();
		$this->assertSame( 'generic_decline', $first[0]['result'] );
		$this->assertSame( 'balance_failed', $this->ledger->booking( 1 )['status'] );
		$failed = $this->actions( 'mbk_payment_failed' );
		$this->assertCount( 1, $failed );
		$this->assertTrue( $failed[0][1][2]['off_session'] );

		$next = new FixedClock( '2027-06-29 09:00:00' );
		$this->assertSame( array( 'skip', 'retry_later' ), array( $this->charger( $next )->run( '2027-06-29' )[0]['action'], $this->charger( $next )->run( '2027-06-29' )[0]['result'] ) );

		$retry  = new FixedClock( '2027-06-30 09:00:00' );
		$second = $this->charger( $retry )->run( '2027-06-30' );
		$this->assertSame( array( 2, 'generic_decline' ), array( $second[0]['attempt'], $second[0]['result'] ) );
		$this->assertNotNull( $this->gateway->intentByKey( 'mbk-balance-1-2' ) );
		$this->assertSame( 'balance_failed', $this->ledger->booking( 1 )['status'] );

		$later = new FixedClock( '2027-07-05 09:00:00' );
		$this->assertSame( 'retries_exhausted', $this->charger( $later )->run( '2027-07-05' )[0]['result'] );
	}

	public function testRetrySuccessPays(): void {
		$this->confirmedBooking();
		$this->gateway->offSession = 'authentication_required';
		$this->charger()->run();
		$this->assertSame( 'balance_failed', $this->ledger->booking( 1 )['status'] );

		$this->gateway->offSession = 'succeed';
		$this->charger( new FixedClock( '2027-06-30 10:00:00' ) )->run( '2027-06-30' );

		$this->assertSame( 'paid', $this->ledger->booking( 1 )['status'] );
	}

	public function testManualChargeReportsAuthenticationRequired(): void {
		$this->confirmedBooking();
		$this->gateway->offSession = 'authentication_required';

		$pi = $this->charger()->charge( 1, 'user:1' );

		$this->assertTrue( $pi->failed() );
		$this->assertTrue( $pi->needsAction() );
		$this->assertSame( 'balance_failed', $this->ledger->booking( 1 )['status'] );
	}

	public function testNoSavedCardIsSkipped(): void {
		$this->ledger->addBooking( array( 'id' => 2, 'status' => 'confirmed' ) );
		$this->ledger->insertPayment( array( 'booking_id' => 2, 'kind' => 'deposit', 'method' => 'cash', 'amount_cents' => 40950, 'status' => 'succeeded' ) );

		$this->assertSame( 'no_saved_card', $this->charger()->run()[0]['result'] );

		$this->expectException( PaymentException::class );
		$this->charger()->charge( 2, 'user:1' );
	}

	public function testNothingDue(): void {
		$this->confirmedBooking();
		$this->charger()->run();

		try {
			$this->charger()->charge( 1, 'user:1' );
			$this->fail( 'charged twice' );
		} catch ( \Marclie\Booking\Domain\Exception\InvalidTransitionException $e ) {
			$this->assertSame( 'paid', $this->ledger->booking( 1 )['status'] );
		}

		$this->ledger->updateBooking( 1, array( 'status' => 'confirmed' ) );
		try {
			$this->charger()->charge( 1, 'user:1' );
			$this->fail( 'charged twice' );
		} catch ( PaymentException $e ) {
			$this->assertSame( PaymentException::NOTHING_DUE, $e->errorCode() );
		}
	}

	public function testDefiniteGatewayErrorFailsTheAttemptWithoutStatusChange(): void {
		$this->confirmedBooking();
		$this->gateway->down = 'parameter_invalid_integer';

		try {
			$this->charger()->charge( 1, 'user:1' );
			$this->fail( 'no exception' );
		} catch ( PaymentException $e ) {
			$this->assertSame( PaymentException::CODE, $e->errorCode() );
			$this->assertFalse( $e->context()['outcome_unknown'] );
		}

		$attempt = array_values( array_filter( $this->ledger->payments( 1 ), static fn( array $p ): bool => 'balance' === $p['kind'] ) );
		$this->assertSame( array( 'failed', 'parameter_invalid_integer' ), array( $attempt[0]['status'], $attempt[0]['error_code'] ) );
		$this->assertSame( 'confirmed', $this->ledger->booking( 1 )['status'] );
	}

	public function testLostResponseKeepsTheAttemptPendingAndReconcileFindsTheCharge(): void {
		$this->confirmedBooking();
		$this->gateway->loseResponse = 'chargeOffSession';

		try {
			$this->charger()->charge( 1, 'user:1' );
			$this->fail( 'no exception' );
		} catch ( PaymentException $e ) {
			$this->assertTrue( $e->context()['outcome_unknown'] );
		}
		$attempt = array_values( array_filter( $this->ledger->payments( 1 ), static fn( array $p ): bool => 'balance' === $p['kind'] ) )[0];
		$this->assertSame( array( 'pending', null ), array( $attempt['status'], $attempt['stripe_intent_id'] ) );
		$this->assertSame( 'in_progress', $this->charger()->run( null, true )[0]['result'], 'no new attempt while the outcome is unknown' );

		// Stripe did charge: the next run (or the admin) resolves it through metadata.payment_id first.
		$result = $this->charger()->run();

		$this->assertSame( 'paid', $this->ledger->booking( 1 )['status'] );
		$this->assertSame( array( 'succeeded', $this->gateway->intentByKey( 'mbk-balance-1-1' )['id'] ), array( $this->ledger->payment( (int) $attempt['id'] )['status'], $this->ledger->payment( (int) $attempt['id'] )['stripe_intent_id'] ) );
		$this->assertNull( $this->gateway->intentByKey( 'mbk-balance-1-2' ), 'never charged twice' );
		$this->assertSame( 'invalid_transition', $result[0]['result'] );
	}

	public function testUnknownAttemptNotFoundAtStripeIsClosedAfterTheIndexDelay(): void {
		$this->confirmedBooking();
		$this->gateway->down = 'api_connection_error';
		try {
			$this->charger()->charge( 1, 'user:1' );
		} catch ( PaymentException ) {
			$this->gateway->down = null;
		}
		$this->gateway->searchable = false;

		$this->reconciler()->reconcile( $this->ledger->booking( 1 ) );
		$this->assertSame( 'pending', $this->ledger->payments( 1 )[1]['status'], 'too early to rule it out' );

		$later = new FixedClock( '2027-06-28 09:30:00' );
		$this->reconciler( $later )->reconcile( $this->ledger->booking( 1 ) );
		$this->assertSame( array( 'failed', 'not_created' ), array( $this->ledger->payments( 1 )[1]['status'], $this->ledger->payments( 1 )[1]['error_code'] ) );

		$this->charger( $later )->charge( 1, 'user:1' );
		$this->assertSame( 'paid', $this->ledger->booking( 1 )['status'] );
	}

	public function testOpenPaymentPageIntentIsCancelledBeforeTheOffSessionCharge(): void {
		$this->confirmedBooking();
		$page = $this->cardPayment( 1, 95550, 'balance', 'mbk-pay-1-1' );

		$this->assertSame( 'in_progress', $this->charger()->run( null, true )[0]['result'] );
		$result = $this->charger()->run();

		$this->assertSame( 'succeeded', $result[0]['result'] );
		$this->assertSame( 'canceled', $this->gateway->intents[ $page['intent'] ]['status'] );
		$this->assertSame( array( 'failed', 'canceled' ), array( $this->ledger->payment( $page['payment_id'] )['status'], $this->ledger->payment( $page['payment_id'] )['error_code'] ) );
		$this->assertSame( 136500, $this->ledger->net( 1 ) );
	}

	public function testPaymentPageProcessingBlocksTheCharge(): void {
		$this->confirmedBooking();
		$page = $this->cardPayment( 1, 95550, 'balance', 'mbk-pay-1-1' );
		$this->gateway->setStatus( $page['intent'], 'processing' );

		try {
			$this->charger()->charge( 1, 'user:1' );
			$this->fail( 'charged next to a processing payment' );
		} catch ( PaymentException $e ) {
			$this->assertSame( PaymentException::IN_PROGRESS, $e->errorCode() );
		}
		$this->assertNull( $this->gateway->intentByKey( 'mbk-balance-1-1' ) );

		// The guest's payment succeeds: the run applies it and charges nothing.
		$this->gateway->succeed( $page['intent'] );
		$this->assertSame( 'invalid_transition', $this->charger()->run()[0]['result'] );
		$this->assertSame( 'paid', $this->ledger->booking( 1 )['status'] );
		$this->assertNull( $this->gateway->intentByKey( 'mbk-balance-1-1' ) );
	}

	public function testWithoutGatewayPaymentsAreUnavailable(): void {
		$this->confirmedBooking();

		$this->expectException( PaymentException::class );
		$this->expectExceptionMessage( 'Online payments are not available.' );
		$this->charger( null, false )->charge( 1, 'user:1' );
	}
}
