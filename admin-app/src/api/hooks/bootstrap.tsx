import { useQuery } from '@tanstack/react-query';
import { createContext, useContext, useMemo, type ReactNode } from 'react';

import { api } from '@/api/client';
import type { Bootstrap, Unit } from '@/api/types';

export function useBootstrap() {
	return useQuery( {
		queryKey: [ 'bootstrap' ],
		queryFn: () => api.get< Bootstrap >( '/bootstrap' ),
		staleTime: 5 * 60_000,
	} );
}

const BootstrapContext = createContext< Bootstrap | null >( null );

export function BootstrapProvider( { value, children }: { value: Bootstrap; children: ReactNode } ) {
	return <BootstrapContext.Provider value={ value }>{ children }</BootstrapContext.Provider>;
}

/** Bootstrap payload; the app only renders once it has loaded. */
export function useBootstrapData(): Bootstrap {
	const value = useContext( BootstrapContext );
	if ( ! value ) {
		throw new Error( 'useBootstrapData() must be used inside <BootstrapProvider>.' );
	}
	return value;
}

export function useUnits(): Unit[] {
	return useBootstrapData().units;
}

export function useUnitMap(): Map< number, Unit > {
	const units = useUnits();
	return useMemo( () => new Map( units.map( ( unit ) => [ unit.id, unit ] ) ), [ units ] );
}
