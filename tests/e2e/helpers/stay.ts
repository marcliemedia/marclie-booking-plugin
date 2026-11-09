/**
 * Picks free stays far in the future through the public GET /search API.
 */
import type { APIRequestContext } from '@playwright/test';

export interface Stay {
	unitId: number;
	code: string;
	title: string;
	permalink: string;
	arrival: string;
	departure: string;
	nights: number;
	totalFormatted: string;
}

interface SearchUnit {
	id: number;
	code: string;
	title: string;
	permalink: string;
	available: boolean;
	fits: boolean;
	total_formatted: string | null;
}

export const addDays = ( ymd: string, n: number ): string => {
	const d = new Date( `${ ymd }T12:00:00Z` );
	d.setUTCDate( d.getUTCDate() + n );
	return d.toISOString().slice( 0, 10 );
};

/**
 * First free stay of `nights` nights arriving on or after `from` (scanning `span` days), on the
 * given unit or on the first free unit. `avoid` filters units (e.g. one taken by another step).
 */
export async function findStay(
	request: APIRequestContext,
	opts: { from: string; nights?: number; unitId?: number; span?: number; avoid?: number[] }
): Promise< Stay > {
	const nights = opts.nights ?? 4;
	for ( let i = 0; i < ( opts.span ?? 60 ); i += 1 ) {
		const arrival = addDays( opts.from, i );
		const departure = addDays( arrival, nights );
		const res = await request.get( '/wp-json/marclie-booking/v1/search', {
			params: { dolazak: arrival, odlazak: departure, odrasli: 2, djeca: 0, _: Date.now() },
		} );
		if ( ! res.ok() ) {
			throw new Error( `GET /search ${ res.status() }: ${ await res.text() }` );
		}
		const body: { units?: SearchUnit[] } = await res.json();
		const units = body.units ?? [];
		const unit = units.find(
			( u ) => u.available && u.fits && ( opts.unitId === undefined || u.id === opts.unitId ) && ! ( opts.avoid ?? [] ).includes( u.id )
		);
		if ( unit ) {
			return {
				unitId: unit.id,
				code: unit.code,
				title: unit.title,
				permalink: unit.permalink,
				arrival,
				departure,
				nights,
				totalFormatted: unit.total_formatted ?? '',
			};
		}
	}
	throw new Error( `No free stay of ${ nights } nights from ${ opts.from }.` );
}

/** Checkout URL of a stay (/rezervacija/?apartman&dolazak&odlazak&odrasli). */
export function checkoutUrl( stay: Stay, extra: Record< string, string > = {} ): string {
	const q = new URLSearchParams( { apartman: String( stay.unitId ), dolazak: stay.arrival, odlazak: stay.departure, odrasli: '2', djeca: '0', ...extra } );
	return `/rezervacija/?${ q.toString() }`;
}
