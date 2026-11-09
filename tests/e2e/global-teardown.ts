/**
 * After the suite: every @mbk-e2e-pw.test booking/guest/user/Stripe customer, PWE2E coupon and
 * [PW-E2E] template edit is deleted; the mail capture mu-plugin, its log and the admin session go.
 */
import fs from 'node:fs';

import { ADMIN_STATE } from './global-setup';
import { cleanup, MAIL_LOG, MAIL_PLUGIN } from './helpers/wp';

export default async function globalTeardown(): Promise< void > {
	try {
		console.log( 'e2e teardown: removed', JSON.stringify( cleanup() ) );
	} finally {
		fs.rmSync( MAIL_PLUGIN, { force: true } );
		fs.rmSync( MAIL_LOG, { force: true } );
		fs.rmSync( ADMIN_STATE, { force: true } );
	}
}
