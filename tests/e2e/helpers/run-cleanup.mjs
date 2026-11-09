// `npm run cleanup`: the global teardown on its own (after an aborted run).
import { execSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname( fileURLToPath( import.meta.url ) );
const root = path.resolve( process.env.MBK_E2E_WP_ROOT ?? path.join( here, '..', '..', '..', '..', '..', '..' ) );
const cli = process.env.MBK_E2E_WP_CLI ?? 'studio wp';
const helper = path.relative( root, path.join( here, 'wp-helper.php' ) ).split( path.sep ).join( '/' );

process.stdout.write( execSync( `${ cli } eval-file "${ helper }" cleanup`, { cwd: root, encoding: 'utf8' } ) );
for ( const file of [ 'wp-content/mu-plugins/mbk-e2e-pw-mail-capture.php', 'wp-content/mbk-e2e-pw-mail.jsonl' ] ) {
	fs.rmSync( path.join( root, file ), { force: true } );
}
fs.rmSync( path.join( here, '..', '.auth' ), { recursive: true, force: true } );
