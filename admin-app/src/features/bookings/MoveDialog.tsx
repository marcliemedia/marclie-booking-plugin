import { ArrowRight, CircleNotch, Warning } from '@phosphor-icons/react';
import { __, sprintf } from '@wordpress/i18n';
import * as React from 'react';

import { errorMessage } from '@/api/client';
import { useBootstrapData, useUnitMap } from '@/api/hooks/bootstrap';
import { moveDryRun, useMoveBooking, type MoveInput } from '@/api/hooks/bookings';
import type { BookingDetail, MoveDryRun } from '@/api/types';
import { ConfirmDialog } from '@/components/app/ConfirmDialog';
import { DateRangePicker } from '@/components/app/DateRangePicker';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { toast } from '@/components/ui/sonner';
import { Field } from '@/features/bookings/dialogs';
import { diffDays } from '@/lib/dates';
import { formatDate, nightsLabel } from '@/lib/format';

export interface MoveTarget {
	unit_id: number;
	arrival: string;
	departure: string;
}

/**
 * Re-quotes the move (dry run), shows old vs new price and applies it on confirm.
 * Used by the booking sheet and by drag & drop on the calendar.
 */
export function MoveConfirmDialog( {
	booking,
	target,
	open,
	onOpenChange,
}: {
	booking: { id: number; ref: string; guest_name: string; unit_id: number; arrival: string; departure: string };
	target: MoveTarget | null;
	open: boolean;
	onOpenChange: ( open: boolean ) => void;
} ) {
	const unitMap = useUnitMap();
	const move = useMoveBooking();
	const [ preview, setPreview ] = React.useState< MoveDryRun | null >( null );
	const [ error, setError ] = React.useState< string | null >( null );
	const [ keepPrice, setKeepPrice ] = React.useState( false );

	React.useEffect( () => {
		if ( ! open || ! target ) {
			return;
		}
		let active = true;
		setPreview( null );
		setError( null );
		setKeepPrice( false );
		moveDryRun( booking.id, target )
			.then( ( result ) => active && setPreview( result ) )
			.catch( ( reason ) => active && setError( errorMessage( reason ) ) );
		return () => {
			active = false;
		};
	}, [ open, target, booking.id ] );

	if ( ! target ) {
		return null;
	}

	const nights = diffDays( target.arrival, target.departure );
	const oldTotal = preview?.quote_old.total_cents ?? 0;
	const newTotal = keepPrice ? oldTotal : preview?.quote_new.total_cents ?? 0;
	const diff = keepPrice ? 0 : preview?.diff_cents ?? 0;
	const unavailable = preview !== null && ! preview.available;

	const submit = () => {
		const input: MoveInput & { id: number } = { id: booking.id, ...target, keep_price: keepPrice };
		return move
			.mutateAsync( input )
			.then( ( detail ) => {
				toast.success(
					sprintf(
						/* translators: %s: booking ref. */
						__( 'Booking %s moved.', 'marclie-booking' ),
						detail.ref
					)
				);
			} )
			.catch( ( reason ) => {
				toast.error( errorMessage( reason ) );
				throw reason;
			} );
	};

	return (
		<ConfirmDialog
			open={ open }
			onOpenChange={ onOpenChange }
			title={ sprintf(
				/* translators: 1: booking ref, 2: guest name. */
				__( 'Move %1$s (%2$s)?', 'marclie-booking' ),
				booking.ref,
				booking.guest_name
			) }
			amounts={
				preview
					? [
							{ label: __( 'Current total', 'marclie-booking' ), cents: oldTotal },
							{ label: __( 'New total', 'marclie-booking' ), cents: newTotal },
							{ label: __( 'Difference', 'marclie-booking' ), cents: diff, signed: true, strong: true },
					  ]
					: undefined
			}
			confirmLabel={ __( 'Move booking', 'marclie-booking' ) }
			variant="ink"
			confirmDisabled={ ! preview || unavailable }
			onConfirm={ submit }
		>
			<div className="flex items-center gap-3 rounded-lg border border-border px-4 py-3 text-sm">
				<div className="min-w-0 flex-1">
					<div className="text-xs text-muted-foreground">{ __( 'From', 'marclie-booking' ) }</div>
					<div className="truncate font-medium text-ink">{ unitMap.get( booking.unit_id )?.title }</div>
					<div className="tabular text-muted-foreground">
						{ formatDate( booking.arrival, 'day' ) } – { formatDate( booking.departure, 'day' ) }
					</div>
				</div>
				<ArrowRight className="h-4 w-4 shrink-0 text-muted-foreground" />
				<div className="min-w-0 flex-1">
					<div className="text-xs text-muted-foreground">{ __( 'To', 'marclie-booking' ) }</div>
					<div className="truncate font-medium text-ink">{ unitMap.get( target.unit_id )?.title }</div>
					<div className="tabular text-muted-foreground">
						{ formatDate( target.arrival, 'day' ) } – { formatDate( target.departure, 'day' ) } ·{ ' ' }
						{ nightsLabel( nights ) }
					</div>
				</div>
			</div>
			{ ! preview && ! error && (
				<div className="flex items-center gap-2 text-sm text-muted-foreground">
					<CircleNotch className="h-4 w-4 animate-spin" />
					{ __( 'Checking availability and price…', 'marclie-booking' ) }
				</div>
			) }
			{ unavailable && (
				<Alert variant="danger">
					<Warning />
					<AlertDescription>{ __( 'These dates are not available for the selected apartment.', 'marclie-booking' ) }</AlertDescription>
				</Alert>
			) }
			{ preview?.coupon_dropped && ! keepPrice && (
				<Alert variant="warning">
					<Warning />
					<AlertDescription>{ __( 'The coupon does not apply to the new stay and will be removed.', 'marclie-booking' ) }</AlertDescription>
				</Alert>
			) }
			{ error && (
				<Alert variant="danger">
					<Warning />
					<AlertDescription>{ error }</AlertDescription>
				</Alert>
			) }
			{ preview && preview.available && preview.diff_cents !== 0 && (
				<div className="flex items-center gap-2">
					<Checkbox id="mv-keep" checked={ keepPrice } onCheckedChange={ ( checked ) => setKeepPrice( checked === true ) } />
					<Label htmlFor="mv-keep" className="font-normal">
						{ __( 'Keep the original price', 'marclie-booking' ) }
					</Label>
				</div>
			) }
		</ConfirmDialog>
	);
}

