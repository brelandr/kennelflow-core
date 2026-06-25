<?php
/**
 * WooCommerce bridge: virtual service products and cart metadata for kf_bookings.
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'WooCommerce' ) ) {

	/**
	 * Class Woocommerce
	 */
	class Woocommerce {

		/**
		 * Option key for product ID map (boarding / clinic_visit).
		 */
		const OPTION_PRODUCT_IDS = 'ltkf_wc_product_ids';

		/**
		 * Map keys stored in option (stable API).
		 */
		const KEY_BOARDING = 'boarding';

		const KEY_CLINIC_VISIT = 'clinic_visit';

		/**
		 * Virtual product for grooming appointments (kf_bookings.booking_kind = grooming).
		 */
		const KEY_GROOMING = 'grooming';

		/**
		 * Virtual product for paying an outstanding deposit balance.
		 */
		const KEY_BALANCE_PAYMENT = 'balance_payment';

		/**
		 * Internal product meta: which service key this product represents.
		 */
		const PRODUCT_META_SERVICE_KEY = '_kf_service_key';

		/**
		 * Order line item meta: booking post ID (wp_kf_bookings.post_id).
		 */
		const ORDER_ITEM_META_BOOKING_ID = 'kf_booking_id';

		/**
		 * Order line item meta: parent WC order ID for a balance payment line.
		 */
		const ORDER_ITEM_META_BALANCE_PARENT = '_kf_balance_parent_order';

		/**
		 * Pending booking post ID for woocommerce_add_cart_item_data (one add_to_cart).
		 *
		 * @var int
		 */
		private static $pending_booking_post_id = 0;

		/**
		 * Pending balance payment payload for one add_to_cart.
		 *
		 * @var array{amount:float,parent_order_id:int,booking_post_id:int}
		 */
		private static $pending_balance = array(
			'amount'          => 0.0,
			'parent_order_id' => 0,
			'booking_post_id' => 0,
		);

		/**
		 * Register hooks (call only when WooCommerce is active).
		 *
		 * @return void
		 */
		public static function init() {
			add_action( 'init', array( __CLASS__, 'maybe_ensure_products' ), 30 );
			add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'filter_add_cart_item_data' ), 10, 4 );
			add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'set_balance_cart_line_price' ), 15, 1 );
			add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'copy_booking_id_to_order_item' ), 10, 4 );
			add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'copy_balance_meta_to_order_item' ), 15, 4 );
			add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_order_status_changed' ), 10, 4 );
		}

		/**
		 * Remove KennelFlow virtual service lines and any cart lines carrying kf_booking_id.
		 *
		 * @return void
		 */
		public static function clear_kennelflow_cart_items() {
			if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
				return;
			}

			$map         = self::get_product_id_map();
			$product_ids = array_values( array_filter( array_map( 'absint', $map ) ) );

			$remove_keys = array();
			foreach ( WC()->cart->get_cart() as $cart_key => $cart_item ) {
				$pid = isset( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0;
				if ( ! empty( $cart_item['kf_balance_parent_order'] ) ) {
					$remove_keys[] = $cart_key;
					continue;
				}
				if ( ! empty( $cart_item['kf_booking_id'] ) || ( $pid > 0 && in_array( $pid, $product_ids, true ) ) ) {
					$remove_keys[] = $cart_key;
				}
			}

			foreach ( $remove_keys as $key ) {
				WC()->cart->remove_cart_item( $key );
			}
		}

		/**
		 * Persist kf_booking_id from cart line onto the order item at checkout.
		 *
		 * @param \WC_Order_Item_Product $item          Order item.
		 * @param string                 $cart_item_key Cart key.
		 * @param array                  $cart_item     Cart line.
		 * @param \WC_Order              $_order        Order (unused).
		 * @return void
		 */
		public static function copy_booking_id_to_order_item( $item, $cart_item_key, $cart_item, $_order ) {
			unset( $cart_item_key, $_order );

			if ( ! isset( $cart_item['kf_booking_id'] ) || (int) $cart_item['kf_booking_id'] < 1 ) {
				return;
			}

			$booking_post_id = absint( $cart_item['kf_booking_id'] );
			$item->add_meta_data( self::ORDER_ITEM_META_BOOKING_ID, (string) $booking_post_id, true );
		}

		/**
		 * Persist balance-payment linkage from cart line onto the order item at checkout.
		 *
		 * @param \WC_Order_Item_Product $item          Order item.
		 * @param string                 $cart_item_key Cart key.
		 * @param array                  $cart_item     Cart line.
		 * @param \WC_Order              $_order        Order (unused).
		 * @return void
		 */
		public static function copy_balance_meta_to_order_item( $item, $cart_item_key, $cart_item, $_order ) {
			unset( $cart_item_key, $_order );

			if ( empty( $cart_item['kf_balance_parent_order'] ) ) {
				return;
			}

			$parent_id = absint( $cart_item['kf_balance_parent_order'] );
			if ( $parent_id < 1 ) {
				return;
			}

			$item->add_meta_data( self::ORDER_ITEM_META_BALANCE_PARENT, (string) $parent_id, true );

			if ( ! empty( $cart_item['kf_balance_booking_post_id'] ) ) {
				$bid = absint( $cart_item['kf_balance_booking_post_id'] );
				if ( $bid > 0 ) {
					$item->add_meta_data( self::ORDER_ITEM_META_BOOKING_ID, (string) $bid, true );
				}
			}
		}

		/**
		 * Set dynamic price for balance payment virtual product lines.
		 *
		 * @param \WC_Cart $cart Cart.
		 * @return void
		 */
		public static function set_balance_cart_line_price( $cart ) {
			if ( is_admin() && ! wp_doing_ajax() ) {
				return;
			}
			if ( ! $cart instanceof \WC_Cart ) {
				return;
			}

			foreach ( $cart->get_cart() as $cart_item ) {
				if ( empty( $cart_item['kf_balance_amount'] ) ) {
					continue;
				}
				$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
				if ( ! $product instanceof \WC_Product ) {
					continue;
				}
				$amt = (float) wc_format_decimal( $cart_item['kf_balance_amount'] );
				if ( $amt <= 0 ) {
					continue;
				}
				$product->set_price( $amt );
			}
		}

		/**
		 * When an order is paid / processing, confirm matching rows in kf_bookings.
		 *
		 * @param int       $order_id   Order ID.
		 * @param string    $old_status Old status.
		 * @param string    $new_status New status.
		 * @param \WC_Order $order      Order object.
		 * @return void
		 */
		public static function on_order_status_changed( $order_id, $old_status, $new_status, $order ) {
			unset( $old_status );

			if ( ! in_array( $new_status, array( 'processing', 'completed' ), true ) ) {
				return;
			}

			$order_id = absint( $order_id );
			if ( $order_id < 1 ) {
				return;
			}

			$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
			if ( ! $order ) {
				return;
			}

			$table = ltkf_bookings_table_name();
			if ( ! is_string( $table ) || ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) || ! ltkf_table_exists( $table ) ) {
				return;
			}

			global $wpdb;

			foreach ( $order->get_items( 'line_item' ) as $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product ) {
					continue;
				}

				$booking_post_id = absint( $item->get_meta( self::ORDER_ITEM_META_BOOKING_ID, true ) );
				if ( $booking_post_id < 1 ) {
					continue;
				}

				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Ledger update; `%i` table validated above.
				$rows = $wpdb->query(
					$wpdb->prepare(
						'UPDATE %i SET status = %s WHERE post_id = %d AND status IN ( %s, %s )',
						$table,
						'confirmed',
						$booking_post_id,
						'pending',
						'pending_payment'
					)
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

				if ( false === $rows || $rows < 1 ) {
					continue;
				}

				/**
				 * Fires after kf_bookings was updated to confirmed for a paid order line.
				 *
				 * @since 0.2.2
				 *
				 * @param int       $booking_post_id Booking post ID (kf_bookings.post_id).
				 * @param int       $order_id        WooCommerce order ID.
				 * @param \WC_Order $order           Order object.
				 */
				do_action( 'ltkf_booking_ledger_confirmed', $booking_post_id, $order_id, $order );

				$order->add_order_note(
					sprintf(
						/* translators: %d: booking post ID (WordPress post) */
						__( 'KennelFlow Booking #%d has been confirmed in the master ledger.', 'kennelflow-core' ),
						$booking_post_id
					)
				);
			}
		}

		/**
		 * Ensure virtual products exist on a normal request (storefront / REST cart).
		 *
		 * @return void
		 */
		public static function maybe_ensure_products() {
			if ( is_admin() && ! wp_doing_ajax() ) {
				return;
			}
			self::ensure_virtual_products();
		}

		/**
		 * Create hidden virtual products for Boarding and Clinic Visit; persist IDs in kf_wc_product_ids.
		 *
		 * @return int[] Map of service key => product ID.
		 */
		public static function ensure_virtual_products() {
			$map = get_option( self::OPTION_PRODUCT_IDS, array() );
			if ( ! is_array( $map ) ) {
				$map = array();
			}

			$definitions = array(
				self::KEY_BOARDING        => array(
					'name' => __( 'Kennel Boarding (KennelFlow)', 'kennelflow-core' ),
					'sku'  => 'kf-svc-boarding',
				),
				self::KEY_CLINIC_VISIT    => array(
					'name' => __( 'Clinic Visit (KennelFlow)', 'kennelflow-core' ),
					'sku'  => 'kf-svc-clinic-visit',
				),
				self::KEY_GROOMING        => array(
					'name' => __( 'Grooming Service (KennelFlow)', 'kennelflow-core' ),
					'sku'  => 'kf-svc-grooming',
				),
				self::KEY_BALANCE_PAYMENT => array(
					'name' => __( 'Balance Payment (KennelFlow)', 'kennelflow-core' ),
					'sku'  => 'kf-svc-balance-payment',
				),
			);

			foreach ( $definitions as $key => $def ) {
				$existing = isset( $map[ $key ] ) ? absint( $map[ $key ] ) : 0;
				if ( $existing > 0 ) {
					$product = wc_get_product( $existing );
					if ( $product && 'trash' !== $product->get_status() ) {
						continue;
					}
				}

				$product_id = self::create_virtual_service_product( $key, $def['name'], $def['sku'] );
				if ( $product_id > 0 ) {
					$map[ $key ] = $product_id;
				}
			}

			update_option( self::OPTION_PRODUCT_IDS, $map, false );

			return $map;
		}

		/**
		 * Product ID map after ensuring products exist.
		 *
		 * @return int[]
		 */
		public static function get_product_id_map() {
			return self::ensure_virtual_products();
		}

		/**
		 * Attach kf_booking_id (booking post ID) to cart line items for our service products.
		 *
		 * @param array $cart_item_data Cart item extra data.
		 * @param int   $product_id     Product ID.
		 * @param int   $variation_id   Variation ID.
		 * @param int   $quantity       Quantity.
		 * @return array
		 */
		public static function filter_add_cart_item_data( $cart_item_data, $product_id, $variation_id, $quantity ) {
			unset( $variation_id, $quantity );

			$product_id = absint( $product_id );
			if ( $product_id < 1 ) {
				return $cart_item_data;
			}

			$ids = self::get_product_id_map();

			if ( self::$pending_balance['amount'] > 0 && self::$pending_balance['parent_order_id'] > 0 ) {
				$balance_pid = isset( $ids[ self::KEY_BALANCE_PAYMENT ] ) ? absint( $ids[ self::KEY_BALANCE_PAYMENT ] ) : 0;
				if ( $balance_pid > 0 && $product_id === $balance_pid ) {
					$cart_item_data['kf_balance_amount']          = (float) wc_format_decimal( self::$pending_balance['amount'] );
					$cart_item_data['kf_balance_parent_order']    = absint( self::$pending_balance['parent_order_id'] );
					$cart_item_data['kf_balance_booking_post_id'] = absint( self::$pending_balance['booking_post_id'] );
					return $cart_item_data;
				}
			}

			if ( self::$pending_booking_post_id < 1 ) {
				return $cart_item_data;
			}

			$boarding = isset( $ids[ self::KEY_BOARDING ] ) ? absint( $ids[ self::KEY_BOARDING ] ) : 0;
			$clinic   = isset( $ids[ self::KEY_CLINIC_VISIT ] ) ? absint( $ids[ self::KEY_CLINIC_VISIT ] ) : 0;
			$grooming = isset( $ids[ self::KEY_GROOMING ] ) ? absint( $ids[ self::KEY_GROOMING ] ) : 0;

			if ( $product_id !== $boarding && $product_id !== $clinic && $product_id !== $grooming ) {
				return $cart_item_data;
			}

			$cart_item_data['kf_booking_id'] = absint( self::$pending_booking_post_id );

			return $cart_item_data;
		}

		/**
		 * Add the correct virtual product for a kf_bookings row to the cart.
		 *
		 * @param int $booking_id Booking post ID (kf_bookings.post_id) or index row id (kf_bookings.id).
		 * @return string|false|\WP_Error Cart item key on success.
		 */
		public static function add_booking_to_cart( $booking_id ) {
			$booking_id = absint( $booking_id );
			if ( $booking_id < 1 ) {
				return new \WP_Error( 'ltkf_wc_invalid_booking', __( 'Invalid booking ID.', 'kennelflow-core' ) );
			}

			if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
				return new \WP_Error( 'ltkf_wc_no_cart', __( 'Cart is not available.', 'kennelflow-core' ) );
			}

			$row = self::get_booking_row( $booking_id );
			if ( null === $row ) {
				return new \WP_Error( 'ltkf_wc_booking_not_found', __( 'Booking not found.', 'kennelflow-core' ) );
			}

			$post_id = absint( $row->post_id );
			if ( $post_id < 1 ) {
				return new \WP_Error( 'ltkf_wc_booking_invalid', __( 'Booking record is invalid.', 'kennelflow-core' ) );
			}

			$service_key = self::booking_kind_to_service_key( isset( $row->booking_kind ) ? (string) $row->booking_kind : '' );

			$map        = self::ensure_virtual_products();
			$product_id = isset( $map[ $service_key ] ) ? absint( $map[ $service_key ] ) : 0;
			if ( $product_id < 1 ) {
				return new \WP_Error( 'ltkf_wc_no_product', __( 'Service product could not be created.', 'kennelflow-core' ) );
			}

			self::$pending_booking_post_id = $post_id;

			$key = WC()->cart->add_to_cart( $product_id, 1, 0, array(), array() );

			self::$pending_booking_post_id = 0;

			if ( ! $key ) {
				return new \WP_Error( 'ltkf_wc_add_failed', __( 'Could not add booking to cart.', 'kennelflow-core' ) );
			}

			return $key;
		}

		/**
		 * Add a balance payment product for the remaining deposit amount.
		 *
		 * @param int   $parent_order_id WooCommerce order ID with _kf_unpaid_balance.
		 * @param float $amount          Amount to charge.
		 * @param int   $booking_post_id Booking post ID (kf_bookings.post_id).
		 * @return string|false|\WP_Error Cart item key on success.
		 */
		public static function add_balance_to_cart( $parent_order_id, $amount, $booking_post_id ) {
			$parent_order_id = absint( $parent_order_id );
			$booking_post_id = absint( $booking_post_id );
			$amount          = (float) wc_format_decimal( $amount );

			if ( $parent_order_id < 1 || $booking_post_id < 1 || $amount <= 0 ) {
				return new \WP_Error( 'ltkf_wc_invalid_balance', __( 'Invalid balance payment.', 'kennelflow-core' ) );
			}

			if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
				return new \WP_Error( 'ltkf_wc_no_cart', __( 'Cart is not available.', 'kennelflow-core' ) );
			}

			$map        = self::ensure_virtual_products();
			$product_id = isset( $map[ self::KEY_BALANCE_PAYMENT ] ) ? absint( $map[ self::KEY_BALANCE_PAYMENT ] ) : 0;
			if ( $product_id < 1 ) {
				return new \WP_Error( 'ltkf_wc_no_product', __( 'Balance product could not be created.', 'kennelflow-core' ) );
			}

			self::$pending_balance = array(
				'amount'          => $amount,
				'parent_order_id' => $parent_order_id,
				'booking_post_id' => $booking_post_id,
			);

			$key = WC()->cart->add_to_cart( $product_id, 1, 0, array(), array() );

			self::$pending_balance = array(
				'amount'          => 0.0,
				'parent_order_id' => 0,
				'booking_post_id' => 0,
			);

			if ( ! $key ) {
				return new \WP_Error( 'ltkf_wc_add_failed', __( 'Could not add balance payment to cart.', 'kennelflow-core' ) );
			}

			return $key;
		}

		/**
		 * Map KennelPress booking_kind to product map key.
		 *
		 * @param string $kind Raw booking_kind from DB.
		 * @return string
		 */
		protected static function booking_kind_to_service_key( $kind ) {
			$kind = sanitize_key( (string) $kind );
			if ( 'clinic' === $kind ) {
				return self::KEY_CLINIC_VISIT;
			}
			if ( 'grooming' === $kind ) {
				return self::KEY_GROOMING;
			}
			return self::KEY_BOARDING;
		}

		/**
		 * Fetch one row from kf_bookings by post_id or numeric id.
		 *
		 * @param int $booking_id Post ID or row id.
		 * @return object|null
		 */
		protected static function get_booking_row( $booking_id ) {
			$table = ltkf_bookings_table_name();
			if ( ! is_string( $table ) || ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) || ! ltkf_table_exists( $table ) ) {
				return null;
			}

			global $wpdb;

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Single booking lookup; `%i` table validated above.
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT post_id, booking_kind FROM %i WHERE post_id = %d LIMIT 1',
					$table,
					$booking_id
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

			if ( is_object( $row ) ) {
				return $row;
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT post_id, booking_kind FROM %i WHERE id = %d LIMIT 1',
					$table,
					$booking_id
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

			return is_object( $row ) ? $row : null;
		}

		/**
		 * Create one hidden virtual simple product.
		 *
		 * @param string $service_key Stable key (boarding | clinic_visit).
		 * @param string $name        Product title.
		 * @param string $sku         SKU.
		 * @return int Product ID or 0.
		 */
		protected static function create_virtual_service_product( $service_key, $name, $sku ) {
			$product = new \WC_Product_Simple();
			$product->set_name( $name );
			$product->set_status( 'publish' );
			$product->set_catalog_visibility( 'hidden' );
			$product->set_virtual( true );
			$product->set_regular_price( '0' );
			$product->set_sku( $sku );

			$product_id = $product->save();
			$product_id = absint( $product_id );

			if ( $product_id > 0 ) {
				update_post_meta( $product_id, self::PRODUCT_META_SERVICE_KEY, sanitize_key( $service_key ) );
			}

			return $product_id;
		}
	}
}
