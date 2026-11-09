import { CheckCircle, CircleNotch, PlugsConnected, WarningCircle } from '@phosphor-icons/react';
import { __, _n, sprintf } from '@wordpress/i18n';
import * as React from 'react';

import { ApiError } from '@/api/client';
import type { IcalFeed, IcalUnit } from '@/api/hooks/ical';
import { useAddIcalFeed, useUpdateIcalFeed } from '@/api/hooks/ical';
import type { IcalChannel } from '@/api/types';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { FormField } from '@/features/common/FormField';
import { channelLabel } from '@/features/common/icalChannels';
import { toastError } from '@/features/common/toastError';

/** Where the operator finds the import link in each channel's extranet. */
export function importHint( channel: IcalChannel ): string {
	switch ( channel ) {
		case 'airbnb':
			return __( 'Airbnb › Listings › (unit) › Availability › Connect calendars › Export calendar: copy the link ending in .ics.', 'marclie-booking' );
		case 'booking':
			return __( 'Booking.com extranet › Rates & Availability › Sync calendars › Export calendar: copy the link.', 'marclie-booking' );
		default:
			return __( 'Any iCal (.ics) export link, e.g. from another portal or a private calendar.', 'marclie-booking' );
	}
}

function validUrl( value: string ): boolean {
	try {
		const url = new URL( value );
		return url.protocol === 'https:' && url.hostname.includes( '.' );
	} catch {
		return false;
	}
}

export function IcalFeedDialog( {
	open,
	onOpenChange,
	unit,
	channel,
	feed,
}: {
	open: boolean;
	onOpenChange: ( open: boolean ) => void;
	unit: IcalUnit;
	channel: IcalChannel;
	/** Edit mode when set. */
	feed?: IcalFeed;
} ) {
	const add = useAddIcalFeed();
	const update = useUpdateIcalFeed();
	const pending = add.isPending || update.isPending;
	const [ url, setUrl ] = React.useState( '' );
	const [ error, setError ] = React.useState< string | undefined >();
	const [ result, setResult ] = React.useState< IcalFeed | null >( null );

	React.useEffect( () => {
		if ( open ) {
			setUrl( feed?.url ?? '' );
			setError( undefined );
			setResult( null );
		}
	}, [ open, feed ] );

	const submit = async ( event: React.FormEvent ) => {
		event.preventDefault();
		const value = url.trim();
		if ( ! validUrl( value ) ) {
			setError( __( 'Enter a full link starting with https://', 'marclie-booking' ) );
			return;
		}
		setError( undefined );
		try {
			const saved = feed
				? await update.mutateAsync( { id: feed.id, url: value } )
				: await add.mutateAsync( { unit_id: unit.unit_id, channel, url: value } );
			setResult( saved );
		} catch ( err ) {
			if ( err instanceof ApiError && [ 400, 409, 422 ].includes( err.status ) ) {
				const fields = Object.values( err.fields );
				setError( fields.length > 0 ? fields.join( ' ' ) : err.message );
			} else {
				toastError( err );
			}
		}
	};

	const title = feed
		? sprintf(
				/* translators: 1: channel name (Airbnb, Booking.com…), 2: unit name. */
				__( 'Edit %1$s calendar link — %2$s', 'marclie-booking' ),
				channelLabel( channel ),
				unit.title
		  )
		: sprintf(
				/* translators: 1: channel name (Airbnb, Booking.com…), 2: unit name. */
				__( 'Connect %1$s calendar — %2$s', 'marclie-booking' ),
				channelLabel( channel ),
				unit.title
		  );

	return (
		<Dialog open={ open } onOpenChange={ ( next ) => ! pending && onOpenChange( next ) }>
			<DialogContent>
				<form onSubmit={ submit } noValidate className="grid gap-4">
					<DialogHeader>
						<DialogTitle>{ title }</DialogTitle>
						<DialogDescription>
							{ __( 'Paste the export link of this unit’s calendar. It is tested right away; bookings and blocks from it then close the dates on the website.', 'marclie-booking' ) }
						</DialogDescription>
					</DialogHeader>

					{ result ? (
						<div role="status" className="flex items-start gap-2 rounded-md bg-success-soft px-3 py-2.5 text-sm text-success">
							<CheckCircle className="mt-0.5 h-4 w-4 shrink-0" weight="fill" aria-hidden="true" />
							<span>
								{ sprintf(
									/* translators: %d: number of calendar events. */
									_n(
										'Link works — %d upcoming event imported.',
										'Link works — %d upcoming events imported.',
										result.events_count,
										'marclie-booking'
									),
									result.events_count
								) }
							</span>
						</div>
					) : (
						<FormField id="mbk-ical-url" label={ __( 'iCal link (.ics)', 'marclie-booking' ) } hint={ importHint( channel ) }>
							<Input
								id="mbk-ical-url"
								type="url"
								inputMode="url"
								autoComplete="off"
								spellCheck={ false }
								placeholder="https://…"
								value={ url }
								aria-invalid={ error ? true : undefined }
								aria-describedby={ error ? 'mbk-ical-url-test' : 'mbk-ical-url-hint' }
								onChange={ ( e ) => {
									setUrl( e.target.value );
									setError( undefined );
								} }
							/>
						</FormField>
					) }

					{ error && ! result && (
						<div id="mbk-ical-url-test" role="alert" className="flex items-start gap-2 rounded-md bg-danger-soft px-3 py-2.5 text-sm text-danger">
							<WarningCircle className="mt-0.5 h-4 w-4 shrink-0" weight="fill" aria-hidden="true" />
							<span>
								<span className="font-medium">{ __( 'Test failed:', 'marclie-booking' ) }</span> { error }
							</span>
						</div>
					) }

					<DialogFooter>
						{ result ? (
							<Button type="button" onClick={ () => onOpenChange( false ) }>
								{ __( 'Done', 'marclie-booking' ) }
							</Button>
						) : (
							<>
								<Button type="button" variant="secondary" disabled={ pending } onClick={ () => onOpenChange( false ) }>
									{ __( 'Cancel', 'marclie-booking' ) }
								</Button>
								<Button type="submit" disabled={ pending || url.trim() === '' }>
									{ pending ? (
										<CircleNotch className="h-4 w-4 animate-spin" aria-hidden="true" />
									) : (
										<PlugsConnected className="h-4 w-4" aria-hidden="true" />
									) }
									{ pending ? __( 'Testing link…', 'marclie-booking' ) : __( 'Test & save', 'marclie-booking' ) }
								</Button>
							</>
						) }
					</DialogFooter>
				</form>
			</DialogContent>
		</Dialog>
	);
}
