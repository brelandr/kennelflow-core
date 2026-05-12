<?php
/**
 * Autoloader for Landtech\KennelFlow\Core\* classes (PSR-4-like file naming).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class Autoloader
 */
class Autoloader {

	/**
	 * Namespace prefix (with trailing separator in length math only).
	 */
	private const PREFIX = 'Landtech\\KennelFlow\\Core\\';

	/**
	 * Register SPL autoload.
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Autoload handler: ClassName -> includes/(admin|cli|...) / class-kebab-case.php.
	 *
	 * @param string $class_name Fully-qualified class name.
	 * @return void
	 */
	public static function autoload( $class_name ) {
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::PREFIX ) );
		if ( '' === $relative ) {
			return;
		}

		$kebab = strtolower( preg_replace( '/([a-z])([A-Z])/', '$1-$2', $relative ) );
		$file  = 'class-' . $kebab . '.php';

		$paths = array(
			LTKF_PLUGIN_DIR . 'includes/' . $file,
			LTKF_PLUGIN_DIR . 'includes/admin/' . $file,
			LTKF_PLUGIN_DIR . 'includes/post-types/' . $file,
			LTKF_PLUGIN_DIR . 'includes/frontend/' . $file,
			LTKF_PLUGIN_DIR . 'includes/cli/' . $file,
		);

		foreach ( $paths as $path ) {
			if ( is_readable( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
}
