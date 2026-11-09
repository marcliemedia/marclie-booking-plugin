import { zodResolver } from '@hookform/resolvers/zod';
import { CircleNotch, Copy, Warning } from '@phosphor-icons/react';
import { __, _n, sprintf } from '@wordpress/i18n';
import * as React from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';

import { ApiError, errorMessage } from '@/api/client';
import { useBootstrapData, useUnitMap } from '@/api/hooks/bootstrap';
import {
	cancelDryRun,
	useCancelBooking,
	useChargeBooking,
	useCreateBlock,
	useCreatePaymentLink,
	useRecordPayment,
	useUpdateBooking,
	type CancelInput,
} from '@/api/hooks/bookings';
import { paymentMethodLabel } from '@/features/payments/labels';
import type { BookingDetail, CancelDecision, UpdateBookingInput } from '@/api/types';
import { ConfirmDialog } from '@/components/app/ConfirmDialog';
import { Money } from '@/components/app/Money';
import { DateRangePicker } from '@/components/app/DateRangePicker';
import { MoneyInput } from '@/components/app/MoneyInput';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Checkbox } from '@/components/ui/checkbox';
import { toast } from '@/components/ui/sonner';
import { Textarea } from '@/components/ui/textarea';
import { formatDate, formatDateTime, formatMoney } from '@/lib/format';
import { applyFieldErrors } from '@/lib/formErrors';
import { cn } from '@/lib/utils';

export function Field( {
	label,
	htmlFor,
	error,
	hint,
	className,
	children,
}: {
	label: React.ReactNode;
	htmlFor?: string;
	error?: string;
	hint?: React.ReactNode;
	className?: string;
	children: React.ReactNode;
} ) {
	const hintId = htmlFor ? `${ htmlFor }-hint` : undefined;
	const errorId = htmlFor ? `${ htmlFor }-error` : undefined;
	const describedBy = error ? errorId : hint ? hintId : undefined;
	// Link the hint/error to a single control child (inputs, select triggers, MoneyInput).
	const control =
		describedBy && React.Children.count( children ) === 1 && React.isValidElement< Record< string, unknown > >( children )
			? React.cloneElement( children, { 'aria-describedby': describedBy, ...( error ? { 'aria-invalid': true } : {} ) } )
			: children;
	return (
		<div className={ cn( 'flex flex-col gap-1.5', className ) }>
			<Label htmlFor={ htmlFor }>{ label }</Label>
			{ control }
			{ error ? (
				<p id={ errorId } className="text-xs text-danger" role="alert">
					{ error }
				</p>
			) : (
				hint && (
					<p id={ hintId } className="text-xs text-muted-foreground">
						{ hint }
					</p>
				)
			) }
		</div>
	);
}

/* ---- Edit guest ---------------------------------------------------------- */

const guestSchema = z.object( {
	first_name: z.string().trim().min( 1, __( 'Required', 'marclie-booking' ) ).max( 100 ),
	last_name: z.string().trim().min( 1, __( 'Required', 'marclie-booking' ) ).max( 100 ),
	email: z.string().trim().max( 190 ).email( __( 'Enter a valid email', 'marclie-booking' ) ),
	phone: z.string().trim().max( 40 ),
	country: z.string().trim().max( 2, __( 'Use a 2-letter country code', 'marclie-booking' ) ),
	lang: z.enum( [ 'hr', 'en' ] ),
	adults: z.number().int().min( 1 ),
	children: z.number().int().min( 0 ),
	infants: z.number().int().min( 0 ),
	arrival_time: z.string().trim().max( 20 ),
} );

type GuestForm = z.infer< typeof guestSchema >;

