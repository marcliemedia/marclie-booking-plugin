/**
 * Regenerates languages/marclie-booking.pot and fails when WP-CLI could not
 * parse a file (make-pot only reports that under --debug and still exits 0,
 * silently dropping every string of the affected file).
 *
 * Runs from the WordPress root (three levels above the plugin). The WP-CLI
 * command defaults to `studio wp`; override with WP_CLI="wp" elsewhere.
 */
const path = require( 'path' );
const { spawnSync } = require( 'child_process' );

const pluginRoot = path.resolve( __dirname, '../..' );
const siteRoot = path.resolve( pluginRoot, '../../..' );
const rel = path.relative( siteRoot, pluginRoot ).split( path.sep ).join( '/' );
const slug = path.basename( pluginRoot );
const wpCli = process.env.WP_CLI || 'studio wp';

// One command string through the shell: `studio`/`wp` are .cmd shims on Windows. No argument contains spaces.
const command = [
	wpCli,
	'i18n',
	'make-pot',
	rel,
	`${ rel }/languages/${ slug }.pot`,
	'--exclude=admin-app,tests,vendor',
	`--domain=${ slug }`,
	'--debug',
].join( ' ' );

const result = spawnSync( command, { cwd: siteRoot, encoding: 'utf8', shell: true } );
const output = `${ result.stdout || '' }${ result.stderr || '' }`;
const failures = output.split( /\r?\n/ ).filter( ( line ) => line.includes( 'Could not parse' ) );

if ( result.status !== 0 ) {
	process.stderr.write( output );
	process.exit( result.status || 1 );
}
if ( failures.length > 0 ) {
	process.stderr.write( `make-pot could not parse ${ failures.length } file(s); their strings are missing from the POT:\n${ failures.join( '\n' ) }\n` );
	process.exit( 1 );
}
process.stdout.write( `${ slug }.pot regenerated; every file parsed.\n` );
