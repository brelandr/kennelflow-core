<?php
/**
 * Seed one confirmed boarding row spanning “today” for E2E surge occupancy (via `wp eval-file`).
 *
 * @package KennelFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$table = $wpdb->prefix . 'kf_bookings';

// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from prefix.
$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
if ( $found !== $table ) {
	fwrite( STDERR, "kf_bookings table missing — is KennelPress installed?\n" );
	exit( 1 );
}

$seed_post_id = 888888001;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->delete( $table, array( 'post_id' => $seed_post_id ), array( '%d' ) );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->insert(
	$table,
	array(
		'post_id'      => $seed_post_id,
		'pet_id'       => 0,
		'kennel_id'    => 1,
		'location_id'  => 1,
		'start_gmt'    => '2020-01-01 00:00:00',
		'end_gmt'      => '2030-12-31 23:59:59',
		'status'       => 'confirmed',
		'booking_kind' => 'boarding',
		'created_gmt'  => gmdate( 'Y-m-d H:i:s' ),
	),
	array( '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
);

wp_cache_delete( 'ltkf_occ_pct_' . gmdate( 'Y-m-d' ), 'kennelflow_availability' );
