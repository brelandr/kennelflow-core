<?php
/**
 * Allow portal Pay / AJAX to reach checkout while compliance is still enforced at WooCommerce (E2E).
 *
 * @package KennelFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

update_option( 'ltkf_compliance_gatekeeper_e2e_allow_noncompliant_checkout', true );

echo 'OK';
