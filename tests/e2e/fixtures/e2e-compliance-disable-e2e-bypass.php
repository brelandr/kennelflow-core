<?php
/**
 * Clear E2E-only portal bypass for compliance gatekeeper tests.
 *
 * @package KennelFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

delete_option( 'ltkf_compliance_gatekeeper_e2e_allow_noncompliant_checkout' );

echo 'OK';
