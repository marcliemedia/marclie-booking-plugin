const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		index: path.resolve( __dirname, 'src/main.tsx' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, '../build/admin' ),
		// Lazy chunks are requested without a ?ver= query; hash them so updates bust caches.
		chunkFilename: '[name].[contenthash:8].js',
	},
	resolve: {
		...defaultConfig.resolve,
		alias: {
			...( defaultConfig.resolve?.alias ?? {} ),
			'@': path.resolve( __dirname, 'src' ),
		},
	},
};
