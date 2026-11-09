/**
 * Client-side CSV export of the current booking filter (pages through the API).
 * Columns follow the eVisitor guest list (name, country, stay dates, guests).
 */
import { __ } from '@wordpress/i18n';

import { api, type Query } from '@/api/client';
import type { BookingListQuery, BookingListResponse, BookingSummary } from '@/api/types';
import { statusLabel, sourceLabel } from '@/components/app/StatusBadge';
import { downloadCsv, toCsv, type CsvValue } from '@/lib/csv';

const PAGE_SIZE = 100;

export async function fetchAllBookings( query: BookingListQuery ): Promise< BookingSummary[] > {
	const items: BookingSummary[] = [];
	let page = 1;
	for ( ;; ) {
		const response = await api.get< BookingListResponse >( '/bookings', {
			...( query as Query ),
			page,
			per_page: PAGE_SIZE,
		} );
		items.push( ...response.items );
		if ( items.length >= response.total || response.items.length === 0 ) {
			return items;
		}
		page++;
	}
}

export async function exportBookingsCsv( query: BookingListQuery ): Promise< number > {
	const items = await fetchAllBookings( query );
	const header = [
		__( 'Ref', 'marclie-booking' ),
		__( 'Status', 'marclie-booking' ),
		__( 'Source', 'marclie-booking' ),
		__( 'Apartment', 'marclie-booking' ),
		__( 'Arrival', 'marclie-booking' ),
		__( 'Departure', 'marclie-booking' ),
		__( 'Nights', 'marclie-booking' ),
		__( 'Adults (11+)', 'marclie-booking' ),
		__( 'Children (5–10)', 'marclie-booking' ),
		__( 'Children (0–4)', 'marclie-booking' ),
		__( 'Guest name', 'marclie-booking' ),
		__( 'Email', 'marclie-booking' ),
		__( 'Country', 'marclie-booking' ),
		__( 'Total (EUR)', 'marclie-booking' ),
		__( 'Paid (EUR)', 'marclie-booking' ),
		__( 'Balance (EUR)', 'marclie-booking' ),
		__( 'Created', 'marclie-booking' ),
	];
	const rows: CsvValue[][] = items.map( ( booking ) => [
		booking.ref,
		statusLabel( booking.status ),
		sourceLabel( booking.source ),
		booking.unit_title,
		booking.arrival,
		booking.departure,
		booking.nights,
		booking.adults,
		booking.children,
		booking.infants,
		booking.guest.name,
		booking.guest.email,
		booking.guest.country ?? '',
		booking.total_cents / 100,
		booking.paid_cents / 100,
		booking.balance_cents / 100,
		booking.created_at,
	] );
	downloadCsv( 'bookings', toCsv( [ header, ...rows ] ) );
	return items.length;
}
