import type { BookingStatus, CalendarData } from '@/api/types';
import { addDays } from '@/lib/dates';

/** Statuses that hold nights on the calendar. */
export const OCCUPYING: Record< BookingStatus, boolean > = {
	pending_payment: true,
	confirmed: true,
	paid: true,
	balance_failed: true,
	completed: true,
	cancelled: false,
	expired: false,
};

/** Occupied nights (Y-m-d of each night) for one unit; `exceptBookingId` ignores a booking being moved. */
export function occupiedNights( data: CalendarData | undefined, unitId: number, exceptBookingId?: number ): Set< string > {
	const nights = new Set< string >();
	if ( ! data ) {
		return nights;
	}
	const add = ( from: string, to: string ) => {
		for ( let day = from; day < to; day = addDays( day, 1 ) ) {
			nights.add( day );
		}
	};
	data.bookings.forEach( ( booking ) => {
		if ( booking.unit_id === unitId && booking.id !== exceptBookingId && OCCUPYING[ booking.status ] ) {
			add( booking.arrival, booking.departure );
		}
	} );
	data.blocks.forEach( ( block ) => block.unit_id === unitId && add( block.date_from, block.date_to ) );
	data.external.forEach( ( block ) => block.unit_id === unitId && add( block.date_from, block.date_to ) );
	return nights;
}
