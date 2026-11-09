<?php
/**
 * Coupon value (row of mbk_coupons).
 */

namespace Marclie\Booking\Coupons;

defined( 'ABSPATH' ) || exit;

final class Coupon {

	public const PCT   = 'pct';
	public const FIXED = 'fixed';

	/**
	 * @param int        $amount  Percent (pct) or cents (fixed).
	 * @param list<int>|null $unitIds Null = every unit.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $code,
		public readonly string $type,
		public readonly int $amount,
		public readonly int $minNights = 0,
		public readonly ?string $bookFrom = null,
		public readonly ?string $bookTo = null,
		public readonly ?string $stayFrom = null,
		public readonly ?string $stayTo = null,
		public readonly ?array $unitIds = null,
		public readonly ?int $usageLimit = null,
		public readonly ?int $perGuestLimit = null,
		public readonly int $usedCount = 0,
		public readonly ?int $guestId = null,
		public readonly bool $active = true
	) {}
}
