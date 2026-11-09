import { zodResolver } from '@hookform/resolvers/zod';
import { Info } from '@phosphor-icons/react';
import { useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import type { PolicySettings } from '@/api/hooks/settings';
import { useUpdateSettings } from '@/api/hooks/settings';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { toast } from '@/components/ui/sonner';
import { FormField } from '@/features/common/FormField';
import { applyFieldErrors } from '@/lib/formErrors';
import { toastError } from '@/features/common/toastError';

const int = ( min: number, max: number ) =>
	z.coerce
		.number()
		.int()
		.min( min )
		.max( max, { message: `${ min }–${ max }` } );

const time = z.string().regex( /^([01]\d|2[0-3]):[0-5]\d$/, __( 'Use HH:MM.', 'marclie-booking' ) );

const schema = z.object( {
	deposit_pct: int( 0, 100 ),
	balance_days: int( 0, 365 ),
	free_cancel_days: int( 0, 365 ),
	hold_minutes: int( 5, 120 ),
	default_coupon: z
		.string()
		.trim()
		.regex( /^[A-Za-z0-9_-]*$/, __( 'Letters, digits, - and _ only.', 'marclie-booking' ) )
		.transform( ( v ) => v.toUpperCase() ),
	checkin_from: time,
	checkin_to: time,
	checkout_until: time,
	ref_prefix: z
		.string()
		.trim()
		.regex( /^[A-Za-z0-9]{1,6}$/, __( 'Use 1–6 letters or digits.', 'marclie-booking' ) )
		.transform( ( v ) => v.toUpperCase() ),
} );

type FormInput = z.input< typeof schema >;
type FormValues = z.output< typeof schema >;

export function GeneralTab( { policy }: { policy: PolicySettings } ) {
	const update = useUpdateSettings( 'policy' );
	const { register, handleSubmit, reset, setError, formState } = useForm< FormInput, unknown, FormValues >( {
		resolver: zodResolver( schema ),
		defaultValues: policy,
	} );
	const { errors, isDirty, dirtyFields } = formState;

	useEffect( () => reset( policy ), [ policy, reset ] );

	const onSubmit = handleSubmit( async ( values ) => {
		try {
			// Send only what changed so concurrent edits of other policy keys are not overwritten.
			const changed = Object.fromEntries( Object.entries( values ).filter( ( [ key ] ) => dirtyFields[ key as keyof FormInput ] ) );
			const res = await update.mutateAsync( changed );
			reset( res.policy );
			toast.success( __( 'Booking policy saved.', 'marclie-booking' ) );
		} catch ( e ) {
			applyFieldErrors< keyof FormInput >( e, setError );
			toastError( e );
		}
	} );

	const num = ( name: keyof FormInput, label: string, hint: string, min: number, max: number, suffix: string ) => (
		<FormField
			id={ `policy-${ name }` }
			label={ label }
			hint={ hint }
			error={
				errors[ name ]
					? sprintf(
							/* translators: 1: minimum, 2: maximum. */
							__( 'Allowed range: %1$d–%2$d', 'marclie-booking' ),
							min,
							max
					  )
					: undefined
			}
		>
			<div className="relative">
				<Input
					id={ `policy-${ name }` }
					type="number"
					min={ min }
					max={ max }
					className="pr-16"
					aria-invalid={ !! errors[ name ] }
					{ ...register( name ) }
				/>
				<span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs text-muted-foreground">
					{ suffix }
				</span>
			</div>
		</FormField>
	);

	return (
		<form onSubmit={ onSubmit } className="flex flex-col gap-4" noValidate>
			<div className="flex items-start gap-2 rounded-lg border border-border bg-surface-muted px-4 py-3 text-sm">
				<Info className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
				<p>
					{ __(
						'Policy changes apply to new bookings only. Existing bookings keep the policy that was in force when they were made.',
						'marclie-booking'
					) }
				</p>
			</div>

			<Card>
				<CardHeader>
					<div>
						<CardTitle>{ __( 'Payments & cancellation', 'marclie-booking' ) }</CardTitle>
						<CardDescription>{ __( 'Currency: EUR (fixed).', 'marclie-booking' ) }</CardDescription>
					</div>
				</CardHeader>
				<CardContent className="grid gap-5 sm:grid-cols-2">
					{ num(
						'deposit_pct',
						__( 'Deposit', 'marclie-booking' ),
						__( 'Share of the total charged when booking.', 'marclie-booking' ),
						0,
						100,
						'%'
					) }
					{ num(
						'balance_days',
						__( 'Balance charged before arrival', 'marclie-booking' ),
						__( 'The remaining amount is charged this many days before arrival. Bookings made later pay in full.', 'marclie-booking' ),
						0,
						365,
						__( 'days', 'marclie-booking' )
					) }
					{ num(
						'free_cancel_days',
						__( 'Free cancellation until', 'marclie-booking' ),
						__( 'Guests can cancel for a full refund up to this many days before arrival.', 'marclie-booking' ),
						0,
						365,
						__( 'days', 'marclie-booking' )
					) }
					{ num(
						'hold_minutes',
						__( 'Checkout hold', 'marclie-booking' ),
						__( 'How long dates stay reserved while the guest completes payment.', 'marclie-booking' ),
						5,
						120,
						__( 'minutes', 'marclie-booking' )
					) }
					<FormField
						id="policy-default_coupon"
						label={ __( 'Default coupon', 'marclie-booking' ) }
						hint={ __( 'Pre-filled in the coupon field at checkout; the guest applies it. Leave empty for none.', 'marclie-booking' ) }
						error={ errors.default_coupon?.message }
					>
						<Input
							id="policy-default_coupon"
							className="uppercase"
							autoComplete="off"
							aria-invalid={ !! errors.default_coupon }
							{ ...register( 'default_coupon' ) }
						/>
					</FormField>
					<FormField
						id="policy-ref_prefix"
						label={ __( 'Booking reference prefix', 'marclie-booking' ) }
						hint={ sprintf(
							/* translators: %s: example booking reference. */
							__( 'Starts every new booking reference, e.g. %s. Existing bookings keep theirs.', 'marclie-booking' ),
							`${ policy.ref_prefix }-27-0712-101`
						) }
						error={ errors.ref_prefix?.message }
					>
						<Input
							id="policy-ref_prefix"
							className="uppercase"
							autoComplete="off"
							maxLength={ 6 }
							aria-invalid={ !! errors.ref_prefix }
							{ ...register( 'ref_prefix' ) }
						/>
					</FormField>
				</CardContent>
			</Card>

			<Card>
				<CardHeader>
					<div>
						<CardTitle>{ __( 'Check-in & check-out', 'marclie-booking' ) }</CardTitle>
						<CardDescription>
							{ __( 'Defaults shown to guests; a unit can override them.', 'marclie-booking' ) }
						</CardDescription>
					</div>
				</CardHeader>
				<CardContent className="grid gap-5 sm:grid-cols-3">
					<FormField id="policy-checkin_from" label={ __( 'Check-in from', 'marclie-booking' ) } error={ errors.checkin_from?.message }>
						<Input id="policy-checkin_from" type="time" { ...register( 'checkin_from' ) } />
					</FormField>
					<FormField id="policy-checkin_to" label={ __( 'Check-in until', 'marclie-booking' ) } error={ errors.checkin_to?.message }>
						<Input id="policy-checkin_to" type="time" { ...register( 'checkin_to' ) } />
					</FormField>
					<FormField id="policy-checkout_until" label={ __( 'Check-out until', 'marclie-booking' ) } error={ errors.checkout_until?.message }>
						<Input id="policy-checkout_until" type="time" { ...register( 'checkout_until' ) } />
					</FormField>
				</CardContent>
			</Card>

			<div className="flex justify-end gap-2">
				<Button variant="outline" disabled={ ! isDirty || update.isPending } onClick={ () => reset( policy ) }>
					{ __( 'Reset', 'marclie-booking' ) }
				</Button>
				<Button type="submit" disabled={ ! isDirty || update.isPending }>
					{ update.isPending ? __( 'Saving…', 'marclie-booking' ) : __( 'Save policy', 'marclie-booking' ) }
				</Button>
			</div>
		</form>
	);
}
