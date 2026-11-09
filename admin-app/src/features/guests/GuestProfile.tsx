import {
	ArrowLeft,
	CalendarCheck,
	CurrencyEur,
	DotsThree,
	DownloadSimple,
	EnvelopeSimple,
	GitMerge,
	Moon,
	Plus,
	ShieldSlash,
	UserCircle,
} from '@phosphor-icons/react';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type { GuestDetail } from '@/api/hooks/guests';
import { downloadGuestExport, useGuest, useUpdateGuest } from '@/api/hooks/guests';
import { useBootstrapData } from '@/api/hooks/bootstrap';
import { Link } from '@/app/router';
import { EmptyState } from '@/components/app/EmptyState';
import { ErrorState } from '@/components/app/ErrorState';
import { KpiCard } from '@/components/app/KpiCard';
import { Money } from '@/components/app/Money';
import { PageHeader } from '@/components/app/PageHeader';
import { PaymentStatusBadge, StatusBadge } from '@/components/app/StatusBadge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
	DropdownMenu,
	DropdownMenuContent,
	DropdownMenuItem,
	DropdownMenuSeparator,
	DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Skeleton } from '@/components/ui/skeleton';
import { toast } from '@/components/ui/sonner';
import { Textarea } from '@/components/ui/textarea';
import { useBookingSheet } from '@/features/bookings/sheet';
import { useBookingWizard } from '@/features/bookings/wizard';
import { ChipsInput } from '@/features/common/ChipsInput';
import { guestName } from '@/features/common/GuestPicker';
import { COUPON_STATE_BADGE, couponState, couponValue } from '@/features/coupons/couponUtils';
import { paymentKindLabel, paymentMethodLabel } from '@/features/payments/labels';
import { toastError } from '@/features/common/toastError';
import { formatDate, formatDateRange, formatDateTime, formatNumber } from '@/lib/format';

import { GuestAccountCard } from './GuestAccountCard';
import { GuestContactCard } from './GuestContactCard';
import { AnonymizeGuestDialog, MergeGuestDialog } from './GuestDialogs';
import { GuestEmailDialog } from './GuestEmailDialog';
import { GUEST_TAGS } from './tags';
import { TierBadge } from './tiers';

export function GuestProfile( { id }: { id: number } ) {
	const guest = useGuest( id );

	if ( guest.isPending ) {
		return (
			<div className="flex flex-col gap-4">
				<Skeleton className="h-10 w-64" />
				<div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
					{ [ 0, 1, 2, 3 ].map( ( i ) => (
						<Skeleton key={ i } className="h-24" />
					) ) }
				</div>
				<Skeleton className="h-80 w-full" />
			</div>
		);
	}
	if ( guest.isError ) {
		return (
			<>
				<BackLink />
				<ErrorState error={ guest.error } onRetry={ () => guest.refetch() } />
			</>
		);
	}
	return <Profile guest={ guest.data } />;
}

function BackLink() {
	return (
		<Link to="/guests" className="mb-3 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-ink-soft">
			<ArrowLeft className="h-4 w-4" aria-hidden="true" />
			{ __( 'All guests', 'marclie-booking' ) }
		</Link>
	);
}

