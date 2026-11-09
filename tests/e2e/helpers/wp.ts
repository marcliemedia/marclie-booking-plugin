/**
 * Bridge to `helpers/wp-helper.php` through WP-CLI (`studio wp eval-file …` by default).
 *
 * MBK_E2E_WP_ROOT  WordPress root (default: the `wordpress-local` folder holding wp-content/plugins/marclie-booking)
 * MBK_E2E_WP_CLI   WP-CLI command (default: `studio wp`; e.g. `wp` on a plain install)
 */
import { execSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

export const E2E_DOMAIN = '@mbk-e2e-pw.test';
export const COUPON_PREFIX = 'PWE2E';
export const TEMPLATE_MARKER = '[PW-E2E]';

export const WP_ROOT = path.resolve( process.env.MBK_E2E_WP_ROOT ?? path.join( __dirname, '..', '..', '..', '..', '..', '..' ) );
const WP_CLI = process.env.MBK_E2E_WP_CLI ?? 'studio wp';
const HELPER = path.relative( WP_ROOT, path.join( __dirname, 'wp-helper.php' ) ).split( path.sep ).join( '/' );

export const MAIL_LOG = path.join( WP_ROOT, 'wp-content', 'mbk-e2e-pw-mail.jsonl' );
export const MAIL_PLUGIN = path.join( WP_ROOT, 'wp-content', 'mu-plugins', 'mbk-e2e-pw-mail-capture.php' );

export interface PaymentRow {
	kind: string;
	status: string;
	amount: number;
	refund: string;
}

export interface BookingRow {
	id: string;
	ref: string;
	unit_id: string;
	status: string;
	arrival: string;
	departure: string;
	total_cents: string;
	deposit_cents: string;
	discount_cents: string;
	coupon_code: string | null;
	hold_expires_at: string | null;
	source: string;
	payments: PaymentRow[];
}

/** Runs one helper action and returns its JSON line. */
export function wp< T = Record< string, unknown > >( ...args: string[] ): T {
	const quoted = [ HELPER, ...args ].map( ( a ) => `"${ a.replace( /"/g, '\\"' ) }"` ).join( ' ' );
	const out = execSync( `${ WP_CLI } eval-file ${ quoted }`, { cwd: WP_ROOT, encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ], timeout: 120_000 } );
	const line = out
		.split( /\r?\n/ )
		.map( ( l ) => l.trim() )
		.reverse()
		.find( ( l ) => l.startsWith( '{' ) );
	if ( ! line ) {
		throw new Error( `wp-helper ${ args.join( ' ' ) }: no JSON in output:\n${ out }` );
	}
	return JSON.parse( line ) as T;
}

export const resetLimits = () => wp( 'reset-limits' );

export const bookingsOf = ( email: string ) => wp< { bookings: BookingRow[] } >( 'bookings', email ).bookings;

export const expireHold = ( email: string ) => wp< { expired: number; bookings: BookingRow[] } >( 'expire-hold', email );

export const seedPaid = ( email: string, arrival: string, departure: string ) =>
	wp< { id: number; ref: string; unit_id: number; status: string } >( 'seed-paid', email, arrival, departure );

export const template = ( code: string, lang: string ) => wp< { subject: string; customized: boolean } >( 'template', code, lang );

export const cleanup = () => wp( 'cleanup' );

/** Unique guest email of the E2E domain. */
export function guestEmail( tag: string ): string {
	return `pw.${ tag }.${ Date.now().toString( 36 ) }${ Math.random().toString( 36 ).slice( 2, 6 ) }${ E2E_DOMAIN }`;
}

export interface CapturedMail {
	time: string;
	to: string[];
	subject: string;
	bytes: number;
}

/** Mail captured by the temporary mu-plugin (only mail to the E2E domain). */
export function capturedMail(): CapturedMail[] {
	if ( ! fs.existsSync( MAIL_LOG ) ) {
		return [];
	}
	return fs
		.readFileSync( MAIL_LOG, 'utf8' )
		.split( /\r?\n/ )
		.filter( Boolean )
		.map( ( l ) => JSON.parse( l ) as CapturedMail );
}
