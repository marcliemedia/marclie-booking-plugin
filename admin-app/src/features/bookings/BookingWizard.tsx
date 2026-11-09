import { Check, CircleNotch, MagnifyingGlass, UserPlus, Warning, X } from '@phosphor-icons/react';
import { __, _n, sprintf } from '@wordpress/i18n';
import * as React from 'react';
import { z } from 'zod';

import { ApiError, errorMessage } from '@/api/client';
import { useBootstrapData } from '@/api/hooks/bootstrap';
import { useCreateBooking, useQuote } from '@/api/hooks/bookings';
import { useCalendar, useSearch } from '@/api/hooks/dashboard';
import type { NewGuestInput } from '@/api/types';
import { DateRangePicker } from '@/components/app/DateRangePicker';
import { Money } from '@/components/app/Money';
import { MoneyInput } from '@/components/app/MoneyInput';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectGroup, SelectItem, SelectLabel, SelectTrigger, SelectValue } from '@/components/ui/select';
import { toast } from '@/components/ui/sonner';
import { Textarea } from '@/components/ui/textarea';
import { Field } from '@/features/bookings/dialogs';
import { quoteLineLabel } from '@/features/bookings/quoteLines';
import { useBookingSheet } from '@/features/bookings/sheet';
import type { WizardPrefill } from '@/features/bookings/wizard';
import { occupiedNights } from '@/features/calendar/occupancy';
import { addDays, addMonths, startOfMonth } from '@/lib/dates';
import { formatDate, formatMoney } from '@/lib/format';
import { groupByHouse } from '@/lib/units';
import { cn } from '@/lib/utils';
import { applyFieldErrors } from '@/lib/formErrors';
import { useDebounced } from '@/lib/useDebounced';

type Step = 1 | 2 | 3;

interface SelectedGuest {
	id: number;
	name: string;
	email: string;
}

const newGuestSchema = z.object( {
	first_name: z.string().trim().min( 1, __( 'Required', 'marclie-booking' ) ),
	last_name: z.string().trim().min( 1, __( 'Required', 'marclie-booking' ) ),
	email: z.string().trim().email( __( 'Enter a valid email', 'marclie-booking' ) ),
	phone: z.string().trim(),
	country: z.string().trim().max( 2, __( 'Use a 2-letter country code', 'marclie-booking' ) ),
	lang: z.enum( [ 'hr', 'en' ] ),
} );

function Stepper( { step }: { step: Step } ) {
	const steps = [ __( 'Stay', 'marclie-booking' ), __( 'Guest', 'marclie-booking' ), __( 'Payment', 'marclie-booking' ) ];
	return (
		<ol className="flex items-center gap-2 text-[13px]">
			{ steps.map( ( label, index ) => {
				const number = ( index + 1 ) as Step;
				const done = number < step;
				const current = number === step;
				return (
					<li key={ label } className="flex items-center gap-2" aria-current={ current ? 'step' : undefined }>
						<span
							className={ cn(
								'flex h-6 w-6 items-center justify-center rounded-full text-xs font-semibold',
								done && 'bg-ink-soft text-white',
								current && 'bg-accent text-white',
								! done && ! current && 'bg-surface-muted text-muted-foreground'
							) }
						>
							{ done ? <Check className="h-3.5 w-3.5" weight="bold" /> : number }
						</span>
						<span className={ cn( current ? 'font-medium text-ink' : 'text-muted-foreground' ) }>{ label }</span>
						{ index < steps.length - 1 && <span className="mx-1 h-px w-6 bg-border" /> }
					</li>
				);
			} ) }
		</ol>
	);
}

