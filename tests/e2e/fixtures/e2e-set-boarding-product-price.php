<?php
/**
 * Set KennelFlow boarding virtual product price for E2E (via `wp eval-file`).
 * Uses env E2E_BOARDING_PRICE (default 100).
 *
 * @package KennelFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( \Landtech\KennelFlow\Core\WooCommerce::class ) ) {
	\Landtech\KennelFlow\Core\WooCommerce::ensure_virtual_products();
}

$price = getenv( 'E2E_BOARDING_PRICE' );
$price = is_string( $price ) && '' !== $price ? $price : '100';

$map = get_option( 'ltkf_wc_product_ids' );
if ( ! is_array( $map ) ) {
	$map = array();
}

$pid = isset( $map['boarding'] ) ? absint( $map['boarding'] ) : 0;
if ( $pid < 1 ) {
	fwrite( STDERR, "kf_wc_product_ids boarding product missing — visit storefront once or run Landtech\\KennelFlow\\Core\\WooCommerce::ensure_virtual_products.\n" );
	exit( 1 );
}

update_post_meta( $pid, '_regular_price', $price );
update_post_meta( $pid, '_price', $price );
delete_post_meta( $pid, '_sale_price' );

echo $price;
