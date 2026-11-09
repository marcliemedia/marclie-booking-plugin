import { zodResolver } from '@hookform/resolvers/zod';
import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';

import type { GuestDetail } from '@/api/hooks/guests';
import type { Guest } from '@/api/types';
import { useUpdateGuest } from '@/api/hooks/guests';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { toast } from '@/components/ui/sonner';
import { Switch } from '@/components/ui/switch';
import { FormField } from '@/features/common/FormField';
import { applyFieldErrors } from '@/lib/formErrors';
import { toastError } from '@/features/common/toastError';

const schema = z.object( {
	first_name: z.string().trim().min( 1, __( 'Required.', 'marclie-booking' ) ).max( 100 ),
	last_name: z.string().trim().min( 1, __( 'Required.', 'marclie-booking' ) ).max( 100 ),
	phone: z
		.string()
		.trim()
		.max( 40 )
		.regex( /^[+0-9 ()\-./]*$/, __( 'Digits, spaces and + ( ) - only.', 'marclie-booking' ) ),
	country: z
		.string()
		.trim()
		.regex( /^([A-Za-z]{2})?$/, __( 'Two-letter country code, e.g. DE.', 'marclie-booking' ) )
		.transform( ( v ) => v.toUpperCase() ),
	lang: z.enum( [ 'hr', 'en' ] ),
	marketing_consent: z.boolean(),
} );

type FormInput = z.input< typeof schema >;
type FormValues = z.output< typeof schema >;

function toForm( g: Guest ): FormInput {
	return {
		first_name: g.first_name,
		last_name: g.last_name,
		phone: g.phone ?? '',
		country: g.country ?? '',
		lang: g.lang,
		marketing_consent: g.marketing_consent,
	};
}

export function GuestContactCard( { guest }: { guest: GuestDetail } ) {
	const update = useUpdateGuest( guest.id );
	const { register, control, handleSubmit, reset, setError, formState } = useForm< FormInput, unknown, FormValues >( {
		resolver: zodResolver( schema ),
		defaultValues: toForm( guest ),
	} );
	const { errors, isDirty } = formState;
	const disabled = guest.anonymized;

	// Background refetches (tags/notes saves, merges) must not wipe fields being edited.
	useEffect( () => reset( toForm( guest ), { keepDirtyValues: true } ), [ guest, reset ] );

	const onSubmit = handleSubmit( async ( v ) => {
		try {
			const saved = await update.mutateAsync( { ...v, phone: v.phone || null, country: v.country || null } );
			reset( toForm( saved ) );
			toast.success( __( 'Guest updated.', 'marclie-booking' ) );
		} catch ( e ) {
			applyFieldErrors< keyof FormInput >( e, setError );
			toastError( e );
		}
	} );

	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Contact', 'marclie-booking' ) }</CardTitle>
			</CardHeader>
			<CardContent>
				<form onSubmit={ onSubmit } className="grid gap-4 sm:grid-cols-2" noValidate>
					<FormField id="guest-first" label={ __( 'First name', 'marclie-booking' ) } error={ errors.first_name?.message }>
						<Input id="guest-first" disabled={ disabled } autoComplete="off" { ...register( 'first_name' ) } />
					</FormField>
					<FormField id="guest-last" label={ __( 'Last name', 'marclie-booking' ) } error={ errors.last_name?.message }>
						<Input id="guest-last" disabled={ disabled } autoComplete="off" { ...register( 'last_name' ) } />
					</FormField>
					<FormField
						id="guest-email"
						className="sm:col-span-2"
						label={ __( 'E-mail', 'marclie-booking' ) }
						hint={ __( 'The e-mail identifies the guest. Use “Merge” to combine two profiles of the same person.', 'marclie-booking' ) }
					>
						<Input id="guest-email" value={ guest.email } readOnly disabled />
					</FormField>
					<FormField id="guest-phone" label={ __( 'Phone', 'marclie-booking' ) } error={ errors.phone?.message }>
						<Input id="guest-phone" type="tel" disabled={ disabled } { ...register( 'phone' ) } />
					</FormField>
					<FormField id="guest-country" label={ __( 'Country', 'marclie-booking' ) } error={ errors.country?.message }>
						<Input id="guest-country" maxLength={ 2 } className="uppercase" disabled={ disabled } { ...register( 'country' ) } />
					</FormField>
					<FormField id="guest-lang" label={ __( 'E-mail language', 'marclie-booking' ) }>
						<Controller
							control={ control }
							name="lang"
							render={ ( { field } ) => (
								<Select value={ field.value } onValueChange={ field.onChange } disabled={ disabled }>
									<SelectTrigger id="guest-lang">
										<SelectValue />
									</SelectTrigger>
									<SelectContent>
										<SelectItem value="hr">Hrvatski</SelectItem>
										<SelectItem value="en">English</SelectItem>
									</SelectContent>
								</Select>
							) }
						/>
					</FormField>
					<div className="flex items-center gap-2 self-end pb-2">
						<Controller
							control={ control }
							name="marketing_consent"
							render={ ( { field } ) => (
								<Switch id="guest-consent" checked={ field.value } onCheckedChange={ field.onChange } disabled={ disabled } />
							) }
						/>
						<Label htmlFor="guest-consent">{ __( 'Marketing consent', 'marclie-booking' ) }</Label>
					</div>
					{ ! disabled && (
						<div className="flex justify-end gap-2 sm:col-span-2">
							<Button variant="outline" disabled={ ! isDirty || update.isPending } onClick={ () => reset( toForm( guest ) ) }>
								{ __( 'Reset', 'marclie-booking' ) }
							</Button>
							<Button type="submit" disabled={ ! isDirty || update.isPending }>
								{ update.isPending ? __( 'Saving…', 'marclie-booking' ) : __( 'Save contact', 'marclie-booking' ) }
							</Button>
						</div>
					) }
				</form>
			</CardContent>
		</Card>
	);
}
