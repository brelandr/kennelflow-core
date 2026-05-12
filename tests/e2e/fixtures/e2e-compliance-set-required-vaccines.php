<?php
/**
 * Set kf_required_vaccines to a fixed list for E2E (default: Rabies).
 *
 * Env: E2E_COMPLIANCE_REQUIRED_JSON — optional JSON array of strings, e.g. ["Rabies"].
 *
 * @package KennelFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$raw = getenv( 'E2E_COMPLIANCE_REQUIRED_JSON' );
$raw = is_string( $raw ) ? trim( $raw ) : '';

if ( '' === $raw ) {
	$list = array( 'Rabies' );
} else {
	$list = json_decode( $raw, true );
	if ( ! is_array( $list ) ) {
		fwrite( STDERR, "Invalid E2E_COMPLIANCE_REQUIRED_JSON.\n" );
		exit( 1 );
	}
	$list = array_map( 'sanitize_text_field', $list );
	$list = array_values( array_filter( $list ) );
}

update_option( 'ltkf_required_vaccines', $list );

echo wp_json_encode(
	array(
		'ok'       => true,
		'required' => $list,
	)
);
