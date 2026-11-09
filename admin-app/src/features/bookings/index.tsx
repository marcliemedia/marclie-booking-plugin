import { DownloadSimple, FunnelSimple, ListBullets, MagnifyingGlass, Plus, X } from '@phosphor-icons/react';
import { __, sprintf } from '@wordpress/i18n';
import * as React from 'react';

import { setQuery, useRoute } from '@/app/router';
import type { PageProps } from '@/app/routes';
import { useBootstrapData } from '@/api/hooks/bootstrap';
import { useBookings } from '@/api/hooks/bookings';
import type { BookingListQuery, BookingSource, BookingStatus, BookingSummary, PaymentState } from '@/api/types';
import { ColumnVisibilityMenu, DataTable, type ColumnDef, type SortingState, type VisibilityState } from '@/components/app/DataTable';
import { DateRangePicker } from '@/components/app/DateRangePicker';
import { EmptyState } from '@/components/app/EmptyState';
import { ErrorState } from '@/components/app/ErrorState';
import { Money } from '@/components/app/Money';
import { PageHeader } from '@/components/app/PageHeader';
import { SourceBadge, sourceLabel, StatusBadge, statusLabel } from '@/components/app/StatusBadge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectGroup, SelectItem, SelectLabel, SelectTrigger, SelectValue } from '@/components/ui/select';
import { toast } from '@/components/ui/sonner';
import { useBookingSheet } from '@/features/bookings/sheet';
import { useBookingWizard } from '@/features/bookings/wizard';
import { exportBookingsCsv } from '@/features/bookings/csv';
import { formatDate, formatDateTime, formatNumber, guestsLabel, nightsLabel } from '@/lib/format';
import { groupByHouse } from '@/lib/units';
import { cn } from '@/lib/utils';
import { useDebounced } from '@/lib/useDebounced';
import { errorMessage } from '@/api/client';

const PER_PAGE = 25;
const VISIBILITY_KEY = 'mbk-bookings-columns';
const ALL = '__all';

type TabKey = 'all' | 'upcoming' | BookingStatus;

function tabs(): { key: TabKey; label: string }[] {
	return [
		{ key: 'all', label: __( 'All', 'marclie-booking' ) },
		{ key: 'upcoming', label: __( 'Upcoming', 'marclie-booking' ) },
		{ key: 'pending_payment', label: statusLabel( 'pending_payment' ) },
		{ key: 'confirmed', label: statusLabel( 'confirmed' ) },
		{ key: 'paid', label: statusLabel( 'paid' ) },
		{ key: 'balance_failed', label: statusLabel( 'balance_failed' ) },
		{ key: 'completed', label: statusLabel( 'completed' ) },
		{ key: 'cancelled', label: statusLabel( 'cancelled' ) },
		{ key: 'expired', label: statusLabel( 'expired' ) },
	];
}

export function paymentStateLabel( state: PaymentState ): string {
	switch ( state ) {
		case 'unpaid':
			return __( 'Unpaid', 'marclie-booking' );
		case 'deposit':
			return __( 'Deposit paid', 'marclie-booking' );
		case 'paid':
			return __( 'Fully paid', 'marclie-booking' );
		case 'failed':
			return __( 'Payment failed', 'marclie-booking' );
	}
}

/** Arrow/Home/End navigation for a `role="tablist"` whose tabs carry `data-tab`. */
function moveTabFocus( event: React.KeyboardEvent< HTMLElement >, activate: ( key: TabKey ) => void ): void {
	const tabsEls = [ ...event.currentTarget.querySelectorAll< HTMLElement >( '[role="tab"]' ) ];
	const index = tabsEls.indexOf( event.target as HTMLElement );
	if ( index === -1 ) {
		return;
	}
	const target =
		event.key === 'ArrowRight' ? ( index + 1 ) % tabsEls.length
		: event.key === 'ArrowLeft' ? ( index - 1 + tabsEls.length ) % tabsEls.length
		: event.key === 'Home' ? 0
		: event.key === 'End' ? tabsEls.length - 1
		: -1;
	if ( target === -1 ) {
		return;
	}
	event.preventDefault();
	tabsEls[ target ].focus();
	activate( tabsEls[ target ].dataset.tab as TabKey );
}

