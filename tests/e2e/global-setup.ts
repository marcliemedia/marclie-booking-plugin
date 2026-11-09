/**
 * Before the suite: site reachable, leftovers of an interrupted run removed, mail capture
 * mu-plugin installed (mail to @mbk-e2e-pw.test is logged, never sent), admin session saved.
 */
import { chromium, request, type FullConfig } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

import { cleanup, MAIL_LOG, MAIL_PLUGIN } from './helpers/wp';

export const ADMIN_STATE = path.join( __dirname, '.auth', 'admin.json' );

export default async function globalSetup( config: FullConfig ): Promise< void > {
	const baseURL = String( config.projects[ 0 ].use.baseURL );

	const api = await request.newContext( { baseURL, ignoreHTTPSErrors: true } );
	const res = await api.get( '/wp-json/marclie-booking/v1/search' );
	if ( ! res.ok() ) {
		throw new Error( `Site not reachable at ${ baseURL } (GET /search → ${ res.status() }).` );
	}
	await api.dispose();

	const leftovers = cleanup();
	console.log( 'e2e setup: leftovers removed', JSON.stringify( leftovers ) );

	fs.mkdirSync( path.dirname( MAIL_PLUGIN ), { recursive: true } );
	fs.copyFileSync( path.join( __dirname, 'helpers', 'mail-capture.php' ), MAIL_PLUGIN );
	fs.rmSync( MAIL_LOG, { force: true } );

	fs.mkdirSync( path.dirname( ADMIN_STATE ), { recursive: true } );
	const browser = await chromium.launch();
	const context = await browser.newContext( { baseURL, ignoreHTTPSErrors: true } );
	const page = await context.newPage();
	await page.goto( `/studio-auto-login?redirect_to=${ encodeURIComponent( `${ baseURL }/wp-admin/admin.php?page=marclie-booking` ) }` );
	await page.waitForSelector( '#mbk-app', { timeout: 60_000 } );
	await context.storageState( { path: ADMIN_STATE } );
	await browser.close();
}
