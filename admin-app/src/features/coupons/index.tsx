import { LockKey, MagnifyingGlass, Plus, Ticket } from '@phosphor-icons/react';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { useBootstrapData } from '@/api/hooks/bootstrap';
import type { CouponListParams, CouponWithStats } from '@/api/hooks/coupons';
import { useCoupons } from '@/api/hooks/coupons';
import { setQuery, useRoute } from '@/app/router';
import type { ColumnDef } from '@/components/app/DataTable';
import { DataTable } from '@/components/app/DataTable';
import { EmptyState } from '@/components/app/EmptyState';
import { ErrorState } from '@/components/app/ErrorState';
import { Money } from '@/components/app/Money';
import { PageHeader } from '@/components/app/PageHeader';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useDebounced } from '@/features/common/GuestPicker';
import { formatDate, formatNumber } from '@/lib/format';

import { CouponSheet } from './CouponSheet';
import { COUPON_STATE_BADGE, couponState, couponValue } from './couponUtils';

const PER_PAGE = 25;

function windowLabel( from: string | null, to: string | null ): string {
	if ( ! from && ! to ) {
		return __( 'Any', 'marclie-booking' );
	}
	return `${ from ? formatDate( from ) : '…' } – ${ to ? formatDate( to ) : '…' }`;
}

