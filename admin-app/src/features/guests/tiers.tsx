import { Star } from '@phosphor-icons/react';
import { __ } from '@wordpress/i18n';

import type { MemberTier } from '@/api/types';
import type { BadgeProps } from '@/components/ui/badge';
import { Badge } from '@/components/ui/badge';

/** Membership tiers, lowest first (`mbk_tier` user meta values). */
export const MEMBER_TIERS: MemberTier[] = [ 'clan', 'srebrni', 'zlatni' ];

export function tierLabel( tier: MemberTier ): string {
	switch ( tier ) {
		case 'zlatni':
			return __( 'Gold member', 'marclie-booking' );
		case 'srebrni':
			return __( 'Silver member', 'marclie-booking' );
		default:
			return __( 'Member', 'marclie-booking' );
	}
}

const VARIANT: Record< MemberTier, BadgeProps[ 'variant' ] > = {
	clan: 'outline',
	srebrni: 'default',
	zlatni: 'warning',
};

/** Membership tier pill of a guest with an account. */
export function TierBadge( { tier }: { tier: MemberTier } ) {
	return (
		<Badge variant={ VARIANT[ tier ] }>
			<Star className="h-3 w-3" weight={ tier === 'clan' ? 'regular' : 'fill' } aria-hidden="true" />
			{ tierLabel( tier ) }
		</Badge>
	);
}
