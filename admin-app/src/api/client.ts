/**
 * Typed REST client for `marclie-booking/v1/admin/*` on top of @wordpress/api-fetch
 * (cookie auth + `wp_rest` nonce, refreshed via admin-ajax when it expires).
 */
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

export type QueryValue = string | number | boolean | null | undefined | ( string | number )[];
export type Query = Record< string, QueryValue >;

const config = window.mbkAdmin;
const namespace = `/${ config?.restNamespace ?? 'marclie-booking/v1' }/admin`;

let configured = false;

export function setupApi(): void {
	if ( configured || ! config ) {
		return;
	}
	configured = true;
	apiFetch.use( apiFetch.createRootURLMiddleware( config.restRoot ) );
	apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );
	apiFetch.nonceEndpoint = `${ config.adminUrl }admin-ajax.php?action=rest-nonce`;
}

export class ApiError extends Error {
	code: string;
	status: number;
	data: Record< string, unknown >;

	constructor( code: string, message: string, status: number, data: Record< string, unknown > = {} ) {
		super( message );
		this.name = 'ApiError';
		this.code = code;
		this.status = status;
		this.data = data;
	}

	/** Field errors for `validation` responses (`data.fields`). */
	get fields(): Record< string, string > {
		const fields = this.data.fields;
		return fields && typeof fields === 'object' ? ( fields as Record< string, string > ) : {};
	}
}

function toApiError( error: unknown ): ApiError {
	if ( error instanceof ApiError ) {
		return error;
	}
	if ( error && typeof error === 'object' ) {
		const raw = error as { code?: unknown; message?: unknown; data?: unknown };
		const data = raw.data && typeof raw.data === 'object' ? ( raw.data as Record< string, unknown > ) : {};
		const status = typeof data.status === 'number' ? data.status : 0;
		const code = typeof raw.code === 'string' ? raw.code : 'unknown_error';
		let message = typeof raw.message === 'string' && raw.message !== '' ? raw.message : '';
		if ( code === 'fetch_error' || code === 'invalid_json' ) {
			message = __( 'The REST API is blocked or unreachable. Check security plugins / firewall rules for /wp-json/.', 'marclie-booking' );
		}
		if ( code === 'rest_cookie_invalid_nonce' || code === 'rest_forbidden' ) {
			message = message || __( 'Your session expired. Reload the page and log in again.', 'marclie-booking' );
		}
		return new ApiError( code, message || __( 'Unknown error.', 'marclie-booking' ), status, data );
	}
	return new ApiError( 'unknown_error', __( 'Unknown error.', 'marclie-booking' ), 0 );
}

export function errorMessage( error: unknown ): string {
	if ( error instanceof Error && error.message ) {
		return error.message;
	}
	return toApiError( error ).message;
}

export function buildQuery( query?: Query ): string {
	if ( ! query ) {
		return '';
	}
	const params = new URLSearchParams();
	for ( const [ key, value ] of Object.entries( query ) ) {
		if ( value === undefined || value === null || value === '' ) {
			continue;
		}
		if ( Array.isArray( value ) ) {
			value.forEach( ( item ) => params.append( `${ key }[]`, String( item ) ) );
		} else if ( typeof value === 'boolean' ) {
			params.set( key, value ? '1' : '0' );
		} else {
			params.set( key, String( value ) );
		}
	}
	const string = params.toString();
	return string ? `?${ string }` : '';
}

async function request< T >( method: string, path: string, body?: unknown, query?: Query ): Promise< T > {
	setupApi();
	try {
		return await apiFetch< T >( {
			path: `${ namespace }${ path }${ buildQuery( query ) }`,
			method,
			...( body !== undefined ? { data: body as Record< string, unknown > } : {} ),
		} );
	} catch ( error ) {
		throw toApiError( error );
	}
}

export const api = {
	get: < T >( path: string, query?: Query ) => request< T >( 'GET', path, undefined, query ),
	post: < T >( path: string, body?: unknown ) => request< T >( 'POST', path, body ?? {} ),
	patch: < T >( path: string, body?: unknown ) => request< T >( 'PATCH', path, body ?? {} ),
	put: < T >( path: string, body?: unknown ) => request< T >( 'PUT', path, body ?? {} ),
	del: < T >( path: string ) => request< T >( 'DELETE', path ),
};
