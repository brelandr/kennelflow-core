<?php
/**
 * Remove E2E kf_medical_records row (env E2E_CRM_RECORD_ID).
 *
 * @package KennelFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'ltkf_medical_records_table_name' ) || ! function_exists( 'ltkf_table_exists' ) ) {
	exit( 0 );
}

$table = ltkf_medical_records_table_name();
if ( ! ltkf_table_exists( $table ) ) {
	exit( 0 );
}

$id = absint( getenv( 'E2E_CRM_RECORD_ID' ) );
if ( $id < 1 ) {
	exit( 0 );
}

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- E2E cleanup.
$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
