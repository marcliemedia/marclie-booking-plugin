import { useSyncExternalStore } from 'react';

/** Tiny external store for app-global UI state (booking sheet, wizard, palette). */
export interface Store< T > {
	get: () => T;
	set: ( next: T | ( ( prev: T ) => T ) ) => void;
	subscribe: ( listener: () => void ) => () => void;
}

export function createStore< T >( initial: T ): Store< T > {
	let state = initial;
	const listeners = new Set< () => void >();
	return {
		get: () => state,
		set: ( next ) => {
			state = typeof next === 'function' ? ( next as ( prev: T ) => T )( state ) : next;
			listeners.forEach( ( listener ) => listener() );
		},
		subscribe: ( listener ) => {
			listeners.add( listener );
			return () => listeners.delete( listener );
		},
	};
}

export function useStore< T >( store: Store< T > ): T {
	return useSyncExternalStore( store.subscribe, store.get );
}
