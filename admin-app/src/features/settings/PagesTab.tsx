import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { PageKey, SettingsResponse } from '@/api/hooks/settings';
import { useUpdateSettings } from '@/api/hooks/settings';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { toast } from '@/components/ui/sonner';
import { FormField } from '@/features/common/FormField';
import { applyFieldErrors } from '@/lib/formErrors';
import { toastError } from '@/features/common/toastError';

const PAGES: { key: PageKey; label: () => string; hint: () => string }[] = [
	{
		key: 'rezervacija',
		label: () => __( 'Booking (Rezervacija)', 'marclie-booking' ),
		hint: () => __( 'Checkout form with guest details and payment.', 'marclie-booking' ),
	},
	{
		key: 'hvala',
		label: () => __( 'Thank you (Hvala)', 'marclie-booking' ),
		hint: () => __( 'Shown after a successful payment.', 'marclie-booking' ),
	},
	{
		key: 'placanje',
		label: () => __( 'Payment (Plaćanje)', 'marclie-booking' ),
		hint: () => __( 'Balance / payment-link page.', 'marclie-booking' ),
	},
	{
		key: 'pretraga',
		label: () => __( 'Search (Pretraga)', 'marclie-booking' ),
		hint: () => __( 'Availability search results.', 'marclie-booking' ),
	},
	{
		key: 'uvjeti',
		label: () => __( 'Terms (Uvjeti)', 'marclie-booking' ),
		hint: () => __( 'Booking terms linked from checkout and e-mails.', 'marclie-booking' ),
	},
	{
		key: 'prijava',
		label: () => __( 'Log in (Prijava)', 'marclie-booking' ),
		hint: () => __( 'Guest account login form.', 'marclie-booking' ),
	},
	{
		key: 'registracija',
		label: () => __( 'Sign up (Registracija)', 'marclie-booking' ),
		hint: () => __( 'Guest account registration form.', 'marclie-booking' ),
	},
	{
		key: 'moj_racun',
		label: () => __( 'My account (Moj račun)', 'marclie-booking' ),
		hint: () => __( 'Bookings, payments, coupons and membership of a signed-in guest; e-mail confirmation links land here.', 'marclie-booking' ),
	},
	{
		key: 'zaboravljena_lozinka',
		label: () => __( 'Forgotten password (Zaboravljena lozinka)', 'marclie-booking' ),
		hint: () => __( 'Password reset request and new-password form.', 'marclie-booking' ),
	},
];

export function PagesTab( { settings }: { settings: SettingsResponse } ) {
	const update = useUpdateSettings( 'pages' );
	const [ values, setValues ] = useState( settings.pages );
	const [ serverErrors, setServerErrors ] = useState< Record< string, string > >( {} );

	useEffect( () => setValues( settings.pages ), [ settings.pages ] );

	const dirty = PAGES.some( ( p ) => values[ p.key ] !== settings.pages[ p.key ] );

	const save = async () => {
		setServerErrors( {} );
		try {
			await update.mutateAsync( values );
			toast.success( __( 'Page map saved.', 'marclie-booking' ) );
		} catch ( e ) {
			applyFieldErrors( e, ( name, { message } ) => setServerErrors( ( prev ) => ( { ...prev, [ name ]: message } ) ) );
			toastError( e );
		}
	};

	return (
		<Card>
			<CardHeader>
				<div>
					<CardTitle>{ __( 'Pages', 'marclie-booking' ) }</CardTitle>
					<CardDescription>
						{ __( 'Which WordPress pages host each step of the booking flow (Croatian originals; translations follow automatically).', 'marclie-booking' ) }
					</CardDescription>
				</div>
			</CardHeader>
			<CardContent className="flex flex-col gap-5">
				<div className="grid gap-5 sm:grid-cols-2">
					{ PAGES.map( ( page ) => {
						const current = values[ page.key ];
						const missing = current > 0 && ! settings.page_options.some( ( o ) => o.id === current );
						return (
							<FormField
								key={ page.key }
								id={ `page-${ page.key }` }
								label={ page.label() }
								hint={ page.hint() }
								error={ missing ? __( 'The selected page no longer exists.', 'marclie-booking' ) : serverErrors[ page.key ] }
							>
								<Select
									value={ String( current ) }
									onValueChange={ ( v ) => setValues( ( prev ) => ( { ...prev, [ page.key ]: Number( v ) } ) ) }
								>
									<SelectTrigger id={ `page-${ page.key }` }>
										<SelectValue />
									</SelectTrigger>
									<SelectContent>
										<SelectItem value="0">{ __( '— Not set —', 'marclie-booking' ) }</SelectItem>
										{ missing && <SelectItem value={ String( current ) }>#{ current }</SelectItem> }
										{ settings.page_options.map( ( o ) => (
											<SelectItem key={ o.id } value={ String( o.id ) }>
												{ o.title || `#${ o.id }` }
											</SelectItem>
										) ) }
									</SelectContent>
								</Select>
							</FormField>
						);
					} ) }
				</div>
				<div className="flex justify-end gap-2">
					<Button variant="outline" disabled={ ! dirty || update.isPending } onClick={ () => setValues( settings.pages ) }>
						{ __( 'Reset', 'marclie-booking' ) }
					</Button>
					<Button onClick={ save } disabled={ ! dirty || update.isPending }>
						{ update.isPending ? __( 'Saving…', 'marclie-booking' ) : __( 'Save pages', 'marclie-booking' ) }
					</Button>
				</div>
			</CardContent>
		</Card>
	);
}
