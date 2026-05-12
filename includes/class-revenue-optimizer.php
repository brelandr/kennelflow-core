<?php
/**
 * Dynamic surge pricing: boarding cart lines when ledger occupancy is high.
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'WooCommerce' ) ) {

	/**
	 * Class RevenueOptimizer
	 */
	class RevenueOptimizer {

		const OPTION_SURGE_ENABLED = 'ltkf_surge_enabled';

		const OPTION_SURGE_THRESHOLD = 'ltkf_surge_threshold';

		const OPTION_SURGE_INCREASE_PERCENTAGE = 'ltkf_surge_increase_percentage';

		/**
		 * Register WooCommerce hooks.
		 *
		 * @return void
		 */
		public static function init() {
			if ( ! apply_filters( 'ltkf_surge_pricing_enabled', true ) ) {
				return;
			}

			add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'maybe_apply_surge_pricing' ), 10, 1 );
			add_filter( 'woocommerce_cart_item_name', array( __CLASS__, 'filter_cart_item_name' ), 10, 3 );
		}

		/**
		 * Whether a cart line is the KennelFlow boarding product.
		 *
		 * @param array $cart_item Cart line.
		 * @return bool
		 */
		protected static function is_boarding_cart_line( $cart_item ) {
			if ( ! is_array( $cart_item ) ) {
				return false;
			}

			$pid = isset( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0;
			if ( $pid < 1 || ! class_exists( 'Woocommerce' ) ) {
				return false;
			}

			$map      = Woocommerce::get_product_id_map();
			$boarding = isset( $map[ Woocommerce::KEY_BOARDING ] ) ? absint( $map[ Woocommerce::KEY_BOARDING ] ) : 0;

			return $boarding > 0 && $pid === $boarding;
		}

		/**
		 * Whether surge pricing is enabled in Revenue settings.
		 *
		 * @return bool
		 */
		protected static function is_surge_option_enabled() {
			$raw = get_option( self::OPTION_SURGE_ENABLED, false );
			return filter_var( $raw, FILTER_VALIDATE_BOOLEAN );
		}

		/**
		 * Occupancy threshold (percent) from settings, filterable.
		 *
		 * @return float
		 */
		protected static function get_surge_threshold_percent() {
			$threshold = (float) get_option( self::OPTION_SURGE_THRESHOLD, 80 );
			$threshold = (float) apply_filters( 'ltkf_surge_occupancy_threshold_percent', $threshold );
			if ( $threshold < 0 ) {
				$threshold = 0.0;
			}
			if ( $threshold > 100 ) {
				$threshold = 100.0;
			}
			return $threshold;
		}

		/**
		 * Price multiplier when surge applies: 1 + (increase_percent / 100).
		 *
		 * @return float
		 */
		protected static function get_surge_price_multiplier() {
			$pct = absint( get_option( self::OPTION_SURGE_INCREASE_PERCENTAGE, 20 ) );
			if ( $pct > 500 ) {
				$pct = 500;
			}
			$mult = 1.0 + ( (float) $pct / 100.0 );
			$mult = (float) apply_filters( 'ltkf_surge_price_multiplier', $mult, $pct );
			if ( $mult < 1 ) {
				$mult = 1.0;
			}
			return $mult;
		}

		/**
		 * Catalog base price for a cart line (avoids compounding set_price).
		 *
		 * @param array $cart_item Cart line.
		 * @return float
		 */
		protected static function get_boarding_base_price( $cart_item ) {
			$product_id = isset( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0;
			if ( $product_id < 1 ) {
				return 0.0;
			}

			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				return 0.0;
			}

			$regular = $product->get_regular_price();
			if ( '' !== (string) $regular && is_numeric( $regular ) ) {
				return (float) wc_format_decimal( $regular );
			}

			$sale = $product->get_sale_price();
			if ( '' !== (string) $sale && is_numeric( $sale ) ) {
				return (float) wc_format_decimal( $sale );
			}

			return (float) wc_format_decimal( $product->get_price( 'edit' ) );
		}

		/**
		 * Adjust boarding line prices when occupancy exceeds the threshold.
		 *
		 * @param \WC_Cart $cart Cart.
		 * @return void
		 */
		public static function maybe_apply_surge_pricing( $cart ) {
			if ( ! $cart instanceof \WC_Cart ) {
				return;
			}

			if ( ! self::is_surge_option_enabled() ) {
				return;
			}

			$threshold = self::get_surge_threshold_percent();

			$occupancy = ltkf_get_current_occupancy_percentage();
			$surge_on  = $occupancy > $threshold;

			$multiplier = self::get_surge_price_multiplier();

			foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
				if ( ! self::is_boarding_cart_line( $cart_item ) ) {
					continue;
				}

				$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
				if ( ! $product instanceof \WC_Product ) {
					continue;
				}

				$base = self::get_boarding_base_price( $cart_item );
				if ( $base <= 0 ) {
					continue;
				}

				if ( $surge_on ) {
					$new = (float) wc_format_decimal( $base * $multiplier );
					$product->set_price( $new );
				} else {
					$product->set_price( $base );
				}
			}

			if ( $surge_on ) {
				/**
				 * Fires after surge pricing was evaluated and applied to boarding lines (if any).
				 *
				 * @since 0.2.0
				 *
				 * @param \WC_Cart $cart       Cart.
				 * @param float    $occupancy Occupancy 0–100.
				 */
				do_action( 'ltkf_surge_pricing_applied', $cart, $occupancy );
			}
		}

		/**
		 * Append a label when surge pricing applies to a boarding line.
		 *
		 * @param string $name         Product name.
		 * @param array  $cart_item    Cart line.
		 * @param string $cart_item_key Cart key.
		 * @return string
		 */
		public static function filter_cart_item_name( $name, $cart_item, $cart_item_key ) {
			unset( $cart_item_key );

			if ( ! self::is_boarding_cart_line( $cart_item ) ) {
				return $name;
			}

			if ( ! self::is_surge_option_enabled() ) {
				return $name;
			}

			$threshold = self::get_surge_threshold_percent();

			if ( ltkf_get_current_occupancy_percentage() <= $threshold ) {
				return $name;
			}

			$suffix = apply_filters(
				'ltkf_surge_cart_item_name_suffix',
				' ' . __( '(High Demand Rate)', 'kennelflow-core' ),
				$cart_item
			);

			if ( '' === (string) $suffix ) {
				return $name;
			}

			$stable = __( '(High Demand Rate)', 'kennelflow-core' );
			if ( '' !== $stable && false !== strpos( $name, $stable ) ) {
				return $name;
			}

			return $name . wp_kses_post( $suffix );
		}
	}
}
