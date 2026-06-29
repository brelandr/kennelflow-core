<?php
/**
 * Plugin Name:       KennelFlow Core
 * Plugin URI:         https://wordpress.org/plugins/kennelflow-core/
 * Description:        Hub foundation for KennelFlow: shared pets & locations, owner↔pet user mapping, and contracts for add-ons (KennelFlow Boarding, KennelFlow Vet, KennelFlow Groom, etc.).
 * Version:            0.3.23
 * Requires at least:  6.2
 * Requires PHP:       7.4
 * Tested up to:       6.8
 * Author:             LandTech Web Designs
 * License:            GPL-2.0-or-later
 * License URI:        https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:        kennelflow-core
 *
 * @package KennelFlow
 */

defined( 'ABSPATH' ) || exit;

define( 'LTKF_CORE_VERSION', '0.3.23' );
define( 'LTKF_PLUGIN_FILE', __FILE__ );
define( 'LTKF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LTKF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LTKF_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once LTKF_PLUGIN_DIR . 'includes/functions-ltkf.php';
require_once LTKF_PLUGIN_DIR . 'includes/class-ltkf-plugin-dependencies.php';
require_once LTKF_PLUGIN_DIR . 'ltkf-global-function-wrappers.php';
require_once LTKF_PLUGIN_DIR . 'includes/class-autoloader.php';

\Landtech\KennelFlow\Core\Autoloader::register();

/**
 * Bootstrap KennelFlow Core on init.
 *
 * @return void
 */
function ltkf_bootstrap() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;

	if ( class_exists( \Landtech\KennelFlow\Core\Activator::class ) ) {
		\Landtech\KennelFlow\Core\Activator::migrate_legacy_options();
	}

	$plugin = new \Landtech\KennelFlow\Core\Plugin();
	$plugin->init();
}
add_action( 'init', 'ltkf_bootstrap', 1 );

register_activation_hook( LTKF_PLUGIN_FILE, array( \Landtech\KennelFlow\Core\Activator::class, 'activate' ) );
register_deactivation_hook( LTKF_PLUGIN_FILE, array( \Landtech\KennelFlow\Core\Deactivator::class, 'deactivate' ) );
