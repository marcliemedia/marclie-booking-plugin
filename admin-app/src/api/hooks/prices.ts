import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '@/api/client';
import type { PricesResponse, UnitPrices } from '@/api/types';

export const priceKeys = {
	prices: [ 'prices' ] as const,
};

/** Monthly per-person price table of every unit. */
export function usePrices() {
	return useQuery( {
		queryKey: priceKeys.prices,
		queryFn: () => api.get< PricesResponse >( '/prices' ),
	} );
}

/** Save full rows (min stay + 12 months) of the changed units. */
export function useSavePrices() {
	const qc = useQueryClient();
	return useMutation( {
		mutationFn: ( changes: UnitPrices[] ) => api.put< PricesResponse >( '/prices', { changes } ),
		onSuccess: ( data ) => {
			qc.setQueryData( priceKeys.prices, data );
			qc.invalidateQueries( { queryKey: [ 'calendar' ] } );
		},
	} );
}
