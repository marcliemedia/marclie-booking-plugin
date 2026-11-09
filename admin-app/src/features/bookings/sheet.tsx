/**
 * Global booking detail sheet. Opens over any page; on the Bookings page the
 * sheet is bound to the deep link `#/bookings/:id`.
 */
import * as React from 'react';
import { lazy, Suspense } from 'react';

import { isActivePath, matchPattern, navigate, parseLocation, useLocation } from '@/app/router';
import { createStore, useStore } from '@/lib/store';

const overlayStore = createStore< number | null >( null );

const BookingSheet = lazy( () => import( /* webpackChunkName: "booking-sheet" */ './BookingSheet' ) );

/** `#/bookings/12` → 12, `#/bookings/BK-27-0712-101` → the ref string, otherwise null. */
function routeBookingId( path: string ): number | string | null {
	const params = matchPattern( '/bookings/:id', path );
	if ( ! params ) {
		return null;
	}
	if ( /^\d+$/.test( params.id ) ) {
		return Number( params.id ) > 0 ? Number( params.id ) : null;
	}
	return /^[A-Za-z0-9][A-Za-z0-9-]*$/.test( params.id ) ? params.id : null;
}

export function useBookingSheet() {
	return {
		open: ( id: number ) => {
			const { path, query } = parseLocation( window.location.hash.replace( /^#/, '' ) );
			if ( isActivePath( path, '/bookings' ) ) {
				const search = query.toString();
				navigate( `/bookings/${ id }${ search ? `?${ search }` : '' }` );
				return;
			}
			overlayStore.set( id );
		},
		close: () => overlayStore.set( null ),
	};
}

export function BookingSheetHost() {
	const location = useLocation();
	const overlayId = useStore( overlayStore );
	const routeId = routeBookingId( location.path );

	// An overlay sheet belongs to the page it was opened on.
	React.useEffect( () => {
		overlayStore.set( null );
	}, [ location.path ] );
	const id = routeId ?? overlayId;

	const close = () => {
		if ( routeId !== null ) {
			const search = location.query.toString();
			navigate( search ? `/bookings?${ search }` : '/bookings' );
		} else {
			overlayStore.set( null );
		}
	};

	if ( id === null ) {
		return null;
	}

	return (
		<Suspense fallback={ null }>
			<BookingSheet id={ id } onClose={ close } />
		</Suspense>
	);
}
