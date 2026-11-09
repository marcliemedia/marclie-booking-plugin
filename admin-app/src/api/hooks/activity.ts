import { keepPreviousData, useQuery } from '@tanstack/react-query';

import { api } from '@/api/client';
import type { EventItem, ListResponse } from '@/api/types';

export function useActivity( page: number, perPage: number ) {
	return useQuery( {
		queryKey: [ 'activity', page, perPage ],
		queryFn: () => api.get< ListResponse< EventItem > >( '/activity', { page, per_page: perPage } ),
		placeholderData: keepPreviousData,
		// Settings, prices, coupons and bookings all log here: refetch on every visit.
		staleTime: 0,
	} );
}
