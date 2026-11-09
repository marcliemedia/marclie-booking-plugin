import { __ } from '@wordpress/i18n';

import type { BadgeProps } from '@/components/ui/badge';
import type { Coupon } from '@/api/types';
import { formatMoney, formatPercent } from '@/lib/format';

export type CouponState = 'active' | 'inactive' | 'scheduled' | 'expired' | 'used_up';

/** "5 %" or "50,00 €". */
export function couponValue( c: Pick< Coupon, 'type' | 'amount' > ): string {
	return c.type === 'pct' ? formatPercent( c.amount ) : formatMoney( c.amount );
}

/** Effective state for the status badge (based on the booking window and usage). */
export function couponState( c: Coupon, today: string ): CouponState {
	if ( ! c.active ) {
		return 'inactive';
	}
	if ( c.usage_limit !== null && c.used_count >= c.usage_limit ) {
		return 'used_up';
	}
	if ( c.book_to && c.book_to < today ) {
		return 'expired';
	}
	if ( c.book_from && c.book_from > today ) {
		return 'scheduled';
	}
	return 'active';
}

export const COUPON_STATE_BADGE: Record< CouponState, { variant: NonNullable< BadgeProps[ 'variant' ] >; label: () => string } > = {
	active: { variant: 'success', label: () => __( 'Active', 'marclie-booking' ) },
	inactive: { variant: 'muted', label: () => __( 'Inactive', 'marclie-booking' ) },
	scheduled: { variant: 'warning', label: () => __( 'Scheduled', 'marclie-booking' ) },
	expired: { variant: 'muted', label: () => __( 'Expired', 'marclie-booking' ) },
	used_up: { variant: 'ink', label: () => __( 'Used up', 'marclie-booking' ) },
};