function readQuery( query: URLSearchParams ): BookingListQuery {
	const order = query.get( 'order' );
	return {
		status: ( query.get( 'status' ) as BookingListQuery[ 'status' ] ) || '',
		unit_id: query.get( 'unit' ) ? Number( query.get( 'unit' ) ) : '',
		source: ( query.get( 'source' ) as BookingSource ) || '',
		payment_state: ( query.get( 'payment' ) as PaymentState ) || '',
		search: query.get( 'q' ) ?? '',
		date_field: ( query.get( 'field' ) as BookingListQuery[ 'date_field' ] ) || 'arrival',
		from: query.get( 'from' ) ?? undefined,
		to: query.get( 'to' ) ?? undefined,
		page: Number( query.get( 'page' ) ) || 1,
		per_page: PER_PAGE,
		orderby: query.get( 'orderby' ) || 'arrival',
		order: order === 'asc' || order === 'desc' ? order : 'desc',
	};
}

function readVisibility(): VisibilityState {
	const fallback: VisibilityState = { created_at: false, source: false };
	try {
		const stored = window.localStorage.getItem( VISIBILITY_KEY );
		return stored ? ( JSON.parse( stored ) as VisibilityState ) : fallback;
	} catch {
		return fallback;
	}
}

export default function BookingsPage( _props: PageProps ) {
	const route = useRoute();
	const { units } = useBootstrapData();
	const sheet = useBookingSheet();
	const wizard = useBookingWizard();
	const filters = readQuery( route.query );
	const [ search, setSearch ] = React.useState( filters.search ?? '' );
	const debouncedSearch = useDebounced( search, 300 );
	const [ visibility, setVisibility ] = React.useState< VisibilityState >( readVisibility );
	const [ exporting, setExporting ] = React.useState( false );

	React.useEffect( () => {
		if ( debouncedSearch !== ( filters.search ?? '' ) ) {
			setQuery( { q: debouncedSearch, page: null } );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ debouncedSearch ] );

	const query = filters;
	const bookings = useBookings( query );
	const counts = bookings.data?.counts;

	const updateVisibility = ( next: VisibilityState ) => {
		setVisibility( next );
		window.localStorage.setItem( VISIBILITY_KEY, JSON.stringify( next ) );
	};

	const sorting: SortingState = [ { id: filters.orderby ?? 'arrival', desc: filters.order === 'desc' } ];

	const columns = React.useMemo< ColumnDef< BookingSummary >[] >(
		() => [
			{
				id: 'ref',
				header: __( 'Ref', 'marclie-booking' ),
				cell: ( { row } ) => <span className="whitespace-nowrap font-mono text-xs text-ink-soft">{ row.original.ref }</span>,
				meta: { label: __( 'Ref', 'marclie-booking' ), className: 'w-[1%]' },
			},
			{
				id: 'guest',
				header: __( 'Guest', 'marclie-booking' ),
				cell: ( { row } ) => (
					<div className="min-w-[140px] leading-tight">
						<div className="font-medium text-ink">{ row.original.guest.name }</div>
						<div className="truncate text-xs text-muted-foreground">{ row.original.guest.email }</div>
					</div>
				),
				meta: { label: __( 'Guest', 'marclie-booking' ) },
			},
			{
				id: 'unit',
				header: __( 'Apartment', 'marclie-booking' ),
				cell: ( { row } ) => <span className="whitespace-nowrap">{ row.original.unit_title }</span>,
				meta: { label: __( 'Apartment', 'marclie-booking' ), hideable: true },
			},
			{
				id: 'arrival',
				header: __( 'Dates', 'marclie-booking' ),
				enableSorting: true,
				cell: ( { row } ) => (
					<div className="whitespace-nowrap leading-tight tabular">
						<div>
							{ formatDate( row.original.arrival, 'day' ) } – { formatDate( row.original.departure, 'medium' ) }
						</div>
						<div className="text-xs text-muted-foreground">
							{ nightsLabel( row.original.nights ) } · { guestsLabel( row.original.adults, row.original.children, row.original.infants ) }
						</div>
					</div>
				),
				meta: { label: __( 'Dates', 'marclie-booking' ) },
			},
			{
				id: 'status',
				header: __( 'Status', 'marclie-booking' ),
				cell: ( { row } ) => <StatusBadge status={ row.original.status } />,
				meta: { label: __( 'Status', 'marclie-booking' ) },
			},
			{
				id: 'source',
				header: __( 'Source', 'marclie-booking' ),
				cell: ( { row } ) => <SourceBadge source={ row.original.source } />,
				meta: { label: __( 'Source', 'marclie-booking' ), hideable: true },
			},
			{
				id: 'total',
				header: __( 'Total', 'marclie-booking' ),
				enableSorting: true,
				cell: ( { row } ) => <Money cents={ row.original.total_cents } className="font-medium" />,
				meta: { label: __( 'Total', 'marclie-booking' ), align: 'right' },
			},
			{
				id: 'paid',
				header: __( 'Paid', 'marclie-booking' ),
				cell: ( { row } ) => <Money cents={ row.original.paid_cents } muted />,
				meta: { label: __( 'Paid', 'marclie-booking' ), align: 'right', hideable: true },
			},
			{
				id: 'balance',
				header: __( 'Balance', 'marclie-booking' ),
				cell: ( { row } ) => (
					<div className="leading-tight">
						<Money
							cents={ row.original.balance_cents }
							muted
							className={ cn( row.original.status === 'balance_failed' && row.original.balance_cents > 0 && 'font-medium text-danger' ) }
						/>
						{ row.original.balance_cents > 0 && row.original.balance_due_on && (
							<div className="text-xs text-muted-foreground">
								{ sprintf(
									/* translators: %s: due date. */
									__( 'due %s', 'marclie-booking' ),
									formatDate( row.original.balance_due_on, 'day' )
								) }
							</div>
						) }
					</div>
				),
				meta: { label: __( 'Balance', 'marclie-booking' ), align: 'right', hideable: true },
			},
			{
				id: 'created_at',
				header: __( 'Created', 'marclie-booking' ),
				enableSorting: true,
				cell: ( { row } ) => (
					<span className="whitespace-nowrap text-xs text-muted-foreground">{ formatDateTime( row.original.created_at ) }</span>
				),
				meta: { label: __( 'Created', 'marclie-booking' ), hideable: true },
			},
		],
		[]
	);

	const hasFilters = Boolean(
		filters.unit_id || filters.source || filters.payment_state || filters.search || filters.from || filters.to
	);

	const handleExport = async () => {
		setExporting( true );
		try {
			const count = await exportBookingsCsv( query );
			toast.success(
				sprintf(
					/* translators: %s: number of bookings. */
					__( 'Exported %s bookings.', 'marclie-booking' ),
					formatNumber( count )
				)
			);
		} catch ( error ) {
			toast.error( errorMessage( error ) );
		} finally {
			setExporting( false );
		}
	};

	const activeTab: TabKey = ( filters.status || 'all' ) as TabKey;

	return (
		<>
			<PageHeader
				title={ __( 'Bookings', 'marclie-booking' ) }
				actions={
					<>
						<Button variant="secondary" onClick={ handleExport } disabled={ exporting || ! bookings.data?.total }>
							<DownloadSimple className="h-4 w-4" />
							{ exporting ? __( 'Exporting…', 'marclie-booking' ) : __( 'Export CSV', 'marclie-booking' ) }
						</Button>
						<Button onClick={ () => wizard.open() }>
							<Plus className="h-4 w-4" weight="bold" />
							{ __( 'New booking', 'marclie-booking' ) }
						</Button>
					</>
				}
			>
				<div
					role="tablist"
					aria-label={ __( 'Booking status', 'marclie-booking' ) }
					className="-mb-1 flex gap-1 overflow-x-auto border-b border-border"
					onKeyDown={ ( event ) => moveTabFocus( event, ( key ) => setQuery( { status: key === 'all' ? null : key, page: null } ) ) }
				>
					{ tabs().map( ( tab ) => {
						const active = activeTab === tab.key;
						const count = counts?.[ tab.key ];
						return (
							<button
								key={ tab.key }
								type="button"
								role="tab"
								data-tab={ tab.key }
								aria-selected={ active }
								tabIndex={ active ? 0 : -1 }
								onClick={ () => setQuery( { status: tab.key === 'all' ? null : tab.key, page: null } ) }
								className={ cn(
									'-mb-px flex h-10 shrink-0 items-center gap-1.5 border-b-2 px-2.5 text-[13px] font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
									active ? 'border-ink-soft text-ink' : 'border-transparent text-muted-foreground hover:text-ink-soft'
								) }
							>
								{ tab.label }
								{ count !== undefined && (
									<span
										className={ cn(
											'rounded-full px-1.5 text-2xs tabular',
											active ? 'bg-ink-soft text-white' : 'bg-surface-muted text-muted-foreground',
											tab.key === 'balance_failed' && count > 0 && ! active && 'bg-danger-soft text-danger'
										) }
									>
										{ formatNumber( count ) }
									</span>
								) }
							</button>
						);
					} ) }
				</div>
			</PageHeader>

			<div className="mb-4 flex flex-wrap items-center gap-2">
				<div className="relative w-full sm:w-64">
					<MagnifyingGlass className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
					<Input
						type="search"
						value={ search }
						onChange={ ( event ) => setSearch( event.target.value ) }
						placeholder={ __( 'Ref, name, email, phone…', 'marclie-booking' ) }
						aria-label={ __( 'Search bookings', 'marclie-booking' ) }
						className="pl-9"
					/>
				</div>
				<Select
					value={ filters.unit_id ? String( filters.unit_id ) : ALL }
					onValueChange={ ( value ) => setQuery( { unit: value === ALL ? null : value, page: null } ) }
				>
					<SelectTrigger className="w-auto min-w-[150px]" aria-label={ __( 'Apartment', 'marclie-booking' ) }>
						<SelectValue />
					</SelectTrigger>
					<SelectContent>
						<SelectItem value={ ALL }>{ __( 'All apartments', 'marclie-booking' ) }</SelectItem>
						{ groupByHouse( units ).map( ( { house, units: houseUnits } ) => (
							<SelectGroup key={ house }>
								<SelectLabel>{ houseUnits[ 0 ].house }</SelectLabel>
								{ houseUnits.map( ( unit ) => (
									<SelectItem key={ unit.id } value={ String( unit.id ) }>
										{ unit.title }
									</SelectItem>
								) ) }
							</SelectGroup>
						) ) }
					</SelectContent>
				</Select>
				<div className="flex items-center">
					<Select
						value={ filters.date_field ?? 'arrival' }
						onValueChange={ ( value ) => setQuery( { field: value === 'arrival' ? null : value, page: null } ) }
					>
						<SelectTrigger className="w-auto rounded-r-none border-r-0" aria-label={ __( 'Date field', 'marclie-booking' ) }>
							<SelectValue />
						</SelectTrigger>
						<SelectContent>
							<SelectItem value="arrival">{ __( 'Arrival', 'marclie-booking' ) }</SelectItem>
							<SelectItem value="departure">{ __( 'Departure', 'marclie-booking' ) }</SelectItem>
							<SelectItem value="created">{ __( 'Created', 'marclie-booking' ) }</SelectItem>
						</SelectContent>
					</Select>
					<DateRangePicker
						className="[&_button]:rounded-l-none"
						value={ { from: filters.from, to: filters.to } }
						onChange={ ( range ) => setQuery( { from: range.from ?? null, to: range.to ?? null, page: null } ) }
						allowSameDay
						placeholder={ __( 'Any date', 'marclie-booking' ) }
						clearable
					/>
				</div>
				<Select
					value={ filters.source || ALL }
					onValueChange={ ( value ) => setQuery( { source: value === ALL ? null : value, page: null } ) }
				>
					<SelectTrigger className="w-auto min-w-[130px]" aria-label={ __( 'Source', 'marclie-booking' ) }>
						<SelectValue />
					</SelectTrigger>
					<SelectContent>
						<SelectItem value={ ALL }>{ __( 'All sources', 'marclie-booking' ) }</SelectItem>
						{ ( [ 'direct', 'admin', 'airbnb', 'booking' ] as BookingSource[] ).map( ( source ) => (
							<SelectItem key={ source } value={ source }>
								{ sourceLabel( source ) }
							</SelectItem>
						) ) }
					</SelectContent>
				</Select>
				<Select
					value={ filters.payment_state || ALL }
					onValueChange={ ( value ) => setQuery( { payment: value === ALL ? null : value, page: null } ) }
				>
					<SelectTrigger className="w-auto min-w-[140px]" aria-label={ __( 'Payment state', 'marclie-booking' ) }>
						<SelectValue />
					</SelectTrigger>
					<SelectContent>
						<SelectItem value={ ALL }>{ __( 'Any payment', 'marclie-booking' ) }</SelectItem>
						{ ( [ 'unpaid', 'deposit', 'paid', 'failed' ] as PaymentState[] ).map( ( state ) => (
							<SelectItem key={ state } value={ state }>
								{ paymentStateLabel( state ) }
							</SelectItem>
						) ) }
					</SelectContent>
				</Select>
				{ hasFilters && (
					<Button
						variant="ghost"
						onClick={ () => {
							setSearch( '' );
							setQuery( { unit: null, source: null, payment: null, q: null, from: null, to: null, field: null, page: null } );
						} }
					>
						<X className="h-4 w-4" />
						{ __( 'Reset', 'marclie-booking' ) }
					</Button>
				) }
				<div className="ml-auto">
					<ColumnVisibilityMenu columns={ columns } value={ visibility } onChange={ updateVisibility } />
				</div>
			</div>

			{ bookings.isError && ! bookings.data ? (
				<ErrorState error={ bookings.error } onRetry={ () => bookings.refetch() } />
			) : (
				<DataTable
					label={ __( 'Bookings', 'marclie-booking' ) }
					columns={ columns }
					data={ bookings.data?.items ?? [] }
					total={ bookings.data?.total ?? 0 }
					page={ filters.page ?? 1 }
					perPage={ PER_PAGE }
					onPageChange={ ( page ) => setQuery( { page: page > 1 ? page : null } ) }
					sorting={ sorting }
					onSortingChange={ ( next ) => {
						const first = next[ 0 ];
						setQuery( { orderby: first?.id ?? null, order: first ? ( first.desc ? 'desc' : 'asc' ) : null, page: null } );
					} }
					columnVisibility={ visibility }
					onColumnVisibilityChange={ updateVisibility }
					loading={ bookings.isPending }
					fetching={ bookings.isPlaceholderData }
					getRowId={ ( row ) => String( row.id ) }
					onRowClick={ ( row ) => sheet.open( row.id ) }
					empty={
						hasFilters || activeTab !== 'all' ? (
							<EmptyState
								compact
								icon={ FunnelSimple }
								title={ __( 'No bookings match these filters', 'marclie-booking' ) }
								action={
									<Button
										variant="secondary"
										onClick={ () => {
											setSearch( '' );
											setQuery( { status: null, unit: null, source: null, payment: null, q: null, from: null, to: null, page: null } );
										} }
									>
										{ __( 'Clear filters', 'marclie-booking' ) }
									</Button>
								}
							/>
						) : (
							<EmptyState
								icon={ ListBullets }
								title={ __( 'No bookings yet', 'marclie-booking' ) }
								description={ __( 'Bookings from the website and the ones you add manually appear here.', 'marclie-booking' ) }
								action={
									<Button onClick={ () => wizard.open() }>
										<Plus className="h-4 w-4" weight="bold" />
										{ __( 'New booking', 'marclie-booking' ) }
									</Button>
								}
							/>
						)
					}
				/>
			) }
		</>
	);
}
