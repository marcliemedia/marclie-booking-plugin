<?php
/**
 * Priced stay (snapshot stored in quote_json).
 */

namespace Marclie\Booking\Domain;

defined( 'ABSPATH' ) || exit;

final class Quote {

	/**
	 * @param list<QuoteLine> $lines
	 */
	public function __construct(
		public readonly Stay $stay,
		public readonly int $adults,
		public readonly int $children,
		/** Children 0–4: free, not counted in capacity. */
		public readonly int $infants,
		public readonly array $lines,
		public readonly Money $subtotal,
		public readonly Money $discount,
		public readonly Money $total,
		public readonly Money $deposit,
		public readonly Money $balance,
		public readonly ?\DateTimeImmutable $balanceDueOn,
		public readonly ?\DateTimeImmutable $freeCancelUntil,
		public readonly bool $payInFull,
		public readonly ?string $couponCode = null,
		public readonly ?int $couponId = null,
		public readonly int $minStay = 1
	) {}

	/** @return array<string, mixed> */
	public function toArray(): array {
		return array(
			'unit_id'           => $this->stay->unitId,
			'arrival'           => $this->stay->arrivalString(),
			'departure'         => $this->stay->departureString(),
			'nights'            => $this->stay->nights(),
			'adults'            => $this->adults,
			'children'          => $this->children,
			'infants'           => $this->infants,
			'lines'             => array_map( static fn ( QuoteLine $l ) => $l->toArray(), $this->lines ),
			'subtotal_cents'    => $this->subtotal->cents(),
			'discount_cents'    => $this->discount->cents(),
			'total_cents'       => $this->total->cents(),
			'deposit_cents'     => $this->deposit->cents(),
			'balance_cents'     => $this->balance->cents(),
			'balance_due_on'    => $this->balanceDueOn?->format( 'Y-m-d' ),
			'free_cancel_until' => $this->freeCancelUntil?->format( 'Y-m-d' ),
			'pay_in_full'       => $this->payInFull,
			'coupon_code'       => $this->couponCode,
			'coupon_id'         => $this->couponId,
			'min_stay'          => $this->minStay,
		);
	}

	/** @param array<string, mixed> $a */
	public static function fromArray( array $a, ?\DateTimeZone $tz = null ): self {
		$tz   = $tz ?? new \DateTimeZone( 'Europe/Zagreb' );
		$date = static fn ( $v ) => empty( $v ) ? null : new \DateTimeImmutable( (string) $v, $tz );
		return new self(
			Stay::fromStrings( (int) $a['unit_id'], (string) $a['arrival'], (string) $a['departure'], $tz ),
			(int) $a['adults'],
			(int) $a['children'],
			(int) ( $a['infants'] ?? 0 ),
			array_values( array_map( array( QuoteLine::class, 'fromArray' ), (array) ( $a['lines'] ?? array() ) ) ),
			new Money( (int) $a['subtotal_cents'] ),
			new Money( (int) $a['discount_cents'] ),
			new Money( (int) $a['total_cents'] ),
			new Money( (int) $a['deposit_cents'] ),
			new Money( (int) $a['balance_cents'] ),
			$date( $a['balance_due_on'] ?? null ),
			$date( $a['free_cancel_until'] ?? null ),
			(bool) ( $a['pay_in_full'] ?? false ),
			isset( $a['coupon_code'] ) ? (string) $a['coupon_code'] : null,
			isset( $a['coupon_id'] ) ? (int) $a['coupon_id'] : null,
			(int) ( $a['min_stay'] ?? 1 )
		);
	}
}