export default function CouponsPage( { params }: { params: Record< string, string > } ) {
	void params;
	const { caps, today, units } = useBootstrapData();
	const { query } = useRoute();
	const page = Math.max( 1, Number( query.get( 'page' ) ) || 1 );
	const active = ( query.get( 'active' ) ?? '' ) as CouponListParams[ 'active' ];
	const [ search, setSearch ] = useState( query.get( 'q' ) ?? '' );
	const debounced = useDebounced( search.trim() );
	const [ editing, setEditing ] = useState< { coupon: CouponWithStats | null } | null >( null );

	useEffect( () => {
		if ( debounced !== ( query.get( 'q' ) ?? '' ) ) {
			setQuery( { q: debounced, page: null } );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ debounced ] );

	const coupons = useCoupons( { page, per_page: PER_PAGE, active, search: query.get( 'q' ) ?? '' } );
	const filtered = !! ( active || query.get( 'q' ) );

	const columns = useMemo< ColumnDef< CouponWithStats >[] >(
		() => [
			{
				id: 'code',
				header: __( 'Code', 'marclie-booking' ),
				meta: { label: __( 'Code', 'marclie-booking' ) },
				cell: ( { row } ) => (
					<div>
						<code className="whitespace-nowrap rounded bg-surface-muted px-1.5 py-0.5 text-xs font-semibold text-ink">{ row.original.code }</code>
						{ row.original.guest_id && (
							<span className="ml-2 text-xs text-muted-foreground">{ __( 'Personal', 'marclie-booking' ) }</span>
						) }
					</div>
				),
			},
			{
				id: 'value',
				header: __( 'Discount', 'marclie-booking' ),
				meta: { label: __( 'Discount', 'marclie-booking' ) },
				cell: ( { row } ) => (
					<span>
						{ couponValue( row.original ) }
						{ row.original.min_nights ? (
							<span className="block text-xs text-muted-foreground">
								{ sprintf(
									/* translators: %d: nights. */
									_n( 'min. %d night', 'min. %d nights', row.original.min_nights, 'marclie-booking' ),
									row.original.min_nights
								) }
							</span>
						) : null }
					</span>
				),
			},
			{
				id: 'booking_window',
				header: __( 'Booking window', 'marclie-booking' ),
				meta: { label: __( 'Booking window', 'marclie-booking' ), hideable: true, className: 'whitespace-nowrap' },
				cell: ( { row } ) => windowLabel( row.original.book_from, row.original.book_to ),
			},
			{
				id: 'stay_window',
				header: __( 'Stay window', 'marclie-booking' ),
				meta: { label: __( 'Stay window', 'marclie-booking' ), hideable: true, className: 'whitespace-nowrap' },
				cell: ( { row } ) => windowLabel( row.original.stay_from, row.original.stay_to ),
			},
			{
				id: 'units',
				header: __( 'Units', 'marclie-booking' ),
				meta: { label: __( 'Units', 'marclie-booking' ), hideable: true },
				cell: ( { row } ) =>
					row.original.unit_ids === null
						? __( 'All', 'marclie-booking' )
						: sprintf( /* translators: 1: selected, 2: total. */ __( '%1$d of %2$d', 'marclie-booking' ), row.original.unit_ids.length, units.length ),
			},
			{
				id: 'usage',
				header: __( 'Used', 'marclie-booking' ),
				meta: { label: __( 'Used / limit', 'marclie-booking' ), align: 'right' },
				cell: ( { row } ) => (
					<span className="tabular-nums">
						{ formatNumber( row.original.used_count ) }
						<span className="text-muted-foreground"> / { row.original.usage_limit === null ? '∞' : formatNumber( row.original.usage_limit ) }</span>
					</span>
				),
			},
			{
				id: 'discount_total',
				header: __( 'Total discount', 'marclie-booking' ),
				meta: { label: __( 'Total discount', 'marclie-booking' ), align: 'right', hideable: true },
				cell: ( { row } ) => <Money cents={ row.original.stats?.discount_total_cents ?? 0 } muted={ ! row.original.stats?.discount_total_cents } />,
			},
			{
				id: 'status',
				header: __( 'Status', 'marclie-booking' ),
				meta: { label: __( 'Status', 'marclie-booking' ) },
				cell: ( { row } ) => {
					const state = COUPON_STATE_BADGE[ couponState( row.original, today ) ];
					return <Badge variant={ state.variant }>{ state.label() }</Badge>;
				},
			},
		],
		[ today, units.length ]
	);

	if ( ! caps.manage_rates ) {
		return (
			<>
				<PageHeader title={ __( 'Coupons', 'marclie-booking' ) } />
				<EmptyState icon={ LockKey } title={ __( 'No access', 'marclie-booking' ) } description={ __( 'Your role cannot manage coupons.', 'marclie-booking' ) } />
			</>
		);
	}

	return (
		<>
			<PageHeader
				title={ __( 'Coupons', 'marclie-booking' ) }
				description={ __( 'Discount codes for direct bookings.', 'marclie-booking' ) }
				actions={
					<Button onClick={ () => setEditing( { coupon: null } ) }>
						<Plus className="h-4 w-4" aria-hidden="true" />
						{ __( 'New coupon', 'marclie-booking' ) }
					</Button>
				}
			>
				<div className="flex flex-wrap items-center gap-2">
					<div className="relative w-full sm:w-64">
						<MagnifyingGlass className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
						<Input
							type="search"
							value={ search }
							onChange={ ( e ) => setSearch( e.target.value ) }
							placeholder={ __( 'Search code…', 'marclie-booking' ) }
							aria-label={ __( 'Search coupons', 'marclie-booking' ) }
							className="pl-8"
						/>
					</div>
					<Select value={ active || 'all' } onValueChange={ ( v ) => setQuery( { active: v === 'all' ? null : v, page: null } ) }>
						<SelectTrigger className="w-[150px]" aria-label={ __( 'Active', 'marclie-booking' ) }>
							<SelectValue />
						</SelectTrigger>
						<SelectContent>
							<SelectItem value="all">{ __( 'All coupons', 'marclie-booking' ) }</SelectItem>
							<SelectItem value="1">{ __( 'Active', 'marclie-booking' ) }</SelectItem>
							<SelectItem value="0">{ __( 'Inactive', 'marclie-booking' ) }</SelectItem>
						</SelectContent>
					</Select>
				</div>
			</PageHeader>

			{ coupons.isError ? (
				<ErrorState error={ coupons.error } onRetry={ () => coupons.refetch() } />
			) : (
				<DataTable
					label={ __( 'Coupons', 'marclie-booking' ) }
					columns={ columns }
					data={ coupons.data?.items ?? [] }
					total={ coupons.data?.total ?? 0 }
					page={ page }
					perPage={ PER_PAGE }
					onPageChange={ ( p ) => setQuery( { page: p > 1 ? p : null } ) }
					loading={ coupons.isPending }
					fetching={ coupons.isPlaceholderData }
					getRowId={ ( c ) => String( c.id ) }
					onRowClick={ ( c ) => setEditing( { coupon: c } ) }
					empty={
						<EmptyState
							compact
							icon={ Ticket }
							title={ filtered ? __( 'No coupons match these filters', 'marclie-booking' ) : __( 'No coupons yet', 'marclie-booking' ) }
							action={
								! filtered && (
									<Button size="sm" onClick={ () => setEditing( { coupon: null } ) }>
										{ __( 'Create coupon', 'marclie-booking' ) }
									</Button>
								)
							}
						/>
					}
				/>
			) }

			<CouponSheet open={ !! editing } onOpenChange={ ( o ) => ! o && setEditing( null ) } coupon={ editing?.coupon ?? null } />
		</>
	);
}