function Profile( { guest }: { guest: GuestDetail } ) {
	const { today } = useBootstrapData();
	const wizard = useBookingWizard();
	const sheet = useBookingSheet();
	const [ dialog, setDialog ] = useState< 'merge' | 'anonymize' | 'email' | null >( null );
	const name = guest.anonymized ? __( 'Anonymized guest', 'marclie-booking' ) : guestName( guest );

	const [ exporting, setExporting ] = useState( false );
	const exportData = async () => {
		if ( exporting ) {
			return;
		}
		setExporting( true );
		try {
			await downloadGuestExport( guest.id, `guest-${ guest.id }-export.json` );
		} catch ( e ) {
			toastError( e );
		} finally {
			setExporting( false );
		}
	};

	return (
		<>
			<BackLink />
			<PageHeader
				title={
					<span className="flex flex-wrap items-center gap-2">
						{ name }
						{ guest.anonymized && <Badge variant="muted">{ __( 'Anonymized', 'marclie-booking' ) }</Badge> }
						{ guest.tier && <TierBadge tier={ guest.tier } /> }
						{ guest.tags.map( ( t ) => (
							<Badge key={ t } variant={ t === 'VIP' ? 'accent' : 'default' }>
								{ t }
							</Badge>
						) ) }
					</span>
				}
				description={
					<>
						{ guest.email }
						{ guest.phone ? ` · ${ guest.phone }` : '' }
						{ guest.country ? ` · ${ guest.country }` : '' }
						{ ` · ${ guest.lang.toUpperCase() }` }
					</>
				}
				actions={
					<>
						{ ! guest.anonymized && guest.email !== '' && (
							<Button variant="secondary" onClick={ () => setDialog( 'email' ) }>
								<EnvelopeSimple className="h-4 w-4" aria-hidden="true" />
								{ __( 'Send e-mail', 'marclie-booking' ) }
							</Button>
						) }
						{ ! guest.anonymized && (
							<Button onClick={ () => wizard.open( { guest } ) }>
								<Plus className="h-4 w-4" aria-hidden="true" />
								{ __( 'New booking for guest', 'marclie-booking' ) }
							</Button>
						) }
						<DropdownMenu>
							<DropdownMenuTrigger asChild>
								<Button variant="outline" size="icon" aria-label={ __( 'More actions', 'marclie-booking' ) }>
									<DotsThree className="h-5 w-5" weight="bold" />
								</Button>
							</DropdownMenuTrigger>
							<DropdownMenuContent align="end">
								<DropdownMenuItem onSelect={ exportData } disabled={ exporting }>
									<DownloadSimple className="h-4 w-4" aria-hidden="true" />
									{ __( 'Export data (JSON)', 'marclie-booking' ) }
								</DropdownMenuItem>
								{ ! guest.anonymized && (
									<>
										<DropdownMenuItem onSelect={ () => setDialog( 'merge' ) }>
											<GitMerge className="h-4 w-4" aria-hidden="true" />
											{ __( 'Merge into another guest…', 'marclie-booking' ) }
										</DropdownMenuItem>
										<DropdownMenuSeparator />
										<DropdownMenuItem destructive onSelect={ () => setDialog( 'anonymize' ) }>
											<ShieldSlash className="h-4 w-4" aria-hidden="true" />
											{ __( 'Anonymize (GDPR)…', 'marclie-booking' ) }
										</DropdownMenuItem>
									</>
								) }
							</DropdownMenuContent>
						</DropdownMenu>
					</>
				}
			/>

			<div className="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
				<KpiCard label={ __( 'Stays', 'marclie-booking' ) } value={ formatNumber( guest.bookings_count ) } icon={ CalendarCheck } />
				<KpiCard label={ __( 'Nights', 'marclie-booking' ) } value={ formatNumber( guest.nights_total ) } icon={ Moon } />
				<KpiCard label={ __( 'Revenue', 'marclie-booking' ) } value={ <Money cents={ guest.revenue_cents } /> } icon={ CurrencyEur } />
				<KpiCard
					label={ __( 'Last stay', 'marclie-booking' ) }
					value={ formatDate( guest.last_stay_at ) }
					hint={
						guest.first_booking_at
							? sprintf(
									/* translators: %s: date. */
									__( 'Guest since %s', 'marclie-booking' ),
									formatDate( guest.first_booking_at.slice( 0, 10 ) )
							  )
							: undefined
					}
					icon={ UserCircle }
				/>
			</div>

			<div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_380px]">
				<div className="flex min-w-0 flex-col gap-5">
					<Card>
						<CardHeader>
							<CardTitle>{ __( 'Bookings', 'marclie-booking' ) }</CardTitle>
						</CardHeader>
						<CardContent className="px-0 pb-2">
							{ guest.bookings.length === 0 ? (
								<EmptyState compact title={ __( 'No bookings yet', 'marclie-booking' ) } />
							) : (
								<ul className="divide-y divide-border">
									{ guest.bookings.map( ( b ) => (
										<li key={ b.id }>
											<button
												type="button"
												onClick={ () => sheet.open( b.id ) }
												className="grid w-full grid-cols-[1fr_auto] items-center gap-x-3 gap-y-0.5 px-5 py-2.5 text-left text-sm hover:bg-surface-muted focus-visible:bg-surface-muted focus-visible:outline-none"
											>
												<span className="flex min-w-0 items-center gap-2">
													<span className="font-mono text-xs font-medium">{ b.ref }</span>
													<StatusBadge status={ b.status } />
												</span>
												<Money cents={ b.total_cents } className="text-right font-medium" />
												<span className="truncate text-xs text-muted-foreground">
													{ b.unit_title } · { formatDateRange( b.arrival, b.departure ) }
												</span>
												<span className="text-right text-xs text-muted-foreground">
													{ b.status === 'cancelled' || b.status === 'expired'
														? null
														: b.balance_cents > 0
														? (
															<>
																{ __( 'Due', 'marclie-booking' ) } <Money cents={ b.balance_cents } />
															</>
														)
														: __( 'Paid', 'marclie-booking' ) }
												</span>
											</button>
										</li>
									) ) }
								</ul>
							) }
						</CardContent>
					</Card>

					<Card>
						<CardHeader>
							<CardTitle>{ __( 'Payments', 'marclie-booking' ) }</CardTitle>
						</CardHeader>
						<CardContent className="px-0 pb-2">
							{ guest.payments.length === 0 ? (
								<EmptyState compact title={ __( 'No payments yet', 'marclie-booking' ) } />
							) : (
								<div className="overflow-x-auto">
									<table className="w-full min-w-[520px] text-sm">
										<thead>
											<tr className="border-b border-border text-left text-xs text-muted-foreground">
												<th scope="col" className="px-5 py-2 font-medium">{ __( 'Date', 'marclie-booking' ) }</th>
												<th scope="col" className="py-2 pr-3 font-medium">{ __( 'Booking', 'marclie-booking' ) }</th>
												<th scope="col" className="py-2 pr-3 font-medium">{ __( 'Type', 'marclie-booking' ) }</th>
												<th scope="col" className="py-2 pr-3 font-medium">{ __( 'Status', 'marclie-booking' ) }</th>
												<th scope="col" className="px-5 py-2 text-right font-medium">{ __( 'Amount', 'marclie-booking' ) }</th>
											</tr>
										</thead>
										<tbody>
											{ guest.payments.map( ( p ) => (
												<tr key={ p.id } className="border-b border-border last:border-0">
													<td className="whitespace-nowrap px-5 py-2">{ formatDateTime( p.created_at ) }</td>
													<td className="py-2 pr-3">
														<button
															type="button"
															className="font-mono text-xs font-medium text-accent hover:underline"
															onClick={ () => sheet.open( p.booking_id ) }
														>
															{ p.booking_ref }
														</button>
													</td>
													<td className="py-2 pr-3">
														{ paymentKindLabel( p.kind ) }
														<span className="block text-xs text-muted-foreground">{ paymentMethodLabel( p.method ) }</span>
													</td>
													<td className="py-2 pr-3">
														<PaymentStatusBadge status={ p.status } />
													</td>
													<td className="px-5 py-2 text-right">
														<Money cents={ p.kind === 'refund' ? -p.amount_cents : p.amount_cents } signed={ p.kind === 'refund' } />
													</td>
												</tr>
											) ) }
										</tbody>
									</table>
								</div>
							) }
						</CardContent>
					</Card>

					<Card>
						<CardHeader>
							<CardTitle>{ __( 'Coupons', 'marclie-booking' ) }</CardTitle>
						</CardHeader>
						<CardContent>
							{ guest.coupons.length === 0 ? (
								<p className="text-sm text-muted-foreground">
									{ __( 'No coupons used or assigned.', 'marclie-booking' ) }
								</p>
							) : (
								<ul className="flex flex-col gap-2">
									{ guest.coupons.map( ( c ) => {
										const state = COUPON_STATE_BADGE[ couponState( c, today ) ];
										return (
											<li key={ c.id } className="flex items-center justify-between gap-3 text-sm">
												<span className="flex items-center gap-2">
													<code className="rounded bg-surface-muted px-1.5 py-0.5 text-xs font-semibold">{ c.code }</code>
													{ couponValue( c ) }
													{ c.guest_id === guest.id && (
														<Badge variant="outline">{ __( 'Assigned', 'marclie-booking' ) }</Badge>
													) }
												</span>
												<Badge variant={ state.variant }>{ state.label() }</Badge>
											</li>
										);
									} ) }
								</ul>
							) }
						</CardContent>
					</Card>
				</div>

				<div className="flex min-w-0 flex-col gap-5">
					<GuestContactCard guest={ guest } />
					<GuestAccountCard account={ guest.account } coupons={ guest.coupons.filter( ( c ) => c.guest_id === guest.id ) } />
					{ ! guest.anonymized && <TagsAndNotes guest={ guest } /> }
				</div>
			</div>

			<MergeGuestDialog guest={ guest } open={ dialog === 'merge' } onOpenChange={ ( o ) => ! o && setDialog( null ) } />
			<AnonymizeGuestDialog guest={ guest } open={ dialog === 'anonymize' } onOpenChange={ ( o ) => ! o && setDialog( null ) } />
			{ ! guest.anonymized && (
				<GuestEmailDialog guest={ guest } open={ dialog === 'email' } onOpenChange={ ( o ) => ! o && setDialog( null ) } />
			) }
		</>
	);
}

