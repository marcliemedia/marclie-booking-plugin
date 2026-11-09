import { ArrowClockwise, CaretDown, EnvelopeSimple, PaperPlaneTilt, WarningCircle } from '@phosphor-icons/react';
import { __, sprintf } from '@wordpress/i18n';
import * as React from 'react';

import { ApiError } from '@/api/client';
import { useResendBookingEmail } from '@/api/hooks/emails';
import type { BookingDetail, EventItem } from '@/api/types';
import { ConfirmDialog } from '@/components/app/ConfirmDialog';
import { EmptyState } from '@/components/app/EmptyState';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
	DropdownMenu,
	DropdownMenuContent,
	DropdownMenuItem,
	DropdownMenuLabel,
	DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { toast } from '@/components/ui/sonner';
import { toastError } from '@/features/common/toastError';
import { emailCodeLabel } from '@/features/emails/labels';
import { formatDateTime, formatRelative } from '@/lib/format';

interface SentEmail {
	event: EventItem;
	code: string;
	to: string;
	subject: string;
	lang: string;
	failed: boolean;
}

function sentEmails( booking: BookingDetail ): SentEmail[] {
	return booking.events
		.filter( ( event ) => event.type === 'email_sent' || event.type === 'email_failed' )
		.map( ( event ) => {
			const p = event.payload;
			return {
				event,
				code: String( p.code ?? p.template ?? '' ),
				to: String( p.to ?? '' ),
				subject: String( p.subject ?? '' ),
				lang: String( p.lang ?? '' ),
				failed: event.type === 'email_failed',
			};
		} );
}

