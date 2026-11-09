/**
 * CSV helpers safe against spreadsheet formula injection.
 *
 * Text cells starting with `= + - @ TAB CR` get a leading `'` so Excel/LibreOffice treat them
 * as text; numbers are written as-is (negative refunds stay numeric). Cells containing
 * `" , ; CR LF` are quoted.
 */
import { localToday } from '@/lib/dates';

export type CsvValue = string | number | null | undefined;

export function csvCell( value: CsvValue ): string {
	if ( value === null || value === undefined ) {
		return '';
	}
	if ( typeof value === 'number' ) {
		return Number.isFinite( value ) ? String( value ) : '';
	}
	const text = /^[=+\-@\t\r]/.test( value ) ? `'${ value }` : value;
	return /[",;\r\n]/.test( text ) ? `"${ text.replace( /"/g, '""' ) }"` : text;
}

export function toCsv( rows: CsvValue[][] ): string {
	return rows.map( ( row ) => row.map( csvCell ).join( ',' ) ).join( '\r\n' );
}

/** Download CSV text as `<basename>-<local Y-m-d>.csv` (UTF-8 BOM so Excel reads č/ć/š/ž). */
export function downloadCsv( basename: string, csv: string ): void {
	const blob = new Blob( [ '\uFEFF', csv ], { type: 'text/csv;charset=utf-8' } );
	const url = URL.createObjectURL( blob );
	const link = document.createElement( 'a' );
	link.href = url;
	link.download = `${ basename }-${ localToday() }.csv`;
	document.body.appendChild( link );
	link.click();
	link.remove();
	// Revoking synchronously can cancel the download in some browsers.
	window.setTimeout( () => URL.revokeObjectURL( url ), 1000 );
}
