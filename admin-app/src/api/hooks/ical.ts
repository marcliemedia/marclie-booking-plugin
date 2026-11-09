import { type QueryClient, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '@/api/client';
import type { IcalChannel, ISO } from '@/api/types';

export interface IcalFeed {
	id: number;
	channel: IcalChannel;
	url_masked: string;
	/** Full URL — only returned to users with `mbk_manage_settings`. */
	url?: string;
	active: boolean;
	last_synced_at: ISO | null;
	last_ok_at: ISO | null;
	/** Translated at read time from `last_error_code`. */
	last_error: string | null;
	last_error_code: string | null;
	events_count: number;
	fail_since: ISO | null;
}

export type IcalExportUrls = Record< IcalChannel, string >;

export interface IcalUnit {
	unit_id: number;
	code: string;
	title: string;
	/** House slug (taxonomy `kuca`); display name comes from bootstrap units. */
	house: string;
	airbnb_listing_id: string | null;
	export: IcalExportUrls;
	feeds: IcalFeed[];
}

export interface IcalOverview {
	units: IcalUnit[];
	cron: { next_run: ISO | null; interval_min: number };
}

export interface IcalSyncResult {
	feed_id: number;
	ok: boolean;
	status: 'ok' | 'not_modified' | 'error' | 'busy' | 'inactive';
	events_count: number;
	error: string | null;
	error_code: string | null;
	/** Per-sync warning codes, e.g. `rrule_unsupported` (repeating event imported as its first occurrence only). */
	warnings: string[];
	/** New conflicts with website/admin bookings found by this sync. */
	conflicts: number;
	feed: IcalFeed | null;
}

export const icalKeys = {
	all: [ 'ical' ] as const,
};

export const ICAL_CHANNELS: IcalChannel[] = [ 'airbnb', 'booking', 'other' ];

/** Imports change availability: refresh everything that renders external blocks. */
function invalidateAfterSync( qc: QueryClient ) {
	qc.invalidateQueries( { queryKey: icalKeys.all } );
	qc.invalidateQueries( { queryKey: [ 'calendar' ] } );
	qc.invalidateQueries( { queryKey: [ 'dashboard' ] } );
}

export function useIcal( enabled = true ) {
	return useQuery( {
		queryKey: icalKeys.all,
		queryFn: () => api.get< IcalOverview >( '/ical' ),
		enabled,
	} );
}

export function useAddIcalFeed() {
	const qc = useQueryClient();
	return useMutation( {
		mutationFn: ( values: { unit_id: number; channel: IcalChannel; url: string } ) =>
			api.post< IcalFeed >( '/ical/feeds', values ),
		onSuccess: () => invalidateAfterSync( qc ),
	} );
}

export function useUpdateIcalFeed() {
	const qc = useQueryClient();
	return useMutation( {
		mutationFn: ( { id, ...values }: { id: number; url?: string; active?: boolean } ) =>
			api.patch< IcalFeed >( `/ical/feeds/${ id }`, values ),
		onSuccess: () => invalidateAfterSync( qc ),
	} );
}

export function useDeleteIcalFeed() {
	const qc = useQueryClient();
	return useMutation( {
		mutationFn: ( id: number ) => api.del< unknown >( `/ical/feeds/${ id }` ),
		onSuccess: () => invalidateAfterSync( qc ),
	} );
}

export function useSyncIcalFeed() {
	const qc = useQueryClient();
	return useMutation( {
		mutationFn: ( id: number ) => api.post< IcalSyncResult >( `/ical/feeds/${ id }/sync` ),
		onSettled: () => invalidateAfterSync( qc ),
	} );
}

export function useSyncAllIcal() {
	const qc = useQueryClient();
	return useMutation( {
		mutationFn: () => api.post< { results: IcalSyncResult[] } >( '/ical/sync-all' ),
		onSettled: () => invalidateAfterSync( qc ),
	} );
}

export function useRotateIcalExport() {
	const qc = useQueryClient();
	return useMutation( {
		mutationFn: ( unitId: number ) =>
			api.post< { unit_id: number; export: IcalExportUrls } >( `/ical/units/${ unitId }/rotate` ),
		onSuccess: ( data ) => {
			qc.setQueryData< IcalOverview >( icalKeys.all, ( old ) =>
				old
					? {
							...old,
							units: old.units.map( ( unit ) => ( unit.unit_id === data.unit_id ? { ...unit, export: data.export } : unit ) ),
					  }
					: old
			);
		},
	} );
}
