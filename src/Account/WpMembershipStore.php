<?php
/**
 * MembershipStore on the plugin tables and user meta: claims are mbk_events
 * rows with a UNIQUE external_id (type `membership_coupon`), coupons are
 * mbk_coupons rows, the tier lives in user meta `mbk_tier` / `mbk_tier_since`.
 */

namespace Marclie\Booking\Account;

use Marclie\Booking\Repository\BookingRepository;
use Marclie\Booking\Repository\CouponRepository;
use Marclie\Booking\Repository\EventRepository;

defined( 'ABSPATH' ) || exit;

final class WpMembershipStore implements MembershipStore {

	public const ACTOR = 'system:membership';

	public function __construct(
		private BookingRepository $bookings,
		private CouponRepository $coupons,
		private EventRepository $events
	) {}

	public function completedStays( int $guestId, string $todayYmd ): int {
		return $this->bookings->countCompletedStays( $guestId, $todayYmd );
	}

	public function tierOf( int $userId ): array {
		$tier  = (string) get_user_meta( $userId, AccountService::META_TIER, true );
		$since = (string) get_user_meta( $userId, AccountService::META_TIER_SINCE, true );

		return array(
			'tier'  => '' !== $tier ? $tier : null,
			'since' => '' !== $since ? $since : null,
		);
	}

	public function setTier( int $userId, string $tier, string $sinceYmd ): void {
		update_user_meta( $userId, AccountService::META_TIER, $tier );
		update_user_meta( $userId, AccountService::META_TIER_SINCE, $sinceYmd );
	}

	public function claim( string $key, int $guestId, array $payload ): int {
		return $this->events->claim( $key, 'membership_coupon', self::ACTOR, null, $guestId, $payload );
	}

	public function complete( int $claimId, array $payload ): void {
		$this->events->updateEvent( $claimId, 'membership_coupon', $payload );
	}

	public function release( int $claimId ): void {
		$this->events->updateEvent( $claimId, 'membership_coupon_failed', array(), true );
	}

	public function insertCoupon( array $row ): int {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );
		$id       = $this->coupons->insert( $row );
		$wpdb->suppress_errors( $suppress );

		return $id;
	}
}
