import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { NotificationSettings } from '@/api/hooks/settings';
import { useUpdateSettings } from '@/api/hooks/settings';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { toast } from '@/components/ui/sonner';
import { ChipsInput } from '@/features/common/ChipsInput';
import { FormField } from '@/features/common/FormField';
import { applyFieldErrors } from '@/lib/formErrors';
import { toastError } from '@/features/common/toastError';

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/** Keys this form owns. The section also stores `disabled_codes` (e-mail on/off), changed only from the Emails screen. */
const FIELDS = [ 'admin_emails', 'from_name', 'from_email', 'reply_to' ] as const;

export function NotificationsTab( { notifications }: { notifications: NotificationSettings } ) {
	const update = useUpdateSettings( 'notifications' );
	const [ values, setValues ] = useState( notifications );
	const [ serverErrors, setServerErrors ] = useState< Record< string, string > >( {} );

	useEffect( () => setValues( notifications ), [ notifications ] );

	const fromInvalid = values.from_email !== '' && ! EMAIL.test( values.from_email );
	const replyInvalid = values.reply_to !== '' && ! EMAIL.test( values.reply_to );
	// Only edited fields are sent, so a stale copy can never overwrite other keys of the section.
	const changes = Object.fromEntries(
		FIELDS.filter( ( key ) => JSON.stringify( values[ key ] ) !== JSON.stringify( notifications[ key ] ) ).map( ( key ) => [ key, values[ key ] ] )
	);
	const dirty = Object.keys( changes ).length > 0;

	const save = async ( e: React.FormEvent ) => {
		e.preventDefault();
		if ( fromInvalid || replyInvalid ) {
			return;
		}
		setServerErrors( {} );
		try {
			await update.mutateAsync( changes );
			toast.success( __( 'Notification settings saved.', 'marclie-booking' ) );
		} catch ( err ) {
			applyFieldErrors( err, ( name, { message } ) => setServerErrors( ( prev ) => ( { ...prev, [ name ]: message } ) ) );
			toastError( err );
		}
	};

	return (
		<form onSubmit={ save } noValidate>
			<Card>
				<CardHeader>
					<div>
						<CardTitle>{ __( 'Notifications', 'marclie-booking' ) }</CardTitle>
						<CardDescription>
							{ __( 'Who receives admin e-mails (new booking, failed payment, cancellation) and how guest e-mails are sent.', 'marclie-booking' ) }
						</CardDescription>
					</div>
				</CardHeader>
				<CardContent className="grid gap-5 sm:grid-cols-2">
					<FormField
						id="notif-admins"
						className="sm:col-span-2"
						error={ serverErrors.admin_emails }
						label={ __( 'Admin recipients', 'marclie-booking' ) }
						hint={ __( 'Press Enter or comma after each address. Empty = the site admin e-mail.', 'marclie-booking' ) }
					>
						<ChipsInput
							id="notif-admins"
							value={ values.admin_emails }
							onChange={ ( admin_emails ) => setValues( ( v ) => ( { ...v, admin_emails } ) ) }
							placeholder="owner@example.com"
							normalize={ ( v ) => v.trim().toLowerCase() }
							validate={ ( v ) => ( EMAIL.test( v ) ? null : __( 'Enter a valid e-mail address.', 'marclie-booking' ) ) }
						/>
					</FormField>
					<FormField id="notif-from-name" label={ __( 'From name', 'marclie-booking' ) } hint={ __( 'e.g. the name of your rental', 'marclie-booking' ) } error={ serverErrors.from_name }>
						<Input
							id="notif-from-name"
							value={ values.from_name }
							onChange={ ( e ) => setValues( ( v ) => ( { ...v, from_name: e.target.value } ) ) }
						/>
					</FormField>
					<FormField
						id="notif-from-email"
						label={ __( 'From e-mail', 'marclie-booking' ) }
						hint={ __( 'Use an address on your own domain so e-mails are not marked as spam.', 'marclie-booking' ) }
						error={ fromInvalid ? __( 'Enter a valid e-mail address.', 'marclie-booking' ) : serverErrors.from_email }
					>
						<Input
							id="notif-from-email"
							type="email"
							value={ values.from_email }
							aria-invalid={ fromInvalid }
							onChange={ ( e ) => setValues( ( v ) => ( { ...v, from_email: e.target.value.trim() } ) ) }
						/>
					</FormField>
					<FormField
						id="notif-reply"
						label={ __( 'Reply-To', 'marclie-booking' ) }
						hint={ __( 'Where guest replies go. Empty = From e-mail.', 'marclie-booking' ) }
						error={ replyInvalid ? __( 'Enter a valid e-mail address.', 'marclie-booking' ) : serverErrors.reply_to }
					>
						<Input
							id="notif-reply"
							type="email"
							value={ values.reply_to }
							aria-invalid={ replyInvalid }
							onChange={ ( e ) => setValues( ( v ) => ( { ...v, reply_to: e.target.value.trim() } ) ) }
						/>
					</FormField>
					<div className="flex justify-end gap-2 sm:col-span-2">
						<Button variant="outline" disabled={ ! dirty || update.isPending } onClick={ () => setValues( notifications ) }>
							{ __( 'Reset', 'marclie-booking' ) }
						</Button>
						<Button type="submit" disabled={ ! dirty || update.isPending || fromInvalid || replyInvalid }>
							{ update.isPending ? __( 'Saving…', 'marclie-booking' ) : __( 'Save', 'marclie-booking' ) }
						</Button>
					</div>
				</CardContent>
			</Card>
		</form>
	);
}
