<?php
/**
 * E2E: set kf_required_vaccines to Rabies only (Compliance Gatekeeper rule).
 *
 * @package KennelFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

update_option( 'ltkf_required_vaccines', array( 'Rabies' ) );

echo wp_json_encode(
	array(
		'ok'                   => true,
		'ltkf_required_vaccines' => array( 'Rabies' ),
	)
);
