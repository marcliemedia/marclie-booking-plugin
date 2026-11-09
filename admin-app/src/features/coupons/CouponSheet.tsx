import { zodResolver } from '@hookform/resolvers/zod';
import { ArrowsClockwise, Trash } from '@phosphor-icons/react';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';

import { useBootstrapData } from '@/api/hooks/bootstrap';
import type { CouponInput, CouponWithStats } from '@/api/hooks/coupons';
import { generateCouponCode, useCouponRedemptions, useDeleteCoupon, useSaveCoupon } from '@/api/hooks/coupons';
import { useGuest } from '@/api/hooks/guests';
import { ConfirmDialog } from '@/components/app/ConfirmDialog';
import { DateRangePicker } from '@/components/app/DateRangePicker';
import { Money } from '@/components/app/Money';
import { MoneyInput } from '@/components/app/MoneyInput';
import { StatusBadge } from '@/components/app/StatusBadge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Sheet, SheetBody, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { toast } from '@/components/ui/sonner';
import { Switch } from '@/components/ui/switch';
import { useBookingSheet } from '@/features/bookings/sheet';
import { FormField } from '@/features/common/FormField';
import type { PickedGuest } from '@/features/common/GuestPicker';
import { GuestPicker, guestName } from '@/features/common/GuestPicker';
import { toastError } from '@/features/common/toastError';
import { applyFieldErrors } from '@/lib/formErrors';
import { formatDate, formatDateRange, formatNumber } from '@/lib/format';

const optionalInt = z.union( [ z.literal( '' ), z.coerce.number().int().min( 1 ).max( 100000 ) ] );

const schema = z
	.object( {
		code: z
			.string()
			.trim()
			.min( 3, __( 'At least 3 characters.', 'marclie-booking' ) )
			.max( 50 )
			.regex( /^[A-Za-z0-9_-]+$/, __( 'Letters, digits, - and _ only.', 'marclie-booking' ) )
			.transform( ( v ) => v.toUpperCase() ),
		type: z.enum( [ 'pct', 'fixed' ] ),
		pct: z.string(),
		fixed: z.number().nullable(),
		book_from: z.string().optional(),
		book_to: z.string().optional(),
		stay_from: z.string().optional(),
		stay_to: z.string().optional(),
		min_nights: optionalInt,
		unit_ids: z.array( z.number() ).nullable(),
		usage_limit: optionalInt,
		per_guest_limit: optionalInt,
		guest: z.object( { id: z.number(), name: z.string(), email: z.string() } ).nullable(),
		active: z.boolean(),
	} )
	.superRefine( ( v, ctx ) => {
		if ( v.type === 'pct' ) {
			const n = Number( v.pct );
			if ( ! /^\d+$/.test( v.pct.trim() ) || n < 1 || n > 100 ) {
				ctx.addIssue( { code: 'custom', path: [ 'pct' ], message: __( 'Enter a whole percentage between 1 and 100.', 'marclie-booking' ) } );
			}
		} else if ( ! v.fixed || v.fixed <= 0 ) {
			ctx.addIssue( { code: 'custom', path: [ 'fixed' ], message: __( 'Enter an amount greater than zero.', 'marclie-booking' ) } );
		}
		if ( v.unit_ids && v.unit_ids.length === 0 ) {
			ctx.addIssue( { code: 'custom', path: [ 'unit_ids' ], message: __( 'Select at least one unit or choose “All units”.', 'marclie-booking' ) } );
		}
	} );

type FormInput = z.input< typeof schema >;
type FormValues = z.output< typeof schema >;

function toForm( c: CouponWithStats | null ): FormInput {
	return {
		code: c?.code ?? '',
		type: c?.type ?? 'pct',
		pct: c?.type === 'pct' ? String( c.amount ) : '10',
		fixed: c?.type === 'fixed' ? c.amount : null,
		book_from: c?.book_from ?? undefined,
		book_to: c?.book_to ?? undefined,
		stay_from: c?.stay_from ?? undefined,
		stay_to: c?.stay_to ?? undefined,
		min_nights: c?.min_nights ? c.min_nights : '',
		unit_ids: c?.unit_ids ?? null,
		usage_limit: c?.usage_limit ?? '',
		per_guest_limit: c?.per_guest_limit ?? '',
		// Seed the assigned guest right away (name filled in once loaded) so saving never drops it.
		guest: c?.guest_id ? { id: c.guest_id, name: `#${ c.guest_id }`, email: '' } : null,
		active: c?.active ?? true,
	};
}

