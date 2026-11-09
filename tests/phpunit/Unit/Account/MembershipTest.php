<?php

namespace Marclie\Booking\Tests\Unit\Account;

use Marclie\Booking\Account\Membership;
use Marclie\Booking\Domain\FixedClock;
use Marclie\Booking\Tests\Fakes\ArrayMembershipStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MembershipTest extends TestCase {

	private ArrayMembershipStore $store;

	private int $suffix = 0;

	protected function setUp(): void {
		$this->store  = new ArrayMembershipStore();
		$this->suffix = 0;
	}

	private function membership( string $now = '2026-10-29 09:00' ): Membership {
		return new Membership( $this->store, new FixedClock( $now ), fn(): string => sprintf( 'T%05d', ++$this->suffix ) );
	}

	/** @return array<string, array{int, string}> */
	public static function tiers(): array {
		return array(
			'no stays'  => array( 0, 'clan' ),
			'one stay'  => array( 1, 'srebrni' ),
			'two stays' => array( 2, 'srebrni' ),
			'three'     => array( 3, 'zlatni' ),
			'ten'       => array( 10, 'zlatni' ),
		);
	}

	#[DataProvider( 'tiers' )]
	public function testTierForCompletedStays( int $stays, string $tier ): void {
		self::assertSame( $tier, Membership::tierFor( $stays ) );
	}

	public function testNextTierAndStaysToNext(): void {
		self::assertSame( 'srebrni', Membership::nextTier( 'clan' ) );
		self::assertSame( 'zlatni', Membership::nextTier( 'srebrni' ) );
		self::assertNull( Membership::nextTier( 'zlatni' ) );
		self::assertSame( 1, Membership::staysToNext( 'clan', 0 ) );
		self::assertSame( 2, Membership::staysToNext( 'srebrni', 1 ) );
		self::assertSame( 0, Membership::staysToNext( 'zlatni', 5 ) );
	}

	public function testPerksAccumulate(): void {
		self::assertSame( array( 'bookings_overview', 'welcome_coupon' ), Membership::perks( 'clan' ) );
		self::assertContains( 'early_checkin', Membership::perks( 'srebrni' ) );
		self::assertNotContains( 'late_checkout', Membership::perks( 'srebrni' ) );
		self::assertContains( 'early_checkin', Membership::perks( 'zlatni' ) );
		self::assertContains( 'late_checkout', Membership::perks( 'zlatni' ) );
	}

	public function testYearIndex(): void {
		self::assertSame( 0, Membership::yearIndex( '2026-03-02', '2026-10-29' ) );
		self::assertSame( 0, Membership::yearIndex( '2026-03-02', '2027-03-01' ) );
		self::assertSame( 1, Membership::yearIndex( '2026-03-02', '2027-03-02' ) );
		self::assertSame( 2, Membership::yearIndex( '2026-03-02', '2028-12-31' ) );
		self::assertSame( 0, Membership::yearIndex( '2027-01-01', '2026-10-29' ) );
	}

	public function testRandomSuffixIs80BitBase32InGroups(): void {
		$a = Membership::randomSuffix();
		self::assertMatchesRegularExpression( '/^[0-9A-HJKMNP-TV-Z]{4}(-[0-9A-HJKMNP-TV-Z]{4}){3}$/', $a );
		self::assertNotSame( $a, Membership::randomSuffix() );
		self::assertStringStartsWith( 'GOLD-', Membership::couponRow( 'zlatni', 1, $a, new \DateTimeImmutable( '2026-10-29' ) )['code'] );
	}

	public function testCouponRows(): void {
		$today  = new \DateTimeImmutable( '2026-10-29' );
		$silver = Membership::couponRow( 'srebrni', 12, 'ab12cd', $today );
		self::assertSame( 'SILVER-AB12CD', $silver['code'] );
		self::assertSame( 5, $silver['amount'] );
		self::assertSame( 7, $silver['min_nights'] );
		self::assertSame( '2027-10-28', $silver['book_to'] );
		self::assertSame( 1, $silver['usage_limit'] );
		self::assertSame( 12, $silver['guest_id'] );

		$gold = Membership::couponRow( 'zlatni', 12, 'x', $today );
		self::assertSame( 10, $gold['amount'] );
		self::assertSame( 0, $gold['min_nights'] );

		$welcome = Membership::couponRow( 'welcome', 12, 'x', $today );
		self::assertSame( 5, $welcome['amount'] );
		self::assertSame( 'welcome', Membership::couponKind( $welcome['code'] ) );
		self::assertSame( 'srebrni', Membership::couponKind( 'silver-ab12cd' ) );
		self::assertNull( Membership::couponKind( 'DIRECT5' ) );
	}

	public function testStartIssuesWelcomeCouponOnce(): void {
		$first = $this->membership()->start( 7, 12 );
		self::assertSame( 'clan', $first['tier'] );
		self::assertTrue( $first['changed'] );
		self::assertCount( 1, $first['coupons'] );
		self::assertStringStartsWith( 'WELCOME-', $first['coupons'][0]['code'] );

		$again = $this->membership()->start( 7, 12 );
		self::assertSame( array(), $again['coupons'] );
		self::assertFalse( $again['changed'] );
		self::assertCount( 1, $this->store->coupons );
	}

	public function testFirstCompletedStayGivesSilverAndOneCouponEvenWhenRunTwice(): void {
		$this->membership()->start( 7, 12 );
		$this->store->stays[12] = 1;

		$run = $this->membership( '2027-08-20 09:00' )->sync( 7, 12 );
		self::assertSame( 'srebrni', $run['tier'] );
		self::assertSame( 'clan', $run['previous'] );
		self::assertTrue( $run['changed'] );
		self::assertCount( 1, $run['coupons'] );
		self::assertSame( 7, $run['coupons'][0]['min_nights'] );
		self::assertSame( '2028-08-19', $run['coupons'][0]['book_to'] );
		self::assertSame( '2027-08-20', $this->store->tiers[7]['since'] );

		$second = $this->membership( '2027-08-20 09:00' )->sync( 7, 12 );
		self::assertFalse( $second['changed'] );
		self::assertSame( array(), $second['coupons'] );
		self::assertCount( 2, $this->store->coupons, 'welcome + silver' );
	}

	public function testSilverCouponEveryMembershipYear(): void {
		$this->store->stays[12] = 1;
		$this->membership( '2027-08-20 09:00' )->sync( 7, 12 );
		self::assertSame( array(), $this->membership( '2028-08-19 09:00' )->sync( 7, 12 )['coupons'] );

		$nextYear = $this->membership( '2028-08-20 09:00' )->sync( 7, 12 );
		self::assertCount( 1, $nextYear['coupons'] );
		self::assertSame( 'srebrni', $nextYear['tier'] );
		self::assertFalse( $nextYear['changed'] );
	}

	public function testGoldReplacesSilverAndTiersNeverGoDown(): void {
		$this->store->stays[12] = 1;
		$this->membership( '2027-08-20 09:00' )->sync( 7, 12 );
		$this->store->stays[12] = 3;
		$gold = $this->membership( '2027-09-01 09:00' )->sync( 7, 12 );
		self::assertSame( 'zlatni', $gold['tier'] );
		self::assertCount( 1, $gold['coupons'] );
		self::assertSame( 10, $gold['coupons'][0]['amount'] );

		$this->store->stays[12] = 1; // A stay un-completed by an admin: the tier stays.
		$later = $this->membership( '2027-09-02 09:00' )->sync( 7, 12 );
		self::assertSame( 'zlatni', $later['tier'] );
		self::assertSame( array(), $later['coupons'] );
	}

	public function testDuplicateCodeIsRetriedAndFailureReleasesTheClaim(): void {
		$this->store->failInserts = 2;
		$result                   = $this->membership()->start( 7, 12 );
		self::assertCount( 1, $result['coupons'] );

		$this->store->failInserts = 5;
		$this->store->stays[13]   = 1;
		try {
			$this->membership()->sync( 8, 13 );
			self::fail( 'Expected a RuntimeException.' );
		} catch ( \RuntimeException $e ) {
			self::assertArrayNotHasKey( 'membership:srebrni:13:0', $this->store->claims, 'claim released for the next run' );
		}

		$this->store->failInserts = 0;
		self::assertCount( 1, $this->membership()->sync( 8, 13 )['coupons'] );
	}
}
