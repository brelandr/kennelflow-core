<?php
/**
 * VIP membership: discount for active WooCommerce Subscribers on boarding/grooming cart lines.
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class VipMemberships
 */
class VipMemberships {

	/**
	 * Option key: percent discount (0–100).
	 */
	const OPTION_DISCOUNT_PERCENTAGE = 'ltkf_vip_discount_percentage';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'apply_cart_discount_fee' ), 15 );
		add_filter( 'ltkf_boarding_booking_line_subtotal_for_deposit', array( __CLASS__, 'filter_line_subtotal_for_deposit' ), 10, 3 );
	}

	/**
	 * Stored discount percentage (0 = disabled).
	 *
	 * @return int
	 */
	public static function get_discount_percentage() {
		$p = get_option( self::OPTION_DISCOUNT_PERCENTAGE, 10 );
		$p = is_numeric( $p ) ? (int) $p : 10;
		if ( $p < 0 ) {
			$p = 0;
		}
		if ( $p > 100 ) {
			$p = 100;
		}

		/**
		 * Filters VIP membership discount percentage (0–100).
		 *
		 * @since 0.2.0
		 *
		 * @param int $percent Default from option.
		 */
		return (int) apply_filters( 'ltkf_vip_discount_percentage', $p );
	}

	/**
	 * Whether WooCommerce Subscriptions reports an active subscription for the user.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function user_has_active_subscription( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id < 1 ) {
			return false;
		}
		if ( ! function_exists( 'wcs_user_has_subscription' ) ) {
			return false;
		}

		return (bool) wcs_user_has_subscription( $user_id, '', 'active' );
	}

	/**
	 * Booking kind for a KennelFlow booking post (kf_bookings preferred).
	 *
	 * @param int $booking_post_id Booking post ID.
	 * @return string
	 */
	protected static function get_booking_kind_for_post( $booking_post_id ) {
		$booking_post_id = absint( $booking_post_id );
		if ( $booking_post_id < 1 ) {
			return '';
		}

		$table = ltkf_bookings_table_name();
		if ( function_exists( 'ltkf_table_exists' ) && ltkf_table_exists( $table ) ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Single-row lookup; table from helper.
			$kind = $wpdb->get_var( $wpdb->prepare( "SELECT booking_kind FROM {$table} WHERE post_id = %d LIMIT 1", $booking_post_id ) );
			if ( null !== $kind && '' !== (string) $kind ) {
				return sanitize_key( (string) $kind );
			}
		}

		if ( class_exists( 'KennelPress_Post_Meta' ) ) {
			return KennelPress_Post_Meta::sanitize_booking_kind(
				(string) get_post_meta( $booking_post_id, KennelPress_Post_Meta::BOOKING_KIND, true )
			);
		}

		return sanitize_key( (string) get_post_meta( $booking_post_id, '_kennelpress_booking_kind', true ) );
	}

	/**
	 * Cart line is a KennelFlow booking eligible for VIP (boarding or grooming), not balance pay or clinic.
	 *
	 * @param array $cart_item Cart line.
	 * @return bool
	 */
	public static function cart_line_is_vip_eligible_booking( $cart_item ) {
		if ( ! is_array( $cart_item ) ) {
			return false;
		}
		if ( ! empty( $cart_item['kf_balance_parent_order'] ) || ! empty( $cart_item['kf_balance_amount'] ) ) {
			return false;
		}
		$bid = isset( $cart_item['kf_booking_id'] ) ? absint( $cart_item['kf_booking_id'] ) : 0;
		if ( $bid < 1 ) {
			return false;
		}

		$kind = self::get_booking_kind_for_post( $bid );
		if ( '' === $kind || 'boarding' === $kind ) {
			return true;
		}
		if ( 'grooming' === $kind ) {
			return true;
		}

		return false;
	}

	/**
	 * Reduce line subtotal for deposit math when VIP applies (matches cart fee).
	 *
	 * @param float    $line      Line subtotal (ex tax).
	 * @param array    $cart_item Cart line.
	 * @param \WC_Cart $cart      Cart.
	 * @return float
	 */
	public static function filter_line_subtotal_for_deposit( $line, $cart_item, $cart ) {
		unset( $cart );

		$line = (float) wc_format_decimal( $line );
		if ( $line <= 0 ) {
			return $line;
		}

		$pct = self::get_discount_percentage();
		if ( $pct < 1 ) {
			return $line;
		}

		$user_id = get_current_user_id();
		if ( $user_id < 1 || ! self::user_has_active_subscription( $user_id ) ) {
			return $line;
		}

		if ( ! self::cart_line_is_vip_eligible_booking( $cart_item ) ) {
			return $line;
		}

		$factor = ( 100 - $pct ) / 100;

		return (float) wc_format_decimal( $line * $factor );
	}

	/**
	 * Add negative fee for VIP discount on eligible booking lines.
	 *
	 * @param \WC_Cart $cart Cart.
	 * @return void
	 */
	public static function apply_cart_discount_fee( $cart ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}

		$pct = self::get_discount_percentage();
		if ( $pct < 1 ) {
			return;
		}

		if ( ! function_exists( 'wcs_user_has_subscription' ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( $user_id < 1 || ! self::user_has_active_subscription( $user_id ) ) {
			return;
		}

		$total_discount = 0.0;
		foreach ( $cart->get_cart() as $cart_item ) {
			if ( ! self::cart_line_is_vip_eligible_booking( $cart_item ) ) {
				continue;
			}
			$line = isset( $cart_item['line_subtotal'] ) ? (float) $cart_item['line_subtotal'] : 0.0;
			if ( $line <= 0 ) {
				continue;
			}
			$total_discount += $line * ( $pct / 100 );
		}

		$total_discount = (float) wc_format_decimal( $total_discount );
		if ( $total_discount <= 0 ) {
			return;
		}

		$cart->add_fee(
			__( 'VIP Member Discount', 'kennelflow-core' ),
			-1 * $total_discount,
			false,
			''
		);
	}
}
