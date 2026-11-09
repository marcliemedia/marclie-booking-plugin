import { ApiError } from '@/api/client';

/**
 * Map `validation` field errors from the API (`data.fields: {name: message}`) onto a form.
 *
 * `setError` matches react-hook-form's `setError(name, error)`; plain-state forms can pass
 * any function with that shape. `map` renames server fields to form fields
 * (e.g. `{ amount_cents: 'amount' }`); unmapped fields keep their server name.
 * Returns true when at least one field error was applied (caller can skip the toast).
 */
export function applyFieldErrors< K extends string >(
	error: unknown,
	setError: ( name: K, error: { type: string; message: string } ) => void,
	map: Partial< Record< string, K > > = {}
): boolean {
	if ( ! ( error instanceof ApiError ) ) {
		return false;
	}
	const entries = Object.entries( error.fields );
	entries.forEach( ( [ field, message ] ) => setError( ( map[ field ] ?? field ) as K, { type: 'server', message } ) );
	return entries.length > 0;
}
