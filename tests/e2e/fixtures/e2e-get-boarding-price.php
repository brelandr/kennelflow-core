<?php
/**
 * Echo boarding virtual product _regular_price for E2E snapshot (via `wp eval-file`).
 *
 * @package KennelFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( \Landtech\KennelFlow\Core\WooCommerce::class ) ) {
	\Landtech\KennelFlow\Core\WooCommerce::ensure_virtual_products();
}

$map = get_option( 'ltkf_wc_product_ids' );
if ( ! is_array( $map ) ) {
	echo '';
	exit;
}

$pid = isset( $map['boarding'] ) ? absint( $map['boarding'] ) : 0;
if ( $pid < 1 ) {
	echo '';
	exit;
}

echo (string) get_post_meta( $pid, '_regular_price', true );