function TagsAndNotes( { guest }: { guest: GuestDetail } ) {
	const update = useUpdateGuest( guest.id );
	const tagsUpdate = useUpdateGuest( guest.id );
	const [ notes, setNotes ] = useState( guest.notes ?? '' );
	// Optimistic tags: shown immediately, rolled back on error; the input is locked while a save runs.
	const [ tags, setTags ] = useState( guest.tags );
	const serverTags = guest.tags.join( '\n' );

	useEffect( () => setNotes( guest.notes ?? '' ), [ guest.notes ] );
	useEffect( () => {
		if ( ! tagsUpdate.isPending ) {
			setTags( serverTags ? serverTags.split( '\n' ) : [] );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ serverTags ] );

	const saveTags = async ( next: string[] ) => {
		if ( tagsUpdate.isPending ) {
			return;
		}
		const previous = tags;
		setTags( next );
		try {
			await tagsUpdate.mutateAsync( { tags: next } );
		} catch ( e ) {
			setTags( previous );
			toastError( e );
		}
	};

	const saveNotes = async () => {
		try {
			await update.mutateAsync( { notes: notes.trim() || null } );
			toast.success( __( 'Notes saved.', 'marclie-booking' ) );
		} catch ( e ) {
			toastError( e );
		}
	};

	return (
		<>
			<Card>
				<CardHeader>
					<CardTitle>
						<label htmlFor="guest-tags">{ __( 'Tags', 'marclie-booking' ) }</label>
					</CardTitle>
				</CardHeader>
				<CardContent>
					<ChipsInput
						id="guest-tags"
						value={ tags }
						onChange={ saveTags }
						disabled={ tagsUpdate.isPending }
						validate={ ( v ) => ( v.length > 50 ? __( 'Tags can be at most 50 characters.', 'marclie-booking' ) : null ) }
						suggestions={ GUEST_TAGS }
						normalize={ ( v ) => v.replace( /,/g, '' ).trim() }
						placeholder={ __( 'Add tag…', 'marclie-booking' ) }
					/>
				</CardContent>
			</Card>
			<Card>
				<CardHeader>
					<CardTitle>
						<label htmlFor="guest-notes">{ __( 'Internal notes', 'marclie-booking' ) }</label>
					</CardTitle>
				</CardHeader>
				<CardContent className="flex flex-col gap-2">
					<Textarea
						id="guest-notes"
						rows={ 5 }
						value={ notes }
						onChange={ ( e ) => setNotes( e.target.value ) }
						placeholder={ __( 'Preferences, allergies, special arrangements… (never shown to the guest)', 'marclie-booking' ) }
					/>
					<div className="flex justify-end">
						<Button size="sm" variant="secondary" onClick={ saveNotes } disabled={ notes === ( guest.notes ?? '' ) || update.isPending }>
							{ __( 'Save notes', 'marclie-booking' ) }
						</Button>
					</div>
				</CardContent>
			</Card>
		</>
	);
}
