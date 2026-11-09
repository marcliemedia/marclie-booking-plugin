/**
 * Builds `marclie-booking.zip` in the plugin root.
 *
 * `wp-scripts plugin-zip` only knows the Plugin Handbook glob (no `src/`,
 * `vendor/`) or a package.json `files` list, and ignores `.distignore`.
 * This script zips the whole plugin root minus `.distignore` patterns
 * (gitignore syntax), under a `marclie-booking/` root folder.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const AdmZip = require( 'adm-zip' );
const glob = require( 'fast-glob' );
const ignore = require( 'ignore' );

const pluginRoot = path.resolve( __dirname, '../..' );
const slug = path.basename( pluginRoot );
const zipPath = path.join( pluginRoot, `${ slug }.zip` );

if ( ! fs.existsSync( path.join( pluginRoot, 'build/admin/index.asset.php' ) ) ) {
	process.stderr.write( 'build/admin is missing — run `npm run build` first.\n' );
	process.exit( 1 );
}

// The release ships vendor/, so it must be a production (--no-dev) install.
const composerFix =
	'Run `composer install --no-dev --optimize-autoloader` in the plugin root, then re-run `composer install` for local dev.\n';
if ( ! fs.existsSync( path.join( pluginRoot, 'vendor/autoload.php' ) ) ) {
	process.stderr.write( `vendor/autoload.php is missing. ${ composerFix }` );
	process.exit( 1 );
}
const installedPath = path.join( pluginRoot, 'vendor/composer/installed.json' );
let installed;
try {
	installed = JSON.parse( fs.readFileSync( installedPath, 'utf8' ) );
} catch ( error ) {
	process.stderr.write( `Cannot read vendor/composer/installed.json (${ error.message }). ${ composerFix }` );
	process.exit( 1 );
}
if ( installed.dev !== false ) {
	process.stderr.write( `vendor/ contains dev dependencies. ${ composerFix }` );
	process.exit( 1 );
}

const distignorePath = path.join( pluginRoot, '.distignore' );
const ig = ignore();
if ( fs.existsSync( distignorePath ) ) {
	ig.add( fs.readFileSync( distignorePath, 'utf8' ) );
}

const files = ig
	.filter(
		glob.sync( '**', {
			cwd: pluginRoot,
			dot: true,
			onlyFiles: true,
			ignore: [ '**/node_modules/**', `${ slug }.zip` ],
		} )
	)
	.sort();

const zip = new AdmZip();
for ( const file of files ) {
	const dir = path.posix.dirname( file );
	zip.addLocalFile(
		path.join( pluginRoot, file ),
		dir === '.' ? `${ slug }/` : `${ slug }/${ dir }/`
	);
}
zip.writeZip( zipPath );

process.stdout.write( `${ files.length } files → ${ zipPath }\n` );
