/**
 * Minimal hash router: `#/bookings/12?tab=payments`.
 * Deep links work without rewrite rules and survive wp-admin reloads.
 *
 * The router keeps its own "accepted" location so navigation guards
 * (`useNavigationGuard`) can veto a path change: `navigate()` asks first, and a
 * hash change from links / Back is confirmed after the fact and reverted when the
 * user decides to stay. Query-only changes on the same path are never guarded.
 */
import { __ } from '@wordpress/i18n';
import * as React from 'react';
import { useSyncExternalStore } from 'react';

import { cn } from '@/lib/utils';

export interface Route {
	/** Path without query, always starting with `/`. */
	path: string;
	/** Named params from the matched pattern (filled by `matchRoute`). */
	params: Record< string, string >;
	query: URLSearchParams;
}

function readHash(): string {
	const hash = window.location.hash.replace( /^#/, '' );
	return hash.startsWith( '/' ) ? hash : `/${ hash }`;
}

export function parseLocation( raw: string ): { path: string; query: URLSearchParams } {
	const [ path, search = '' ] = raw.split( '?' );
	const clean = path.replace( /\/+$/, '' ) || '/';
	return { path: clean, query: new URLSearchParams( search ) };
}

/* ---- guarded location store -------------------------------------------- */

const guards = new Map< symbol, string >();
const listeners = new Set< () => void >();
let accepted = readHash();

function notify(): void {
	listeners.forEach( ( listener ) => listener() );
}

/** True when leaving `from` for `to` may proceed (asks the user when a guard is active). */
function confirmLeave( from: string, to: string ): boolean {
	if ( guards.size === 0 || parseLocation( from ).path === parseLocation( to ).path ) {
		return true;
	}
	const message = [ ...guards.values() ][ guards.size - 1 ];
	return window.confirm( message );
}

function writeHash( target: string, replace: boolean ): void {
	const url = new URL( window.location.href );
	url.hash = target;
	if ( replace ) {
		window.history.replaceState( null, '', url.toString() );
	} else {
		window.history.pushState( null, '', url.toString() );
	}
}

window.addEventListener( 'hashchange', () => {
	const next = readHash();
	if ( next === accepted ) {
		return;
	}
	if ( ! confirmLeave( accepted, next ) ) {
		// Stay: put the previous location back without another hashchange.
		writeHash( accepted, true );
		return;
	}
	accepted = next;
	notify();
} );

function subscribe( callback: () => void ): () => void {
	listeners.add( callback );
	return () => listeners.delete( callback );
}

/** Current hash location (re-renders on change). Params are empty — see `useRoute`. */
export function useLocation(): { path: string; query: URLSearchParams; raw: string } {
	const raw = useSyncExternalStore( subscribe, () => accepted );
	return React.useMemo( () => ( { ...parseLocation( raw ), raw } ), [ raw ] );
}

export function navigate( to: string, options: { replace?: boolean } = {} ): void {
	const target = to.startsWith( '/' ) ? to : `/${ to }`;
	if ( target === accepted || ! confirmLeave( accepted, target ) ) {
		return;
	}
	accepted = target;
	writeHash( target, options.replace === true );
	notify();
}

/**
 * Block in-app navigation (and tab close) while `isDirty`, asking `message` first.
 * Changing only the query string of the current path is always allowed.
 */
export function useNavigationGuard( isDirty: boolean, message?: string ): void {
	const text = message ?? __( 'You have unsaved changes. Leave this page and discard them?', 'marclie-booking' );
	React.useEffect( () => {
		if ( ! isDirty ) {
			return;
		}
		const key = Symbol( 'guard' );
		guards.set( key, text );
		const onBeforeUnload = ( event: BeforeUnloadEvent ) => {
			event.preventDefault();
			event.returnValue = '';
		};
		window.addEventListener( 'beforeunload', onBeforeUnload );
		return () => {
			guards.delete( key );
			window.removeEventListener( 'beforeunload', onBeforeUnload );
		};
	}, [ isDirty, text ] );
}

/** Update query params of the current route in place (replace, no history entry). */
export function setQuery( patch: Record< string, string | number | null | undefined > ): void {
	const { path, query } = parseLocation( accepted );
	for ( const [ key, value ] of Object.entries( patch ) ) {
		if ( value === null || value === undefined || value === '' ) {
			query.delete( key );
		} else {
			query.set( key, String( value ) );
		}
	}
	const search = query.toString();
	navigate( search ? `${ path }?${ search }` : path, { replace: true } );
}

/** Match `/bookings/:id` against `/bookings/12` → `{ id: '12' }`, or null. */
export function matchPattern( pattern: string, path: string ): Record< string, string > | null {
	const patternParts = pattern.split( '/' ).filter( Boolean );
	const pathParts = path.split( '/' ).filter( Boolean );
	const params: Record< string, string > = {};
	for ( let i = 0; i < patternParts.length; i++ ) {
		const part = patternParts[ i ];
		const optional = part.endsWith( '?' );
		const value = pathParts[ i ];
		if ( part.startsWith( ':' ) ) {
			if ( value === undefined ) {
				if ( optional ) {
					continue;
				}
				return null;
			}
			params[ part.slice( 1 ).replace( /\?$/, '' ) ] = decodeURIComponent( value );
		} else if ( part !== value ) {
			return null;
		}
	}
	return pathParts.length <= patternParts.length ? params : null;
}

const RouteContext = React.createContext< Route >( {
	path: '/',
	params: {},
	query: new URLSearchParams(),
} );

export const RouteProvider = RouteContext.Provider;

/** Matched route (path, params, query) of the page being rendered. */
export function useRoute(): Route {
	return React.useContext( RouteContext );
}

export function isActivePath( current: string, target: string ): boolean {
	if ( target === '/' ) {
		return current === '/';
	}
	return current === target || current.startsWith( `${ target }/` );
}

export const Link = React.forwardRef<
	HTMLAnchorElement,
	Omit< React.AnchorHTMLAttributes< HTMLAnchorElement >, 'href' > & { to: string }
>( ( { to, className, ...props }, ref ) => (
	<a ref={ ref } href={ `#${ to.startsWith( '/' ) ? to : `/${ to }` }` } className={ cn( className ) } { ...props } />
) );
Link.displayName = 'Link';
