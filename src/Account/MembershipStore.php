<?php
/**
 * Persistence port of Membership: completed stays, tier user meta, idempotency
 * claims and member coupons. WpMembershipStore is the real one; unit tests use
 * an in-memory fake.
 */

namespace Marclie\Booking\Account;

defined( 'ABSPATH' ) || exit;

interface MembershipStore {

	/** Completed stays of the guest (see BookingRepository::countCompletedStays()). */
	public function completedStays( int $guestId, string $todayYmd ): int;

	/**
	 * @return array{tier: ?string, since: ?string} Stored tier (null = none yet) and its start date (Y-m-d).
	 */
	public function tierOf( int $userId ): array;

	public function setTier( int $userId, string $tier, string $sinceYmd ): void;

	/**
	 * Atomically claims an idempotency key: the claim ID, or 0 when the key is
	 * already taken (the coupon was issued before).
	 *
	 * @param array<string, mixed> $payload
	 */
	public function claim( string $key, int $guestId, array $payload ): int;

	/**
	 * Completes a claim with the issued coupon.
	 *
	 * @param array<string, mixed> $payload
	 */
	public function complete( int $claimId, array $payload ): void;

	/** Gives a claim up (the key can be claimed again). */
	public function release( int $claimId ): void;

	/**
	 * @param array<string, mixed> $row mbk_coupons columns.
	 * @return int New coupon ID, 0 on failure (e.g. duplicate code).
	 */
	public function insertCoupon( array $row ): int;
}
