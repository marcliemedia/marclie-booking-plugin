<?php

namespace Marclie\Booking\Tests\Unit;

use Marclie\Booking\Domain\BookingStatus as S;
use PHPUnit\Framework\TestCase;

final class BookingStatusTest extends TestCase {

	private const ALLOWED = array(
		'pending_payment' => array( 'expired', 'confirmed', 'paid', 'cancelled' ),
		'confirmed'       => array( 'paid', 'balance_failed', 'cancelled' ),
		'balance_failed'  => array( 'paid', 'cancelled' ),
		'paid'            => array( 'cancelled', 'completed' ),
		'cancelled'       => array(),
		'expired'         => array(),
		'completed'       => array(),
	);

	public function testTransitionMatrix(): void {
		foreach ( S::cases() as $from ) {
			foreach ( S::cases() as $to ) {
				$this->assertSame(
					in_array( $to->value, self::ALLOWED[ $from->value ], true ),
					$from->canTransitionTo( $to ),
					$from->value . ' -> ' . $to->value
				);
			}
		}
	}

	public function testOccupiesAndFinal(): void {
		foreach ( S::cases() as $s ) {
			$this->assertSame( ! in_array( $s, array( S::Cancelled, S::Expired ), true ), $s->occupiesNights(), $s->value );
			$this->assertSame( in_array( $s, array( S::Cancelled, S::Expired, S::Completed ), true ), $s->isFinal(), $s->value );
		}
		$this->assertSame( S::BalanceFailed, S::from( 'balance_failed' ) );
	}

	public function testAfterPayment(): void {
		// total 136500, deposit 40950 (30 %).
		$this->assertNull( S::PendingPayment->afterPayment( 0, 136500, 40950 ) );
		$this->assertNull( S::PendingPayment->afterPayment( 40949, 136500, 40950 ) );
		$this->assertSame( S::Confirmed, S::PendingPayment->afterPayment( 40950, 136500, 40950 ) );
		$this->assertSame( S::Paid, S::PendingPayment->afterPayment( 136500, 136500, 40950 ) );
		$this->assertSame( S::Paid, S::PendingPayment->afterPayment( 140000, 136500, 40950 ) );
		$this->assertNull( S::Confirmed->afterPayment( 100000, 136500, 40950 ) );
		$this->assertSame( S::Paid, S::Confirmed->afterPayment( 136500, 136500, 40950 ) );
		$this->assertSame( S::Paid, S::BalanceFailed->afterPayment( 136500, 136500, 40950 ) );
		$this->assertNull( S::Paid->afterPayment( 200000, 136500, 40950 ) );
		$this->assertNull( S::Cancelled->afterPayment( 136500, 136500, 40950 ) );
		// Pay-in-full quote: deposit = total.
		$this->assertNull( S::PendingPayment->afterPayment( 50000, 136500, 136500 ) );
	}

	public function testOnlyExpiredHasLatePaymentTargets(): void {
		foreach ( S::cases() as $status ) {
			$expected = S::Expired === $status ? array( S::Confirmed, S::Paid, S::Cancelled ) : array();
			$this->assertSame( $expected, $status->latePaymentTargets(), $status->value );
		}
		$this->assertTrue( S::Expired->isFinal(), 'still final for the admin' );
	}
}
