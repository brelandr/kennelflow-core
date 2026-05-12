<?php
/**
 * Restore kf_required_vaccines from JSON in E2E_COMPLIANCE_SNAPSHOT (output of e2e-compliance-backup-required-vaccines.php).
 *
 * @package KennelFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$raw = getenv( 'E2E_COMPLIANCE_SNAPSHOT' );
$raw = is_string( $raw ) ? trim( $raw ) : '';
if ( '' === $raw ) {
	exit( 0 );
}

$json = json_decode( $raw, true );
if ( ! is_array( $json ) || ! isset( $json['ltkf_required_vaccines'] ) ) {
	exit( 0 );
}

$v = $json['ltkf_required_vaccines'];
if ( ! is_array( $v ) ) {
	$v = array();
}

$v = array_map( 'sanitize_text_field', $v );
$v = array_values( array_filter( $v ) );

update_option( 'ltkf_required_vaccines', $v );

echo 'OK';
