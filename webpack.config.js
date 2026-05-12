/**
 * Extends @wordpress/scripts with WooCommerce dependency extraction so
 *
 * @woocommerce/* imports resolve to wc.* globals and appear in checkout-blocks.asset.php.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/packages/packages-scripts/#webpack-config
 * @see https://www.npmjs.com/package/@woocommerce/dependency-extraction-webpack-plugin
 */

const path          = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const WooCommerceDependencyExtractionWebpackPlugin = require( '@woocommerce/dependency-extraction-webpack-plugin' );

const defaultEntry = defaultConfig.entry;

module.exports     = {
	...defaultConfig,
	entry: () => {
		const base =
			'function' === typeof defaultEntry ? defaultEntry() : defaultEntry;
		return {
			...base,
			'checkout-blocks': path.resolve(
				__dirname,
				'src/checkout-blocks.js'
			),
		'report-card': path.resolve( __dirname, 'src/report-card.js' ),
		'permissions-matrix': path.resolve(
			__dirname,
			'src/permissions-matrix.js'
		),
		};
	},
	plugins: [
		...defaultConfig.plugins.filter(
			( plugin ) =>
			'DependencyExtractionWebpackPlugin' !== plugin.constructor.name
		),
		new WooCommerceDependencyExtractionWebpackPlugin(
			{
					// Not listed in plugin assets/packages.js; map like other wc-blocks packages.
					requestToExternal( request ) {
						if ( '@woocommerce/blocks-components' === request ) {
							return [ 'wc', 'blocksComponents' ];
						}
				},
					requestToHandle( request ) {
						if ( '@woocommerce/blocks-components' === request ) {
							return 'wc-blocks-components';
						}
				},
			}
		),
	],
};