function toPayload( v: FormValues ): Partial< CouponInput > {
	return {
		code: v.code,
		type: v.type,
		amount: v.type === 'pct' ? Number( v.pct ) : ( v.fixed as number ),
		book_from: v.book_from || null,
		book_to: v.book_to || null,
		stay_from: v.stay_from || null,
		stay_to: v.stay_to || null,
		min_nights: v.min_nights === '' ? null : v.min_nights,
		unit_ids: v.unit_ids,
		usage_limit: v.usage_limit === '' ? null : v.usage_limit,
		per_guest_limit: v.per_guest_limit === '' ? null : v.per_guest_limit,
		guest_id: v.guest?.id ?? null,
		active: v.active,
	};
}

export function CouponSheet( {
	open,
	onOpenChange,
	coupon,
}: {
	open: boolean;
	onOpenChange: ( open: boolean ) => void;
	/** Null = create. */
	coupon: CouponWithStats | null;
} ) {
	const { units } = useBootstrapData();
	const save = useSaveCoupon();
	const remove = useDeleteCoupon();
	const [ confirmDelete, setConfirmDelete ] = useState( false );
	const [ generating, setGenerating ] = useState( false );
	const assigned = useGuest( coupon?.guest_id ?? 0 );

	const { register, control, handleSubmit, reset, watch, setValue, setError, formState } = useForm< FormInput, unknown, FormValues >( {
		resolver: zodResolver( schema ),
		defaultValues: toForm( coupon ),
	} );
	const errors = formState.errors;
	const type = watch( 'type' );
	const unitIds = watch( 'unit_ids' );

	useEffect( () => {
		if ( open ) {
			reset( toForm( coupon ) );
		}
	}, [ open, coupon, reset ] );

	useEffect( () => {
		if ( open && assigned.data && coupon?.guest_id === assigned.data.id ) {
			setValue( 'guest', { id: assigned.data.id, name: guestName( assigned.data ), email: assigned.data.email } );
		}
	}, [ open, assigned.data, coupon, setValue ] );

	const generate = async () => {
		setGenerating( true );
		try {
			const { code } = await generateCouponCode();
			setValue( 'code', code, { shouldDirty: true, shouldValidate: true } );
		} catch ( e ) {
			toastError( e );
		} finally {
			setGenerating( false );
		}
	};

	const onSubmit = handleSubmit( async ( v ) => {
		try {
			await save.mutateAsync( { id: coupon?.id, data: toPayload( v ) } );
			toast.success( coupon ? __( 'Coupon updated.', 'marclie-booking' ) : __( 'Coupon created.', 'marclie-booking' ) );
			onOpenChange( false );
		} catch ( e ) {
			applyFieldErrors< keyof FormInput >( e, setError, { amount: type === 'pct' ? 'pct' : 'fixed', guest_id: 'guest' } );
			toastError( e );
		}
	} );

	const houses = [ ...new Set( units.map( ( u ) => u.house ) ) ];
	const used = ( coupon?.used_count ?? 0 ) > 0;

	return (
		<Sheet open={ open } onOpenChange={ onOpenChange }>
			<SheetContent className="flex w-full flex-col sm:max-w-xl">
				<SheetHeader>
					<SheetTitle>{ coupon ? sprintf( /* translators: %s: coupon code. */ __( 'Coupon %s', 'marclie-booking' ), coupon.code ) : __( 'New coupon', 'marclie-booking' ) }</SheetTitle>
					<SheetDescription>
						{ __( 'Discount applies to the accommodation price (nights), not to tourist tax.', 'marclie-booking' ) }
					</SheetDescription>
				</SheetHeader>
				<SheetBody>
					<form id="mbk-coupon-form" onSubmit={ onSubmit } className="flex flex-col gap-5" noValidate>
						<FormField id="coupon-code" label={ __( 'Code', 'marclie-booking' ) } error={ errors.code?.message }>
							<div className="flex gap-2">
								<Input id="coupon-code" className="font-mono uppercase" autoComplete="off" { ...register( 'code' ) } aria-invalid={ !! errors.code } />
								<Button variant="outline" onClick={ generate } disabled={ generating }>
									<ArrowsClockwise className={ `h-4 w-4 ${ generating ? 'animate-spin' : '' }` } aria-hidden="true" />
									{ __( 'Generate', 'marclie-booking' ) }
								</Button>
							</div>
						</FormField>

						<div className="grid gap-4 sm:grid-cols-2">
							<FormField id="coupon-type" label={ __( 'Discount type', 'marclie-booking' ) }>
								<Controller
									control={ control }
									name="type"
									render={ ( { field } ) => (
										<Select value={ field.value } onValueChange={ field.onChange }>
											<SelectTrigger id="coupon-type">
												<SelectValue />
											</SelectTrigger>
											<SelectContent>
												<SelectItem value="pct">{ __( 'Percentage', 'marclie-booking' ) }</SelectItem>
												<SelectItem value="fixed">{ __( 'Fixed amount', 'marclie-booking' ) }</SelectItem>
											</SelectContent>
										</Select>
									) }
								/>
							</FormField>
							{ type === 'pct' ? (
								<FormField id="coupon-pct" label={ __( 'Discount (%)', 'marclie-booking' ) } error={ errors.pct?.message }>
									<Input id="coupon-pct" inputMode="numeric" { ...register( 'pct' ) } aria-invalid={ !! errors.pct } />
								</FormField>
							) : (
								<FormField id="coupon-fixed" label={ __( 'Discount (EUR)', 'marclie-booking' ) } error={ errors.fixed?.message }>
									<Controller
										control={ control }
										name="fixed"
										render={ ( { field } ) => <MoneyInput id="coupon-fixed" value={ field.value } onChange={ field.onChange } aria-invalid={ !! errors.fixed } /> }
									/>
								</FormField>
							) }
						</div>

						<div className="grid gap-4 sm:grid-cols-2">
							<FormField
								id="coupon-book"
								label={ __( 'Booking window', 'marclie-booking' ) }
								hint={ __( 'When the code can be used. Empty = always.', 'marclie-booking' ) }
								error={ errors.book_from?.message ?? errors.book_to?.message }
							>
								<DateRangePicker
									id="coupon-book"
									value={ { from: watch( 'book_from' ), to: watch( 'book_to' ) } }
									onChange={ ( r ) => {
										setValue( 'book_from', r.from, { shouldDirty: true } );
										setValue( 'book_to', r.to, { shouldDirty: true } );
									} }
									placeholder={ __( 'Any time', 'marclie-booking' ) }
									numberOfMonths={ 1 }
									clearable
								/>
							</FormField>
							<FormField
								id="coupon-stay"
								label={ __( 'Stay window', 'marclie-booking' ) }
								hint={ __( 'Which nights qualify. Empty = any.', 'marclie-booking' ) }
								error={ errors.stay_from?.message ?? errors.stay_to?.message }
							>
								<DateRangePicker
									id="coupon-stay"
									value={ { from: watch( 'stay_from' ), to: watch( 'stay_to' ) } }
									onChange={ ( r ) => {
										setValue( 'stay_from', r.from, { shouldDirty: true } );
										setValue( 'stay_to', r.to, { shouldDirty: true } );
									} }
									placeholder={ __( 'Any dates', 'marclie-booking' ) }
									numberOfMonths={ 1 }
									clearable
								/>
							</FormField>
						</div>

						<div className="grid gap-4 sm:grid-cols-3">
							<FormField id="coupon-min" label={ __( 'Min. nights', 'marclie-booking' ) } error={ errors.min_nights ? __( 'Whole number ≥ 1.', 'marclie-booking' ) : undefined }>
								<Input id="coupon-min" inputMode="numeric" placeholder="—" { ...register( 'min_nights' ) } />
							</FormField>
							<FormField id="coupon-limit" label={ __( 'Total uses', 'marclie-booking' ) } error={ errors.usage_limit ? __( 'Whole number ≥ 1.', 'marclie-booking' ) : undefined }>
								<Input id="coupon-limit" inputMode="numeric" placeholder={ __( 'Unlimited', 'marclie-booking' ) } { ...register( 'usage_limit' ) } />
							</FormField>
							<FormField id="coupon-per-guest" label={ __( 'Uses per guest', 'marclie-booking' ) } error={ errors.per_guest_limit ? __( 'Whole number ≥ 1.', 'marclie-booking' ) : undefined }>
								<Input id="coupon-per-guest" inputMode="numeric" placeholder={ __( 'Unlimited', 'marclie-booking' ) } { ...register( 'per_guest_limit' ) } />
							</FormField>
						</div>

						<fieldset className="flex flex-col gap-2">
							<legend className="mb-1.5 text-sm font-medium">{ __( 'Units', 'marclie-booking' ) }</legend>
							<div className="flex items-center gap-2">
								<Checkbox
									id="coupon-all-units"
									checked={ unitIds === null }
									onCheckedChange={ ( v ) => setValue( 'unit_ids', v === true ? null : units.map( ( u ) => u.id ), { shouldDirty: true, shouldValidate: true } ) }
								/>
								<Label htmlFor="coupon-all-units">{ __( 'All units', 'marclie-booking' ) }</Label>
							</div>
							{ unitIds !== null && (
								<div className="grid gap-3 rounded-md border border-border p-3 sm:grid-cols-2">
									{ houses.map( ( house ) => (
										<div key={ house } className="flex flex-col gap-1.5">
											<p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{ house }</p>
											{ units
												.filter( ( u ) => u.house === house )
												.map( ( u ) => (
													<div key={ u.id } className="flex items-center gap-2">
														<Checkbox
															id={ `coupon-unit-${ u.id }` }
															checked={ unitIds.includes( u.id ) }
															onCheckedChange={ ( v ) =>
																setValue(
																	'unit_ids',
																	v === true ? [ ...unitIds, u.id ] : unitIds.filter( ( x ) => x !== u.id ),
																	{ shouldDirty: true, shouldValidate: true }
																)
															}
														/>
														<Label htmlFor={ `coupon-unit-${ u.id }` } className="font-normal">
															{ u.title }
														</Label>
													</div>
												) ) }
										</div>
									) ) }
								</div>
							) }
							{ errors.unit_ids && (
								<p className="text-xs text-danger" role="alert">
									{ errors.unit_ids.message }
								</p>
							) }
						</fieldset>

						<FormField
							id="coupon-guest"
							label={ __( 'Only for guest', 'marclie-booking' ) }
							hint={ __( 'Optional: restrict the code to one guest (matched by e-mail at checkout).', 'marclie-booking' ) }
							error={ errors.guest?.message }
						>
							<Controller
								control={ control }
								name="guest"
								render={ ( { field } ) => (
									<GuestPicker id="coupon-guest" value={ field.value as PickedGuest | null } onChange={ field.onChange } />
								) }
							/>
						</FormField>

						<div className="flex items-center justify-between gap-4 rounded-md border border-border px-3 py-2.5">
							<Label htmlFor="coupon-active">{ __( 'Active', 'marclie-booking' ) }</Label>
							<Controller
								control={ control }
								name="active"
								render={ ( { field } ) => <Switch id="coupon-active" checked={ field.value } onCheckedChange={ field.onChange } /> }
							/>
						</div>
					</form>

					{ coupon && (
						<>
							<Separator className="my-6" />
							<CouponStats coupon={ coupon } />
						</>
					) }
				</SheetBody>
				<SheetFooter className="justify-between">
					{ coupon ? (
						<Button variant="ghost" className="text-danger hover:text-danger" onClick={ () => setConfirmDelete( true ) }>
							<Trash className="h-4 w-4" aria-hidden="true" />
							{ used ? __( 'Deactivate', 'marclie-booking' ) : __( 'Delete', 'marclie-booking' ) }
						</Button>
					) : (
						<span />
					) }
					<div className="flex gap-2">
						<Button variant="outline" onClick={ () => onOpenChange( false ) }>
							{ __( 'Cancel', 'marclie-booking' ) }
						</Button>
						<Button type="submit" form="mbk-coupon-form" disabled={ save.isPending }>
							{ save.isPending ? __( 'Saving…', 'marclie-booking' ) : __( 'Save coupon', 'marclie-booking' ) }
						</Button>
					</div>
				</SheetFooter>
			</SheetContent>
			{ coupon && (
				<ConfirmDialog
					open={ confirmDelete }
					onOpenChange={ setConfirmDelete }
					title={ used ? __( 'Deactivate coupon?', 'marclie-booking' ) : __( 'Delete coupon?', 'marclie-booking' ) }
					description={
						used
							? __( 'This coupon was already used, so it is kept for the booking history and only switched off.', 'marclie-booking' )
							: __( 'The coupon has never been used and will be deleted permanently.', 'marclie-booking' )
					}
					confirmLabel={ used ? __( 'Deactivate', 'marclie-booking' ) : __( 'Delete', 'marclie-booking' ) }
					variant="destructive"
					onConfirm={ async () => {
						try {
							const res = await remove.mutateAsync( coupon.id );
							toast.success( res.deleted ? __( 'Coupon deleted.', 'marclie-booking' ) : __( 'Coupon deactivated.', 'marclie-booking' ) );
							onOpenChange( false );
						} catch ( e ) {
							toastError( e );
							throw e;
						}
					} }
				/>
			) }
		</Sheet>
	);
}

