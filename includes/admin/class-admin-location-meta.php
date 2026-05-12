<?php
/**
 * Admin: Location (kf_location) — help text and discoverability (hub record is minimal by design).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class AdminLocationMeta
 */
class AdminLocationMeta {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_boxes' ), 10, 0 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_expand_meta_script' ) );
	}

	/**
	 * Open meta boxes by default (same behavior as pets; block editor loads boxes late).
	 *
	 * Disable with: add_filter( 'ltkf_location_auto_expand_meta_boxes', '__return_false' );
	 *
	 * @param string $hook_suffix Current admin screen hook.
	 * @return void
	 */
	public static function enqueue_expand_meta_script( $hook_suffix ) {
		if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
			return;
		}

		/**
		 * Whether to auto-expand meta boxes on the kf_location edit screen.
		 *
		 * @since 0.2.0
		 *
		 * @param bool $expand Default true.
		 */
		if ( ! apply_filters( 'ltkf_location_auto_expand_meta_boxes', true ) ) {
			return;
		}

		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post_type = '';
		if ( $screen && isset( $screen->post_type ) ) {
			$post_type = (string) $screen->post_type;
		} elseif ( 'post-new.php' === $hook_suffix ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
			$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
		} elseif ( 'post.php' === $hook_suffix ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
			$post_id   = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
			$post_type = $post_id > 0 ? (string) get_post_type( $post_id ) : '';
		}

		if ( ltkf_get_location_post_type() !== $post_type ) {
			return;
		}

		wp_enqueue_script(
			'kf-admin-pet-expand-meta',
			LTKF_PLUGIN_URL . 'assets/js/admin-pet-expand-meta.js',
			array(),
			LTKF_CORE_VERSION,
			true
		);
	}

	/**
	 * Register meta boxes for the location CPT.
	 *
	 * @return void
	 */
	public static function register_meta_boxes() {
		/*
		 * Use "normal" (below the editor) instead of "side" so the box reliably appears
		 * in the block editor Meta Boxes area. "Side" panels are easy to miss or stay
		 * inactive in Gutenberg's INITIALIZE_META_BOX_STATE flow.
		 */
		add_meta_box(
			'ltkf_hub_location_help',
			__( 'About this location', 'kennelflow-core' ),
			array( __CLASS__, 'render_help_box' ),
			ltkf_get_location_post_type(),
			'normal',
			'high',
			array(
				'__block_editor_compatible_meta_box' => true,
			)
		);
	}

	/**
	 * Explain minimal hub fields and where boarding/clinical settings live.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public static function render_help_box( $post ) {
		unset( $post );

		echo '<p class="description">';
		esc_html_e( 'This hub record is intentionally simple: use the title for the site name, the main editor for optional internal notes, and the featured image for branding. Kennels, rooms, and bookings reference this post by ID.', 'kennelflow-core' );
		echo '</p>';

		if ( defined( 'KENNELPRESS_VERSION' ) ) {
			$url = add_query_arg(
				array(
					'post_type' => ltkf_get_pet_post_type(),
					'page'      => 'kennelpress-facility-settings',
				),
				admin_url( 'edit.php' )
			);
			echo '<p>';
			printf(
				'<a class="button button-secondary" href="%1$s">%2$s</a>',
				esc_url( $url ),
				esc_html__( 'Kennel rules (hours, timezone, holidays)', 'kennelflow-core' )
			);
			echo '</p>';
			echo '<p class="description">';
			esc_html_e( 'Boarding hours, time zone, holidays, and blackout windows for this location are configured on the Kennel rules screen (KennelPress), not in custom fields here.', 'kennelflow-core' );
			echo '</p>';
		}

		if ( defined( 'KENNELFLOW_VET_VERSION' ) ) {
			echo '<p class="description">';
			esc_html_e( 'KennelFlow Vet exam rooms use the Clinic locations taxonomy under Rooms; that is separate from this physical Hub location.', 'kennelflow-core' );
			echo '</p>';
		}
	}
}
