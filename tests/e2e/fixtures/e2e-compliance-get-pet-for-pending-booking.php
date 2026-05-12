<?php
/**
 * Print pet_post_id for the owner’s first pending_payment boarding row (E2E_OWNER_USER).
 *
 * Used by compliance gatekeeper E2E so medical record cleanup matches the booking being paid.
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

if ( ! function_exists( 'ltkf_bookings_table_name' ) || ! function_exists( 'ltkf_table_exists' ) ) {
	fwrite( STDERR, "KennelFlow helpers missing.\n" );
	exit( 1 );
}

$table = ltkf_bookings_table_name();
if ( ! ltkf_table_exists( $table ) ) {
	fwrite( STDERR, "kf_bookings table missing.\n" );
	exit( 1 );
}

$user = get_user_by( 'email', $email );
if ( ! $user || $user->ID < 1 ) {
	fwrite( STDERR, "User not found for E2E_OWNER_USER.\n" );
	exit( 1 );
}

$pet_ids = array();
$ids     = get_user_meta( $user->ID, 'kf_owner_pet_ids', true );
if ( is_array( $ids ) ) {
	foreach ( $ids as $pid ) {
		$pid = absint( $pid );
		if ( $pid > 0 ) {
			$pet_ids[] = $pid;
		}
	}
}
$pet_ids = array_values( array_unique( $pet_ids ) );

if ( empty( $pet_ids ) ) {
	fwrite( STDERR, "No owner pet IDs in meta.\n" );
	exit( 1 );
}

global $wpdb;

$placeholders = implode( ',', array_fill( 0, count( $pet_ids ), '%d' ) );

$sql = "
	SELECT pet_id FROM `{$table}`
	WHERE status = 'pending_payment'
	AND ( booking_kind = '' OR booking_kind = 'boarding' )
	AND pet_id IN ( {$placeholders} )
	ORDER BY id DESC
	LIMIT 1
";

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- E2E fixture; IN list from fixed pet IDs.
$pet_id = $wpdb->get_var( call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $sql ), $pet_ids ) ) );

$pet_id = absint( $pet_id );
if ( $pet_id < 1 ) {
	fwrite( STDERR, "No pending_payment boarding booking found for this owner.\n" );
	exit( 1 );
}

echo (string) $pet_id;
