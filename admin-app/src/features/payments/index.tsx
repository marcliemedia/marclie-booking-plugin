import { ArrowCounterClockwise, CalendarDots, CurrencyEur, DownloadSimple, Receipt, Scales } from '@phosphor-icons/react';
import { useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import type { PaymentListParams } from '@/api/hooks/payments';
import { fetchAllPayments, usePayments, useScheduledPayments } from '@/api/hooks/payments';
import type { Payment, PaymentKind, PaymentMethod, PaymentStatus } from '@/api/types';
import { setQuery, useRoute } from '@/app/router';
import type { ColumnDef } from '@/components/app/DataTable';
import { DataTable } from '@/components/app/DataTable';
import { DateRangePicker } from '@/components/app/DateRangePicker';
import { EmptyState } from '@/components/app/EmptyState';
import { ErrorState } from '@/components/app/ErrorState';
import { KpiCard } from '@/components/app/KpiCard';
import { Money } from '@/components/app/Money';
import { PageHeader } from '@/components/app/PageHeader';
import { PaymentStatusBadge, paymentStatusLabel, StatusBadge } from '@/components/app/StatusBadge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { toast } from '@/components/ui/sonner';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useBookingSheet } from '@/features/bookings/sheet';
import { toastError } from '@/features/common/toastError';
import { downloadCsv, toCsv } from '@/lib/csv';
import { formatDate, formatDateRange, formatDateTime, formatNumber } from '@/lib/format';

import { PAYMENT_KINDS, PAYMENT_METHODS, paymentKindLabel, paymentMethodLabel } from './labels';

const PER_PAGE = 25;
const STATUSES: PaymentStatus[] = [ 'succeeded', 'pending', 'scheduled', 'failed', 'refunded' ];

export default function PaymentsPage( { params }: { params: Record< string, string > } ) {
	void params;
	const { query } = useRoute();
	const tab = query.get( 'tab' ) === 'scheduled' ? 'scheduled' : 'all';

	return (
		<>
			<PageHeader
				title={ __( 'Payments', 'marclie-booking' ) }
				description={ __( 'Every deposit, balance, full payment and refund.', 'marclie-booking' ) }
			>
				<Tabs value={ tab } onValueChange={ ( v ) => setQuery( { tab: v === 'all' ? null : v, page: null } ) }>
					<TabsList>
						<TabsTrigger value="all">{ __( 'All transactions', 'marclie-booking' ) }</TabsTrigger>
						<TabsTrigger value="scheduled">{ __( 'Scheduled', 'marclie-booking' ) }</TabsTrigger>
					</TabsList>
				</Tabs>
			</PageHeader>
			{ tab === 'all' ? <AllPayments query={ query } /> : <ScheduledPayments /> }
		</>
	);
}

function AllPayments( { query }: { query: URLSearchParams } ) {
	const sheet = useBookingSheet();
	const [ exporting, setExporting ] = useState( false );
	const page = Math.max( 1, Number( query.get( 'page' ) ) || 1 );
	const filters: PaymentListParams = {
		status: ( query.get( 'status' ) ?? '' ) as PaymentListParams[ 'status' ],
		kind: ( query.get( 'kind' ) ?? '' ) as PaymentListParams[ 'kind' ],
		method: ( query.get( 'method' ) ?? '' ) as PaymentListParams[ 'method' ],
		from: query.get( 'from' ) ?? undefined,
		to: query.get( 'to' ) ?? undefined,
	};
	const payments = usePayments( { ...filters, page, per_page: PER_PAGE, orderby: 'created_at', order: 'desc' } );
	const filtered = !! ( filters.status || filters.kind || filters.method || filters.from || filters.to );

	const columns = useMemo< ColumnDef< Payment >[] >(
		() => [
			{
				id: 'created_at',
				header: __( 'Date', 'marclie-booking' ),
				meta: { label: __( 'Date', 'marclie-booking' ), className: 'whitespace-nowrap' },
				cell: ( { row } ) => formatDateTime( row.original.created_at ),
			},
			{
				id: 'booking',
				header: __( 'Booking', 'marclie-booking' ),
				meta: { label: __( 'Booking', 'marclie-booking' ) },
				cell: ( { row } ) => (
					<div className="min-w-0">
						<p className="font-mono text-xs font-medium text-ink">{ row.original.booking_ref }</p>
						<p className="truncate text-xs text-muted-foreground">{ row.original.guest_name }</p>
					</div>
				),
			},
			{
				id: 'kind',
				header: __( 'Type', 'marclie-booking' ),
				meta: { label: __( 'Type', 'marclie-booking' ) },
				cell: ( { row } ) => paymentKindLabel( row.original.kind ),
			},
			{
				id: 'method',
				header: __( 'Method', 'marclie-booking' ),
				meta: { label: __( 'Method', 'marclie-booking' ), hideable: true },
				cell: ( { row } ) => (
					<span>
						{ paymentMethodLabel( row.original.method ) }
						{ row.original.card_last4 && (
							<span className="block text-xs text-muted-foreground">
								{ row.original.card_brand ?? '' } •••• { row.original.card_last4 }
							</span>
						) }
						{ row.original.note && <span className="block max-w-[220px] truncate text-xs text-muted-foreground">{ row.original.note }</span> }
					</span>
				),
			},
			{
				id: 'status',
				header: __( 'Status', 'marclie-booking' ),
				meta: { label: __( 'Status', 'marclie-booking' ) },
				cell: ( { row } ) => <PaymentStatusBadge status={ row.original.status } />,
			},
			{
				id: 'amount',
				header: __( 'Amount', 'marclie-booking' ),
				meta: { label: __( 'Amount', 'marclie-booking' ), align: 'right' },
				cell: ( { row } ) =>
					row.original.kind === 'refund' ? (
						<Money cents={ -row.original.amount_cents } signed />
					) : (
						<Money cents={ row.original.amount_cents } className="font-medium" />
					),
			},
		],
		[]
	);

	const exportCsv = async () => {
		setExporting( true );
		try {
			const rows = await fetchAllPayments( filters );
			const header = [
				__( 'ID', 'marclie-booking' ),
				__( 'Date', 'marclie-booking' ),
				__( 'Booking', 'marclie-booking' ),
				__( 'Guest', 'marclie-booking' ),
				__( 'Type', 'marclie-booking' ),
				__( 'Method', 'marclie-booking' ),
				__( 'Status', 'marclie-booking' ),
				__( 'Amount (EUR)', 'marclie-booking' ),
				__( 'Card', 'marclie-booking' ),
				__( 'Stripe payment', 'marclie-booking' ),
				__( 'Note', 'marclie-booking' ),
			];
			const lines = rows.map( ( p ) => [
				p.id,
				p.created_at,
				p.booking_ref,
				p.guest_name,
				paymentKindLabel( p.kind ),
				paymentMethodLabel( p.method ),
				paymentStatusLabel( p.status ),
				( p.kind === 'refund' ? -p.amount_cents : p.amount_cents ) / 100,
				p.card_last4 ? `${ p.card_brand ?? '' } ${ p.card_last4 }`.trim() : '',
				p.stripe_intent_id,
				p.note,
			] );
			downloadCsv( 'payments', toCsv( [ header, ...lines ] ) );
			toast.success(
				sprintf(
					/* translators: %d: number of rows. */
					_n( 'Exported %d payment.', 'Exported %d payments.', rows.length, 'marclie-booking' ),
					rows.length
				)
			);
		} catch ( e ) {
			toastError( e );
		} finally {
			setExporting( false );
		}
	};

	const totals = payments.data?.totals;

	return (
		<div className="flex flex-col gap-4">
			<div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
				<KpiCard
					label={ __( 'Received', 'marclie-booking' ) }
					value={ <Money cents={ totals?.succeeded_cents ?? 0 } /> }
					icon={ CurrencyEur }
					loading={ payments.isPending }
					hint={ filtered ? __( 'For the current filters', 'marclie-booking' ) : __( 'All time', 'marclie-booking' ) }
				/>
				<KpiCard
					label={ __( 'Refunded', 'marclie-booking' ) }
					value={ <Money cents={ totals?.refunded_cents ?? 0 } /> }
					icon={ ArrowCounterClockwise }
					loading={ payments.isPending }
				/>
				<KpiCard
					label={ __( 'Net', 'marclie-booking' ) }
					value={ <Money cents={ totals?.net_cents ?? 0 } /> }
					icon={ Scales }
					loading={ payments.isPending }
					className="col-span-2 sm:col-span-1"
				/>
			</div>

			<div className="flex flex-wrap items-center gap-2">
				<Select value={ filters.status || 'all' } onValueChange={ ( v ) => setQuery( { status: v === 'all' ? null : v, page: null } ) }>
					<SelectTrigger className="w-[150px]" aria-label={ __( 'Status', 'marclie-booking' ) }>
						<SelectValue />
					</SelectTrigger>
					<SelectContent>
						<SelectItem value="all">{ __( 'All statuses', 'marclie-booking' ) }</SelectItem>
						{ STATUSES.map( ( s ) => (
							<SelectItem key={ s } value={ s }>
								{ paymentStatusLabel( s ) }
							</SelectItem>
						) ) }
					</SelectContent>
				</Select>
				<Select value={ filters.kind || 'all' } onValueChange={ ( v ) => setQuery( { kind: v === 'all' ? null : v, page: null } ) }>
					<SelectTrigger className="w-[150px]" aria-label={ __( 'Type', 'marclie-booking' ) }>
						<SelectValue />
					</SelectTrigger>
					<SelectContent>
						<SelectItem value="all">{ __( 'All types', 'marclie-booking' ) }</SelectItem>
						{ PAYMENT_KINDS.map( ( k: PaymentKind ) => (
							<SelectItem key={ k } value={ k }>
								{ paymentKindLabel( k ) }
							</SelectItem>
						) ) }
					</SelectContent>
				</Select>
				<Select value={ filters.method || 'all' } onValueChange={ ( v ) => setQuery( { method: v === 'all' ? null : v, page: null } ) }>
					<SelectTrigger className="w-[160px]" aria-label={ __( 'Method', 'marclie-booking' ) }>
						<SelectValue />
					</SelectTrigger>
					<SelectContent>
						<SelectItem value="all">{ __( 'All methods', 'marclie-booking' ) }</SelectItem>
						{ PAYMENT_METHODS.map( ( m: PaymentMethod ) => (
							<SelectItem key={ m } value={ m }>
								{ paymentMethodLabel( m ) }
							</SelectItem>
						) ) }
					</SelectContent>
				</Select>
				<DateRangePicker
					value={ { from: filters.from, to: filters.to } }
					onChange={ ( r ) => setQuery( { from: r.from ?? null, to: r.to ?? null, page: null } ) }
					placeholder={ __( 'Any date', 'marclie-booking' ) }
					clearable
					className="w-full sm:w-[260px]"
				/>
				{ filtered && (
					<Button
						variant="ghost"
						size="sm"
						onClick={ () => setQuery( { status: null, kind: null, method: null, from: null, to: null, page: null } ) }
					>
						{ __( 'Clear filters', 'marclie-booking' ) }
					</Button>
				) }
				<Button variant="outline" className="sm:ml-auto" onClick={ exportCsv } disabled={ exporting || ! payments.data?.total }>
					<DownloadSimple className="h-4 w-4" aria-hidden="true" />
					{ exporting ? __( 'Exporting…', 'marclie-booking' ) : __( 'Export CSV', 'marclie-booking' ) }
				</Button>
			</div>

			{ payments.isError ? (
				<ErrorState error={ payments.error } onRetry={ () => payments.refetch() } />
			) : (
				<DataTable
					label={ __( 'Payments', 'marclie-booking' ) }
					columns={ columns }
					data={ payments.data?.items ?? [] }
					total={ payments.data?.total ?? 0 }
					page={ page }
					perPage={ PER_PAGE }
					onPageChange={ ( p ) => setQuery( { page: p > 1 ? p : null } ) }
					loading={ payments.isPending }
					fetching={ payments.isPlaceholderData }
					getRowId={ ( p ) => String( p.id ) }
					onRowClick={ ( p ) => sheet.open( p.booking_id ) }
					empty={
						<EmptyState
							compact
							icon={ Receipt }
							title={ filtered ? __( 'No payments match these filters', 'marclie-booking' ) : __( 'No payments yet', 'marclie-booking' ) }
						/>
					}
				/>
			) }
		</div>
	);
}

function ScheduledPayments() {
	const sheet = useBookingSheet();
	const scheduled = useScheduledPayments( 30 );

	if ( scheduled.isPending ) {
		return <Skeleton className="h-64 w-full" />;
	}
	if ( scheduled.isError ) {
		return <ErrorState error={ scheduled.error } onRetry={ () => scheduled.refetch() } />;
	}
	const items = scheduled.data.items;
	const total = items.reduce( ( sum, i ) => sum + i.amount_cents, 0 );

	return (
		<div className="flex flex-col gap-4">
			<div className="grid grid-cols-2 gap-3">
				<KpiCard label={ __( 'Due in the next 30 days', 'marclie-booking' ) } value={ <Money cents={ total } /> } icon={ CalendarDots } />
				<KpiCard label={ __( 'Bookings', 'marclie-booking' ) } value={ formatNumber( items.length ) } icon={ Receipt } />
			</div>
			<Card className="overflow-hidden p-0">
				{ items.length === 0 ? (
					<EmptyState
						compact
						icon={ CalendarDots }
						title={ __( 'Nothing scheduled', 'marclie-booking' ) }
						description={ __( 'No balances fall due in the next 30 days.', 'marclie-booking' ) }
					/>
				) : (
					<div className="relative overflow-x-auto">
						<table className="w-full min-w-[640px] text-sm">
							<caption className="sr-only">{ __( 'Balances due in the next 30 days', 'marclie-booking' ) }</caption>
							<thead>
								<tr className="border-b border-border bg-surface-muted/60 text-left text-xs text-muted-foreground">
									<th scope="col" className="px-4 py-2.5 font-medium">{ __( 'Due on', 'marclie-booking' ) }</th>
									<th scope="col" className="px-4 py-2.5 font-medium">{ __( 'Booking', 'marclie-booking' ) }</th>
									<th scope="col" className="px-4 py-2.5 font-medium">{ __( 'Stay', 'marclie-booking' ) }</th>
									<th scope="col" className="px-4 py-2.5 font-medium">{ __( 'Status', 'marclie-booking' ) }</th>
									<th scope="col" className="px-4 py-2.5 text-right font-medium">{ __( 'Amount', 'marclie-booking' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ items.map( ( i ) => (
									<tr
										key={ i.booking.id }
										className="cursor-pointer border-b border-border last:border-0 hover:bg-surface-muted/50"
										onClick={ () => sheet.open( i.booking.id ) }
									>
										<td className="whitespace-nowrap px-4 py-2.5 font-medium">{ formatDate( i.due_on ) }</td>
										<td className="px-4 py-2.5">
											<button
												type="button"
												className="font-mono text-xs font-medium text-ink hover:underline focus-visible:underline focus-visible:outline-none"
												onClick={ ( e ) => {
													e.stopPropagation();
													sheet.open( i.booking.id );
												} }
											>
												{ i.booking.ref }
											</button>
											<span className="block text-xs text-muted-foreground">{ i.booking.guest.name }</span>
										</td>
										<td className="px-4 py-2.5">
											{ i.booking.unit_title }
											<span className="block text-xs text-muted-foreground">{ formatDateRange( i.booking.arrival, i.booking.departure ) }</span>
										</td>
										<td className="px-4 py-2.5">
											<StatusBadge status={ i.booking.status } />
										</td>
										<td className="px-4 py-2.5 text-right font-medium">
											<Money cents={ i.amount_cents } />
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				) }
			</Card>
			<p className="text-xs text-muted-foreground">
				{ __( 'Balances are charged automatically on the due date once card payments are enabled.', 'marclie-booking' ) }
			</p>
		</div>
	);
}
