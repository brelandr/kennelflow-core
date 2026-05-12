<?php
/**
 * Count KennelFlow Vet audit rows for the E2E CRM reminder (action crm_medical_30d_reminder).
 *
 * Env: E2E_CRM_PET_ID — pet post ID from seed output.
 *
 * @package KennelFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pet_id = absint( getenv( 'E2E_CRM_PET_ID' ) );
if ( $pet_id < 1 ) {
	fwrite( STDERR, "E2E_CRM_PET_ID required.\n" );
	exit( 1 );
}

if ( ! class_exists( 'KennelFlow_Vet_Install' ) ) {
	echo '0';
	exit( 0 );
}

global $wpdb;

$table = KennelFlow_Vet_Install::audit_table();

// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table from KennelFlow Vet install.
$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
if ( $found !== $table ) {
	echo '0';
	exit( 0 );
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- E2E; table from install.
$n = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM `{$table}` WHERE `entity_type` = %s AND `entity_id` = %d AND `action` = %s AND `new_value` LIKE %s",
		'kf_pet',
		$pet_id,
		'crm_medical_30d_reminder',
		'%E2E CRM Rabies%'
	)
);

echo (string) max( 0, $n );
