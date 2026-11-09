import { ArrowSquareOut, CheckCircle, WarningCircle } from '@phosphor-icons/react';
import { __, sprintf } from '@wordpress/i18n';

import type { Coupon, GuestAccount } from '@/api/types';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { couponValue } from '@/features/coupons/couponUtils';
import { formatDate } from '@/lib/format';

import { TierBadge } from './tiers';

/** wp-admin profile screen of the linked WordPress user. */
export function userEditUrl( userId: number ): string {
	return `${ window.mbkAdmin?.adminUrl ?? '/wp-admin/' }user-edit.php?user_id=${ userId }`;
}

interface Props {
	account: GuestAccount | null;
	/** Coupons assigned to this guest (membership/welcome coupons); omitted where not loaded. */
	coupons?: Coupon[];
}

/** Website account of a guest: linked WP user, e-mail verification, membership tier. */
export function GuestAccountCard( { account, coupons }: Props ) {
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Website account', 'marclie-booking' ) }</CardTitle>
			</CardHeader>
			<CardContent>
				{ account ? (
					<AccountFacts account={ account } coupons={ coupons } />
				) : (
					<p className="text-sm text-muted-foreground">
						{ __( 'No account yet. The guest can register on the website with the same e-mail address; their bookings are then linked automatically.', 'marclie-booking' ) }
					</p>
				) }
			</CardContent>
		</Card>
	);
}

export function AccountFacts( { account, coupons }: { account: GuestAccount; coupons?: Coupon[] } ) {
	return (
		<dl className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-2 text-sm">
			<dt className="text-muted-foreground">{ __( 'User', 'marclie-booking' ) }</dt>
			<dd className="min-w-0">
				<a
					href={ userEditUrl( account.id ) }
					target="_blank"
					rel="noreferrer"
					className="inline-flex max-w-full items-center gap-1 font-medium text-accent hover:underline"
				>
					<span className="truncate">{ account.email }</span>
					<ArrowSquareOut className="h-3.5 w-3.5 flex-none" aria-hidden="true" />
					<span className="sr-only">{ __( '(opens in a new tab)', 'marclie-booking' ) }</span>
				</a>
			</dd>

			<dt className="text-muted-foreground">{ __( 'E-mail', 'marclie-booking' ) }</dt>
			<dd>
				{ account.email_verified_at ? (
					<span className="inline-flex items-center gap-1 text-success">
						<CheckCircle className="h-4 w-4" weight="fill" aria-hidden="true" />
						{ sprintf(
							/* translators: %s: date. */
							__( 'Verified %s', 'marclie-booking' ),
							formatDate( account.email_verified_at.slice( 0, 10 ) )
						) }
					</span>
				) : (
					<span className="inline-flex items-center gap-1 text-warning">
						<WarningCircle className="h-4 w-4" weight="fill" aria-hidden="true" />
						{ __( 'Not verified', 'marclie-booking' ) }
					</span>
				) }
			</dd>

			<dt className="text-muted-foreground">{ __( 'Membership', 'marclie-booking' ) }</dt>
			<dd className="flex flex-wrap items-center gap-2">
				{ account.tier ? <TierBadge tier={ account.tier } /> : '—' }
				{ account.tier_since && (
					<span className="text-xs text-muted-foreground">
						{ sprintf(
							/* translators: %s: date. */
							__( 'since %s', 'marclie-booking' ),
							formatDate( account.tier_since )
						) }
					</span>
				) }
			</dd>

			<dt className="text-muted-foreground">{ __( 'Member since', 'marclie-booking' ) }</dt>
			<dd>{ formatDate( account.member_since.slice( 0, 10 ) ) }</dd>

			{ coupons && (
				<>
					<dt className="text-muted-foreground">{ __( 'Member coupons', 'marclie-booking' ) }</dt>
					<dd>
						{ coupons.length === 0 ? (
							'—'
						) : (
							<ul className="flex flex-col gap-1">
								{ coupons.map( ( c ) => (
									<li key={ c.id } className="flex flex-wrap items-center gap-2">
										<code className="rounded bg-surface-muted px-1.5 py-0.5 text-xs font-semibold">{ c.code }</code>
										<span className="text-xs text-muted-foreground">{ couponValue( c ) }</span>
									</li>
								) ) }
							</ul>
						) }
					</dd>
				</>
			) }
		</dl>
	);
}