export function MoveDialog( {
	booking,
	open,
	onOpenChange,
}: {
	booking: BookingDetail;
	open: boolean;
	onOpenChange: ( open: boolean ) => void;
} ) {
	const { units, today } = useBootstrapData();
	const [ unit, setUnit ] = React.useState( booking.unit_id );
	const [ range, setRange ] = React.useState< { from?: string; to?: string } >( { from: booking.arrival, to: booking.departure } );
	const [ target, setTarget ] = React.useState< MoveTarget | null >( null );

	React.useEffect( () => {
		if ( open ) {
			setUnit( booking.unit_id );
			setRange( { from: booking.arrival, to: booking.departure } );
			setTarget( null );
		}
	}, [ open, booking.unit_id, booking.arrival, booking.departure ] );

	const unchanged = unit === booking.unit_id && range.from === booking.arrival && range.to === booking.departure;

	return (
		<>
			<Dialog open={ open && ! target } onOpenChange={ onOpenChange }>
				<DialogContent>
					<DialogHeader>
						<DialogTitle>{ __( 'Change dates or apartment', 'marclie-booking' ) }</DialogTitle>
						<DialogDescription>
							{ __( 'The new stay is re-priced and checked for availability before anything changes.', 'marclie-booking' ) }
						</DialogDescription>
					</DialogHeader>
					<div className="flex flex-col gap-4">
						<Field label={ __( 'Apartment', 'marclie-booking' ) } htmlFor="mv-unit">
							<Select value={ String( unit ) } onValueChange={ ( value ) => setUnit( Number( value ) ) }>
								<SelectTrigger id="mv-unit">
									<SelectValue />
								</SelectTrigger>
								<SelectContent>
									{ units.map( ( item ) => (
										<SelectItem key={ item.id } value={ String( item.id ) } disabled={ item.capacity < booking.adults + booking.children }>
											{ item.title }
										</SelectItem>
									) ) }
								</SelectContent>
							</Select>
						</Field>
						<Field label={ __( 'Dates', 'marclie-booking' ) } htmlFor="mv-dates">
							<DateRangePicker id="mv-dates" value={ range } onChange={ setRange } showNights min={ today } className="w-full" />
						</Field>
					</div>
					<DialogFooter>
						<Button variant="secondary" onClick={ () => onOpenChange( false ) }>
							{ __( 'Cancel', 'marclie-booking' ) }
						</Button>
						<Button
							variant="ink"
							disabled={ ! range.from || ! range.to || unchanged }
							onClick={ () => range.from && range.to && setTarget( { unit_id: unit, arrival: range.from, departure: range.to } ) }
						>
							{ __( 'Review change', 'marclie-booking' ) }
						</Button>
					</DialogFooter>
				</DialogContent>
			</Dialog>
			<MoveConfirmDialog
				booking={ { ...booking, guest_name: booking.guest.name } }
				target={ target }
				open={ open && target !== null }
				onOpenChange={ ( next ) => {
					if ( ! next ) {
						setTarget( null );
						onOpenChange( false );
					}
				} }
			/>
		</>
	);
}
