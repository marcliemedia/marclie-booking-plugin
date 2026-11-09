/**
 * Global "New booking" wizard, openable from anywhere:
 * `useBookingWizard().open({ guest, unitId, arrival, departure })`.
 */
import { lazy, Suspense } from 'react';

import type { Guest, GuestRef } from '@/api/types';
import { createStore, useStore } from '@/lib/store';

export interface WizardPrefill {
	guest?: Guest | GuestRef;
	unitId?: number;
	arrival?: string;
	departure?: string;
}

const wizardStore = createStore< { open: boolean; prefill: WizardPrefill; key: number } >( {
	open: false,
	prefill: {},
	key: 0,
} );

const BookingWizard = lazy( () => import( /* webpackChunkName: "booking-wizard" */ './BookingWizard' ) );

export function useBookingWizard() {
	return {
		open: ( prefill: WizardPrefill = {} ) =>
			wizardStore.set( ( prev ) => ( { open: true, prefill, key: prev.key + 1 } ) ),
		close: () => wizardStore.set( ( prev ) => ( { ...prev, open: false } ) ),
	};
}

export function BookingWizardHost() {
	const state = useStore( wizardStore );
	if ( state.key === 0 ) {
		return null;
	}
	return (
		<Suspense fallback={ null }>
			<BookingWizard
				key={ state.key }
				open={ state.open }
				prefill={ state.prefill }
				onClose={ () => wizardStore.set( ( prev ) => ( { ...prev, open: false } ) ) }
			/>
		</Suspense>
	);
}
