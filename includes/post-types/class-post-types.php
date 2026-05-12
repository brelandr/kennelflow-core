<?php
/**
 * Hub CPTs: Pet and Location (unified from legacy KennelPress / KennelFlow Vet models).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class PostTypes
 */
class PostTypes {

	/**
	 * Register CPTs on init.
	 *
	 * @return void
	 */
	public static function register() {
		self::register_pet();
		self::register_location();

		/**
		 * Fires after Hub registers `kf_pet` and `kf_location`.
		 *
		 * @since 0.1.0
		 */
		do_action( 'ltkf_core_post_types_registered' );
	}

	/**
	 * Pets (shared across KennelFlow add-ons).
	 *
	 * @return void
	 */
	protected static function register_pet() {
		$labels = array(
			'name'               => __( 'Pets', 'kennelflow-core' ),
			'singular_name'      => __( 'Pet', 'kennelflow-core' ),
			'add_new'            => __( 'Add New', 'kennelflow-core' ),
			'add_new_item'       => __( 'Add New Pet', 'kennelflow-core' ),
			'edit_item'          => __( 'Edit Pet', 'kennelflow-core' ),
			'new_item'           => __( 'New Pet', 'kennelflow-core' ),
			'view_item'          => __( 'View Pet', 'kennelflow-core' ),
			'search_items'       => __( 'Search Pets', 'kennelflow-core' ),
			'not_found'          => __( 'No pets found', 'kennelflow-core' ),
			'not_found_in_trash' => __( 'No pets found in Trash', 'kennelflow-core' ),
			'menu_name'          => __( 'Pets', 'kennelflow-core' ),
		);

		$args = array(
			'labels'          => $labels,
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => ltkf_get_hub_menu_slug(),
			'capability_type' => 'post',
			'map_meta_cap'    => true,
			'supports'        => array( 'title', 'editor', 'thumbnail' ),
			'has_archive'     => false,
			'show_in_rest'    => true,
			'rest_base'       => 'kf-pets',
			'rewrite'         => false,
		);

		$args = apply_filters( 'ltkf_pet_post_type_args', $args );

		register_post_type( ltkf_get_pet_post_type(), $args );
	}

	/**
	 * Physical locations / sites (CPT hub model).
	 *
	 * @return void
	 */
	protected static function register_location() {
		$labels = array(
			'name'               => __( 'Locations', 'kennelflow-core' ),
			'singular_name'      => __( 'Location', 'kennelflow-core' ),
			'add_new_item'       => __( 'Add New Location', 'kennelflow-core' ),
			'edit_item'          => __( 'Edit Location', 'kennelflow-core' ),
			'new_item'           => __( 'New Location', 'kennelflow-core' ),
			'view_item'          => __( 'View Location', 'kennelflow-core' ),
			'search_items'       => __( 'Search Locations', 'kennelflow-core' ),
			'not_found'          => __( 'No locations found', 'kennelflow-core' ),
			'not_found_in_trash' => __( 'No locations found in Trash', 'kennelflow-core' ),
			'menu_name'          => __( 'Locations', 'kennelflow-core' ),
		);

		$args = array(
			'labels'          => $labels,
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => ltkf_get_hub_menu_slug(),
			'menu_icon'       => 'dashicons-location',
			'capability_type' => 'post',
			'map_meta_cap'    => true,
			'supports'        => array( 'title', 'editor', 'thumbnail' ),
			'has_archive'     => false,
			'show_in_rest'    => true,
			'rest_base'       => 'kf-locations',
			'rewrite'         => false,
		);

		$args = apply_filters( 'ltkf_location_post_type_args', $args );

		register_post_type( ltkf_get_location_post_type(), $args );
	}
}
