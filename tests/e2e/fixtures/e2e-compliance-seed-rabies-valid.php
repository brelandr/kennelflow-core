<?php
/**
 * Insert one valid Rabies kf_medical_records row (expiration_gmt ≈ +1 year UTC) for E2E_PET_ID.
 *
 * analyte_name must match compliance rules (e.g. "Rabies" vs required label).
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

if ( ! function_exists( 'ltkf_db_column_exists' ) || ! ltkf_db_column_exists( $table, 'expiration_gmt' ) ) {
	fwrite( STDERR, "expiration_gmt column missing.\n" );
	exit( 1 );
}

if ( class_exists( \Landtech\KennelFlow\Core\ComplianceRetention::class ) ) {
	\Landtech\KennelFlow\Core\ComplianceRetention::maybe_upgrade_medical_records_schema();
}

$email = getenv( 'E2E_OWNER_USER' );
$email = is_string( $email ) ? trim( $email ) : '';
$user  = '' !== $email ? get_user_by( 'email', $email ) : null;
$uid   = ( $user && $user->ID > 0 ) ? (int) $user->ID : 1;

$utc = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
try {
	$expiration_gmt = $utc->modify( '+1 year' )->format( 'Y-m-d H:i:s' );
} catch ( Exception $e ) {
	unset( $e );
	fwrite( STDERR, "Date error.\n" );
	exit( 1 );
}

$now_gmt = current_time( 'mysql', true );

global $wpdb;

$analyte = 'Rabies';

$meta_json = wp_json_encode( array( 'source' => 'e2e_compliance_gatekeeper' ) );
if ( false === $meta_json ) {
	$meta_json = '{}';
}

$data = array(
	'pet_post_id'      => $pet_id,
	'pid_primary_id'   => '',
	'hl7_message_type' => '',
	'hl7_control_id'   => '',
	'obx_set_id'       => '',
	'obx_sequence'     => 1,
	'analyte_code'     => 'E2E_RABIES',
	'analyte_name'     => $analyte,
	'value_text'       => 'Valid',
	'unit'             => '',
	'reference_text'   => '',
	'flag'             => '',
	'collected_gmt'    => null,
	'reported_gmt'     => null,
	'expiration_gmt'   => $expiration_gmt,
	'meta_json'        => $meta_json,
	'created_gmt'      => $now_gmt,
	'created_by'       => $uid,
);

$formats = array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' );

if ( ltkf_db_column_exists( $table, 'status' ) ) {
	$data['status'] = class_exists( \Landtech\KennelFlow\Core\ComplianceRetention::class ) ? \Landtech\KennelFlow\Core\ComplianceRetention::RECORD_STATUS_ACTIVE : 'active';
	$formats[]      = '%s';
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- E2E fixture.
$ok = $wpdb->insert( $table, $data, $formats );

if ( false === $ok ) {
	fwrite( STDERR, "Insert failed.\n" );
	exit( 1 );
}

$record_id = (int) $wpdb->insert_id;

echo wp_json_encode(
	array(
		'ok'        => true,
		'pet_id'    => $pet_id,
		'record_id' => $record_id,
		'expires'   => $expiration_gmt,
	)
);
