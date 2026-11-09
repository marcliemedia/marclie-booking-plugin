<?php
/**
 * Bookable unit (HR original `property` post).
 */

namespace Marclie\Booking\Units;

defined( 'ABSPATH' ) || exit;

final class Unit {

	public function __construct(
		public readonly int $id,
		public readonly string $code,
		public readonly string $title,
		public readonly int $capacity,
		public readonly int $maxChildren,
		public readonly string $checkinFrom,
		public readonly string $checkinTo,
		public readonly string $checkoutUntil,
		public readonly ?string $houseSlug = null,
		public readonly string $permalink = '',
		public readonly int $thumbnailId = 0
	) {}
}
