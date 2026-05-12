<?php
/**
 * E2E: enable KennelFlow “owner may choose clinician” (public REST + booking wizard).
 *
 * @package KennelFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

update_option( 'ltkf_allow_owner_clinician_selection', '1' );

echo 'OK';
