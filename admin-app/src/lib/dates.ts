/**
 * Timezone-safe helpers for calendar dates as `Y-m-d` strings.
 * Dates are handled as local midnight; arithmetic uses UTC to avoid DST drift.
 */
export type YMD = string;

export function parseYmd( ymd: YMD ): Date {
	const [ y, m, d ] = ymd.split( '-' ).map( Number );
	return new Date( y, ( m || 1 ) - 1, d || 1 );
}

export function toYmd( date: Date ): YMD {
	const y = date.getFullYear();
	const m = String( date.getMonth() + 1 ).padStart( 2, '0' );
	const d = String( date.getDate() ).padStart( 2, '0' );
	return `${ y }-${ m }-${ d }`;
}

function utc( ymd: YMD ): number {
	const [ y, m, d ] = ymd.split( '-' ).map( Number );
	return Date.UTC( y, m - 1, d );
}

export function addDays( ymd: YMD, days: number ): YMD {
	const date = new Date( utc( ymd ) + days * 86400000 );
	return date.toISOString().slice( 0, 10 );
}

/** Whole days from `a` to `b` (b - a). */
export function diffDays( a: YMD, b: YMD ): number {
	return Math.round( ( utc( b ) - utc( a ) ) / 86400000 );
}

export function startOfMonth( ymd: YMD ): YMD {
	return `${ ymd.slice( 0, 7 ) }-01`;
}

export function addMonths( ymd: YMD, months: number ): YMD {
	const [ y, m ] = ymd.split( '-' ).map( Number );
	const date = new Date( Date.UTC( y, m - 1 + months, 1 ) );
	return date.toISOString().slice( 0, 10 );
}

export function daysInMonth( ymd: YMD ): number {
	return diffDays( startOfMonth( ymd ), addMonths( ymd, 1 ) );
}

/** 0 = Monday … 6 = Sunday. */
export function weekdayIndex( ymd: YMD ): number {
	return ( new Date( utc( ymd ) ).getUTCDay() + 6 ) % 7;
}

export function minYmd( a: YMD, b: YMD ): YMD {
	return a < b ? a : b;
}

export function maxYmd( a: YMD, b: YMD ): YMD {
	return a > b ? a : b;
}

export function localToday(): YMD {
	return toYmd( new Date() );
}
