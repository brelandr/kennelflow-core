<?php
/**
 * Admin notice when WooCommerce is not active (payments / revenue features unavailable).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class AdminWoocommerceNotice
 */
class AdminWoocommerceNotice {

	const USER_META_DISMISSED = 'ltkf_dismiss_woocommerce_inactive_notice';

	const AJAX_ACTION = 'ltkf_dismiss_wc_inactive_notice';

	const NONCE_ACTION = 'ltkf_dismiss_wc_inactive_notice';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_notice' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_dismiss_script' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'ajax_dismiss' ) );
	}

	/**
	 * Whether the current admin screen should show the KennelFlow / Woo notice.
	 *
	 * @return bool
	 */
	protected static function is_relevant_screen() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! isset( $screen->id ) ) {
			return false;
		}

		$pt = ltkf_get_pet_post_type();

		if ( in_array( $screen->id, array( 'plugins', 'dashboard', 'edit-' . $pt, $pt ), true ) ) {
			return true;
		}

		$hub = ltkf_get_hub_menu_slug();

		if ( isset( $screen->parent_file ) && $hub === $screen->parent_file ) {
			return true;
		}

		if ( 0 === strpos( $screen->id, $hub . '_page_' ) ) {
			return true;
		}

		if ( isset( $screen->parent_file ) && 'edit.php?post_type=' . $pt === $screen->parent_file ) {
			return true;
		}

		if ( 0 === strpos( $screen->id, $pt . '_page_' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Enqueue script for the dismiss action (replaces inline JS).
	 *
	 * @return void
	 */
	public static function enqueue_dismiss_script() {
		if ( class_exists( 'WooCommerce' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( get_user_meta( get_current_user_id(), self::USER_META_DISMISSED, true ) ) {
			return;
		}
		if ( ! self::is_relevant_screen() ) {
			return;
		}

		wp_enqueue_script(
			'kf-admin-wc-inactive-notice',
			LTKF_PLUGIN_URL . 'assets/js/kf-admin-wc-inactive-notice.js',
			array( 'jquery' ),
			LTKF_CORE_VERSION,
			true
		);

		wp_localize_script(
			'kf-admin-wc-inactive-notice',
			'kfWcInactiveNotice',
			array(
				'action' => self::AJAX_ACTION,
				'nonce'  => wp_create_nonce( self::NONCE_ACTION ),
			)
		);
	}

	/**
	 * Output notice.
	 *
	 * @return void
	 */
	public static function maybe_show_notice() {
		if ( class_exists( 'WooCommerce' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( get_user_meta( get_current_user_id(), self::USER_META_DISMISSED, true ) ) {
			return;
		}

		if ( ! self::is_relevant_screen() ) {
			return;
		}

		$plugins_url = admin_url( 'plugins.php' );

		echo '<div class="notice notice-warning is-dismissible kf-wc-inactive-notice" data-kf-wc-inactive-notice>';
		echo '<p>';
		printf(
			/* translators: 1: opening anchor to Plugins screen, 2: closing anchor */
			esc_html__( 'WooCommerce is not active. KennelFlow checkout, deposits, surge pricing, and portal payments require WooCommerce. %1$sInstall or activate WooCommerce%2$s when you are ready.', 'kennelflow-core' ),
			'<a href="' . esc_url( $plugins_url ) . '">',
			'</a>'
		);
		echo '</p>';
		echo '</div>';
	}

	/**
	 * Persist dismiss for current user.
	 *
	 * @return void
	 */
	public static function ajax_dismiss() {
		if ( ! check_ajax_referer( self::NONCE_ACTION, '_wpnonce', false ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Invalid security token', 'kennelflow-core' ) ),
				403
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You are not allowed to do that', 'kennelflow-core' ) ),
				403
			);
		}

		update_user_meta( get_current_user_id(), self::USER_META_DISMISSED, '1' );
		wp_send_json_success();
	}
}
