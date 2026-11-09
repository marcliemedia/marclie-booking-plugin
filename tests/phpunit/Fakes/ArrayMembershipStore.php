<?php
/**
 * In-memory MembershipStore: stays per guest, tiers per user, claims by key
 * (UNIQUE like mbk_events.external_id) and coupons with unique codes.
 */

namespace Marclie\Booking\Tests\Fakes;

use Marclie\Booking\Account\MembershipStore;

final class ArrayMembershipStore implements MembershipStore {

	/** @var array<int, int> Guest ID => completed stays. */
	public array $stays = array();

	/** @var array<int, array{tier: ?string, since: ?string}> */
	public array $tiers = array();

	/** @var array<string, int> Claim key => claim ID. */
	public array $claims = array();

	/** @var array<int, array<string, mixed>> Claim ID => payload. */
	public array $claimPayloads = array();

	/** @var array<int, array<string, mixed>> Coupon ID => row. */
	public array $coupons = array();

	/** Inserts failing before one succeeds (duplicate code simulation). */
	public int $failInserts = 0;

	private int $seq = 0;

	public function completedStays( int $guestId, string $todayYmd ): int {
		return $this->stays[ $guestId ] ?? 0;
	}

	public function tierOf( int $userId ): array {
		return $this->tiers[ $userId ] ?? array(
			'tier'  => null,
			'since' => null,
		);
	}

	public function setTier( int $userId, string $tier, string $sinceYmd ): void {
		$this->tiers[ $userId ] = array(
			'tier'  => $tier,
			'since' => $sinceYmd,
		);
	}

	public function claim( string $key, int $guestId, array $payload ): int {
		if ( isset( $this->claims[ $key ] ) ) {
			return 0;
		}
		$id                         = ++$this->seq;
		$this->claims[ $key ]       = $id;
		$this->claimPayloads[ $id ] = $payload;

		return $id;
	}

	public function complete( int $claimId, array $payload ): void {
		$this->claimPayloads[ $claimId ] = $payload;
	}

	public function release( int $claimId ): void {
		$key = array_search( $claimId, $this->claims, true );
		if ( false !== $key ) {
			unset( $this->claims[ $key ] );
		}
	}

	public function insertCoupon( array $row ): int {
		if ( $this->failInserts > 0 ) {
			--$this->failInserts;

			return 0;
		}
		foreach ( $this->coupons as $existing ) {
			if ( $existing['code'] === $row['code'] ) {
				return 0;
			}
		}
		$id                   = ++$this->seq;
		$this->coupons[ $id ] = $row;

		return $id;
	}
}
