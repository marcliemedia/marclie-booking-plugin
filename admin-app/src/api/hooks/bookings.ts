import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api, type Query } from '@/api/client';
import type {
	BookingDetail,
	BookingListQuery,
	BookingListResponse,
	CalendarBlock,
	CancelDecision,
	CreateBookingInput,
	MoveDryRun,
	PaymentLink,
	QuoteResponse,
	UpdateBookingInput,
	YMD,
} from '@/api/types';

export function useBookings( query: BookingListQuery ) {
	return useQuery( {
		queryKey: [ 'bookings', 'list', query ],
		queryFn: () => api.get< BookingListResponse >( '/bookings', query as Query ),
		placeholderData: keepPreviousData,
	} );
}

/** Booking detail by numeric id or by ref (`BK-27-0712-101`); ref lookups also seed the id cache. */
export function useBooking( idOrRef: number | string | null ) {
	const client = useQueryClient();
	return useQuery( {
		queryKey: [ 'bookings', 'detail', idOrRef ],
		queryFn: async () => {
			const detail = await api.get< BookingDetail >( `/bookings/${ encodeURIComponent( String( idOrRef ) ) }` );
			if ( typeof idOrRef === 'string' ) {
				client.setQueryData( [ 'bookings', 'detail', detail.id ], detail );
			}
			return detail;
		},
		enabled: typeof idOrRef === 'string' ? idOrRef !== '' : idOrRef !== null && idOrRef > 0,
	} );
}

export interface QuoteInput {
	unit_id: number;
	arrival: YMD;
	departure: YMD;
	adults: number;
	children: number;
	infants: number;
	coupon?: string;
	/** Needed for coupons restricted to one guest. */
	guest_id?: number;
}

export function useQuote( input: QuoteInput | null ) {
	return useQuery( {
		queryKey: [ 'bookings', 'quote', input ],
		queryFn: () => api.post< QuoteResponse >( '/bookings/quote', input ),
		enabled: input !== null,
		placeholderData: keepPreviousData,
		staleTime: 10_000,
	} );
}

/** Invalidate everything that shows booking data after a mutation. */
export function useInvalidateBookings() {
	const client = useQueryClient();
	return ( detail?: BookingDetail ) => {
		if ( detail ) {
			client.setQueryData( [ 'bookings', 'detail', detail.id ], detail );
		}
		void client.invalidateQueries( { queryKey: [ 'bookings', 'list' ] } );
		void client.invalidateQueries( { queryKey: [ 'calendar' ] } );
		void client.invalidateQueries( { queryKey: [ 'dashboard' ] } );
		void client.invalidateQueries( { queryKey: [ 'activity' ] } );
		void client.invalidateQueries( { queryKey: [ 'payments' ] } );
		void client.invalidateQueries( { queryKey: [ 'guests' ] } );
		void client.invalidateQueries( { queryKey: [ 'coupons' ] } );
		void client.invalidateQueries( { queryKey: [ 'search' ] } );
		void client.invalidateQueries( { queryKey: [ 'bookings', 'quote' ] } );
	};
}

export function useCreateBooking() {
	const invalidate = useInvalidateBookings();
	return useMutation( {
		mutationFn: ( input: CreateBookingInput ) => api.post< BookingDetail >( '/bookings', input ),
		onSuccess: ( detail ) => invalidate( detail ),
	} );
}

export function useUpdateBooking( id: number ) {
	const invalidate = useInvalidateBookings();
	return useMutation( {
		mutationFn: ( input: UpdateBookingInput ) => api.patch< BookingDetail >( `/bookings/${ id }`, input ),
		onSuccess: ( detail ) => invalidate( detail ),
	} );
}

export interface MoveInput {
	unit_id: number;
	arrival: YMD;
	departure: YMD;
	keep_price?: boolean;
}

export function moveDryRun( id: number, input: MoveInput ) {
	return api.post< MoveDryRun >( `/bookings/${ id }/move`, { ...input, dry_run: true } );
}

export function useMoveBooking() {
	const invalidate = useInvalidateBookings();
	return useMutation( {
		mutationFn: ( { id, ...input }: MoveInput & { id: number } ) =>
			api.post< BookingDetail >( `/bookings/${ id }/move`, { ...input, dry_run: false } ),
		onSuccess: ( detail ) => invalidate( detail ),
	} );
}

export interface CancelInput {
	refund_mode: 'policy' | 'none' | 'custom';
	amount_cents?: number;
	reason?: string;
}

export function cancelDryRun( id: number, input: CancelInput ) {
	return api.post< { booking: BookingDetail; decision: CancelDecision } >( `/bookings/${ id }/cancel`, { ...input, dry_run: true } );
}

export function useCancelBooking( id: number ) {
	const invalidate = useInvalidateBookings();
	return useMutation( {
		mutationFn: ( input: CancelInput ) =>
			api.post< { booking: BookingDetail; decision: CancelDecision } >( `/bookings/${ id }/cancel`, input ),
		onSuccess: ( result ) => invalidate( result.booking ),
	} );
}

export interface RecordPaymentInput {
	kind: 'deposit' | 'balance' | 'full';
	amount_cents: number;
	method: 'cash' | 'bank' | 'other';
	note?: string;
	/** One per dialog submit; the server rejects repeats (409 duplicate_payment otherwise within 10 s). */
	idempotency_key?: string;
}

export function useRecordPayment( id: number ) {
	const invalidate = useInvalidateBookings();
	return useMutation( {
		mutationFn: ( input: RecordPaymentInput ) => api.post< BookingDetail >( `/bookings/${ id }/payments`, input ),
		onSuccess: ( detail ) => invalidate( detail ),
	} );
}

/** Off-session charge of the outstanding amount with the saved card. 402 card_requires_action → balance_failed. */
export function useChargeBooking( id: number ) {
	const client = useQueryClient();
	const invalidate = useInvalidateBookings();
	return useMutation( {
		mutationFn: () => api.post< BookingDetail >( `/bookings/${ id }/charge` ),
		onSuccess: ( detail ) => invalidate( detail ),
		// A declined/3DS card still changes the booking (balance_failed + failed payment row).
		onError: () => {
			void client.invalidateQueries( { queryKey: [ 'bookings', 'detail', id ] } );
			invalidate();
		},
	} );
}

/** New 'pay' token link for the on-session balance page (/rezervacija/placanje/); `send_email` also e-mails it to the guest. */
export function useCreatePaymentLink( id: number ) {
	const client = useQueryClient();
	return useMutation( {
		mutationFn: ( input: { expires_days: number; send_email?: boolean } ) =>
			api.post< PaymentLink >( `/bookings/${ id }/payment-link`, input ),
		// Only the history (event log) changes.
		onSettled: () => void client.invalidateQueries( { queryKey: [ 'bookings', 'detail', id ] } ),
	} );
}

/** Blocks the same nights in every listed unit; all-or-nothing (`unavailable` if any unit is taken). */
export interface BlockInput {
	unit_ids: number[];
	date_from: YMD;
	date_to: YMD;
	reason: string;
}

export function useCreateBlock() {
	const invalidate = useInvalidateBookings();
	return useMutation( {
		mutationFn: ( input: BlockInput ) => api.post< { blocks: CalendarBlock[] } >( '/blocks', input ),
		onSuccess: () => invalidate(),
	} );
}

export function useDeleteBlock() {
	const invalidate = useInvalidateBookings();
	return useMutation( {
		mutationFn: ( id: number ) => api.del< { deleted: boolean } >( `/blocks/${ id }` ),
		onSuccess: () => invalidate(),
	} );
}
