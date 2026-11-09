import { CheckCircle, MagnifyingGlass, Users } from '@phosphor-icons/react';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import type { GuestListParams } from '@/api/hooks/guests';
import { useGuests } from '@/api/hooks/guests';
import type { Guest, MemberTier } from '@/api/types';
import { navigate, setQuery, useRoute } from '@/app/router';
import type { ColumnDef, SortingState } from '@/components/app/DataTable';
import { DataTable } from '@/components/app/DataTable';
import { EmptyState } from '@/components/app/EmptyState';
import { ErrorState } from '@/components/app/ErrorState';
import { Money } from '@/components/app/Money';
import { PageHeader } from '@/components/app/PageHeader';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { guestName, useDebounced } from '@/features/common/GuestPicker';
import { formatDate, formatNumber } from '@/lib/format';

import { GUEST_TAGS } from './tags';
import { MEMBER_TIERS, TierBadge, tierLabel } from './tiers';

const PER_PAGE = 25;
const ORDER_BY: Record< string, NonNullable< GuestListParams[ 'orderby' ] > > = {
	name: 'name',
	bookings_count: 'bookings_count',
	revenue_cents: 'revenue',
	last_stay_at: 'last_stay_at',
};

export function GuestsList() {
	const { query } = useRoute();
	const page = Math.max( 1, Number( query.get( 'page' ) ) || 1 );
	const tag = query.get( 'tag' ) ?? '';
	const country = query.get( 'country' ) ?? '';
	const consent = ( query.get( 'consent' ) ?? '' ) as GuestListParams[ 'has_consent' ];
	// `account`: '' (any), '0' (no account), or a tier / 'any' (has an account).
	const account = query.get( 'account' ) ?? '';
	const sortId = query.get( 'sort' ) ?? 'last_stay_at';
	const order = query.get( 'order' ) === 'asc' ? 'asc' : 'desc';

	const [ search, setSearch ] = useState( query.get( 'q' ) ?? '' );
	const debounced = useDebounced( search.trim() );
	useEffect( () => {
		if ( debounced !== ( query.get( 'q' ) ?? '' ) ) {
			setQuery( { q: debounced, page: null } );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ debounced ] );

	const params: GuestListParams = {
		page,
		per_page: PER_PAGE,
		search: query.get( 'q' ) ?? '',
		tag,
		country,
		has_consent: consent,
		has_account: account === '0' ? '0' : account ? '1' : '',
		tier: ( MEMBER_TIERS as string[] ).includes( account ) ? ( account as MemberTier ) : '',
		orderby: ORDER_BY[ sortId ] ?? 'last_stay_at',
		order,
	};
	const guests = useGuests( params );
	const sorting: SortingState = [ { id: sortId, desc: order === 'desc' } ];
	const filtered = !! ( params.search || tag || country || consent || account );

	const columns = useMemo< ColumnDef< Guest >[] >(
		() => [
			{
				id: 'name',
				header: __( 'Guest', 'marclie-booking' ),
				enableSorting: true,
				meta: { label: __( 'Guest', 'marclie-booking' ) },
				cell: ( { row } ) => (
					<div className="min-w-0">
						<p className="truncate font-medium text-ink">
							{ row.original.anonymized ? __( 'Anonymized guest', 'marclie-booking' ) : guestName( row.original ) }
						</p>
						<p className="truncate text-xs text-muted-foreground">{ row.original.email }</p>
					</div>
				),
			},
			{
				id: 'tier',
				header: __( 'Account', 'marclie-booking' ),
				meta: { label: __( 'Account', 'marclie-booking' ), hideable: true },
				cell: ( { row } ) =>
					row.original.tier ? (
						<TierBadge tier={ row.original.tier } />
					) : (
						<span className="text-muted-foreground" aria-label={ __( 'No account', 'marclie-booking' ) }>
							—
						</span>
					),
			},
			{
				id: 'country',
				header: __( 'Country', 'marclie-booking' ),
				meta: { label: __( 'Country', 'marclie-booking' ), hideable: true },
				cell: ( { row } ) => row.original.country ?? '—',
			},
			{
				id: 'bookings_count',
				header: __( 'Stays', 'marclie-booking' ),
				enableSorting: true,
				meta: { label: __( 'Stays', 'marclie-booking' ), align: 'right' },
				cell: ( { row } ) => formatNumber( row.original.bookings_count ),
			},
			{
				id: 'nights_total',
				header: __( 'Nights', 'marclie-booking' ),
				meta: { label: __( 'Nights', 'marclie-booking' ), align: 'right', hideable: true },
				cell: ( { row } ) => formatNumber( row.original.nights_total ),
			},
			{
				id: 'revenue_cents',
				header: __( 'Revenue', 'marclie-booking' ),
				enableSorting: true,
				meta: { label: __( 'Revenue', 'marclie-booking' ), align: 'right' },
				cell: ( { row } ) => <Money cents={ row.original.revenue_cents } />,
			},
			{
				id: 'last_stay_at',
				header: __( 'Last stay', 'marclie-booking' ),
				enableSorting: true,
				meta: { label: __( 'Last stay', 'marclie-booking' ) },
				cell: ( { row } ) => formatDate( row.original.last_stay_at ),
			},
			{
				id: 'tags',
				header: __( 'Tags', 'marclie-booking' ),
				meta: { label: __( 'Tags', 'marclie-booking' ), hideable: true },
				cell: ( { row } ) => (
					<div className="flex flex-wrap gap-1">
						{ row.original.tags.map( ( t ) => (
							<Badge key={ t } variant={ t === 'VIP' ? 'accent' : 'default' }>
								{ t }
							</Badge>
						) ) }
					</div>
				),
			},
			{
				id: 'consent',
				header: __( 'Marketing', 'marclie-booking' ),
				meta: { label: __( 'Marketing consent', 'marclie-booking' ), align: 'center', hideable: true },
				cell: ( { row } ) =>
					row.original.marketing_consent ? (
						<CheckCircle className="mx-auto h-4 w-4 text-success" weight="fill" aria-label={ __( 'Consented', 'marclie-booking' ) } />
					) : (
						<span className="text-muted-foreground" aria-label={ __( 'No consent', 'marclie-booking' ) }>
							—
						</span>
					),
			},
		],
		[]
	);

	return (
		<>
			<PageHeader
				title={ __( 'Guests', 'marclie-booking' ) }
				description={
					guests.data
						? sprintf(
								/* translators: %s: number of guests. */
								_n( '%s guest', '%s guests', guests.data.total, 'marclie-booking' ),
								formatNumber( guests.data.total )
						  )
						: undefined
				}
			>
				<div className="flex flex-wrap items-center gap-2">
					<div className="relative w-full sm:w-72">
						<MagnifyingGlass className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
						<Input
							type="search"
							value={ search }
							onChange={ ( e ) => setSearch( e.target.value ) }
							placeholder={ __( 'Search name, e-mail, phone…', 'marclie-booking' ) }
							aria-label={ __( 'Search guests', 'marclie-booking' ) }
							className="pl-8"
						/>
					</div>
					<Select value={ tag || 'all' } onValueChange={ ( v ) => setQuery( { tag: v === 'all' ? null : v, page: null } ) }>
						<SelectTrigger className="w-[150px]" aria-label={ __( 'Tag', 'marclie-booking' ) }>
							<SelectValue />
						</SelectTrigger>
						<SelectContent>
							<SelectItem value="all">{ __( 'All tags', 'marclie-booking' ) }</SelectItem>
							{ GUEST_TAGS.map( ( t ) => (
								<SelectItem key={ t } value={ t }>
									{ t }
								</SelectItem>
							) ) }
						</SelectContent>
					</Select>
					<Input
						value={ country }
						onChange={ ( e ) => setQuery( { country: e.target.value.toUpperCase().slice( 0, 2 ), page: null } ) }
						placeholder={ __( 'Country', 'marclie-booking' ) }
						aria-label={ __( 'Country code (2 letters)', 'marclie-booking' ) }
						className="w-[100px] uppercase"
						maxLength={ 2 }
					/>
					<Select
						value={ consent || 'all' }
						onValueChange={ ( v ) => setQuery( { consent: v === 'all' ? null : v, page: null } ) }
					>
						<SelectTrigger className="w-[180px]" aria-label={ __( 'Marketing consent', 'marclie-booking' ) }>
							<SelectValue />
						</SelectTrigger>
						<SelectContent>
							<SelectItem value="all">{ __( 'Any consent', 'marclie-booking' ) }</SelectItem>
							<SelectItem value="1">{ __( 'Marketing consent', 'marclie-booking' ) }</SelectItem>
							<SelectItem value="0">{ __( 'No consent', 'marclie-booking' ) }</SelectItem>
						</SelectContent>
					</Select>
					<Select
						value={ account || 'all' }
						onValueChange={ ( v ) => setQuery( { account: v === 'all' ? null : v, page: null } ) }
					>
						<SelectTrigger className="w-[170px]" aria-label={ __( 'Guest account', 'marclie-booking' ) }>
							<SelectValue />
						</SelectTrigger>
						<SelectContent>
							<SelectItem value="all">{ __( 'Any account', 'marclie-booking' ) }</SelectItem>
							<SelectItem value="any">{ __( 'Has an account', 'marclie-booking' ) }</SelectItem>
							{ MEMBER_TIERS.map( ( t ) => (
								<SelectItem key={ t } value={ t }>
									{ tierLabel( t ) }
								</SelectItem>
							) ) }
							<SelectItem value="0">{ __( 'No account', 'marclie-booking' ) }</SelectItem>
						</SelectContent>
					</Select>
					{ filtered && (
						<Button
							variant="ghost"
							size="sm"
							onClick={ () => {
								setSearch( '' );
								setQuery( { q: null, tag: null, country: null, consent: null, account: null, page: null } );
							} }
						>
							{ __( 'Clear filters', 'marclie-booking' ) }
						</Button>
					) }
				</div>
			</PageHeader>

			{ guests.isError ? (
				<ErrorState error={ guests.error } onRetry={ () => guests.refetch() } />
			) : (
				<DataTable
					label={ __( 'Guests', 'marclie-booking' ) }
					columns={ columns }
					data={ guests.data?.items ?? [] }
					total={ guests.data?.total ?? 0 }
					page={ page }
					perPage={ PER_PAGE }
					onPageChange={ ( p ) => setQuery( { page: p > 1 ? p : null } ) }
					sorting={ sorting }
					onSortingChange={ ( next ) =>
						setQuery( { sort: next[ 0 ]?.id ?? null, order: next[ 0 ]?.desc === false ? 'asc' : 'desc', page: null } )
					}
					loading={ guests.isPending }
					fetching={ guests.isPlaceholderData }
					getRowId={ ( g ) => String( g.id ) }
					onRowClick={ ( g ) => navigate( `/guests/${ g.id }` ) }
					empty={
						<EmptyState
							compact
							icon={ Users }
							title={ filtered ? __( 'No guests match these filters', 'marclie-booking' ) : __( 'No guests yet', 'marclie-booking' ) }
							description={
								filtered
									? undefined
									: __( 'Guests are created automatically with their first booking.', 'marclie-booking' )
							}
						/>
					}
				/>
			) }
		</>
	);
}
