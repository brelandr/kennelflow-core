<?php
/**
 * Output JSON snapshot of kf_required_vaccines (for E2E restore).
 *
 * @package KennelFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$v = get_option( 'ltkf_required_vaccines', array() );
if ( ! is_array( $v ) ) {
	$v = array();
}

echo wp_json_encode( array( 'ltkf_required_vaccines' => $v ) );
