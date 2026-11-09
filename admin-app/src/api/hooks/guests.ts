import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '@/api/client';
import type { BookingSummary, Coupon, Guest, GuestAccount, ListResponse, MemberTier, Payment } from '@/api/types';

export interface GuestListParams {
	page?: number;
	per_page?: number;
	search?: string;
	tag?: string;
	country?: string;
	has_consent?: '1' | '0' | '';
	has_account?: '1' | '0' | '';
	tier?: MemberTier | '';
	orderby?: 'last_stay_at' | 'revenue' | 'bookings_count' | 'name';
	order?: 'asc' | 'desc';
}

export type GuestDetail = Guest & {
	bookings: BookingSummary[];
	payments: Payment[];
	coupons: Coupon[];
	account: GuestAccount | null;
};

export interface GuestPatch {
	first_name?: string;
	last_name?: string;
	phone?: string | null;
	country?: string | null;
	lang?: 'hr' | 'en';
	tags?: string[];
	notes?: string | null;
	marketing_consent?: boolean;
}

export const guestKeys = {
	all: [ 'guests' ] as const,
	list: ( params: GuestListParams ) => [ 'guests', 'list', params ] as const,
	detail: ( id: number ) => [ 'guests', id ] as const,
};

export function useGuests( params: GuestListParams, enabled = true ) {
	return useQuery( {
		queryKey: guestKeys.list( params ),
		queryFn: () => api.get< ListResponse< Guest > >( '/guests', { ...params } ),
		placeholderData: keepPreviousData,
		enabled,
	} );
}

export function useGuest( id: number ) {
	return useQuery( {
		queryKey: guestKeys.detail( id ),
		queryFn: () => api.get< GuestDetail >( `/guests/${ id }` ),
		enabled: id > 0,
	} );
}

export function useUpdateGuest( id: number ) {
	const qc = useQueryClient();
	return useMutation( {
		mutationFn: ( patch: GuestPatch ) => api.patch< Guest >( `/guests/${ id }`, patch ),
		onSuccess: () => {
			qc.invalidateQueries( { queryKey: guestKeys.all } );
			qc.invalidateQueries( { queryKey: [ 'bookings' ] } );
		},
	} );
}

export function useMergeGuest( id: number ) {
	const qc = useQueryClient();
	return useMutation( {
		mutationFn: ( intoId: number ) =>
			api.post< Guest >( `/guests/${ id }/merge`, { into_id: intoId } ),
		onSuccess: () => {
			// The merged-away profile no longer exists: drop it instead of refetching a 404.
			qc.removeQueries( { queryKey: guestKeys.detail( id ) } );
			for ( const key of [ guestKeys.all, [ 'bookings' ], [ 'search' ], [ 'coupons' ], [ 'payments' ] ] ) {
				qc.invalidateQueries( { queryKey: key } );
			}
		},
	} );
}

export function useAnonymizeGuest( id: number ) {
	const qc = useQueryClient();
	return useMutation( {
		mutationFn: () => api.post< Guest >( `/guests/${ id }/anonymize`, { confirm: 'ANONYMIZE' } ),
		onSuccess: () => {
			// Guest names appear on bookings, the calendar, dashboard lists, payments and search.
			for ( const key of [ guestKeys.all, [ 'bookings' ], [ 'calendar' ], [ 'dashboard' ], [ 'payments' ], [ 'search' ] ] ) {
				qc.invalidateQueries( { queryKey: key } );
			}
		},
	} );
}

/** Triggers a browser download of in-memory content. */
export function saveFile( content: string, filename: string, mime: string ): void {
	const url = URL.createObjectURL( new Blob( [ content ], { type: mime } ) );
	const a = document.createElement( 'a' );
	a.href = url;
	a.download = filename;
	document.body.appendChild( a );
	a.click();
	a.remove();
	// Revoking synchronously can cancel the download in some browsers.
	window.setTimeout( () => URL.revokeObjectURL( url ), 1000 );
}

/** Fetches the GDPR export and triggers a browser download of the JSON file. */
export async function downloadGuestExport( id: number, filename: string ): Promise< void > {
	const data = await api.get< unknown >( `/guests/${ id }/export` );
	saveFile( JSON.stringify( data, null, 2 ), filename, 'application/json' );
}
