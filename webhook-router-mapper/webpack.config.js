const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
module.exports = {
	...defaultConfig,
	entry: {
		'wrm-admin-app': './src/admin/index.js',
	},
};
