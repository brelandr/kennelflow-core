<?php
/**
 * E2E teardown: restore kf_required_vaccines from E2E_COMPLIANCE_SNAPSHOT;
 * delete kf_medical_records row when E2E_RECORD_ID > 0.
 *
 * @package KennelFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$raw = getenv( 'E2E_COMPLIANCE_SNAPSHOT' );
$raw = is_string( $raw ) ? trim( $raw ) : '';
if ( '' !== $raw ) {
	$json = json_decode( $raw, true );
	if ( is_array( $json ) && isset( $json['ltkf_required_vaccines'] ) ) {
		$v = $json['ltkf_required_vaccines'];
		if ( ! is_array( $v ) ) {
			$v = array();
		}
		$v = array_map( 'sanitize_text_field', $v );
		$v = array_values( array_filter( $v ) );
		update_option( 'ltkf_required_vaccines', $v );
	}
}

$record_id = absint( getenv( 'E2E_RECORD_ID' ) );
if ( $record_id > 0 && function_exists( 'ltkf_medical_records_table_name' ) && function_exists( 'ltkf_table_exists' ) ) {
	$table = ltkf_medical_records_table_name();
	if ( ltkf_table_exists( $table ) ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- E2E teardown.
		$wpdb->delete( $table, array( 'id' => $record_id ), array( '%d' ) );
	}
}

echo wp_json_encode( array( 'ok' => true ) );
