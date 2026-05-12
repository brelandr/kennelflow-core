<?php
/**
 * Delete all kf_medical_records rows for E2E_PET_ID (compliance gatekeeper E2E).
 *
 * @package KennelFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pet_id = absint( getenv( 'E2E_PET_ID' ) );
if ( $pet_id < 1 ) {
	fwrite( STDERR, "E2E_PET_ID is required.\n" );
	exit( 1 );
}

if ( ! function_exists( 'ltkf_medical_records_table_name' ) || ! function_exists( 'ltkf_table_exists' ) ) {
	fwrite( STDERR, "KennelFlow helpers missing.\n" );
	exit( 1 );
}

$table = ltkf_medical_records_table_name();
if ( ! ltkf_table_exists( $table ) ) {
	fwrite( STDERR, "kf_medical_records table missing.\n" );
	exit( 1 );
}

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- E2E fixture.
$n = $wpdb->delete( $table, array( 'pet_post_id' => $pet_id ), array( '%d' ) );

if ( false === $n ) {
	fwrite( STDERR, "Delete failed.\n" );
	exit( 1 );
}

echo (string) (int) $n;