function CouponStats( { coupon }: { coupon: CouponWithStats } ) {
	const [ page, setPage ] = useState( 1 );
	const redemptions = useCouponRedemptions( coupon.id, page );
	const sheet = useBookingSheet();
	const total = redemptions.data?.total ?? 0;
	const pages = Math.max( 1, Math.ceil( total / 10 ) );

	return (
		<section className="flex flex-col gap-3" aria-labelledby="coupon-stats-title">
			<h3 id="coupon-stats-title" className="text-sm font-semibold text-ink">
				{ __( 'Usage', 'marclie-booking' ) }
			</h3>
			<dl className="grid grid-cols-3 gap-3">
				<div className="rounded-md bg-surface-muted px-3 py-2">
					<dt className="text-xs text-muted-foreground">{ __( 'Redemptions', 'marclie-booking' ) }</dt>
					<dd className="text-lg font-semibold">{ formatNumber( coupon.stats?.redemptions ?? coupon.used_count ) }</dd>
				</div>
				<div className="rounded-md bg-surface-muted px-3 py-2">
					<dt className="text-xs text-muted-foreground">{ __( 'Total discount', 'marclie-booking' ) }</dt>
					<dd className="text-lg font-semibold">
						<Money cents={ coupon.stats?.discount_total_cents ?? 0 } />
					</dd>
				</div>
				<div className="rounded-md bg-surface-muted px-3 py-2">
					<dt className="text-xs text-muted-foreground">{ __( 'Created', 'marclie-booking' ) }</dt>
					<dd className="text-sm font-medium">{ formatDate( coupon.created_at.slice( 0, 10 ) ) }</dd>
				</div>
			</dl>
			{ total === 0 && ! redemptions.isPending && (
				<p className="text-sm text-muted-foreground">{ __( 'Not used yet.', 'marclie-booking' ) }</p>
			) }
			{ total > 0 && (
				<ul className="divide-y divide-border rounded-md border border-border">
					{ redemptions.data?.items.map( ( r ) => (
						<li key={ r.booking.id }>
							<button
								type="button"
								onClick={ () => sheet.open( r.booking.id ) }
								className="grid w-full grid-cols-[1fr_auto] gap-x-3 px-3 py-2 text-left text-sm hover:bg-surface-muted focus-visible:bg-surface-muted focus-visible:outline-none"
							>
								<span className="flex items-center gap-2">
									<span className="font-mono text-xs font-medium">{ r.booking.ref }</span>
									<StatusBadge status={ r.booking.status } />
								</span>
								<Money cents={ -r.discount_cents } signed className="text-right" />
								<span className="truncate text-xs text-muted-foreground">
									{ r.booking.guest.name } · { formatDateRange( r.booking.arrival, r.booking.departure ) }
								</span>
							</button>
						</li>
					) ) }
				</ul>
			) }
			{ pages > 1 && (
				<div className="flex items-center justify-end gap-2 text-xs text-muted-foreground">
					{ sprintf(
						/* translators: 1: page, 2: pages. */
						__( 'Page %1$d of %2$d', 'marclie-booking' ),
						page,
						pages
					) }
					<Button variant="outline" size="sm" disabled={ page <= 1 } onClick={ () => setPage( page - 1 ) }>
						{ __( 'Previous', 'marclie-booking' ) }
					</Button>
					<Button variant="outline" size="sm" disabled={ page >= pages } onClick={ () => setPage( page + 1 ) }>
						{ __( 'Next', 'marclie-booking' ) }
					</Button>
				</div>
			) }
			<p className="sr-only" aria-live="polite">
				{ sprintf( /* translators: %d: count. */ _n( '%d redemption', '%d redemptions', total, 'marclie-booking' ), total ) }
			</p>
		</section>
	);
}
