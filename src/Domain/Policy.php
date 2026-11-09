<?php
/**
 * Booking policy snapshot (deposit, balance, cancellation, hold, check-in times).
 */

namespace Marclie\Booking\Domain;

defined( 'ABSPATH' ) || exit;

use Marclie\Booking\Settings;

final class Policy {

	public function __construct(
		public readonly int $depositPct = 30,
		public readonly int $balanceDays = 14,
		public readonly int $freeCancelDays = 30,
		public readonly int $holdMinutes = 20,
		public readonly string $checkinFrom = '15:00',
		public readonly string $checkinTo = '22:00',
		public readonly string $checkoutUntil = '10:00',
		public readonly ?string $defaultCoupon = 'DIRECT5'
	) {}

	/**
	 * @param Settings|null $s Unused instance hook; settings are read via Settings::all().
	 */
	public static function fromSettings( ?Settings $s = null ): self {
		$policy = apply_filters( 'mbk_policy', Settings::all()['policy'] );
		return self::fromArray( is_array( $policy ) ? $policy : Settings::DEFAULTS['policy'] );
	}

	/** @return array<string, int|string|null> */
	public function toArray(): array {
		return array(
			'deposit_pct'      => $this->depositPct,
			'balance_days'     => $this->balanceDays,
			'free_cancel_days' => $this->freeCancelDays,
			'hold_minutes'     => $this->holdMinutes,
			'checkin_from'     => $this->checkinFrom,
			'checkin_to'       => $this->checkinTo,
			'checkout_until'   => $this->checkoutUntil,
			'default_coupon'   => $this->defaultCoupon,
		);
	}

	/** @param array<string, mixed> $a */
	public static function fromArray( array $a ): self {
		$d = new self();
		return new self(
			(int) ( $a['deposit_pct'] ?? $d->depositPct ),
			(int) ( $a['balance_days'] ?? $d->balanceDays ),
			(int) ( $a['free_cancel_days'] ?? $d->freeCancelDays ),
			(int) ( $a['hold_minutes'] ?? $d->holdMinutes ),
			(string) ( $a['checkin_from'] ?? $d->checkinFrom ),
			(string) ( $a['checkin_to'] ?? $d->checkinTo ),
			(string) ( $a['checkout_until'] ?? $d->checkoutUntil ),
			isset( $a['default_coupon'] ) && '' !== $a['default_coupon'] ? (string) $a['default_coupon'] : null
		);
	}
}
