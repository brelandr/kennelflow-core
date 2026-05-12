<?php
/**
 * WooCommerce checkout fields when a KennelFlow booking is in the cart.
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'WooCommerce' ) ) {

	/**
	 * Class WoocommerceCheckout
	 */
	class WoocommerceCheckout {

		/**
		 * Order meta: emergency contact phone (optional).
		 */
		const ORDER_META_EMERGENCY_CONTACT = '_kf_emergency_contact';

		/**
		 * Checkout field key (posted / saved as order meta without leading underscore in field id only).
		 */
		const CHECKOUT_FIELD_EMERGENCY = 'ltkf_emergency_contact';

		/**
		 * Register hooks.
		 *
		 * @return void
		 */
		public static function init() {
			add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'filter_checkout_fields' ), 20 );
			add_action( 'woocommerce_checkout_update_order_meta', array( __CLASS__, 'save_emergency_contact_meta' ), 10, 1 );
			add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'display_emergency_contact_admin' ), 10, 1 );
		}

		/**
		 * Whether the cart has a line tied to a KennelFlow booking.
		 *
		 * @return bool
		 */
		public static function cart_has_kf_booking() {
			if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
				return false;
			}

			foreach ( WC()->cart->get_cart() as $cart_item ) {
				if ( ! empty( $cart_item['kf_booking_id'] ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Whether the order has a line item with KennelFlow booking meta or a KennelFlow service product.
		 *
		 * @param \WC_Order $order Order.
		 * @return bool
		 */
		public static function order_has_kf_booking( $order ) {
			if ( ! $order instanceof \WC_Order ) {
				return false;
			}

			$service_ids = array();
			if ( class_exists( 'Woocommerce' ) ) {
				$map         = Woocommerce::get_product_id_map();
				$service_ids = array_values( array_filter( array_map( 'absint', $map ) ) );
			}

			foreach ( $order->get_items( 'line_item' ) as $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product ) {
					continue;
				}
				$bid = $item->get_meta( Woocommerce::ORDER_ITEM_META_BOOKING_ID, true );
				if ( '' !== (string) $bid && absint( $bid ) > 0 ) {
					return true;
				}
				$pid = absint( $item->get_product_id() );
				if ( $pid > 0 && ! empty( $service_ids ) && in_array( $pid, $service_ids, true ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Add emergency contact field to billing when a KennelFlow booking is in the cart.
		 *
		 * @param array $fields Checkout fields.
		 * @return array
		 */
		public static function filter_checkout_fields( $fields ) {
			if ( ! is_array( $fields ) ) {
				$fields = array();
			}

			if ( ! self::cart_has_kf_booking() ) {
				return $fields;
			}

			if ( ! isset( $fields['billing'] ) || ! is_array( $fields['billing'] ) ) {
				$fields['billing'] = array();
			}

			$fields['billing'][ self::CHECKOUT_FIELD_EMERGENCY ] = array(
				'type'        => 'text',
				'label'       => __( 'Emergency Contact Phone (Optional)', 'kennelflow-core' ),
				'placeholder' => __( 'Phone number', 'kennelflow-core' ),
				'required'    => false,
				'class'       => array( 'form-row-wide' ),
				'clear'       => true,
				'priority'    => 120,
			);

			return $fields;
		}

		/**
		 * Persist emergency contact to order meta when provided.
		 *
		 * @param int $order_id Order ID.
		 * @return void
		 */
		public static function save_emergency_contact_meta( $order_id ) {
			$order_id = absint( $order_id );
			if ( $order_id < 1 ) {
				return;
			}

			if ( ! isset( $_POST['woocommerce-process-checkout-nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce-process-checkout-nonce'] ) ), 'woocommerce-process_checkout' ) ) {
				return;
			}

			if ( ! isset( $_POST[ self::CHECKOUT_FIELD_EMERGENCY ] ) ) {
				return;
			}

			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order ) {
				return;
			}

			if ( ! self::order_has_kf_booking( $order ) ) {
				return;
			}

			$value = sanitize_text_field( wp_unslash( $_POST[ self::CHECKOUT_FIELD_EMERGENCY ] ) );

			if ( '' === $value ) {
				$order->delete_meta_data( self::ORDER_META_EMERGENCY_CONTACT );
			} else {
				$order->update_meta_data( self::ORDER_META_EMERGENCY_CONTACT, $value );
			}

			$order->save();
		}

		/**
		 * Show emergency contact on the admin order screen.
		 *
		 * @param \WC_Order $order Order.
		 * @return void
		 */
		public static function display_emergency_contact_admin( $order ) {
			if ( ! $order instanceof \WC_Order ) {
				return;
			}

			$phone = $order->get_meta( self::ORDER_META_EMERGENCY_CONTACT, true );
			if ( '' === (string) $phone ) {
				return;
			}

			echo '<div class="kf-order-emergency-contact">';
			echo '<p><strong>' . esc_html__( 'Emergency contact phone', 'kennelflow-core' ) . ':</strong> ';
			echo esc_html( (string) $phone );
			echo '</p>';
			echo '</div>';
		}
	}
}
