<?php
/**
 * Print count of pending kf_commissions rows for E2E_GROOMER_ID.
 *
 * @package KennelFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'GroomPress_Install' ) || ! GroomPress_Install::commissions_table_exists() ) {
	echo '0';
	exit;
}

$groomer = absint( getenv( 'E2E_GROOMER_ID' ) );
if ( $groomer < 1 ) {
	echo '0';
	exit;
}

global $wpdb;

$table = GroomPress_Install::commissions_table_name();
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$n = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM `{$table}` WHERE staff_user_id = %d AND status = %s",
		$groomer,
		'pending'
	)
);

echo (string) $n;
