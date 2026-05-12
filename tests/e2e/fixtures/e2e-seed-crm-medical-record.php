<?php
/**
 * Insert one kf_medical_records row expiring in 30 days (GMT) for E2E CRM sweep (via `wp eval-file`).
 *
 * Requires: KennelFlow Core, KennelFlow Vet (kf_medical_records + kennelflow_vet_audit_log), a pet owner account.
 * Env: E2E_OWNER_USER — email of a user who owns at least one kf_pet.
 *
 * @package KennelFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$email = getenv( 'E2E_OWNER_USER' );
$email = is_string( $email ) ? trim( $email ) : '';
if ( '' === $email ) {
	fwrite( STDERR, "E2E_OWNER_USER is required.\n" );
	exit( 1 );
}

if ( ! function_exists( 'ltkf_medical_records_table_name' ) || ! function_exists( 'ltkf_table_exists' ) ) {
	fwrite( STDERR, "KennelFlow Core helpers missing.\n" );
	exit( 1 );
}

$table = ltkf_medical_records_table_name();
if ( ! ltkf_table_exists( $table ) ) {
	fwrite( STDERR, "kf_medical_records table missing — is KennelFlow Vet installed and activated?\n" );
	exit( 1 );
}

if ( ! function_exists( 'ltkf_db_column_exists' ) || ! ltkf_db_column_exists( $table, 'expiration_gmt' ) ) {
	fwrite( STDERR, "expiration_gmt column missing — upgrade KennelFlow Vet DB.\n" );
	exit( 1 );
}

if ( class_exists( \Landtech\KennelFlow\Core\ComplianceRetention::class ) ) {
	\Landtech\KennelFlow\Core\ComplianceRetention::maybe_upgrade_medical_records_schema();
}

$user = get_user_by( 'email', $email );
if ( ! $user || $user->ID < 1 ) {
	fwrite( STDERR, "User not found for E2E_OWNER_USER.\n" );
	exit( 1 );
}

$owner_key = function_exists( 'ltkf_get_pet_owner_user_meta_key' ) ? ltkf_get_pet_owner_user_meta_key() : 'kf_owner_user_id';
$pet_id    = 0;

$ids = get_user_meta( $user->ID, 'kf_owner_pet_ids', true );
if ( is_array( $ids ) ) {
	foreach ( $ids as $pid ) {
		$pid = absint( $pid );
		if ( $pid > 0 && ltkf_get_pet_post_type() === get_post_type( $pid ) ) {
			$pet_id = $pid;
			break;
		}
	}
}

if ( $pet_id < 1 ) {
	$q = new WP_Query(
		array(
			'post_type'              => ltkf_get_pet_post_type(),
			'post_status'            => array( 'publish', 'private' ),
			'posts_per_page'         => 1,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'meta_key'               => $owner_key,
			'meta_value'             => (string) $user->ID,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
		)
	);
	if ( ! empty( $q->posts[0] ) && $q->posts[0] instanceof WP_Post ) {
		$pet_id = (int) $q->posts[0]->ID;
	}
}

if ( $pet_id < 1 ) {
	fwrite( STDERR, "No kf_pet found for this owner — link a pet to E2E_OWNER_USER.\n" );
	exit( 1 );
}

try {
	$utc         = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	$target_date = $utc->modify( '+30 days' )->format( 'Y-m-d' );
} catch ( Exception $e ) {
	unset( $e );
	fwrite( STDERR, "Date error.\n" );
	exit( 1 );
}

$expiration_gmt = $target_date . ' 12:00:00';
$now_gmt        = current_time( 'mysql', true );
$created_by     = (int) $user->ID;
if ( $created_by < 1 ) {
	$created_by = 1;
}

global $wpdb;

$analyte = 'E2E CRM Rabies Vaccine';

$meta_json = wp_json_encode( array( 'source' => 'e2e_crm' ) );
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
	'analyte_code'     => 'E2E_CRM',
	'analyte_name'     => $analyte,
	'value_text'       => '',
	'unit'             => '',
	'reference_text'   => '',
	'flag'             => '',
	'collected_gmt'    => null,
	'reported_gmt'     => null,
	'expiration_gmt'   => $expiration_gmt,
	'meta_json'        => $meta_json,
	'created_gmt'      => $now_gmt,
	'created_by'       => $created_by,
);

$formats = array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' );

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
		'target'    => $target_date,
	)
);
