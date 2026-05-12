<?php
/**
 * Remove E2E occupancy seed row (via `wp eval-file`).
 *
 * @package KennelFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$table        = $wpdb->prefix . 'kf_bookings';
$seed_post_id = 888888001;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->delete( $table, array( 'post_id' => $seed_post_id ), array( '%d' ) );

wp_cache_delete( 'ltkf_occ_pct_' . gmdate( 'Y-m-d' ), 'kennelflow_availability' );
