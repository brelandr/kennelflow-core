<?php
/**
 * Deposits: negative fee for remaining balance, order meta, clearing parent on balance paid.
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'WooCommerce' ) ) {

	/**
	 * Class WoocommerceDeposits
	 */
	class WoocommerceDeposits {

		/**
		 * Order meta: remaining amount after deposit (currency decimal string).
		 */
		const ORDER_META_UNPAID_BALANCE = '_kf_unpaid_balance';

		/**
		 * Register hooks.
		 *
		 * @return void
		 */
		public static function init() {
			add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'add_remaining_balance_fee' ), 20 );
			add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_unpaid_balance_on_order' ), 20, 2 );
			add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'maybe_clear_parent_balance_on_balance_payment' ), 15, 4 );
		}

		/**
		 * Add a negative fee so the cart total reflects deposit-only due now.
		 *
		 * @return void
		 */
		public static function add_remaining_balance_fee() {
			if ( is_admin() && ! wp_doing_ajax() ) {
				return;
			}

			if ( ! apply_filters( 'ltkf_deposit_fees_enabled', true ) ) {
				return;
			}

			$pct = ltkf_get_deposit_percentage();
			if ( $pct < 1 || $pct > 99 ) {
				return;
			}

			if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
				return;
			}

			$boarding_subtotal = self::get_boarding_booking_subtotal( WC()->cart );
			if ( $boarding_subtotal <= 0 ) {
				return;
			}

			$factor    = ( 100 - $pct ) / 100;
			$remaining = (float) wc_format_decimal( $boarding_subtotal * $factor );
			if ( $remaining <= 0 ) {
				return;
			}

			WC()->cart->add_fee(
				__( 'Remaining Balance', 'kennelflow-core' ),
				-1 * $remaining,
				false,
				''
			);
		}

		/**
		 * Sum line subtotals for boarding lines that carry a kf_booking_id.
		 *
		 * @param \WC_Cart $cart Cart.
		 * @return float
		 */
		protected static function get_boarding_booking_subtotal( $cart ) {
			if ( ! $cart instanceof \WC_Cart || ! class_exists( 'Woocommerce' ) ) {
				return 0.0;
			}

			$map         = Woocommerce::get_product_id_map();
			$boarding_id = isset( $map[ Woocommerce::KEY_BOARDING ] ) ? absint( $map[ Woocommerce::KEY_BOARDING ] ) : 0;
			if ( $boarding_id < 1 ) {
				return 0.0;
			}

			$sum = 0.0;
			foreach ( $cart->get_cart() as $cart_item ) {
				$pid = isset( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0;
				if ( $pid !== $boarding_id ) {
					continue;
				}
				if ( empty( $cart_item['kf_booking_id'] ) ) {
					continue;
				}
				$line = isset( $cart_item['line_subtotal'] ) ? (float) $cart_item['line_subtotal'] : 0.0;
				/**
				 * Filters a boarding booking line subtotal before deposit “remaining balance” is calculated.
				 *
				 * @since 0.2.0
				 *
				 * @param float    $line        Line subtotal (ex tax).
				 * @param array    $cart_item   Cart line.
				 * @param \WC_Cart $cart        Cart.
				 */
				$line = (float) apply_filters( 'ltkf_boarding_booking_line_subtotal_for_deposit', $line, $cart_item, $cart );
				$sum += $line;
			}

			return (float) wc_format_decimal( $sum );
		}

		/**
		 * Store remaining balance on the order for portal + accounting.
		 *
		 * @param \WC_Order $order Order.
		 * @param array     $data  Checkout payload.
		 * @return void
		 */
		public static function save_unpaid_balance_on_order( $order, $data ) {
			unset( $data );

			if ( ! $order instanceof \WC_Order ) {
				return;
			}

			$pct = ltkf_get_deposit_percentage();
			if ( $pct < 1 || $pct > 99 ) {
				$order->update_meta_data( self::ORDER_META_UNPAID_BALANCE, wc_format_decimal( 0 ) );
				return;
			}

			$boarding_subtotal = 0.0;

			foreach ( $order->get_items( 'line_item' ) as $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product ) {
					continue;
				}

				if ( ! self::is_boarding_product_id( $item->get_product_id() ) ) {
					continue;
				}

				$bid = absint( $item->get_meta( Woocommerce::ORDER_ITEM_META_BOOKING_ID, true ) );
				if ( $bid < 1 ) {
					continue;
				}

				$boarding_subtotal += (float) $item->get_subtotal();
			}

			$boarding_subtotal = (float) wc_format_decimal( $boarding_subtotal );

			if ( $boarding_subtotal <= 0 ) {
				$order->update_meta_data( self::ORDER_META_UNPAID_BALANCE, wc_format_decimal( 0 ) );
				return;
			}

			$factor    = ( 100 - $pct ) / 100;
			$remaining = (float) wc_format_decimal( $boarding_subtotal * $factor );

			$order->update_meta_data( self::ORDER_META_UNPAID_BALANCE, wc_format_decimal( $remaining ) );
		}

		/**
		 * Whether the product is the KennelFlow boarding virtual product.
		 *
		 * @param int $product_id Product ID.
		 * @return bool
		 */
		protected static function is_boarding_product_id( $product_id ) {
			$product_id = absint( $product_id );
			if ( $product_id < 1 || ! class_exists( 'Woocommerce' ) ) {
				return false;
			}

			$map      = Woocommerce::get_product_id_map();
			$boarding = isset( $map[ Woocommerce::KEY_BOARDING ] ) ? absint( $map[ Woocommerce::KEY_BOARDING ] ) : 0;

			return $boarding > 0 && $product_id === $boarding;
		}

		/**
		 * When a balance payment order is paid, zero out the parent order’s unpaid meta.
		 *
		 * @param int       $order_id   Order ID.
		 * @param string    $old_status Old status.
		 * @param string    $new_status New status.
		 * @param \WC_Order $order      Order object.
		 * @return void
		 */
		public static function maybe_clear_parent_balance_on_balance_payment( $order_id, $old_status, $new_status, $order ) {
			unset( $old_status );

			if ( ! in_array( $new_status, array( 'processing', 'completed' ), true ) ) {
				return;
			}

			$order_id = absint( $order_id );
			$order    = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
			if ( ! $order ) {
				return;
			}

			foreach ( $order->get_items( 'line_item' ) as $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product ) {
					continue;
				}

				$parent_id = absint( $item->get_meta( Woocommerce::ORDER_ITEM_META_BALANCE_PARENT, true ) );
				if ( $parent_id < 1 ) {
					continue;
				}

				$parent = wc_get_order( $parent_id );
				if ( ! $parent ) {
					continue;
				}

				$parent->update_meta_data( self::ORDER_META_UNPAID_BALANCE, wc_format_decimal( 0 ) );
				$parent->save();

				$order->add_order_note(
					sprintf(
						/* translators: %d: WooCommerce order ID */
						__( 'KennelFlow: remaining balance recorded against parent order #%d.', 'kennelflow-core' ),
						$parent_id
					)
				);
			}
		}
	}
}
