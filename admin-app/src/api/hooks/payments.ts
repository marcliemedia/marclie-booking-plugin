import { keepPreviousData, useQuery } from '@tanstack/react-query';

import { api } from '@/api/client';
import type { BookingSummary, Cents, ListResponse, Payment, YMD } from '@/api/types';

export interface PaymentListParams {
	page?: number;
	per_page?: number;
	status?: Payment[ 'status' ] | '';
	kind?: Payment[ 'kind' ] | '';
	method?: Payment[ 'method' ] | '';
	from?: YMD;
	to?: YMD;
	orderby?: string;
	order?: 'asc' | 'desc';
}

export interface PaymentTotals {
	succeeded_cents: Cents;
	refunded_cents: Cents;
	net_cents: Cents;
}

export type PaymentListResponse = ListResponse< Payment > & { totals: PaymentTotals };

export interface ScheduledPayment {
	booking: BookingSummary;
	amount_cents: Cents;
	due_on: YMD;
}

export const paymentKeys = {
	all: [ 'payments' ] as const,
	list: ( params: PaymentListParams ) => [ 'payments', 'list', params ] as const,
	scheduled: ( days: number ) => [ 'payments', 'scheduled', days ] as const,
};

export function usePayments( params: PaymentListParams ) {
	return useQuery( {
		queryKey: paymentKeys.list( params ),
		queryFn: () => api.get< PaymentListResponse >( '/payments', { ...params } ),
		placeholderData: keepPreviousData,
	} );
}

export function useScheduledPayments( days = 30 ) {
	return useQuery( {
		queryKey: paymentKeys.scheduled( days ),
		queryFn: () => api.get< { items: ScheduledPayment[] } >( '/payments/scheduled', { days } ),
	} );
}

/** Fetches every page for the given filters (CSV export). */
export async function fetchAllPayments( params: PaymentListParams ): Promise< Payment[] > {
	const out: Payment[] = [];
	let page = 1;
	for ( ;; ) {
		const res = await api.get< PaymentListResponse >( '/payments', {
			...params,
			page,
			per_page: 100,
		} );
		out.push( ...res.items );
		if ( res.items.length === 0 || out.length >= res.total ) {
			return out;
		}
		page++;
	}
}
