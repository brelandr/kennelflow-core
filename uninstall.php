<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Removes options and transients using the ltkf_ prefix.
 *
 * @package KennelFlow
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/**
 * Delete all options and transients whose names are prefixed with ltkf_.
 *
 * @return void
 */
$ltkf_delete_options_for_site = static function () use ( $wpdb ) {
	$like_options = $wpdb->esc_like( 'ltkf_' ) . '%';
	$like_trans   = $wpdb->esc_like( '_transient_ltkf_' ) . '%';
	$like_timeout = $wpdb->esc_like( '_transient_timeout_ltkf_' ) . '%';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$like_options,
			$like_trans,
			$like_timeout
		)
	);
};

if ( is_multisite() ) {
	$ltkf_site_ids = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $ltkf_site_ids as $ltkf_site_id ) {
		switch_to_blog( (int) $ltkf_site_id );
		$ltkf_delete_options_for_site();
		restore_current_blog();
	}
} else {
	$ltkf_delete_options_for_site();
}
