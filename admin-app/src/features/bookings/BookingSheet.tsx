import {
	ArrowSquareOut,
	ArrowsLeftRight,
	CalendarX,
	CaretDown,
	ChatText,
	ClockCounterClockwise,
	CreditCard,
	Envelope,
	Link as LinkIcon,
	Phone,
	PencilSimple,
	Prohibit,
	Receipt,
} from '@phosphor-icons/react';
import { __, _n, sprintf } from '@wordpress/i18n';
import * as React from 'react';

import { navigate } from '@/app/router';
import { errorMessage } from '@/api/client';
import { useBootstrapData } from '@/api/hooks/bootstrap';
import { useBooking, useUpdateBooking } from '@/api/hooks/bookings';
import type { BookingDetail } from '@/api/types';
import { DetailRow } from '@/components/app/PageHeader';
import { ErrorState } from '@/components/app/ErrorState';
import { Money } from '@/components/app/Money';
import { PaymentStatusBadge, SourceBadge, StatusBadge } from '@/components/app/StatusBadge';
import { Button } from '@/components/ui/button';
import {
	DropdownMenu,
	DropdownMenuContent,
	DropdownMenuItem,
	DropdownMenuSeparator,
	DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Sheet, SheetBody, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { toast } from '@/components/ui/sonner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { SimpleTooltip } from '@/components/ui/tooltip';
import {
	BlockDialog,
	CancelDialog,
	ChargeDialog,
	EditGuestDialog,
	PaymentLinkDialog,
	RecordPaymentDialog,
} from '@/features/bookings/dialogs';
import { paymentKindLabel, paymentMethodLabel } from '@/features/payments/labels';
import { BookingEmails } from '@/features/bookings/BookingEmails';
import { MoveDialog } from '@/features/bookings/MoveDialog';
import { quoteLineLabel } from '@/features/bookings/quoteLines';
import { AccountFacts } from '@/features/guests/GuestAccountCard';
import { formatDate, formatDateTime, formatPercent, formatRelative, guestsLabel } from '@/lib/format';
import { applyFieldErrors } from '@/lib/formErrors';
import { cn } from '@/lib/utils';

type DialogKey = 'edit' | 'payment' | 'charge' | 'link' | 'move' | 'cancel' | 'block' | null;

const CLOSED: BookingDetail[ 'status' ][] = [ 'cancelled', 'expired', 'completed' ];

function Section( { title, children, action }: { title: string; children: React.ReactNode; action?: React.ReactNode } ) {
	return (
		<section className="mb-6">
			<div className="mb-2 flex items-center justify-between">
				<h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{ title }</h3>
				{ action }
			</div>
			{ children }
		</section>
	);
}

function OverviewTab( { booking }: { booking: BookingDetail } ) {
	const guest = booking.guest_full;
	const policy = booking.policy as Partial< Record< string, number | string > >;
	return (
		<>
			<Section title={ __( 'Guest', 'marclie-booking' ) }>
				<div className="rounded-lg border border-border p-4">
					<div className="flex items-start justify-between gap-3">
						<div>
							{ booking.guest.id ? (
								<button
									type="button"
									className="text-left font-semibold text-ink hover:underline"
									onClick={ () => navigate( `/guests/${ booking.guest.id }` ) }
								>
									{ booking.guest.name }
								</button>
							) : (
								<div className="font-semibold text-ink">{ booking.guest.name }</div>
							) }
							<div className="mt-1 flex flex-col gap-0.5 text-sm text-muted-foreground">
								<a className="inline-flex items-center gap-1.5 hover:text-ink-soft" href={ `mailto:${ booking.guest.email }` }>
									<Envelope className="h-3.5 w-3.5" />
									{ booking.guest.email }
								</a>
								{ booking.phone && (
									<a className="inline-flex items-center gap-1.5 hover:text-ink-soft" href={ `tel:${ booking.phone }` }>
										<Phone className="h-3.5 w-3.5" />
										{ booking.phone }
									</a>
								) }
							</div>
						</div>
						<div className="text-right text-xs text-muted-foreground">
							{ booking.guest.country && <div>{ booking.guest.country }</div> }
							<div className="uppercase">{ booking.lang }</div>
							{ guest && guest.bookings_count > 1 && (
								<div className="mt-1 font-medium text-success">
									{ sprintf(
										/* translators: %d: number of bookings. */
										_n( 'Returning guest · %d stay', 'Returning guest · %d stays', guest.bookings_count, 'marclie-booking' ),
										guest.bookings_count
									) }
								</div>
							) }
						</div>
					</div>
					{ booking.message && (
						<div className="mt-3 flex gap-2 rounded-md bg-surface-muted p-3 text-sm">
							<ChatText className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
							<p className="whitespace-pre-wrap">{ booking.message }</p>
						</div>
					) }
					<div className="mt-3 border-t border-border pt-3">
						<p className="mb-2 text-xs font-medium text-muted-foreground">{ __( 'Website account', 'marclie-booking' ) }</p>
						{ booking.account ? (
							<AccountFacts account={ booking.account } />
						) : (
							<p className="text-sm text-muted-foreground">{ __( 'Not linked to an account.', 'marclie-booking' ) }</p>
						) }
					</div>
				</div>
			</Section>

			<Section title={ __( 'Stay', 'marclie-booking' ) }>
				<dl className="divide-y divide-border rounded-lg border border-border px-4">
					<DetailRow label={ __( 'Apartment', 'marclie-booking' ) }>{ booking.unit_title }</DetailRow>
					<DetailRow label={ __( 'Arrival', 'marclie-booking' ) }>
						{ formatDate( booking.arrival, 'long' ) }
						{ booking.arrival_time && <span className="text-muted-foreground"> · { booking.arrival_time }</span> }
					</DetailRow>
					<DetailRow label={ __( 'Departure', 'marclie-booking' ) }>{ formatDate( booking.departure, 'long' ) }</DetailRow>
					<DetailRow label={ __( 'Guests', 'marclie-booking' ) }>
						{ guestsLabel( booking.adults, booking.children, booking.infants ) }
					</DetailRow>
					<DetailRow label={ __( 'Source', 'marclie-booking' ) }>
						<SourceBadge source={ booking.source } />
					</DetailRow>
					<DetailRow label={ __( 'Created', 'marclie-booking' ) }>{ formatDateTime( booking.created_at ) }</DetailRow>
					{ booking.hold_expires_at && (
						<DetailRow label={ __( 'Hold expires', 'marclie-booking' ) }>{ formatDateTime( booking.hold_expires_at ) }</DetailRow>
					) }
				</dl>
			</Section>

			<Section title={ __( 'Price', 'marclie-booking' ) }>
				<div className="rounded-lg border border-border px-4">
					<dl className="divide-y divide-border">
						{ booking.quote?.lines.map( ( line, index ) => (
							<DetailRow key={ index } label={ quoteLineLabel( line ) }>
								<Money cents={ line.total_cents } />
							</DetailRow>
						) ) }
						{ booking.coupon_code && (
							<DetailRow label={ __( 'Coupon', 'marclie-booking' ) }>
								<span className="font-mono text-xs">{ booking.coupon_code }</span>
							</DetailRow>
						) }
						<DetailRow label={ <span className="font-semibold text-ink">{ __( 'Total', 'marclie-booking' ) }</span> }>
							<Money cents={ booking.total_cents } className="font-semibold text-ink" />
						</DetailRow>
						<DetailRow label={ __( 'Paid', 'marclie-booking' ) }>
							<Money cents={ booking.paid_cents } />
						</DetailRow>
						<DetailRow label={ __( 'Outstanding', 'marclie-booking' ) }>
							<Money cents={ booking.balance_cents } className={ cn( booking.balance_cents > 0 && 'font-medium' ) } />
							{ booking.balance_cents > 0 && booking.balance_due_on && (
								<div className="text-xs text-muted-foreground">
									{ sprintf(
										/* translators: %s: date. */
										__( 'due %s', 'marclie-booking' ),
										formatDate( booking.balance_due_on )
									) }
								</div>
							) }
						</DetailRow>
					</dl>
				</div>
			</Section>

			<Section title={ __( 'Policy', 'marclie-booking' ) }>
				<dl className="divide-y divide-border rounded-lg border border-border px-4">
					<DetailRow label={ __( 'Free cancellation until', 'marclie-booking' ) }>
						{ booking.free_cancel_until ? formatDate( booking.free_cancel_until ) : __( 'Not applicable', 'marclie-booking' ) }
					</DetailRow>
					{ policy.deposit_pct !== undefined && (
						<DetailRow label={ __( 'Deposit', 'marclie-booking' ) }>{ formatPercent( Number( policy.deposit_pct ) ) }</DetailRow>
					) }
					{ policy.balance_days !== undefined && (
						<DetailRow label={ __( 'Balance charged', 'marclie-booking' ) }>
							{ sprintf(
								/* translators: %d: number of days. */
								_n( '%d day before arrival', '%d days before arrival', Number( policy.balance_days ), 'marclie-booking' ),
								Number( policy.balance_days )
							) }
						</DetailRow>
					) }
				</dl>
			</Section>
		</>
	);
}

function PaymentsTab( { booking, onRecord }: { booking: BookingDetail; onRecord?: () => void } ) {
	return (
		<>
			<div className="mb-4 grid grid-cols-3 gap-2">
				{ [
					{ label: __( 'Total', 'marclie-booking' ), cents: booking.total_cents },
					{ label: __( 'Paid', 'marclie-booking' ), cents: booking.paid_cents },
					{ label: __( 'Outstanding', 'marclie-booking' ), cents: booking.balance_cents },
				].map( ( item ) => (
					<div key={ item.label } className="rounded-lg border border-border px-3 py-2.5">
						<div className="text-xs text-muted-foreground">{ item.label }</div>
						<Money cents={ item.cents } className="text-base font-semibold text-ink" />
					</div>
				) ) }
			</div>
			<Section
				title={ __( 'Transactions', 'marclie-booking' ) }
				action={
					onRecord && (
						<Button size="sm" variant="secondary" onClick={ onRecord }>
							<Receipt className="h-4 w-4" />
							{ __( 'Record payment', 'marclie-booking' ) }
						</Button>
					)
				}
			>
				{ booking.payments.length === 0 ? (
					<p className="rounded-lg border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground">
						{ __( 'No payments yet.', 'marclie-booking' ) }
					</p>
				) : (
					<ol className="relative ml-2 border-l border-border">
						{ booking.payments.map( ( payment ) => (
							<li key={ payment.id } className="relative pb-4 pl-5 last:pb-0">
								<span
									className={ cn(
										'absolute -left-[5px] top-1.5 h-2.5 w-2.5 rounded-full border-2 border-surface',
										payment.status === 'succeeded' ? 'bg-success' : payment.status === 'failed' ? 'bg-danger' : 'bg-border-strong'
									) }
								/>
								<div className="flex items-start justify-between gap-3">
									<div>
										<div className="flex items-center gap-2 text-sm font-medium text-ink">
											{ paymentKindLabel( payment.kind ) }
											<PaymentStatusBadge status={ payment.status } />
										</div>
										<div className="text-xs text-muted-foreground">
											{ paymentMethodLabel( payment.method ) }
											{ payment.card_last4 && ` · ${ payment.card_brand ?? '' } •••• ${ payment.card_last4 }` }
											{ ' · ' }
											{ formatDateTime( payment.created_at ) }
										</div>
										{ payment.kind === 'refund' && payment.method === 'stripe' && (
											<div className="text-xs text-muted-foreground">
												{ payment.status === 'pending'
													? __( 'Refund sent to Stripe — waiting for confirmation (the guest sees it in 5–10 business days).', 'marclie-booking' )
													: payment.status === 'refunded' || payment.status === 'succeeded'
													? __( 'Refunded through Stripe.', 'marclie-booking' )
													: payment.status === 'failed'
													? __( 'Stripe could not complete this refund — pay the guest back another way or retry in the Stripe Dashboard.', 'marclie-booking' )
													: null }
											</div>
										) }
										{ ( payment.stripe_refund_id || payment.stripe_intent_id ) && (
											<div className="mt-0.5 flex items-center gap-1 font-mono text-2xs text-muted-foreground">
												{ payment.stripe_dashboard_url ? (
													<a
														href={ payment.stripe_dashboard_url }
														target="_blank"
														rel="noreferrer"
														className="inline-flex items-center gap-1 underline-offset-2 hover:text-ink hover:underline"
														aria-label={ __( 'Open in Stripe Dashboard', 'marclie-booking' ) }
													>
														{ payment.stripe_refund_id ?? payment.stripe_intent_id }
														<ArrowSquareOut className="h-3 w-3" />
													</a>
												) : (
													payment.stripe_refund_id ?? payment.stripe_intent_id
												) }
											</div>
										) }
										{ payment.status === 'failed' && payment.error_code && (
											<div className="mt-0.5 font-mono text-2xs text-danger">{ payment.error_code }</div>
										) }
										{ payment.note && <div className="mt-1 text-xs text-ink-soft">{ payment.note }</div> }
									</div>
									<Money
										cents={ payment.kind === 'refund' ? -Math.abs( payment.amount_cents ) : payment.amount_cents }
										className={ cn( 'text-sm font-medium', payment.kind === 'refund' && 'text-danger' ) }
									/>
								</div>
							</li>
						) ) }
					</ol>
				) }
			</Section>
		</>
	);
}

function HistoryTab( { booking }: { booking: BookingDetail } ) {
	if ( booking.events.length === 0 ) {
		return <p className="py-8 text-center text-sm text-muted-foreground">{ __( 'No history yet.', 'marclie-booking' ) }</p>;
	}
	return (
		<ol className="relative ml-2 border-l border-border">
			{ booking.events.map( ( event ) => (
				<li key={ event.id } className="relative pb-4 pl-5 last:pb-0">
					<span className="absolute -left-[4px] top-2 h-2 w-2 rounded-full bg-border-strong" />
					<div className="text-sm text-ink-soft">{ event.summary }</div>
					<div className="text-xs text-muted-foreground">
						<span title={ formatDateTime( event.created_at ) }>{ formatRelative( event.created_at ) }</span> · { event.actor }
					</div>
				</li>
			) ) }
		</ol>
	);
}

function NotesTab( { booking }: { booking: BookingDetail } ) {
	const [ notes, setNotes ] = React.useState( booking.internal_notes ?? '' );
	const update = useUpdateBooking( booking.id );
	const [ notesError, setNotesError ] = React.useState< string | null >( null );
	const dirty = notes !== ( booking.internal_notes ?? '' );

	React.useEffect( () => {
		setNotes( booking.internal_notes ?? '' );
	}, [ booking.internal_notes ] );

	const save = () =>
		update.mutate(
			{ internal_notes: notes },
			{
				onSuccess: () => toast.success( __( 'Notes saved.', 'marclie-booking' ) ),
				onError: ( error ) => {
					if ( ! applyFieldErrors( error, ( _name, { message } ) => setNotesError( message ) ) ) {
						toast.error( errorMessage( error ) );
					}
				},
			}
		);

	return (
		<div className="flex flex-col gap-3">
			<label htmlFor="mbk-internal-notes" className="text-sm font-medium text-ink-soft">
				{ __( 'Internal notes', 'marclie-booking' ) }
			</label>
			<Textarea
				id="mbk-internal-notes"
				value={ notes }
				onChange={ ( event ) => {
					setNotes( event.target.value );
					setNotesError( null );
				} }
				aria-invalid={ notesError !== null }
				aria-describedby={ notesError ? 'mbk-internal-notes-error' : undefined }
				rows={ 8 }
				placeholder={ __( 'Only visible to staff.', 'marclie-booking' ) }
			/>
			{ notesError && (
				<p id="mbk-internal-notes-error" className="text-xs text-danger" role="alert">
					{ notesError }
				</p>
			) }
			<div className="flex justify-end gap-2">
				{ dirty && (
					<Button variant="ghost" onClick={ () => setNotes( booking.internal_notes ?? '' ) }>
						{ __( 'Discard', 'marclie-booking' ) }
					</Button>
				) }
				<Button variant="ink" onClick={ save } disabled={ ! dirty || update.isPending }>
					{ update.isPending ? __( 'Saving…', 'marclie-booking' ) : __( 'Save notes', 'marclie-booking' ) }
				</Button>
			</div>
			{ booking.message && (
				<div className="mt-2">
					<div className="mb-1 text-sm font-medium text-ink-soft">{ __( 'Message from guest', 'marclie-booking' ) }</div>
					<p className="whitespace-pre-wrap rounded-md bg-surface-muted p-3 text-sm">{ booking.message }</p>
				</div>
			) }
		</div>
	);
}

function SheetSkeleton() {
	return (
		<div className="flex flex-col gap-4 p-6">
			<Skeleton className="h-6 w-40" />
			<Skeleton className="h-4 w-64" />
			<Skeleton className="mt-4 h-9 w-full" />
			<Skeleton className="h-32 w-full" />
			<Skeleton className="h-48 w-full" />
		</div>
	);
}

export default function BookingSheet( { id, onClose }: { id: number | string; onClose: () => void } ) {
	const { today, stripe } = useBootstrapData();
	// Ref deep links (`#/bookings/BK-…`) resolve once, then the id-keyed detail (seeded) drives the sheet.
	const refQuery = useBooking( typeof id === 'string' ? id : null );
	const detailQuery = useBooking( typeof id === 'number' ? id : refQuery.data?.id ?? null );
	const query = typeof id === 'string' && ! refQuery.data ? refQuery : detailQuery;
	const [ open, setOpen ] = React.useState( true );
	const [ dialog, setDialog ] = React.useState< DialogKey >( null );
	const [ tab, setTab ] = React.useState( 'overview' );
	const booking = query.data;

	React.useEffect( () => {
		setOpen( true );
		setTab( 'overview' );
	}, [ id ] );

	const handleOpenChange = ( next: boolean ) => {
		setOpen( next );
		if ( ! next ) {
			window.setTimeout( onClose, 200 );
		}
	};

	const isClosed = booking ? CLOSED.includes( booking.status ) : true;
	const canCancel = booking ? booking.allowed_transitions.includes( 'cancelled' ) : false;
	const canPay = booking ? ! isClosed && booking.balance_cents > 0 : false;
	// Charges: confirmed/balance_failed with a saved card. Links also work for admin holds without expiry.
	const stripeStatus =
		booking?.status === 'confirmed' ||
		booking?.status === 'balance_failed' ||
		( booking?.status === 'pending_payment' && booking.source === 'admin' && booking.hold_expires_at === null );
	const canStripe = canPay && stripe.configured && stripeStatus;
	const canCharge = canStripe && booking?.status !== 'pending_payment' && booking?.saved_card === true;
	// The server re-prices moves from today on; a stay that has started cannot be moved.
	const inProgress = booking ? booking.arrival < today : false;

	return (
		<Sheet open={ open } onOpenChange={ handleOpenChange }>
			<SheetContent className="sm:max-w-[640px]" aria-describedby={ undefined }>
				{ query.isPending && (
					<>
						<SheetTitle className="sr-only">{ __( 'Booking', 'marclie-booking' ) }</SheetTitle>
						<SheetSkeleton />
					</>
				) }
				{ query.isError && (
					<>
						<SheetTitle className="sr-only">{ __( 'Booking', 'marclie-booking' ) }</SheetTitle>
						<ErrorState error={ query.error } onRetry={ () => query.refetch() } className="mt-16" />
					</>
				) }
				{ booking && (
					<>
						<SheetHeader>
							<div className="flex flex-wrap items-center gap-2">
								<span className="font-mono text-xs text-muted-foreground">{ booking.ref }</span>
								<StatusBadge status={ booking.status } />
							</div>
							<SheetTitle>{ booking.guest.name }</SheetTitle>
							<SheetDescription className="tabular">
								{ booking.unit_title } · { formatDate( booking.arrival, 'day' ) } – { formatDate( booking.departure, 'medium' ) } ·{ ' ' }
								{ sprintf(
									/* translators: %d: number of nights. */
									_n( '%d night', '%d nights', booking.nights, 'marclie-booking' ),
									booking.nights
								) }
							</SheetDescription>
							<div className="mt-3 flex flex-wrap gap-2">
								{ canPay && (
									<Button size="sm" onClick={ () => setDialog( 'payment' ) }>
										<Receipt className="h-4 w-4" />
										{ __( 'Record payment', 'marclie-booking' ) }
									</Button>
								) }
								{ canCharge && (
									<Button size="sm" variant="secondary" onClick={ () => setDialog( 'charge' ) }>
										<CreditCard className="h-4 w-4" />
										{ __( 'Charge now', 'marclie-booking' ) }
									</Button>
								) }
								{ canStripe && (
									<Button size="sm" variant="secondary" onClick={ () => setDialog( 'link' ) }>
										<LinkIcon className="h-4 w-4" />
										{ __( 'Payment link', 'marclie-booking' ) }
									</Button>
								) }
								<Button size="sm" variant="secondary" onClick={ () => setDialog( 'edit' ) }>
									<PencilSimple className="h-4 w-4" />
									{ __( 'Edit guest', 'marclie-booking' ) }
								</Button>
								{ ! isClosed && ! inProgress && (
									<Button size="sm" variant="secondary" onClick={ () => setDialog( 'move' ) }>
										<ArrowsLeftRight className="h-4 w-4" />
										{ __( 'Change dates', 'marclie-booking' ) }
									</Button>
								) }
								{ ! isClosed && inProgress && (
									<SimpleTooltip label={ __( 'This stay has already started, so its dates or apartment can no longer be changed.', 'marclie-booking' ) }>
										<span tabIndex={ 0 } className="rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
											<Button size="sm" variant="secondary" disabled aria-disabled="true">
												<ArrowsLeftRight className="h-4 w-4" />
												{ __( 'Change dates', 'marclie-booking' ) }
											</Button>
										</span>
									</SimpleTooltip>
								) }
								<DropdownMenu>
									<DropdownMenuTrigger asChild>
										<Button size="sm" variant="secondary">
											{ __( 'More', 'marclie-booking' ) }
											<CaretDown className="h-3.5 w-3.5" />
										</Button>
									</DropdownMenuTrigger>
									<DropdownMenuContent align="end" className="w-52">
										<DropdownMenuItem onSelect={ () => setDialog( 'block' ) }>
											<CalendarX />
											{ __( 'Block dates', 'marclie-booking' ) }
										</DropdownMenuItem>
										<DropdownMenuItem onSelect={ () => setTab( 'payments' ) }>
											<CreditCard />
											{ __( 'View payments', 'marclie-booking' ) }
										</DropdownMenuItem>
										<DropdownMenuItem onSelect={ () => setTab( 'history' ) }>
											<ClockCounterClockwise />
											{ __( 'View history', 'marclie-booking' ) }
										</DropdownMenuItem>
										{ canCancel && (
											<>
												<DropdownMenuSeparator />
												<DropdownMenuItem destructive onSelect={ () => setDialog( 'cancel' ) }>
													<Prohibit />
													{ __( 'Cancel booking', 'marclie-booking' ) }
												</DropdownMenuItem>
											</>
										) }
									</DropdownMenuContent>
								</DropdownMenu>
							</div>
						</SheetHeader>
						<SheetBody className="pt-3">
							<Tabs value={ tab } onValueChange={ setTab }>
								{ /* Five tabs: scroll sideways on narrow phones instead of clipping the last one. */ }
								<div className="-mx-1 overflow-x-auto px-1">
									<TabsList variant="underline" className="w-max min-w-full">
										<TabsTrigger value="overview">{ __( 'Overview', 'marclie-booking' ) }</TabsTrigger>
										<TabsTrigger value="payments">
											{ __( 'Payments', 'marclie-booking' ) }
											{ booking.payments.length > 0 && (
												<span className="rounded-full bg-surface-muted px-1.5 text-2xs tabular">{ booking.payments.length }</span>
											) }
										</TabsTrigger>
										<TabsTrigger value="emails">{ __( 'Emails', 'marclie-booking' ) }</TabsTrigger>
										<TabsTrigger value="history">{ __( 'History', 'marclie-booking' ) }</TabsTrigger>
										<TabsTrigger value="notes">
											{ __( 'Notes', 'marclie-booking' ) }
											{ booking.internal_notes && <span className="h-1.5 w-1.5 rounded-full bg-accent" /> }
										</TabsTrigger>
									</TabsList>
								</div>
								<TabsContent value="overview">
									<OverviewTab booking={ booking } />
								</TabsContent>
								<TabsContent value="payments">
									<PaymentsTab booking={ booking } onRecord={ canPay ? () => setDialog( 'payment' ) : undefined } />
								</TabsContent>
								<TabsContent value="emails">
									<BookingEmails booking={ booking } />
								</TabsContent>
								<TabsContent value="history">
									<HistoryTab booking={ booking } />
								</TabsContent>
								<TabsContent value="notes">
									<NotesTab booking={ booking } />
								</TabsContent>
							</Tabs>
						</SheetBody>

						<EditGuestDialog booking={ booking } open={ dialog === 'edit' } onOpenChange={ ( next ) => setDialog( next ? 'edit' : null ) } />
						<RecordPaymentDialog
							booking={ booking }
							open={ dialog === 'payment' }
							onOpenChange={ ( next ) => setDialog( next ? 'payment' : null ) }
						/>
						<ChargeDialog
							booking={ booking }
							open={ dialog === 'charge' }
							onOpenChange={ ( next ) => setDialog( next ? 'charge' : null ) }
							onSendLink={ () => setDialog( 'link' ) }
						/>
						<PaymentLinkDialog booking={ booking } open={ dialog === 'link' } onOpenChange={ ( next ) => setDialog( next ? 'link' : null ) } />
						<CancelDialog booking={ booking } open={ dialog === 'cancel' } onOpenChange={ ( next ) => setDialog( next ? 'cancel' : null ) } />
						<BlockDialog
							unitId={ booking.unit_id }
							open={ dialog === 'block' }
							onOpenChange={ ( next ) => setDialog( next ? 'block' : null ) }
						/>
						<MoveDialog booking={ booking } open={ dialog === 'move' } onOpenChange={ ( next ) => setDialog( next ? 'move' : null ) } />
					</>
				) }
			</SheetContent>
		</Sheet>
	);
}