export function EditGuestDialog( {
	booking,
	open,
	onOpenChange,
}: {
	booking: BookingDetail;
	open: boolean;
	onOpenChange: ( open: boolean ) => void;
} ) {
	const unit = useUnitMap().get( booking.unit_id );
	const update = useUpdateBooking( booking.id );
	// The booking's own contact fields (not the guest profile's), so saving never renames the booking.
	const defaults: GuestForm = {
		first_name: booking.first_name,
		last_name: booking.last_name,
		email: booking.email,
		phone: booking.phone ?? '',
		country: booking.guest.country ?? '',
		lang: booking.lang,
		adults: booking.adults,
		children: booking.children,
		infants: booking.infants,
		arrival_time: booking.arrival_time ?? '',
	};
	const form = useForm< GuestForm >( { resolver: zodResolver( guestSchema ), defaultValues: defaults } );
	const { register, handleSubmit, formState, reset, setError, control } = form;

	React.useEffect( () => {
		if ( open ) {
			reset( defaults );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ open ] );

	const onSubmit = handleSubmit( ( values ) => {
		// Children 0–4 are free and do not count toward the capacity.
		if ( unit && values.adults + values.children > unit.capacity ) {
			setError( 'adults', {
				message: sprintf(
					/* translators: %d: max guests. */
					_n( 'This apartment sleeps at most %d guest.', 'This apartment sleeps at most %d guests.', unit.capacity, 'marclie-booking' ),
					unit.capacity
				),
			} );
			return;
		}
		// Send only what changed.
		const changes: UpdateBookingInput = {};
		( Object.keys( formState.dirtyFields ) as ( keyof GuestForm )[] ).forEach( ( key ) => {
			const value = key === 'country' ? values.country.toUpperCase() : values[ key ];
			( changes as Record< string, unknown > )[ key ] = value;
		} );
		if ( Object.keys( changes ).length === 0 ) {
			onOpenChange( false );
			return;
		}
		update.mutate( changes, {
			onSuccess: () => {
				toast.success( __( 'Guest details saved.', 'marclie-booking' ) );
				onOpenChange( false );
			},
			onError: ( error ) => {
				if ( ! applyFieldErrors( error, setError ) ) {
					toast.error( errorMessage( error ) );
				}
			},
		} );
	} );

	const errors = formState.errors;

	return (
		<Dialog open={ open } onOpenChange={ onOpenChange }>
			<DialogContent className="max-w-xl">
				<DialogHeader>
					<DialogTitle>{ __( 'Edit guest details', 'marclie-booking' ) }</DialogTitle>
					<DialogDescription>{ booking.ref }</DialogDescription>
				</DialogHeader>
				<form id="mbk-edit-guest" onSubmit={ onSubmit } className="grid grid-cols-2 gap-4" noValidate>
					<Field label={ __( 'First name', 'marclie-booking' ) } htmlFor="eg-first" error={ errors.first_name?.message }>
						<Input id="eg-first" { ...register( 'first_name' ) } aria-invalid={ !! errors.first_name } />
					</Field>
					<Field label={ __( 'Last name', 'marclie-booking' ) } htmlFor="eg-last" error={ errors.last_name?.message }>
						<Input id="eg-last" { ...register( 'last_name' ) } aria-invalid={ !! errors.last_name } />
					</Field>
					<Field label={ __( 'Email', 'marclie-booking' ) } htmlFor="eg-email" error={ errors.email?.message } className="col-span-2 sm:col-span-1">
						<Input id="eg-email" type="email" { ...register( 'email' ) } aria-invalid={ !! errors.email } />
					</Field>
					<Field label={ __( 'Phone', 'marclie-booking' ) } htmlFor="eg-phone" error={ errors.phone?.message } className="col-span-2 sm:col-span-1">
						<Input id="eg-phone" type="tel" { ...register( 'phone' ) } />
					</Field>
					<Field label={ __( 'Country', 'marclie-booking' ) } htmlFor="eg-country" error={ errors.country?.message } hint="HR, DE, AT…">
						<Input id="eg-country" maxLength={ 2 } className="uppercase" { ...register( 'country' ) } />
					</Field>
					<Field label={ __( 'Language', 'marclie-booking' ) } htmlFor="eg-lang">
						<Controller
							control={ control }
							name="lang"
							render={ ( { field } ) => (
								<Select value={ field.value } onValueChange={ field.onChange }>
									<SelectTrigger id="eg-lang">
										<SelectValue />
									</SelectTrigger>
									<SelectContent>
										<SelectItem value="hr">Hrvatski</SelectItem>
										<SelectItem value="en">English</SelectItem>
									</SelectContent>
								</Select>
							) }
						/>
					</Field>
					<Field label={ __( 'Adults (11+)', 'marclie-booking' ) } htmlFor="eg-adults" error={ errors.adults?.message }>
						<Input id="eg-adults" type="number" min={ 1 } { ...register( 'adults', { valueAsNumber: true } ) } />
					</Field>
					<Field label={ __( 'Children (5–10)', 'marclie-booking' ) } htmlFor="eg-children" error={ errors.children?.message }>
						<Input id="eg-children" type="number" min={ 0 } { ...register( 'children', { valueAsNumber: true } ) } />
					</Field>
					<Field label={ __( 'Children (0–4)', 'marclie-booking' ) } htmlFor="eg-infants" error={ errors.infants?.message }>
						<Input id="eg-infants" type="number" min={ 0 } { ...register( 'infants', { valueAsNumber: true } ) } />
					</Field>
					<Field label={ __( 'Expected arrival time', 'marclie-booking' ) } htmlFor="eg-time" className="col-span-2 sm:col-span-1">
						<Input id="eg-time" type="time" { ...register( 'arrival_time' ) } />
					</Field>
				</form>
				<DialogFooter>
					<Button variant="secondary" onClick={ () => onOpenChange( false ) }>
						{ __( 'Cancel', 'marclie-booking' ) }
					</Button>
					<Button type="submit" form="mbk-edit-guest" disabled={ update.isPending }>
						{ update.isPending && <CircleNotch className="h-4 w-4 animate-spin" /> }
						{ __( 'Save changes', 'marclie-booking' ) }
					</Button>
				</DialogFooter>
			</DialogContent>
		</Dialog>
	);
}

/* ---- Record outside payment --------------------------------------------- */

export function RecordPaymentDialog( {
	booking,
	open,
	onOpenChange,
}: {
	booking: BookingDetail;
	open: boolean;
	onOpenChange: ( open: boolean ) => void;
} ) {
	const record = useRecordPayment( booking.id );
	const deposit = booking.quote?.deposit_cents ?? 0;
	const defaultKind = booking.paid_cents === 0 ? ( deposit > 0 && deposit < booking.total_cents ? 'deposit' : 'full' ) : 'balance';
	const [ kind, setKind ] = React.useState< 'deposit' | 'balance' | 'full' >( defaultKind );
	const [ amount, setAmount ] = React.useState< number | null >( null );
	const [ method, setMethod ] = React.useState< 'cash' | 'bank' | 'other' >( 'bank' );
	const [ note, setNote ] = React.useState( '' );
	const [ confirming, setConfirming ] = React.useState( false );
	const [ fieldErrors, setFieldErrors ] = React.useState< Record< string, string > >( {} );
	// One key per dialog session: a double submit or retry can never record the payment twice.
	const idempotencyKey = React.useRef( '' );

	const suggested = ( k: typeof kind ) => ( k === 'deposit' ? Math.min( deposit, booking.balance_cents ) : booking.balance_cents );

	React.useEffect( () => {
		if ( open ) {
			setKind( defaultKind );
			setAmount( suggested( defaultKind ) );
			setMethod( 'bank' );
			setNote( '' );
			setFieldErrors( {} );
			idempotencyKey.current = window.crypto.randomUUID();
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ open ] );

	const invalid = amount === null || amount <= 0;
	const over = amount !== null && amount > booking.balance_cents;

	const submit = () =>
		record
			.mutateAsync( {
				kind,
				amount_cents: amount ?? 0,
				method,
				note: note.trim() || undefined,
				idempotency_key: idempotencyKey.current,
			} )
			.then( ( detail ) => {
				toast.success(
					sprintf(
						/* translators: 1: amount, 2: booking ref. */
						__( 'Payment of %1$s recorded for %2$s.', 'marclie-booking' ),
						formatMoney( amount ?? 0 ),
						detail.ref
					)
				);
				onOpenChange( false );
			} )
			.catch( ( error ) => {
				const errors: Record< string, string > = {};
				if ( applyFieldErrors( error, ( name, { message } ) => ( errors[ name ] = message ), { amount_cents: 'amount' } ) ) {
					// Back to the form so the field errors are visible.
					setFieldErrors( errors );
					setConfirming( false );
					return;
				}
				toast.error( errorMessage( error ) );
				throw error;
			} );

	const methodLabel = { cash: __( 'Cash', 'marclie-booking' ), bank: __( 'Bank transfer', 'marclie-booking' ), other: __( 'Other', 'marclie-booking' ) }[ method ];

	return (
		<>
			<Dialog open={ open && ! confirming } onOpenChange={ ( next ) => ! confirming && onOpenChange( next ) }>
				<DialogContent>
					<DialogHeader>
						<DialogTitle>{ __( 'Record outside payment', 'marclie-booking' ) }</DialogTitle>
						<DialogDescription>
							{ __( 'Money received outside Stripe (cash, bank transfer). It is added to the booking balance.', 'marclie-booking' ) }
						</DialogDescription>
					</DialogHeader>
					<div className="grid grid-cols-2 gap-4">
						<Field label={ __( 'Type', 'marclie-booking' ) } htmlFor="rp-kind" error={ fieldErrors.kind }>
							<Select
								value={ kind }
								onValueChange={ ( value ) => {
									const next = value as typeof kind;
									setKind( next );
									setAmount( suggested( next ) );
								} }
							>
								<SelectTrigger id="rp-kind">
									<SelectValue />
								</SelectTrigger>
								<SelectContent>
									<SelectItem value="deposit">{ __( 'Deposit', 'marclie-booking' ) }</SelectItem>
									<SelectItem value="balance">{ __( 'Balance', 'marclie-booking' ) }</SelectItem>
									<SelectItem value="full">{ __( 'Full payment', 'marclie-booking' ) }</SelectItem>
								</SelectContent>
							</Select>
						</Field>
						<Field label={ __( 'Method', 'marclie-booking' ) } htmlFor="rp-method" error={ fieldErrors.method }>
							<Select value={ method } onValueChange={ ( value ) => setMethod( value as typeof method ) }>
								<SelectTrigger id="rp-method">
									<SelectValue />
								</SelectTrigger>
								<SelectContent>
									<SelectItem value="bank">{ __( 'Bank transfer', 'marclie-booking' ) }</SelectItem>
									<SelectItem value="cash">{ __( 'Cash', 'marclie-booking' ) }</SelectItem>
									<SelectItem value="other">{ __( 'Other', 'marclie-booking' ) }</SelectItem>
								</SelectContent>
							</Select>
						</Field>
						<Field
							label={ __( 'Amount', 'marclie-booking' ) }
							htmlFor="rp-amount"
							className="col-span-2"
							error={ over ? __( 'Amount is higher than the outstanding balance.', 'marclie-booking' ) : fieldErrors.amount }
							hint={ sprintf(
								/* translators: %s: amount. */
								__( 'Outstanding: %s', 'marclie-booking' ),
								formatMoney( booking.balance_cents )
							) }
						>
							<MoneyInput
								id="rp-amount"
								value={ amount }
								onChange={ ( cents ) => {
									setAmount( cents );
									setFieldErrors( ( prev ) => ( { ...prev, amount: '' } ) );
								} }
							/>
						</Field>
						<Field label={ __( 'Note', 'marclie-booking' ) } htmlFor="rp-note" className="col-span-2" error={ fieldErrors.note }>
							<Input id="rp-note" maxLength={ 500 } value={ note } onChange={ ( event ) => setNote( event.target.value ) } placeholder={ __( 'e.g. bank reference', 'marclie-booking' ) } />
						</Field>
					</div>
					<DialogFooter>
						<Button variant="secondary" onClick={ () => onOpenChange( false ) }>
							{ __( 'Cancel', 'marclie-booking' ) }
						</Button>
						<Button onClick={ () => setConfirming( true ) } disabled={ invalid || over }>
							{ __( 'Continue', 'marclie-booking' ) }
						</Button>
					</DialogFooter>
				</DialogContent>
			</Dialog>
			<ConfirmDialog
				open={ confirming }
				onOpenChange={ ( next ) => {
					setConfirming( next );
				} }
				title={ __( 'Record this payment?', 'marclie-booking' ) }
				description={ sprintf(
					/* translators: 1: method, 2: booking ref. */
					__( '%1$s for booking %2$s. This cannot be undone from the admin.', 'marclie-booking' ),
					methodLabel,
					booking.ref
				) }
				amounts={ [
					{ label: __( 'Received now', 'marclie-booking' ), cents: amount ?? 0, strong: true },
					{ label: __( 'Paid so far', 'marclie-booking' ), cents: booking.paid_cents },
					{ label: __( 'Outstanding after', 'marclie-booking' ), cents: Math.max( 0, booking.balance_cents - ( amount ?? 0 ) ) },
				] }
				confirmLabel={ sprintf(
					/* translators: %s: amount. */
					__( 'Record %s', 'marclie-booking' ),
					formatMoney( amount ?? 0 )
				) }
				onConfirm={ submit }
			/>
		</>
	);
}

/* ---- Cancel booking ------------------------------------------------------ */

export function CancelDialog( {
	booking,
	open,
	onOpenChange,
}: {
	booking: BookingDetail;
	open: boolean;
	onOpenChange: ( open: boolean ) => void;
} ) {
	const { caps } = useBootstrapData();
	const cancel = useCancelBooking( booking.id );
	const [ mode, setMode ] = React.useState< CancelInput[ 'refund_mode' ] >( 'policy' );
	const [ amount, setAmount ] = React.useState< number | null >( null );
	const [ reason, setReason ] = React.useState( '' );
	const [ decision, setDecision ] = React.useState< CancelDecision | null >( null );
	const [ previewError, setPreviewError ] = React.useState< string | null >( null );
	const [ loadingPreview, setLoadingPreview ] = React.useState( false );
	const [ fieldErrors, setFieldErrors ] = React.useState< Record< string, string > >( {} );

	React.useEffect( () => {
		if ( open ) {
			setMode( 'policy' );
			setAmount( null );
			setReason( '' );
			setDecision( null );
			setPreviewError( null );
			setFieldErrors( {} );
		}
	}, [ open ] );

	// Dry run so the dialog shows the exact refund the server will apply. Until it answers
	// for the current inputs there is no decision, and confirming is disabled.
	React.useEffect( () => {
		if ( ! open ) {
			return;
		}
		let active = true;
		setLoadingPreview( true );
		setDecision( null );
		setPreviewError( null );
		const timer = window.setTimeout( () => {
			cancelDryRun( booking.id, {
				refund_mode: mode,
				amount_cents: mode === 'custom' ? amount ?? 0 : undefined,
				reason: reason.trim() || 'preview',
			} )
				.then( ( result ) => active && setDecision( result.decision ) )
				.catch( ( error ) => active && setPreviewError( errorMessage( error ) ) )
				.finally( () => active && setLoadingPreview( false ) );
		}, mode === 'custom' ? 400 : 0 );
		return () => {
			active = false;
			window.clearTimeout( timer );
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ open, mode, amount, booking.id ] );

	const paid = decision?.paid_cents ?? booking.paid_cents;
	const refund = mode === 'none' ? 0 : decision?.refund_cents ?? 0;
	const customAmount = amount ?? 0;
	const overPolicy = mode === 'custom' && decision !== null && customAmount > decision.refundable_cents;
	const needsReason = mode === 'custom';
	const blocked =
		previewError !== null ||
		decision === null ||
		( needsReason && reason.trim() === '' ) ||
		( overPolicy && ! caps.manage_settings ) ||
		( mode === 'custom' && ( customAmount < 0 || customAmount > paid ) );

	const submit = () =>
		cancel
			.mutateAsync( {
				refund_mode: mode,
				amount_cents: mode === 'custom' ? customAmount : undefined,
				reason: reason.trim() || undefined,
			} )
			.then( ( result ) => {
				toast.success(
					result.decision.refund_cents > 0
						? sprintf(
								/* translators: 1: booking ref, 2: refund amount. */
								__( 'Booking %1$s cancelled. Refund due: %2$s.', 'marclie-booking' ),
								result.booking.ref,
								formatMoney( result.decision.refund_cents )
						  )
						: sprintf(
								/* translators: %s: booking ref. */
								__( 'Booking %s cancelled.', 'marclie-booking' ),
								result.booking.ref
						  )
				);
			} )
			.catch( ( error ) => {
				const errors: Record< string, string > = {};
				if ( ! applyFieldErrors( error, ( name, { message } ) => ( errors[ name ] = message ), { amount_cents: 'amount' } ) ) {
					toast.error( errorMessage( error ) );
				}
				setFieldErrors( errors );
				throw error;
			} );

	const modes: { value: CancelInput[ 'refund_mode' ]; label: string; hint: string }[] = [
		{
			value: 'policy',
			label: __( 'Refund per policy', 'marclie-booking' ),
			hint: booking.free_cancel_until
				? sprintf(
						/* translators: %s: date. */
						__( 'Free cancellation until %s', 'marclie-booking' ),
						formatDate( booking.free_cancel_until )
				  )
				: __( 'Uses the booking’s cancellation policy', 'marclie-booking' ),
		},
		{ value: 'none', label: __( 'No refund', 'marclie-booking' ), hint: __( 'Guest gets nothing back', 'marclie-booking' ) },
		{ value: 'custom', label: __( 'Custom amount', 'marclie-booking' ), hint: __( 'Requires a reason', 'marclie-booking' ) },
	];

	return (
		<ConfirmDialog
			open={ open }
			onOpenChange={ onOpenChange }
			title={ sprintf(
				/* translators: %s: booking ref. */
				__( 'Cancel booking %s?', 'marclie-booking' ),
				booking.ref
			) }
			description={ __( 'The dates are released immediately and the guest receives the cancellation email.', 'marclie-booking' ) }
			amounts={
				decision
					? [
							{ label: __( 'Paid by guest', 'marclie-booking' ), cents: paid },
							{ label: __( 'Refundable per policy', 'marclie-booking' ), cents: decision.refundable_cents },
							{ label: __( 'Refund', 'marclie-booking' ), cents: refund, strong: true },
					  ]
					: undefined
			}
			variant="destructive"
			confirmLabel={
				refund > 0
					? sprintf(
							/* translators: %s: amount. */
							__( 'Cancel and refund %s', 'marclie-booking' ),
							formatMoney( refund )
					  )
					: __( 'Cancel booking', 'marclie-booking' )
			}
			confirmDisabled={ blocked || loadingPreview }
			onConfirm={ submit }
		>
			{ ! decision && loadingPreview && (
				<div className="flex items-center gap-2 text-sm text-muted-foreground">
					<CircleNotch className="h-4 w-4 animate-spin" />
					{ __( 'Calculating the refund…', 'marclie-booking' ) }
				</div>
			) }
			<fieldset className="flex flex-col gap-2">
				<legend className="mb-1.5 text-[13px] font-medium text-ink-soft">{ __( 'Refund', 'marclie-booking' ) }</legend>
				{ modes.map( ( option ) => (
					<label
						key={ option.value }
						className={ cn(
							'flex cursor-pointer items-start gap-3 rounded-md border px-3 py-2 text-sm transition-colors',
							mode === option.value ? 'border-ink-soft bg-surface-muted/60' : 'border-border hover:bg-surface-muted/40'
						) }
					>
						<input
							type="radio"
							name="mbk-refund-mode"
							className="mt-1 accent-[hsl(var(--ink-soft))]"
							checked={ mode === option.value }
							onChange={ () => setMode( option.value ) }
						/>
						<span>
							<span className="block font-medium text-ink">{ option.label }</span>
							<span className="block text-xs text-muted-foreground">{ option.hint }</span>
						</span>
					</label>
				) ) }
			</fieldset>
			{ mode === 'custom' && (
				<Field
					label={ __( 'Refund amount', 'marclie-booking' ) }
					htmlFor="cx-amount"
					error={
						customAmount < 0
							? __( 'The refund cannot be negative.', 'marclie-booking' )
							: customAmount > paid
							? __( 'Cannot refund more than was paid.', 'marclie-booking' )
							: overPolicy && ! caps.manage_settings
							? __( 'Refunds above the policy need an administrator.', 'marclie-booking' )
							: fieldErrors.amount
					}
					hint={ overPolicy ? __( 'Above the policy amount.', 'marclie-booking' ) : undefined }
				>
					<MoneyInput id="cx-amount" value={ amount } onChange={ setAmount } />
				</Field>
			) }
			<Field
				label={ needsReason ? __( 'Reason (required)', 'marclie-booking' ) : __( 'Reason', 'marclie-booking' ) }
				htmlFor="cx-reason"
				error={ fieldErrors.reason }
			>
				<Textarea id="cx-reason" rows={ 2 } maxLength={ 500 } value={ reason } onChange={ ( event ) => setReason( event.target.value ) } />
			</Field>
			{ refund > 0 && ( decision?.refunds?.length ?? 0 ) > 0 && (
				<div className="rounded-md border border-border px-3 py-2 text-sm">
					<div className="mb-1 text-[13px] font-medium text-ink-soft">{ __( 'Refund breakdown', 'marclie-booking' ) }</div>
					<ul className="flex flex-col gap-1">
						{ decision?.refunds?.map( ( line, index ) => (
							<li key={ line.payment_id ?? index } className="flex items-center justify-between gap-3">
								<span className="text-ink">
									{ line.method === 'stripe'
										? line.card_last4
											? sprintf(
													/* translators: 1: card brand, 2: last four digits. */
													__( 'Card %1$s •••• %2$s (via Stripe)', 'marclie-booking' ),
													line.card_brand ?? '',
													line.card_last4
											  )
											: __( 'Card (via Stripe)', 'marclie-booking' )
										: sprintf(
												/* translators: %s: payment method (cash, bank transfer…). */
												__( '%s — pay back manually', 'marclie-booking' ),
												paymentMethodLabel( line.method )
										  ) }
								</span>
								<Money cents={ line.amount_cents } className="tabular font-medium" />
							</li>
						) ) }
					</ul>
					{ decision?.refunds?.some( ( line ) => line.method === 'stripe' ) && (
						<p className="mt-2 text-xs text-muted-foreground">
							{ __( 'Card refunds are sent through Stripe immediately; the guest usually sees the money in 5–10 business days.', 'marclie-booking' ) }
						</p>
					) }
				</div>
			) }
			{ previewError && (
				<Alert variant="danger">
					<Warning />
					<AlertDescription>{ previewError }</AlertDescription>
				</Alert>
			) }
		</ConfirmDialog>
	);
}

/* ---- Charge saved card now ----------------------------------------------- */

/** Card of the latest received Stripe charge (shown in the charge confirmation). */
function lastCard( booking: BookingDetail ): { brand: string; last4: string } | null {
	const charge = [ ...booking.payments ]
		.reverse()
		.find( ( p ) => p.method === 'stripe' && p.kind !== 'refund' && p.card_last4 && ( p.status === 'succeeded' || p.status === 'refunded' ) );
	return charge?.card_last4 ? { brand: charge.card_brand ?? '', last4: charge.card_last4 } : null;
}

export function ChargeDialog( {
	booking,
	open,
	onOpenChange,
	onSendLink,
}: {
	booking: BookingDetail;
	open: boolean;
	onOpenChange: ( open: boolean ) => void;
	onSendLink: () => void;
} ) {
	const charge = useChargeBooking( booking.id );
	// 402 card_requires_action / card_declined: the booking is now balance_failed; offer a payment link.
	const [ cardFailure, setCardFailure ] = React.useState< 'card_requires_action' | 'card_declined' | null >( null );
	const [ error, setError ] = React.useState< string | null >( null );
	// 502 payment_error with outcome_unknown: Stripe did not answer, so retrying could charge twice.
	const [ outcomeUnknown, setOutcomeUnknown ] = React.useState( false );
	const card = lastCard( booking );
	const amount = booking.balance_cents;

	React.useEffect( () => {
		if ( open ) {
			setCardFailure( null );
			setError( null );
			setOutcomeUnknown( false );
		}
	}, [ open ] );

	const submit = () =>
		charge.mutateAsync().then(
			( detail ) => {
				toast.success(
					sprintf(
						/* translators: 1: amount, 2: booking ref. */
						__( 'Charged %1$s for %2$s.', 'marclie-booking' ),
						formatMoney( amount ),
						detail.ref
					)
				);
			},
			( err ) => {
				const code = err instanceof ApiError ? err.code : '';
				const failure = code === 'card_requires_action' || code === 'card_declined' ? code : null;
				const unknown = err instanceof ApiError && code === 'payment_error' && err.data.outcome_unknown === true;
				setCardFailure( failure );
				setOutcomeUnknown( unknown );
				setError( failure || unknown ? null : errorMessage( err ) );
				throw err;
			}
		);

	return (
		<ConfirmDialog
			open={ open }
			onOpenChange={ onOpenChange }
			title={ sprintf(
				/* translators: %s: booking ref. */
				__( 'Charge the outstanding amount for %s?', 'marclie-booking' ),
				booking.ref
			) }
			description={
				card
					? sprintf(
							/* translators: 1: card brand, 2: last four digits. */
							__( 'The guest’s saved card %1$s •••• %2$s is charged immediately.', 'marclie-booking' ),
							card.brand,
							card.last4
					  )
					: __( 'The card saved with this booking is charged immediately.', 'marclie-booking' )
			}
			amounts={ [
				{ label: __( 'Total', 'marclie-booking' ), cents: booking.total_cents },
				{ label: __( 'Paid so far', 'marclie-booking' ), cents: booking.paid_cents },
				{ label: __( 'Charge now', 'marclie-booking' ), cents: amount, strong: true },
			] }
			confirmLabel={
				cardFailure
					? __( 'Try again', 'marclie-booking' )
					: sprintf(
							/* translators: %s: amount. */
							__( 'Charge %s', 'marclie-booking' ),
							formatMoney( amount )
					  )
			}
			confirmDisabled={ amount <= 0 || outcomeUnknown }
			onConfirm={ submit }
		>
			{ outcomeUnknown && (
				<Alert variant="warning">
					<Warning />
					<AlertDescription>
						{ __( 'Stripe did not answer; the result is being checked. Do not charge again — the booking updates automatically once Stripe confirms.', 'marclie-booking' ) }
					</AlertDescription>
				</Alert>
			) }
			{ cardFailure && (
				<Alert variant="warning">
					<Warning />
					<AlertDescription>
						<p>
							{ cardFailure === 'card_requires_action'
								? __( 'The bank asks the guest to confirm this payment (3-D Secure), so it cannot be charged without them. The booking is marked “Payment failed”. Send the guest a payment link so they can pay themselves.', 'marclie-booking' )
								: __( 'The card was declined. The booking is marked “Payment failed”. Send the guest a payment link so they can pay with another card.', 'marclie-booking' ) }
						</p>
						<Button
							size="sm"
							variant="secondary"
							className="mt-2"
							onClick={ () => {
								onOpenChange( false );
								onSendLink();
							} }
						>
							{ __( 'Create payment link', 'marclie-booking' ) }
						</Button>
					</AlertDescription>
				</Alert>
			) }
			{ error && (
				<Alert variant="danger">
					<Warning />
					<AlertDescription>{ error }</AlertDescription>
				</Alert>
			) }
		</ConfirmDialog>
	);
}

/* ---- Payment link -------------------------------------------------------- */

export function PaymentLinkDialog( {
	booking,
	open,
	onOpenChange,
}: {
	booking: BookingDetail;
	open: boolean;
	onOpenChange: ( open: boolean ) => void;
} ) {
	const create = useCreatePaymentLink( booking.id );
	const [ days, setDays ] = React.useState( '7' );
	const [ sendEmail, setSendEmail ] = React.useState( true );
	const [ link, setLink ] = React.useState< { url: string; expires_at: string } | null >( null );
	const [ fieldError, setFieldError ] = React.useState< string | undefined >();
	const canEmail = booking.email !== '';

	React.useEffect( () => {
		if ( open ) {
			setDays( '7' );
			setSendEmail( true );
			setLink( null );
			setFieldError( undefined );
		}
	}, [ open ] );

	const daysNum = Number( days );
	const invalid = ! Number.isInteger( daysNum ) || daysNum < 1 || daysNum > 30;
	const emailIt = sendEmail && canEmail;

	const submit = () =>
		create
			.mutateAsync( { expires_days: daysNum, send_email: emailIt } )
			.then( ( result ) => {
				setLink( result );
				if ( emailIt && result.email_sent === false ) {
					toast.error( __( 'The link was created, but the e-mail could not be sent. Copy the link and send it yourself.', 'marclie-booking' ) );
				} else if ( emailIt ) {
					toast.success(
						sprintf(
							/* translators: %s: e-mail address. */
							__( 'Payment link e-mailed to %s.', 'marclie-booking' ),
							booking.email
						)
					);
				}
			} )
			.catch( ( error ) => {
				if ( ! applyFieldErrors( error, ( _name, { message } ) => setFieldError( message ) ) ) {
					toast.error( errorMessage( error ) );
				}
			} );

	const copy = () =>
		link &&
		navigator.clipboard.writeText( link.url ).then(
			() => toast.success( __( 'Link copied.', 'marclie-booking' ) ),
			() => toast.error( __( 'Could not copy — select the link and copy it manually.', 'marclie-booking' ) )
		);

	return (
		<Dialog open={ open } onOpenChange={ onOpenChange }>
			<DialogContent>
				<DialogHeader>
					<DialogTitle>{ __( 'Payment link', 'marclie-booking' ) }</DialogTitle>
					<DialogDescription>
						{ sprintf(
							/* translators: %s: amount. */
							__( 'A secure page where the guest pays the outstanding %s by card.', 'marclie-booking' ),
							formatMoney( booking.balance_cents )
						) }
					</DialogDescription>
				</DialogHeader>
				{ link ? (
					<div className="flex flex-col gap-2">
						<Label htmlFor="pl-url">{ __( 'Link', 'marclie-booking' ) }</Label>
						<div className="flex gap-2">
							<Input id="pl-url" readOnly value={ link.url } onFocus={ ( event ) => event.target.select() } className="font-mono text-xs" />
							<Button variant="secondary" onClick={ copy }>
								<Copy className="h-4 w-4" />
								{ __( 'Copy', 'marclie-booking' ) }
							</Button>
						</div>
						<p className="text-xs text-muted-foreground">
							{ sprintf(
								/* translators: %s: date and time. */
								__( 'Valid until %s.', 'marclie-booking' ),
								formatDateTime( link.expires_at )
							) }
						</p>
					</div>
				) : (
					<div className="flex flex-col gap-4">
						<Field
							label={ __( 'Valid for (days)', 'marclie-booking' ) }
							htmlFor="pl-days"
							error={ invalid ? __( 'Enter 1 to 30 days.', 'marclie-booking' ) : fieldError }
						>
							<Input id="pl-days" type="number" min={ 1 } max={ 30 } value={ days } onChange={ ( event ) => setDays( event.target.value ) } />
						</Field>
						<div className="flex items-start gap-2">
							<Checkbox
								id="pl-send"
								checked={ emailIt }
								disabled={ ! canEmail }
								onCheckedChange={ ( checked ) => setSendEmail( checked === true ) }
								className="mt-0.5"
							/>
							<Label htmlFor="pl-send" className="font-normal leading-snug">
								{ canEmail
									? sprintf(
											/* translators: %s: e-mail address. */
											__( 'Send link by e-mail to %s', 'marclie-booking' ),
											booking.email
									  )
									: __( 'The booking has no e-mail address — copy the link and send it yourself.', 'marclie-booking' ) }
							</Label>
						</div>
					</div>
				) }
				<DialogFooter>
					<Button variant="secondary" onClick={ () => onOpenChange( false ) }>
						{ link ? __( 'Done', 'marclie-booking' ) : __( 'Cancel', 'marclie-booking' ) }
					</Button>
					{ ! link && (
						<Button onClick={ submit } disabled={ invalid || create.isPending }>
							{ create.isPending && <CircleNotch className="h-4 w-4 animate-spin" /> }
							{ emailIt ? __( 'Create and send', 'marclie-booking' ) : __( 'Create link', 'marclie-booking' ) }
						</Button>
					) }
				</DialogFooter>
			</DialogContent>
		</Dialog>
	);
}

/* ---- Block dates --------------------------------------------------------- */

export function BlockDialog( {
	unitId,
	from,
	to,
	open,
	onOpenChange,
}: {
	unitId: number;
	from?: string;
	to?: string;
	open: boolean;
	onOpenChange: ( open: boolean ) => void;
} ) {
	const { units, today } = useBootstrapData();
	const create = useCreateBlock();
	const [ selected, setSelected ] = React.useState< number[] >( [ unitId ] );
	const [ range, setRange ] = React.useState< { from?: string; to?: string } >( { from, to } );
	const [ reason, setReason ] = React.useState( '' );
	const [ fieldErrors, setFieldErrors ] = React.useState< Record< string, string > >( {} );

	const houses = React.useMemo( () => {
		const map = new Map< string, typeof units >();
		for ( const item of units ) {
			map.set( item.house, [ ...( map.get( item.house ) ?? [] ), item ] );
		}
		return [ ...map.entries() ];
	}, [ units ] );

	React.useEffect( () => {
		if ( open ) {
			setSelected( [ unitId ] );
			setRange( { from, to } );
			setReason( '' );
			setFieldErrors( {} );
		}
	}, [ open, unitId, from, to ] );

	const allSelected = selected.length === units.length;

	const submit = () => {
		if ( ! range.from || ! range.to || selected.length === 0 ) {
			return;
		}
		setFieldErrors( {} );
		// Keep the bootstrap (house) order so the server reports conflicts predictably.
		const unitIds = units.filter( ( item ) => selected.includes( item.id ) ).map( ( item ) => item.id );
		create.mutate(
			{ unit_ids: unitIds, date_from: range.from, date_to: range.to, reason: reason.trim() },
			{
				onSuccess: ( { blocks } ) => {
					toast.success(
						sprintf(
							/* translators: %d: number of apartments. */
							_n( 'Dates blocked in %d apartment.', 'Dates blocked in %d apartments.', blocks.length, 'marclie-booking' ),
							blocks.length
						)
					);
					onOpenChange( false );
				},
				onError: ( error ) => {
					const errors: Record< string, string > = {};
					const mapped = applyFieldErrors( error, ( name, { message } ) => {
						// `unit_ids` and `unit_ids.{i}` both belong to the apartment list.
						errors[ name.startsWith( 'unit_ids' ) ? 'units' : name ] = message;
					}, {
						date_from: 'dates',
						date_to: 'dates',
					} );
					setFieldErrors( errors );
					if ( ! mapped ) {
						toast.error( errorMessage( error ) );
					}
				},
			}
		);
	};

	return (
		<Dialog open={ open } onOpenChange={ onOpenChange }>
			<DialogContent>
				<DialogHeader>
					<DialogTitle>{ __( 'Block dates', 'marclie-booking' ) }</DialogTitle>
					<DialogDescription>
						{ __(
							'Blocked nights cannot be booked online. Use it to close days (e.g. before the season) or for owner stays. If any selected apartment is already taken on these dates, nothing is blocked.',
							'marclie-booking'
						) }
					</DialogDescription>
				</DialogHeader>
				<div className="flex flex-col gap-4">
					<fieldset className="flex flex-col gap-2" aria-describedby={ fieldErrors.units ? 'bl-units-error' : undefined }>
						<legend className="mb-1.5 text-sm font-medium">{ __( 'Apartments', 'marclie-booking' ) }</legend>
						<div className="flex items-center gap-2">
							<Checkbox
								id="bl-all-units"
								checked={ allSelected ? true : selected.length > 0 ? 'indeterminate' : false }
								onCheckedChange={ ( checked ) => setSelected( checked === true ? units.map( ( item ) => item.id ) : [] ) }
							/>
							<Label htmlFor="bl-all-units">{ __( 'All apartments', 'marclie-booking' ) }</Label>
						</div>
						<div className="grid max-h-60 gap-3 overflow-y-auto rounded-md border border-border p-3 sm:grid-cols-2">
							{ houses.map( ( [ house, list ] ) => (
								<div key={ house } className="flex flex-col gap-1.5">
									<p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{ house }</p>
									{ list.map( ( item ) => (
										<div key={ item.id } className="flex items-center gap-2">
											<Checkbox
												id={ `bl-unit-${ item.id }` }
												checked={ selected.includes( item.id ) }
												onCheckedChange={ ( checked ) =>
													setSelected( ( prev ) => [ ...prev.filter( ( x ) => x !== item.id ), ...( checked === true ? [ item.id ] : [] ) ] )
												}
											/>
											<Label htmlFor={ `bl-unit-${ item.id }` } className="font-normal">
												{ item.title }
											</Label>
										</div>
									) ) }
								</div>
							) ) }
						</div>
						{ fieldErrors.units ? (
							<p id="bl-units-error" className="text-xs text-danger" role="alert">
								{ fieldErrors.units }
							</p>
						) : (
							selected.length === 0 && (
								<p className="text-xs text-muted-foreground">{ __( 'Select at least one apartment.', 'marclie-booking' ) }</p>
							)
						) }
					</fieldset>
					<Field
						label={ __( 'Dates', 'marclie-booking' ) }
						htmlFor="bl-dates"
						error={ fieldErrors.dates }
						hint={ __( 'Pick the first blocked night and the day the block ends.', 'marclie-booking' ) }
					>
						<DateRangePicker id="bl-dates" value={ range } onChange={ setRange } showNights min={ today } className="w-full" />
					</Field>
					<Field label={ __( 'Reason', 'marclie-booking' ) } htmlFor="bl-reason" error={ fieldErrors.reason }>
						<Input
							id="bl-reason"
							maxLength={ 255 }
							value={ reason }
							onChange={ ( event ) => setReason( event.target.value ) }
							placeholder={ __( 'e.g. Owner stay, maintenance', 'marclie-booking' ) }
						/>
					</Field>
				</div>
				<DialogFooter>
					<Button variant="secondary" onClick={ () => onOpenChange( false ) }>
						{ __( 'Cancel', 'marclie-booking' ) }
					</Button>
					<Button variant="ink" onClick={ submit } disabled={ ! range.from || ! range.to || selected.length === 0 || create.isPending }>
						{ create.isPending && <CircleNotch className="h-4 w-4 animate-spin" /> }
						{ __( 'Block dates', 'marclie-booking' ) }
					</Button>
				</DialogFooter>
			</DialogContent>
		</Dialog>
	);
}