export default function BookingWizard( {
	open,
	prefill,
	onClose,
}: {
	open: boolean;
	prefill: WizardPrefill;
	onClose: () => void;
} ) {
	const { units, today } = useBootstrapData();
	const sheet = useBookingSheet();
	const create = useCreateBooking();
	const [ step, setStep ] = React.useState< Step >( 1 );

	// Step 1 — stay.
	const [ unitId, setUnitId ] = React.useState< number | null >( prefill.unitId ?? null );
	const [ range, setRange ] = React.useState< { from?: string; to?: string } >( { from: prefill.arrival, to: prefill.departure } );
	// Text state so clearing the field while typing doesn't snap back; numbers derive from it.
	const [ adultsText, setAdultsText ] = React.useState( '2' );
	const [ childrenText, setChildrenText ] = React.useState( '0' );
	const [ infantsText, setInfantsText ] = React.useState( '0' );
	const adults = Math.max( 1, parseInt( adultsText, 10 ) || 1 );
	const children = Math.max( 0, parseInt( childrenText, 10 ) || 0 );
	const infants = Math.max( 0, parseInt( infantsText, 10 ) || 0 );
	const [ coupon, setCoupon ] = React.useState( '' );
	const couponCode = useDebounced( coupon.trim(), 400 );
	const [ month, setMonth ] = React.useState( startOfMonth( prefill.arrival ?? today ) );

	// Step 2 — guest.
	const prefillGuest =
		prefill.guest && prefill.guest.id
			? {
					id: prefill.guest.id,
					name: 'name' in prefill.guest ? prefill.guest.name : `${ prefill.guest.first_name } ${ prefill.guest.last_name }`,
					email: prefill.guest.email,
			  }
			: null;
	const [ guest, setGuest ] = React.useState< SelectedGuest | null >( prefillGuest );
	const [ guestMode, setGuestMode ] = React.useState< 'search' | 'new' >( 'search' );
	const [ guestQuery, setGuestQuery ] = React.useState( '' );
	const [ newGuest, setNewGuest ] = React.useState< NewGuestInput >( {
		first_name: '',
		last_name: '',
		email: '',
		phone: '',
		country: '',
		lang: 'en',
	} );
	const [ guestErrors, setGuestErrors ] = React.useState< Partial< Record< keyof NewGuestInput, string > > >( {} );
	const searchTerm = useDebounced( guestQuery, 250 );
	const search = useSearch( guestMode === 'search' ? searchTerm : '' );

	// Step 3 — payment.
	const [ paymentMode, setPaymentMode ] = React.useState< 'outside' | 'none' >( 'none' );
	const [ amount, setAmount ] = React.useState< number | null >( null );
	const [ paymentError, setPaymentError ] = React.useState< string | null >( null );
	const [ method, setMethod ] = React.useState< 'cash' | 'bank' | 'other' >( 'bank' );
	const [ notes, setNotes ] = React.useState( '' );

	const unit = units.find( ( item ) => item.id === unitId ) ?? null;
	const calendar = useCalendar( month, addMonths( month, 2 ) );
	const nights = React.useMemo( () => ( unitId ? occupiedNights( calendar.data, unitId ) : new Set< string >() ), [ calendar.data, unitId ] );
	const isBooked = React.useCallback( ( ymd: string ) => nights.has( ymd ), [ nights ] );
	// A day is unusable when it is booked and the night before is booked too (can't arrive or leave).
	const isDisabled = React.useCallback( ( ymd: string ) => nights.has( ymd ) && nights.has( addDays( ymd, -1 ) ), [ nights ] );

	// guest_id lets the server accept coupons restricted to that guest; picking a guest re-quotes.
	const quoteInput =
		unitId && range.from && range.to
			? {
					unit_id: unitId,
					arrival: range.from,
					departure: range.to,
					adults,
					children,
					infants,
					coupon: couponCode || undefined,
					guest_id: guest?.id,
			  }
			: null;
	const quote = useQuote( quoteInput );
	const quoteData = quoteInput && ! quote.isError ? quote.data : undefined;
	// Children 0–4 are free and do not count toward the capacity.
	const overCapacity = unit !== null && adults + children > unit.capacity;
	// A guest-only coupon can only be checked once the guest is known (step 2 re-quotes).
	const awaitingGuestForCoupon =
		quote.error instanceof ApiError && quote.error.code === 'coupon_invalid' && quote.error.data.reason === 'guest_only' && ! guest;
	const stayValid =
		! overCapacity &&
		! quote.isPlaceholderData &&
		! quote.isFetching &&
		( awaitingGuestForCoupon || ( !! quoteData && quoteData.available ) );
	const quoteReady = !! quoteData && quoteData.available && ! quote.isFetching;

	// A new price (dates, guests, coupon) resets the suggested amount to the new deposit.
	const depositCents = quoteData?.quote.deposit_cents;
	const totalCents = quoteData?.quote.total_cents;
	React.useEffect( () => {
		if ( depositCents !== undefined ) {
			setAmount( depositCents );
		}
	}, [ depositCents, totalCents ] );

	const goToPayment = () => {
		if ( guestMode === 'new' && ! guest ) {
			const parsed = newGuestSchema.safeParse( newGuest );
			if ( ! parsed.success ) {
				const errors: Partial< Record< keyof NewGuestInput, string > > = {};
				parsed.error.issues.forEach( ( issue ) => {
					errors[ issue.path[ 0 ] as keyof NewGuestInput ] = issue.message;
				} );
				setGuestErrors( errors );
				return;
			}
			setGuestErrors( {} );
		}
		setStep( 3 );
	};

	const submit = () => {
		if ( ! quoteInput || ! quoteData ) {
			return;
		}
		create.mutate(
			{
				...quoteInput,
				guest: guest ? { id: guest.id } : { ...newGuest, country: newGuest.country.toUpperCase() },
				payment:
					paymentMode === 'outside' ? { mode: 'outside', amount_cents: amount ?? 0, method } : { mode: 'none' },
				internal_notes: notes.trim() || undefined,
			},
			{
				onSuccess: ( detail ) => {
					toast.success(
						sprintf(
							/* translators: 1: booking ref, 2: guest name. */
							__( 'Booking %1$s created for %2$s.', 'marclie-booking' ),
							detail.ref,
							detail.guest.name
						)
					);
					onClose();
					sheet.open( detail.id );
				},
				onError: ( error ) => {
					const guestFieldErrors: Partial< Record< keyof NewGuestInput, string > > = {};
					const other: string[] = [];
					const mapped = applyFieldErrors( error, ( name: string, { message } ) => {
						// `\]` must stay escaped: WP-CLI's JS parser (Peast) rejects a bare `]` in a regex, which hides this chunk's strings from make-pot.
						const field = name.replace( /^guest[.[]/, '' ).replace( /\]$/, '' );
						if ( field in newGuest ) {
							guestFieldErrors[ field as keyof NewGuestInput ] = message;
						} else if ( name === 'payment.amount_cents' || name === 'amount_cents' ) {
							setPaymentError( message );
						} else {
							other.push( message );
						}
					} );
					if ( Object.keys( guestFieldErrors ).length > 0 ) {
						setGuestErrors( guestFieldErrors );
						if ( ! guest ) {
							setGuestMode( 'new' );
							setStep( 2 );
						}
					}
					if ( ! mapped || other.length > 0 ) {
						toast.error( other[ 0 ] ?? errorMessage( error ) );
					}
				},
			}
		);
	};

	const total = quoteData?.quote.total_cents ?? 0;
	const amountInvalid = paymentMode === 'outside' && ( amount === null || amount <= 0 || amount > total );
	const guestReady = guest !== null || guestMode === 'new';

	const houses = groupByHouse( units );

	return (
		<Dialog open={ open } onOpenChange={ ( next ) => ! next && onClose() }>
			<DialogContent className="max-w-[860px] gap-5">
				<DialogHeader>
					<DialogTitle>{ __( 'New booking', 'marclie-booking' ) }</DialogTitle>
					<DialogDescription className="sr-only">{ __( 'Create a booking in three steps.', 'marclie-booking' ) }</DialogDescription>
					<div className="pt-2">
						<Stepper step={ step } />
					</div>
				</DialogHeader>

				{ step === 1 && (
					<div className="grid gap-5 md:grid-cols-[1fr_auto]">
						<div className="flex flex-col gap-4">
							<Field label={ __( 'Apartment', 'marclie-booking' ) } htmlFor="wz-unit">
								<Select value={ unitId ? String( unitId ) : undefined } onValueChange={ ( value ) => setUnitId( Number( value ) ) }>
									<SelectTrigger id="wz-unit">
										<SelectValue placeholder={ __( 'Choose an apartment', 'marclie-booking' ) } />
									</SelectTrigger>
									<SelectContent>
										{ houses.map( ( { house, units: list } ) => (
											<SelectGroup key={ house }>
												<SelectLabel>{ list[ 0 ].house }</SelectLabel>
												{ list.map( ( item ) => (
													<SelectItem key={ item.id } value={ String( item.id ) }>
														{ item.title } · { sprintf(
															/* translators: %d: capacity. */
															__( 'max %d', 'marclie-booking' ),
															item.capacity
														) }
													</SelectItem>
												) ) }
											</SelectGroup>
										) ) }
									</SelectContent>
								</Select>
							</Field>
							<div className="grid grid-cols-3 items-end gap-3">
								<Field label={ __( 'Adults (11+)', 'marclie-booking' ) } htmlFor="wz-adults">
									<Input
										id="wz-adults"
										type="number"
										inputMode="numeric"
										min={ 1 }
										value={ adultsText }
										onChange={ ( event ) => setAdultsText( event.target.value ) }
										onBlur={ () => setAdultsText( String( adults ) ) }
									/>
								</Field>
								<Field label={ __( 'Children (5–10)', 'marclie-booking' ) } htmlFor="wz-children">
									<Input
										id="wz-children"
										type="number"
										inputMode="numeric"
										min={ 0 }
										value={ childrenText }
										onChange={ ( event ) => setChildrenText( event.target.value ) }
										onBlur={ () => setChildrenText( String( children ) ) }
									/>
								</Field>
								<Field label={ __( 'Children (0–4)', 'marclie-booking' ) } htmlFor="wz-infants">
									<Input
										id="wz-infants"
										type="number"
										inputMode="numeric"
										min={ 0 }
										value={ infantsText }
										onChange={ ( event ) => setInfantsText( event.target.value ) }
										onBlur={ () => setInfantsText( String( infants ) ) }
									/>
								</Field>
							</div>
							{ overCapacity && unit && (
								<p className="text-xs text-danger">
									{ sprintf(
										/* translators: %d: capacity. */
										_n( 'This apartment sleeps at most %d guest.', 'This apartment sleeps at most %d guests.', unit.capacity, 'marclie-booking' ),
										unit.capacity
									) }
								</p>
							) }
							<Field label={ __( 'Coupon (optional)', 'marclie-booking' ) } htmlFor="wz-coupon">
								<Input id="wz-coupon" value={ coupon } onChange={ ( event ) => setCoupon( event.target.value.toUpperCase() ) } className="font-mono uppercase" />
							</Field>

							<div className="rounded-lg border border-border bg-surface-muted/40 p-4">
								{ ! quoteInput && (
									<p className="text-sm text-muted-foreground">
										{ __( 'Pick an apartment and dates to see the price.', 'marclie-booking' ) }
									</p>
								) }
								{ quoteInput && quote.isFetching && ! quoteData && (
									<div className="flex items-center gap-2 text-sm text-muted-foreground">
										<CircleNotch className="h-4 w-4 animate-spin" />
										{ __( 'Calculating…', 'marclie-booking' ) }
									</div>
								) }
								{ quoteInput && quote.isError && awaitingGuestForCoupon && (
									<div className="flex items-start gap-2 text-sm text-warning">
										<Warning className="mt-0.5 h-4 w-4 shrink-0" />
										{ __( 'This coupon is reserved for one guest. It is checked again after you choose the guest.', 'marclie-booking' ) }
									</div>
								) }
								{ quoteInput && quote.isError && ! awaitingGuestForCoupon && (
									<div className="flex items-start gap-2 text-sm text-danger">
										<Warning className="mt-0.5 h-4 w-4 shrink-0" />
										{ errorMessage( quote.error ) }
									</div>
								) }
								{ quoteData && ! quote.isError && (
									<div className={ cn( 'flex flex-col gap-1.5 text-sm', quote.isPlaceholderData && 'opacity-60' ) }>
										{ ! quoteData.available && (
											<div className="mb-1 flex items-center gap-2 font-medium text-danger">
												<Warning className="h-4 w-4" />
												{ __( 'Not available for these dates', 'marclie-booking' ) }
											</div>
										) }
										{ quoteData.quote.lines.map( ( line, index ) => (
											<div key={ index } className="flex justify-between gap-3">
												<span className="text-muted-foreground">{ quoteLineLabel( line ) }</span>
												<Money cents={ line.total_cents } />
											</div>
										) ) }
										<div className="mt-1 flex justify-between gap-3 border-t border-border pt-2 font-semibold text-ink">
											<span>{ __( 'Total', 'marclie-booking' ) }</span>
											<Money cents={ quoteData.quote.total_cents } />
										</div>
										{ ! quoteData.quote.pay_in_full && (
											<div className="flex justify-between gap-3 text-xs text-muted-foreground">
												<span>
													{ sprintf(
														/* translators: %s: balance due date. */
														__( 'Deposit now · balance due %s', 'marclie-booking' ),
														formatDate( quoteData.quote.balance_due_on )
													) }
												</span>
												<Money cents={ quoteData.quote.deposit_cents } />
											</div>
										) }
									</div>
								) }
							</div>
						</div>
						<div>
							<DateRangePicker
								inline
								value={ range }
								onChange={ setRange }
								min={ today }
								numberOfMonths={ 2 }
								isBooked={ unitId ? isBooked : undefined }
								isDisabled={ unitId ? isDisabled : undefined }
								defaultMonth={ month }
								onMonthChange={ ( next ) => setMonth( startOfMonth( next ) ) }
							/>
							<p className="mt-2 text-xs text-muted-foreground">
								{ unitId
									? __( 'Struck-through days are occupied. A stay can end on the first occupied day.', 'marclie-booking' )
									: __( 'Choose an apartment to see its availability.', 'marclie-booking' ) }
							</p>
						</div>
					</div>
				) }

				{ step === 2 && (
					<div className="flex min-h-[320px] flex-col gap-4">
						{ guest ? (
							<div className="flex items-center justify-between gap-3 rounded-lg border border-ink-soft/20 bg-surface-muted/50 px-4 py-3">
								<div>
									<div className="font-medium text-ink">{ guest.name }</div>
									<div className="text-sm text-muted-foreground">{ guest.email }</div>
								</div>
								<Button variant="ghost" size="sm" onClick={ () => setGuest( null ) }>
									<X className="h-4 w-4" />
									{ __( 'Change', 'marclie-booking' ) }
								</Button>
							</div>
						) : guestMode === 'search' ? (
							<>
								<div className="relative">
									<MagnifyingGlass className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
									<Input
										autoFocus
										value={ guestQuery }
										onChange={ ( event ) => setGuestQuery( event.target.value ) }
										placeholder={ __( 'Search existing guests by name or email…', 'marclie-booking' ) }
										aria-label={ __( 'Search guests', 'marclie-booking' ) }
										className="pl-9"
									/>
								</div>
								<div className="flex flex-col divide-y divide-border rounded-lg border border-border">
									{ searchTerm.trim().length < 2 && (
										<p className="px-4 py-6 text-center text-sm text-muted-foreground">
											{ __( 'Type at least 2 characters.', 'marclie-booking' ) }
										</p>
									) }
									{ searchTerm.trim().length >= 2 && search.isFetching && ! search.data && (
										<p className="flex items-center justify-center gap-2 px-4 py-6 text-sm text-muted-foreground">
											<CircleNotch className="h-4 w-4 animate-spin" />
											{ __( 'Searching…', 'marclie-booking' ) }
										</p>
									) }
									{ searchTerm.trim().length >= 2 && search.data && search.data.guests.length === 0 && (
										<p className="px-4 py-6 text-center text-sm text-muted-foreground">{ __( 'No guests found.', 'marclie-booking' ) }</p>
									) }
									{ searchTerm.trim().length >= 2 &&
										search.data?.guests.map( ( item ) => (
											<button
												key={ item.id }
												type="button"
												onClick={ () => setGuest( item ) }
												className="flex items-center justify-between gap-3 px-4 py-2.5 text-left text-sm hover:bg-surface-muted focus-visible:bg-surface-muted focus-visible:outline-none"
											>
												<span className="font-medium text-ink">{ item.name }</span>
												<span className="truncate text-muted-foreground">{ item.email }</span>
											</button>
										) ) }
								</div>
								<Button variant="secondary" className="self-start" onClick={ () => setGuestMode( 'new' ) }>
									<UserPlus className="h-4 w-4" />
									{ __( 'Create a new guest', 'marclie-booking' ) }
								</Button>
							</>
						) : (
							<>
								<div className="grid grid-cols-2 gap-4">
									<Field label={ __( 'First name', 'marclie-booking' ) } htmlFor="ng-first" error={ guestErrors.first_name }>
										<Input id="ng-first" autoFocus value={ newGuest.first_name } onChange={ ( event ) => setNewGuest( { ...newGuest, first_name: event.target.value } ) } />
									</Field>
									<Field label={ __( 'Last name', 'marclie-booking' ) } htmlFor="ng-last" error={ guestErrors.last_name }>
										<Input id="ng-last" value={ newGuest.last_name } onChange={ ( event ) => setNewGuest( { ...newGuest, last_name: event.target.value } ) } />
									</Field>
									<Field label={ __( 'Email', 'marclie-booking' ) } htmlFor="ng-email" error={ guestErrors.email }>
										<Input id="ng-email" type="email" value={ newGuest.email } onChange={ ( event ) => setNewGuest( { ...newGuest, email: event.target.value } ) } />
									</Field>
									<Field label={ __( 'Phone', 'marclie-booking' ) } htmlFor="ng-phone">
										<Input id="ng-phone" type="tel" value={ newGuest.phone } onChange={ ( event ) => setNewGuest( { ...newGuest, phone: event.target.value } ) } />
									</Field>
									<Field label={ __( 'Country', 'marclie-booking' ) } htmlFor="ng-country" error={ guestErrors.country } hint="HR, DE, AT…">
										<Input id="ng-country" maxLength={ 2 } className="uppercase" value={ newGuest.country } onChange={ ( event ) => setNewGuest( { ...newGuest, country: event.target.value } ) } />
									</Field>
									<Field label={ __( 'Language', 'marclie-booking' ) } htmlFor="ng-lang">
										<Select value={ newGuest.lang } onValueChange={ ( value ) => setNewGuest( { ...newGuest, lang: value as 'hr' | 'en' } ) }>
											<SelectTrigger id="ng-lang">
												<SelectValue />
											</SelectTrigger>
											<SelectContent>
												<SelectItem value="hr">Hrvatski</SelectItem>
												<SelectItem value="en">English</SelectItem>
											</SelectContent>
										</Select>
									</Field>
								</div>
								<Button variant="ghost" className="self-start" onClick={ () => setGuestMode( 'search' ) }>
									<MagnifyingGlass className="h-4 w-4" />
									{ __( 'Search existing guests instead', 'marclie-booking' ) }
								</Button>
							</>
						) }
					</div>
				) }

				{ step === 3 && ! quoteData && (
					<div className="flex min-h-[240px] flex-col items-center justify-center gap-3 text-sm">
						{ quote.isError ? (
							<Alert variant="danger" className="max-w-md">
								<Warning />
								<AlertDescription>{ errorMessage( quote.error ) }</AlertDescription>
							</Alert>
						) : (
							<span className="flex items-center gap-2 text-muted-foreground">
								<CircleNotch className="h-4 w-4 animate-spin" />
								{ __( 'Calculating…', 'marclie-booking' ) }
							</span>
						) }
					</div>
				) }

				{ step === 3 && quoteData && (
					<div className="grid gap-5 md:grid-cols-2">
						<div className="flex flex-col gap-3">
							<fieldset className="flex flex-col gap-2">
								<legend className="mb-1.5 text-[13px] font-medium text-ink-soft">{ __( 'Payment', 'marclie-booking' ) }</legend>
								{ (
									[
										{ value: 'none', label: __( 'No payment yet', 'marclie-booking' ), hint: __( 'Held as “Awaiting payment” — the hold does not expire.', 'marclie-booking' ) },
										{ value: 'outside', label: __( 'Paid outside the system', 'marclie-booking' ), hint: __( 'Cash or bank transfer already received.', 'marclie-booking' ) },
									] as const
								).map( ( option ) => (
									<label
										key={ option.value }
										className={ cn(
											'flex cursor-pointer items-start gap-3 rounded-md border px-3 py-2.5 text-sm',
											paymentMode === option.value ? 'border-ink-soft bg-surface-muted/60' : 'border-border hover:bg-surface-muted/40'
										) }
									>
										<input
											type="radio"
											name="mbk-payment-mode"
											className="mt-1"
											checked={ paymentMode === option.value }
											onChange={ () => setPaymentMode( option.value ) }
										/>
										<span>
											<span className="block font-medium text-ink">{ option.label }</span>
											<span className="block text-xs text-muted-foreground">{ option.hint }</span>
										</span>
									</label>
								) ) }
							</fieldset>
							{ paymentMode === 'outside' && (
								<div className="grid grid-cols-2 gap-3">
									<Field
										label={ __( 'Amount received', 'marclie-booking' ) }
										htmlFor="wz-amount"
										error={ amount !== null && amount > total ? __( 'More than the total.', 'marclie-booking' ) : paymentError ?? undefined }
									>
										<MoneyInput
											id="wz-amount"
											value={ amount }
											onChange={ ( cents ) => {
												setAmount( cents );
												setPaymentError( null );
											} }
										/>
									</Field>
									<Field label={ __( 'Method', 'marclie-booking' ) } htmlFor="wz-method">
										<Select value={ method } onValueChange={ ( value ) => setMethod( value as typeof method ) }>
											<SelectTrigger id="wz-method">
												<SelectValue />
											</SelectTrigger>
											<SelectContent>
												<SelectItem value="bank">{ __( 'Bank transfer', 'marclie-booking' ) }</SelectItem>
												<SelectItem value="cash">{ __( 'Cash', 'marclie-booking' ) }</SelectItem>
												<SelectItem value="other">{ __( 'Other', 'marclie-booking' ) }</SelectItem>
											</SelectContent>
										</Select>
									</Field>
									<div className="col-span-2 flex gap-2">
										<Button size="sm" variant="outline" onClick={ () => setAmount( quoteData.quote.deposit_cents ) }>
											{ __( 'Deposit', 'marclie-booking' ) } · <Money cents={ quoteData.quote.deposit_cents } />
										</Button>
										<Button size="sm" variant="outline" onClick={ () => setAmount( total ) }>
											{ __( 'Full', 'marclie-booking' ) } · <Money cents={ total } />
										</Button>
									</div>
								</div>
							) }
							<Field label={ __( 'Internal notes', 'marclie-booking' ) } htmlFor="wz-notes">
								<Textarea id="wz-notes" rows={ 3 } value={ notes } onChange={ ( event ) => setNotes( event.target.value ) } />
							</Field>
						</div>
						<div className="rounded-lg border border-border bg-surface-muted/40 p-4 text-sm">
							<h3 className="mb-3 font-semibold text-ink">{ __( 'Summary', 'marclie-booking' ) }</h3>
							<dl className="flex flex-col gap-2">
								<div className="flex justify-between gap-3">
									<dt className="text-muted-foreground">{ __( 'Guest', 'marclie-booking' ) }</dt>
									<dd className="text-right font-medium">{ guest ? guest.name : `${ newGuest.first_name } ${ newGuest.last_name }` }</dd>
								</div>
								<div className="flex justify-between gap-3">
									<dt className="text-muted-foreground">{ __( 'Apartment', 'marclie-booking' ) }</dt>
									<dd className="text-right">{ unit?.title }</dd>
								</div>
								<div className="flex justify-between gap-3">
									<dt className="text-muted-foreground">{ __( 'Dates', 'marclie-booking' ) }</dt>
									<dd className="text-right tabular">
										{ formatDate( quoteData.quote.arrival, 'day' ) } – { formatDate( quoteData.quote.departure, 'medium' ) }
									</dd>
								</div>
								<div className="flex justify-between gap-3">
									<dt className="text-muted-foreground">{ __( 'Guests', 'marclie-booking' ) }</dt>
									<dd className="text-right">
										{ adults } + { children }
									</dd>
								</div>
								{ quoteData.quote.discount_cents > 0 && (
									<div className="flex justify-between gap-3">
										<dt className="text-muted-foreground">
											{ __( 'Discount', 'marclie-booking' ) }
											{ quoteData.quote.coupon_code && <span className="ml-1 font-mono text-xs">{ quoteData.quote.coupon_code }</span> }
										</dt>
										<dd>
											<Money cents={ -quoteData.quote.discount_cents } />
										</dd>
									</div>
								) }
								<div className="mt-1 flex justify-between gap-3 border-t border-border pt-2 font-semibold text-ink">
									<dt>{ __( 'Total', 'marclie-booking' ) }</dt>
									<dd>
										<Money cents={ total } />
									</dd>
								</div>
								{ paymentMode === 'outside' && (
									<>
										<div className="flex justify-between gap-3">
											<dt className="text-muted-foreground">{ __( 'Received', 'marclie-booking' ) }</dt>
											<dd>
												<Money cents={ amount ?? 0 } />
											</dd>
										</div>
										<div className="flex justify-between gap-3">
											<dt className="text-muted-foreground">{ __( 'Outstanding', 'marclie-booking' ) }</dt>
											<dd>
												<Money cents={ Math.max( 0, total - ( amount ?? 0 ) ) } />
											</dd>
										</div>
									</>
								) }
							</dl>
							{ paymentMode === 'outside' && amount !== null && amount > 0 && amount < quoteData.quote.deposit_cents && (
								<Alert variant="warning" className="mt-3">
									<Warning />
									<AlertDescription>
										{ __( 'Less than the deposit — the booking stays “Awaiting payment”.', 'marclie-booking' ) }
									</AlertDescription>
								</Alert>
							) }
						</div>
					</div>
				) }

				<DialogFooter className="items-center sm:justify-between">
					<div className="hidden text-sm text-muted-foreground sm:block">
						{ quoteData && step > 1 && (
							<>
								{ unit?.title } · <Money cents={ total } className="font-medium text-ink" />
							</>
						) }
					</div>
					<div className="flex flex-col-reverse gap-2 sm:flex-row">
						{ step > 1 ? (
							<Button variant="secondary" onClick={ () => setStep( ( step - 1 ) as Step ) }>
								{ __( 'Back', 'marclie-booking' ) }
							</Button>
						) : (
							<Button variant="secondary" onClick={ onClose }>
								{ __( 'Cancel', 'marclie-booking' ) }
							</Button>
						) }
						{ step === 1 && (
							<Button variant="ink" onClick={ () => setStep( 2 ) } disabled={ ! stayValid }>
								{ __( 'Continue', 'marclie-booking' ) }
							</Button>
						) }
						{ step === 2 && (
							<Button variant="ink" onClick={ goToPayment } disabled={ ! guestReady }>
								{ __( 'Continue', 'marclie-booking' ) }
							</Button>
						) }
						{ step === 3 && (
							<Button onClick={ submit } disabled={ create.isPending || amountInvalid || ! quoteReady || quote.isPlaceholderData }>
								{ create.isPending && <CircleNotch className="h-4 w-4 animate-spin" /> }
								{ paymentMode === 'outside' && amount
									? sprintf(
											/* translators: %s: amount. */
											__( 'Create booking · %s received', 'marclie-booking' ),
											formatMoney( amount )
									  )
									: __( 'Create booking', 'marclie-booking' ) }
							</Button>
						) }
					</div>
				</DialogFooter>
			</DialogContent>
		</Dialog>
	);
}
