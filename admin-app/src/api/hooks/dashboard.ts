import { keepPreviousData, useQuery } from '@tanstack/react-query';

import { api } from '@/api/client';
import type { CalendarData, Dashboard, SearchResults, YMD } from '@/api/types';

export function useDashboard( month?: string ) {
	return useQuery( {
		queryKey: [ 'dashboard', month ?? 'current' ],
		queryFn: () => api.get< Dashboard >( '/dashboard', { month } ),
	} );
}

export function useCalendar( from: YMD, to: YMD, withPrices = false ) {
	return useQuery( {
		queryKey: [ 'calendar', from, to, withPrices ],
		queryFn: () => api.get< CalendarData >( '/calendar', { from, to, with_prices: withPrices ? 1 : undefined } ),
		placeholderData: keepPreviousData,
	} );
}

export function useSearch( q: string ) {
	const term = q.trim();
	return useQuery( {
		queryKey: [ 'search', term ],
		queryFn: () => api.get< SearchResults >( '/search', { q: term } ),
		enabled: term.length >= 2,
		placeholderData: keepPreviousData,
		staleTime: 10_000,
	} );
}