/** E-mails logged for the booking (`email_sent` events) and manual resend of guest templates. */
export function BookingEmails( { booking }: { booking: BookingDetail } ) {
	const resend = useResendBookingEmail( booking.id );
	const [ pending, setPending ] = React.useState< string | null >( null );
	const emails = sentEmails( booking );
	const sentCodes = new Set( emails.map( ( email ) => email.code ) );
	// The server lists the templates that fit the booking's status and have a valid recipient.
	const resendable = booking.resendable_codes ?? [];
	const guestCodes = resendable.filter( ( code ) => code.startsWith( 'guest_' ) );
	const adminCodes = resendable.filter( ( code ) => ! code.startsWith( 'guest_' ) );
	const pendingIsAdmin = pending !== null && ! pending.startsWith( 'guest_' );

	const confirm = async () => {
		if ( ! pending ) {
			return;
		}
		try {
			await resend.mutateAsync( pending );
			toast.success(
				pendingIsAdmin
					? sprintf(
							/* translators: %s: e-mail name. */
							__( '“%s” sent to the admin recipients.', 'marclie-booking' ),
							emailCodeLabel( pending )
					  )
					: sprintf(
							/* translators: 1: e-mail name, 2: e-mail address. */
							__( '“%1$s” sent to %2$s.', 'marclie-booking' ),
							emailCodeLabel( pending ),
							booking.email
					  )
			);
		} catch ( error ) {
			if ( error instanceof ApiError && error.code === 'not_applicable' ) {
				toast.error( __( 'This e-mail does not apply to the booking in its current status.', 'marclie-booking' ) );
			} else if ( error instanceof ApiError && error.code === 'no_recipient' ) {
				toast.error( __( 'The booking has no valid e-mail address to send to.', 'marclie-booking' ) );
			} else {
				toastError( error );
			}
			throw error;
		}
	};

	return (
		<>
			<div className="mb-3 flex items-center justify-between gap-2">
				<p className="text-xs text-muted-foreground">
					{ sprintf(
						/* translators: 1: e-mail address, 2: language code. */
						__( 'Guest e-mails go to %1$s in %2$s.', 'marclie-booking' ),
						booking.email,
						booking.lang.toUpperCase()
					) }
				</p>
				<DropdownMenu>
					<DropdownMenuTrigger asChild>
						<Button size="sm" variant="secondary" disabled={ resendable.length === 0 }>
							<PaperPlaneTilt className="h-4 w-4" aria-hidden="true" />
							{ __( 'Send e-mail', 'marclie-booking' ) }
							<CaretDown className="h-3.5 w-3.5" aria-hidden="true" />
						</Button>
					</DropdownMenuTrigger>
					<DropdownMenuContent align="end" className="w-60">
						{ guestCodes.length > 0 && <DropdownMenuLabel>{ __( 'Send to the guest again', 'marclie-booking' ) }</DropdownMenuLabel> }
						{ guestCodes.map( ( code ) => (
							<DropdownMenuItem key={ code } onSelect={ () => setPending( code ) }>
								<EnvelopeSimple />
								<span className="flex-1">{ emailCodeLabel( code ) }</span>
								{ sentCodes.has( code ) && <span className="text-2xs text-muted-foreground">{ __( 'sent', 'marclie-booking' ) }</span> }
							</DropdownMenuItem>
						) ) }
						{ adminCodes.length > 0 && <DropdownMenuLabel>{ __( 'Send to the admin recipients', 'marclie-booking' ) }</DropdownMenuLabel> }
						{ adminCodes.map( ( code ) => (
							<DropdownMenuItem key={ code } onSelect={ () => setPending( code ) }>
								<EnvelopeSimple />
								<span className="flex-1">{ emailCodeLabel( code ) }</span>
								{ sentCodes.has( code ) && <span className="text-2xs text-muted-foreground">{ __( 'sent', 'marclie-booking' ) }</span> }
							</DropdownMenuItem>
						) ) }
					</DropdownMenuContent>
				</DropdownMenu>
			</div>

			{ emails.length === 0 ? (
				<EmptyState compact icon={ EnvelopeSimple } title={ __( 'No e-mails sent yet', 'marclie-booking' ) } />
			) : (
				<ul className="divide-y divide-border rounded-lg border border-border">
					{ emails.map( ( email ) => (
						<li key={ email.event.id } className="flex items-start gap-3 px-3 py-2.5">
							{ email.failed ? (
								<WarningCircle className="mt-0.5 h-4 w-4 shrink-0 text-danger" aria-hidden="true" />
							) : (
								<EnvelopeSimple className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
							) }
							<div className="min-w-0 flex-1">
								<div className="flex flex-wrap items-center gap-1.5 text-sm text-ink-soft">
									<span className="font-medium">{ emailCodeLabel( email.code ) }</span>
									{ email.lang && <Badge variant="muted">{ email.lang.toUpperCase() }</Badge> }
									{ email.failed && <Badge variant="danger">{ __( 'Not sent', 'marclie-booking' ) }</Badge> }
								</div>
								{ email.subject && <p className="truncate text-xs text-ink-soft">{ email.subject }</p> }
								<p className="text-xs text-muted-foreground">
									<span title={ formatDateTime( email.event.created_at ) }>{ formatRelative( email.event.created_at ) }</span>
									{ email.to && ` · ${ email.to }` }
									{ email.event.actor && ` · ${ email.event.actor }` }
								</p>
							</div>
							{ resendable.includes( email.code ) && (
								<Button
									size="sm"
									variant="ghost"
									className="shrink-0"
									onClick={ () => setPending( email.code ) }
									aria-label={ sprintf(
										/* translators: %s: e-mail name. */
										__( 'Resend “%s”', 'marclie-booking' ),
										emailCodeLabel( email.code )
									) }
								>
									<ArrowClockwise className="h-4 w-4" aria-hidden="true" />
									{ __( 'Resend', 'marclie-booking' ) }
								</Button>
							) }
						</li>
					) ) }
				</ul>
			) }

			<ConfirmDialog
				open={ pending !== null }
				onOpenChange={ ( open ) => ! open && setPending( null ) }
				title={ sprintf(
					pendingIsAdmin
						? /* translators: %s: e-mail name. */
						  __( 'Send “%s” to the admin recipients?', 'marclie-booking' )
						: /* translators: %s: e-mail name. */
						  __( 'Send “%s” to the guest?', 'marclie-booking' ),
					emailCodeLabel( pending ?? '' )
				) }
				description={
					pendingIsAdmin
						? __( 'The e-mail is sent now to the admin recipients from Settings › Notifications.', 'marclie-booking' )
						: sprintf(
								/* translators: 1: e-mail address, 2: language code. */
								__( 'The e-mail is sent now to %1$s (%2$s) with the current booking details.', 'marclie-booking' ),
								booking.email,
								booking.lang.toUpperCase()
						  )
				}
				confirmLabel={ __( 'Send', 'marclie-booking' ) }
				onConfirm={ confirm }
			/>
		</>
	);
}
