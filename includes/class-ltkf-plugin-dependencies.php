<?php
/**
 * Map Requires Plugins slugs to installed KennelFlow spoke folder names.
 *
 * WordPress 6.5+ matches dependency slugs to plugin directory names. Legacy installs
 * (groompress/, kennelpress/) and inactive duplicate folders otherwise block Pro activation.
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class Plugin_Dependencies
 */
class Plugin_Dependencies {

	/**
	 * Canonical dependency slug => known plugin basenames (folder/file.php).
	 *
	 * @return array<string, string[]>
	 */
	public static function plugin_basenames_by_slug() {
		return array(
			'kennelflow-core'     => array( 'kennelflow-core/kennelflow-core.php' ),
			'kennelflow-groom'    => array(
				'kennelflow-groom/kennelflow-groom.php',
				'groompress/kennelflow-groom.php',
				'groompress/groompress.php',
			),
			'kennelflow-boarding' => array(
				'kennelflow-boarding/kennelflow-boarding.php',
				'kennelpress/kennelflow-boarding.php',
				'kennelpress/kennelpress.php',
			),
			'kennelflow-vet'      => array(
				'kennelflow-vet/kennelflow-vet.php',
				'vetpress/kennelflow-vet.php',
				'vetpress/vetpress.php',
			),
		);
	}

	/**
	 * Register slug mapper before WP_Plugin_Dependencies reads headers.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'wp_plugin_dependencies_slug', array( __CLASS__, 'map_dependency_slug' ), 10, 1 );
	}

	/**
	 * Resolve a Requires Plugins slug to an installed plugin folder name.
	 *
	 * @param string $slug Dependency slug from a plugin header.
	 * @return string
	 */
	public static function map_dependency_slug( $slug ) {
		$slug = sanitize_key( (string) $slug );
		$map  = self::plugin_basenames_by_slug();

		if ( ! isset( $map[ $slug ] ) ) {
			return $slug;
		}

		$active = self::find_active_basename( $slug );
		if ( $active ) {
			return self::basename_to_slug( $active );
		}

		$installed = self::find_installed_basename( $slug );
		if ( $installed ) {
			return self::basename_to_slug( $installed );
		}

		return $slug;
	}

	/**
	 * Whether a dependency slug is satisfied (installed and active).
	 *
	 * @param string $slug Canonical dependency slug.
	 * @return bool
	 */
	public static function is_dependency_active( $slug ) {
		$slug = sanitize_key( (string) $slug );

		if ( 'kennelflow-core' === $slug && defined( 'LTKF_CORE_VERSION' ) ) {
			return true;
		}
		if ( 'kennelflow-groom' === $slug && defined( 'KENNELFLOW_GROOM_VERSION' ) ) {
			return true;
		}
		if ( 'kennelflow-boarding' === $slug && defined( 'KENNELFLOW_BOARDING_VERSION' ) ) {
			return true;
		}
		if ( 'kennelflow-vet' === $slug && defined( 'KENNELFLOW_VET_VERSION' ) ) {
			return true;
		}

		$basename = self::find_active_basename( $slug );
		return (bool) $basename;
	}

	/**
	 * Human-readable dependency label.
	 *
	 * @param string $slug Canonical slug.
	 * @return string
	 */
	public static function dependency_label( $slug ) {
		$labels = array(
			'kennelflow-core'     => __( 'KennelFlow Core', 'kennelflow-core' ),
			'kennelflow-groom'    => __( 'KennelFlow Groom', 'kennelflow-core' ),
			'kennelflow-boarding' => __( 'KennelFlow Boarding', 'kennelflow-core' ),
			'kennelflow-vet'      => __( 'KennelFlow Vet', 'kennelflow-core' ),
		);

		return isset( $labels[ $slug ] ) ? $labels[ $slug ] : $slug;
	}

	/**
	 * First active basename for a canonical slug, if any.
	 *
	 * @param string $slug Canonical slug.
	 * @return string Empty when none active.
	 */
	protected static function find_active_basename( $slug ) {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$map = self::plugin_basenames_by_slug();
		if ( ! isset( $map[ $slug ] ) ) {
			return '';
		}

		foreach ( $map[ $slug ] as $basename ) {
			if ( is_plugin_active( $basename ) ) {
				return $basename;
			}
		}

		return '';
	}

	/**
	 * First installed basename for a canonical slug, if any.
	 *
	 * @param string $slug Canonical slug.
	 * @return string Empty when none installed.
	 */
	protected static function find_installed_basename( $slug ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$map     = self::plugin_basenames_by_slug();
		$plugins = get_plugins();

		if ( ! isset( $map[ $slug ] ) ) {
			return '';
		}

		foreach ( $map[ $slug ] as $basename ) {
			if ( isset( $plugins[ $basename ] ) ) {
				return $basename;
			}
		}

		return '';
	}

	/**
	 * Folder slug from a plugin basename.
	 *
	 * @param string $basename Plugin basename.
	 * @return string
	 */
	protected static function basename_to_slug( $basename ) {
		if ( false === strpos( $basename, '/' ) ) {
			return str_replace( '.php', '', $basename );
		}

		return dirname( $basename );
	}
}

Plugin_Dependencies::register();
