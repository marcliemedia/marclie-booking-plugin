import { CircleNotch, PaperPlaneTilt } from '@phosphor-icons/react';
import { __, sprintf } from '@wordpress/i18n';
import * as React from 'react';

import { useBootstrapData } from '@/api/hooks/bootstrap';
import { useSendTestEmail } from '@/api/hooks/emails';
import type { EmailContent, EmailLang } from '@/api/types';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { toast } from '@/components/ui/sonner';
import { FormField } from '@/features/common/FormField';
import { toastError } from '@/features/common/toastError';
import { applyFieldErrors } from '@/lib/formErrors';

import type { PreviewBooking } from './EmailPreviewPane';

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export function SendTestDialog( {
	code,
	open,
	onOpenChange,
	lang: initialLang,
	drafts,
	booking,
}: {
	code: string;
	open: boolean;
	onOpenChange: ( open: boolean ) => void;
	lang: EmailLang;
	/** Current (possibly unsaved) text of both languages. */
	drafts: Record< EmailLang, EmailContent >;
	booking: PreviewBooking | null;
} ) {
	const { user } = useBootstrapData();
	const send = useSendTestEmail( code );
	const [ to, setTo ] = React.useState( '' );
	const [ lang, setLang ] = React.useState< EmailLang >( initialLang );
	const [ error, setError ] = React.useState< string | undefined >();

	React.useEffect( () => {
		if ( open ) {
			setTo( ( current ) => current || user.email || '' );
			setLang( initialLang );
			setError( undefined );
		}
	}, [ open, initialLang, user.email ] );

	const invalid = ! EMAIL.test( to.trim() );

	const submit = async ( event: React.FormEvent ) => {
		event.preventDefault();
		if ( invalid ) {
			setError( __( 'Enter a valid e-mail address.', 'marclie-booking' ) );
			return;
		}
		try {
			await send.mutateAsync( { to: to.trim(), lang, draft: drafts[ lang ], ...( booking ? { booking_id: booking.id } : {} ) } );
			toast.success(
				sprintf(
					/* translators: %s: e-mail address. */
					__( 'Test e-mail sent to %s.', 'marclie-booking' ),
					to.trim()
				)
			);
			onOpenChange( false );
		} catch ( err ) {
			if ( ! applyFieldErrors( err, ( _name, { message } ) => setError( message ) ) ) {
				toastError( err );
			}
		}
	};

	return (
		<Dialog open={ open } onOpenChange={ ( next ) => ! send.isPending && onOpenChange( next ) }>
			<DialogContent>
				<form onSubmit={ submit } noValidate className="grid gap-4">
					<DialogHeader>
						<DialogTitle>{ __( 'Send test e-mail', 'marclie-booking' ) }</DialogTitle>
						<DialogDescription>
							{ booking
								? sprintf(
										/* translators: %s: booking reference. */
										__( 'Uses the text in the editor (including unsaved changes) with the data of booking %s. The guest does not receive anything.', 'marclie-booking' ),
										booking.ref
								  )
								: __( 'Uses the text in the editor (including unsaved changes) with sample data.', 'marclie-booking' ) }
						</DialogDescription>
					</DialogHeader>
					<FormField id="mbk-test-to" label={ __( 'Send to', 'marclie-booking' ) } error={ error }>
						<Input
							id="mbk-test-to"
							type="email"
							autoComplete="email"
							value={ to }
							aria-invalid={ error ? true : undefined }
							onChange={ ( event ) => {
								setTo( event.target.value );
								setError( undefined );
							} }
						/>
					</FormField>
					<FormField id="mbk-test-lang" label={ __( 'Language', 'marclie-booking' ) }>
						<Select value={ lang } onValueChange={ ( next ) => setLang( next as EmailLang ) }>
							<SelectTrigger id="mbk-test-lang">
								<SelectValue />
							</SelectTrigger>
							<SelectContent>
								<SelectItem value="hr">{ __( 'Croatian', 'marclie-booking' ) }</SelectItem>
								<SelectItem value="en">{ __( 'English', 'marclie-booking' ) }</SelectItem>
							</SelectContent>
						</Select>
					</FormField>
					<DialogFooter>
						<Button type="button" variant="secondary" disabled={ send.isPending } onClick={ () => onOpenChange( false ) }>
							{ __( 'Cancel', 'marclie-booking' ) }
						</Button>
						<Button type="submit" disabled={ send.isPending }>
							{ send.isPending ? <CircleNotch className="h-4 w-4 animate-spin" aria-hidden="true" /> : <PaperPlaneTilt className="h-4 w-4" aria-hidden="true" /> }
							{ __( 'Send test', 'marclie-booking' ) }
						</Button>
					</DialogFooter>
				</form>
			</DialogContent>
		</Dialog>
	);
}
