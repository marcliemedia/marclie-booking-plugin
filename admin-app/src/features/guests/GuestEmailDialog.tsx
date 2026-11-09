import { CircleNotch, PaperPlaneTilt } from '@phosphor-icons/react';
import { __, sprintf } from '@wordpress/i18n';
import * as React from 'react';

import { useBootstrapData } from '@/api/hooks/bootstrap';
import { useEmailTemplate, useSendGuestEmail } from '@/api/hooks/emails';
import type { EmailLang, Guest } from '@/api/types';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { toast } from '@/components/ui/sonner';
import { FormField } from '@/features/common/FormField';
import { guestName } from '@/features/common/GuestPicker';
import { toastError } from '@/features/common/toastError';
import { LazyRichTextEditor } from '@/features/emails/LazyRichTextEditor';
import { applyFieldErrors } from '@/lib/formErrors';

type Errors = Partial< Record< 'subject' | 'body_html', string > >;

/**
 * Free-form e-mail to a guest in the shared layout (template `guest_message`).
 * Admins with settings access get the template's starter text as a prefill.
 */
export function GuestEmailDialog( { guest, open, onOpenChange }: { guest: Guest; open: boolean; onOpenChange: ( open: boolean ) => void } ) {
	const { caps } = useBootstrapData();
	const starter = useEmailTemplate( open && caps.manage_settings ? 'guest_message' : '' );
	const send = useSendGuestEmail( guest.id );
	const [ lang, setLang ] = React.useState< EmailLang >( guest.lang );
	const [ subject, setSubject ] = React.useState( '' );
	const [ body, setBody ] = React.useState( '' );
	const [ touched, setTouched ] = React.useState( false );
	const [ errors, setErrors ] = React.useState< Errors >( {} );

	React.useEffect( () => {
		if ( open ) {
			setLang( guest.lang );
			setSubject( '' );
			setBody( '' );
			setTouched( false );
			setErrors( {} );
		}
	}, [ open, guest.lang ] );

	// Untouched drafts follow the starter text of the chosen language.
	React.useEffect( () => {
		if ( open && ! touched && starter.data ) {
			setSubject( starter.data[ lang ].subject );
			setBody( starter.data[ lang ].body_html );
		}
	}, [ open, touched, starter.data, lang ] );

	const submit = async ( event: React.FormEvent ) => {
		event.preventDefault();
		const next: Errors = {};
		if ( subject.trim() === '' ) {
			next.subject = __( 'Required', 'marclie-booking' );
		}
		if ( body.replace( /<[^>]*>/g, '' ).trim() === '' ) {
			next.body_html = __( 'Write a message.', 'marclie-booking' );
		}
		setErrors( next );
		if ( Object.keys( next ).length > 0 ) {
			return;
		}
		try {
			await send.mutateAsync( { subject: subject.trim(), body_html: body, lang } );
			toast.success(
				sprintf(
					/* translators: %s: e-mail address. */
					__( 'E-mail sent to %s.', 'marclie-booking' ),
					guest.email
				)
			);
			onOpenChange( false );
		} catch ( error ) {
			if ( ! applyFieldErrors( error, ( name, { message } ) => setErrors( ( current ) => ( { ...current, [ name ]: message } ) ) ) ) {
				toastError( error );
			}
		}
	};

	return (
		<Dialog open={ open } onOpenChange={ ( next ) => ! send.isPending && onOpenChange( next ) }>
			<DialogContent className="max-w-2xl">
				<form onSubmit={ submit } noValidate className="grid gap-4">
					<DialogHeader>
						<DialogTitle>{ __( 'Send e-mail', 'marclie-booking' ) }</DialogTitle>
						<DialogDescription>
							{ sprintf(
								/* translators: 1: guest name, 2: e-mail address. */
								__( 'To %1$s <%2$s>, in the booking e-mail layout. A copy is logged in the guest history.', 'marclie-booking' ),
								guestName( guest ),
								guest.email
							) }
						</DialogDescription>
					</DialogHeader>
					<div className="grid gap-4 sm:grid-cols-[minmax(0,1fr)_160px]">
						<FormField id="mbk-guest-mail-subject" label={ __( 'Subject', 'marclie-booking' ) } error={ errors.subject }>
							<Input
								id="mbk-guest-mail-subject"
								value={ subject }
								aria-invalid={ errors.subject ? true : undefined }
								onChange={ ( event ) => {
									setSubject( event.target.value );
									setTouched( true );
									setErrors( ( current ) => ( { ...current, subject: undefined } ) );
								} }
							/>
						</FormField>
						<FormField id="mbk-guest-mail-lang" label={ __( 'Language', 'marclie-booking' ) }>
							<Select value={ lang } onValueChange={ ( next ) => setLang( next as EmailLang ) }>
								<SelectTrigger id="mbk-guest-mail-lang">
									<SelectValue />
								</SelectTrigger>
								<SelectContent>
									<SelectItem value="hr">{ __( 'Croatian', 'marclie-booking' ) }</SelectItem>
									<SelectItem value="en">{ __( 'English', 'marclie-booking' ) }</SelectItem>
								</SelectContent>
							</Select>
						</FormField>
					</div>
					<div className="flex flex-col gap-1.5">
						<span id="mbk-guest-mail-body-label" className="text-sm font-medium leading-none text-ink-soft">
							{ __( 'Message', 'marclie-booking' ) }
						</span>
						<LazyRichTextEditor
							aria-labelledby="mbk-guest-mail-body-label"
							aria-describedby={ errors.body_html ? 'mbk-guest-mail-body-error' : undefined }
							invalid={ Boolean( errors.body_html ) }
							value={ body }
							onChange={ ( html ) => {
								setBody( html );
								setTouched( true );
								setErrors( ( current ) => ( { ...current, body_html: undefined } ) );
							} }
						/>
						{ errors.body_html && (
							<p id="mbk-guest-mail-body-error" className="text-xs text-danger" role="alert">
								{ errors.body_html }
							</p>
						) }
					</div>
					<DialogFooter>
						<Button type="button" variant="secondary" disabled={ send.isPending } onClick={ () => onOpenChange( false ) }>
							{ __( 'Cancel', 'marclie-booking' ) }
						</Button>
						<Button type="submit" disabled={ send.isPending }>
							{ send.isPending ? <CircleNotch className="h-4 w-4 animate-spin" aria-hidden="true" /> : <PaperPlaneTilt className="h-4 w-4" aria-hidden="true" /> }
							{ __( 'Send', 'marclie-booking' ) }
						</Button>
					</DialogFooter>
				</form>
			</DialogContent>
		</Dialog>
	);
}
