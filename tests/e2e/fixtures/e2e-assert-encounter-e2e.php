<?php
/**
 * Assert latest structured SOAP encounter for E2E_PET_ID contains E2E_SOAP_SUBJECTIVE_NEEDLE
 * and that kennelflow_vet_audit_log has a matching create row (emr_encounter).
 *
 * Env: E2E_PET_ID (required), E2E_SOAP_SUBJECTIVE_NEEDLE (optional, default "E2E Subj").
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

$needle = getenv( 'E2E_SOAP_SUBJECTIVE_NEEDLE' );
$needle = is_string( $needle ) && '' !== trim( $needle ) ? trim( $needle ) : 'E2E Subj';

if ( ! class_exists( 'KennelFlow_Vet_EMR_Encounters' ) || ! class_exists( 'KennelFlow_Vet_Install' ) ) {
	fwrite( STDERR, "KennelFlow Vet EMR not loaded.\n" );
	exit( 1 );
}

global $wpdb;

$enc_table = KennelFlow_Vet_Install::encounters_table();
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$row = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT id, subjective FROM `{$enc_table}` WHERE pet_post_id = %d ORDER BY id DESC LIMIT 1",
		$pet_id
	)
);

if ( ! $row || ! isset( $row->subjective ) ) {
	fwrite( STDERR, "No encounter row for pet.\n" );
	exit( 1 );
}

if ( false === strpos( (string) $row->subjective, $needle ) ) {
	fwrite( STDERR, "Subjective does not contain needle.\n" );
	exit( 1 );
}

$eid = absint( $row->id );
if ( $eid < 1 ) {
	fwrite( STDERR, "Bad encounter id.\n" );
	exit( 1 );
}

if ( ! function_exists( 'ltkf_table_exists' ) || ! ltkf_table_exists( KennelFlow_Vet_Install::audit_table() ) ) {
	fwrite( STDERR, "Audit table missing.\n" );
	exit( 1 );
}

$audit = KennelFlow_Vet_Install::audit_table();
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$n = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM `{$audit}` WHERE entity_type = %s AND entity_id = %d AND action = %s",
		'emr_encounter',
		$eid,
		'create'
	)
);

if ( $n < 1 ) {
	fwrite( STDERR, "No audit row for encounter create.\n" );
	exit( 1 );
}

echo 'OK';
