<?php
/**
 * Insert one pending kf_commissions row for E2E_GROOMER_ID (WordPress user id).
 * Uses a unique (order_id, booking_id) pair so the row does not collide with real orders.
 *
 * Requires: GroomPress active, wp_kf_commissions exists.
 *
 * @package KennelFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'GroomPress_Install' ) || ! GroomPress_Install::commissions_table_exists() ) {
	fwrite( STDERR, "GroomPress commissions table missing.\n" );
	exit( 1 );
}

$groomer = absint( getenv( 'E2E_GROOMER_ID' ) );
if ( $groomer < 1 ) {
	fwrite( STDERR, "E2E_GROOMER_ID is required.\n" );
	exit( 1 );
}

global $wpdb;

$table = GroomPress_Install::commissions_table_name();
$base  = (int) ( microtime( true ) * 1000 ) % 100000000;
$oid   = 880000000 + $base;
$bid   = $base + 1;

$now = gmdate( 'Y-m-d H:i:s' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
$ok = $wpdb->insert(
	$table,
	array(
		'staff_user_id'     => $groomer,
		'order_id'          => $oid,
		'booking_id'        => $bid,
		'gross_amount'      => 100.0,
		'commission_amount' => 25.0,
		'status'            => 'pending',
		'created_gmt'       => $now,
	),
	array( '%d', '%d', '%d', '%f', '%f', '%s', '%s' )
);

if ( false === $ok ) {
	fwrite( STDERR, "Insert failed.\n" );
	exit( 1 );
}

echo wp_json_encode(
	array(
		'ok'         => true,
		'row_id'     => (int) $wpdb->insert_id,
		'order_id'   => $oid,
		'booking_id' => $bid,
	)
);
