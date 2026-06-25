<?php
/**
 * Block WooCommerce checkout when a boarding stay’s pet is not vaccine-compliant.
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WooCommerce' ) ) {
	return;
}

/**
 * Class BookingComplianceGate
 */
class BookingComplianceGate {

	/**
	 * Bootstrap hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'validate_checkout_classic' ), 10 );
		add_action( 'woocommerce_store_api_cart_errors', array( __CLASS__, 'validate_cart_store_api' ), 10, 2 );
	}

	/**
	 * Classic checkout: block boarding / balance lines when compliance fails.
	 *
	 * @return void
	 */
	public static function validate_checkout_classic() {
		if ( ! apply_filters( 'ltkf_enforce_boarding_compliance_checkout', true ) ) {
			return;
		}

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$messages = self::collect_violation_messages( WC()->cart );
		if ( empty( $messages ) ) {
			return;
		}

		foreach ( $messages as $msg ) {
			wc_add_notice( wp_kses_post( $msg ), 'error' );
		}
	}

	/**
	 * Block / Store API checkout: cart errors before order placement.
	 *
	 * @param \WP_Error $errors Errors object.
	 * @param \WC_Cart  $cart   Cart.
	 * @return void
	 */
	public static function validate_cart_store_api( $errors, $cart ) {
		unset( $cart );

		if ( ! apply_filters( 'ltkf_enforce_boarding_compliance_checkout', true ) ) {
			return;
		}

		if ( ! $errors instanceof \WP_Error ) {
			return;
		}

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$messages = self::collect_violation_messages( WC()->cart );
		if ( empty( $messages ) ) {
			return;
		}

		$plain = array();
		foreach ( $messages as $m ) {
			$plain[] = wp_strip_all_tags( (string) $m );
		}
		$plain = array_filter( array_map( 'trim', $plain ) );

		$errors->add(
			'ltkf_boarding_compliance',
			implode( ' ', $plain )
		);
	}

	/**
	 * Build HTML notices for each compliance violation on applicable cart lines.
	 *
	 * @param \WC_Cart $cart Cart.
	 * @return string[]
	 */
	protected static function collect_violation_messages( $cart ) {
		if ( ! $cart instanceof \WC_Cart || ! class_exists( 'Woocommerce' ) ) {
			return array();
		}

		$map = Woocommerce::get_product_id_map();

		$boarding_id = isset( $map[ Woocommerce::KEY_BOARDING ] ) ? absint( $map[ Woocommerce::KEY_BOARDING ] ) : 0;
		$clinic_id   = isset( $map[ Woocommerce::KEY_CLINIC_VISIT ] ) ? absint( $map[ Woocommerce::KEY_CLINIC_VISIT ] ) : 0;
		$balance_id  = isset( $map[ Woocommerce::KEY_BALANCE_PAYMENT ] ) ? absint( $map[ Woocommerce::KEY_BALANCE_PAYMENT ] ) : 0;

		$out = array();

		foreach ( $cart->get_cart() as $cart_item ) {
			$product_id = isset( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0;

			if ( $clinic_id > 0 && $product_id === $clinic_id ) {
				continue;
			}

			$booking_post_id = 0;

			if ( $boarding_id > 0 && $product_id === $boarding_id && ! empty( $cart_item['kf_booking_id'] ) ) {
				$booking_post_id = absint( $cart_item['kf_booking_id'] );
			} elseif ( $balance_id > 0 && $product_id === $balance_id && ! empty( $cart_item['kf_balance_booking_post_id'] ) ) {
				$booking_post_id = absint( $cart_item['kf_balance_booking_post_id'] );
			}

			if ( $booking_post_id < 1 ) {
				continue;
			}

			if ( ! self::booking_kind_requires_boarding_vaccine_compliance( $booking_post_id ) ) {
				continue;
			}

			$pet_id = self::get_pet_id_for_booking_post( $booking_post_id );
			if ( $pet_id < 1 ) {
				continue;
			}

			$msgs = self::messages_for_pet_noncompliance( $pet_id );
			foreach ( $msgs as $m ) {
				$out[] = $m;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Hub `kf_bookings` table identifier when safe and present (for `$wpdb->prepare()` `%i`).
	 *
	 * @return string Empty when not usable.
	 */
	protected static function get_valid_bookings_index_table() {
		$table = ltkf_bookings_table_name();
		if ( ! is_string( $table ) || ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) ) {
			return '';
		}
		if ( ! function_exists( 'ltkf_table_exists' ) || ! ltkf_table_exists( $table ) ) {
			return '';
		}

		return $table;
	}

	/**
	 * Only boarding stays (not clinic or grooming) require vaccine compliance for payment.
	 *
	 * @param int $booking_post_id kf_bookings.post_id.
	 * @return bool
	 */
	protected static function booking_kind_requires_boarding_vaccine_compliance( $booking_post_id ) {
		$booking_post_id = absint( $booking_post_id );
		if ( $booking_post_id < 1 ) {
			return false;
		}

		$table = self::get_valid_bookings_index_table();
		if ( '' === $table ) {
			return false;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Checkout gate; `%i` validated table name.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT booking_kind FROM %i WHERE post_id = %d LIMIT 1',
				$table,
				$booking_post_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		return ltkf_booking_row_is_boarding_stay_for_vaccine_compliance( $row );
	}

	/**
	 * Pet post ID for a booking row.
	 *
	 * @param int $booking_post_id kf_bookings.post_id.
	 * @return int
	 */
	protected static function get_pet_id_for_booking_post( $booking_post_id ) {
		$booking_post_id = absint( $booking_post_id );
		if ( $booking_post_id < 1 ) {
			return 0;
		}

		$table = self::get_valid_bookings_index_table();
		if ( '' === $table ) {
			return 0;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$pet_id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT pet_id FROM %i WHERE post_id = %d LIMIT 1',
				$table,
				$booking_post_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		return absint( $pet_id );
	}

	/**
	 * One HTML message per failed vaccine (Missing or Expired).
	 *
	 * @param int $pet_id Pet post ID.
	 * @return string[]
	 */
	protected static function messages_for_pet_noncompliance( $pet_id ) {
		$pet_id = absint( $pet_id );
		if ( $pet_id < 1 ) {
			return array();
		}

		$status = ltkf_get_pet_compliance_status( $pet_id );
		if ( is_wp_error( $status ) ) {
			$name = get_the_title( $pet_id );
			if ( '' === $name ) {
				$name = '#' . (string) $pet_id;
			}

			return array(
				sprintf(
					/* translators: %s: pet name or id */
					__( 'Checkout halted: we could not verify vaccination compliance for %s. Please contact the facility.', 'kennelflow-core' ),
					esc_html( $name )
				),
			);
		}

		$vaccines = isset( $status['vaccines'] ) && is_array( $status['vaccines'] ) ? $status['vaccines'] : array();

		$name = get_the_title( $pet_id );
		if ( '' === $name ) {
			$name = '#' . (string) $pet_id;
		}

		$out = array();

		foreach ( $vaccines as $label => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$st = isset( $row['status'] ) ? (string) $row['status'] : '';
			if ( 'Valid' === $st ) {
				continue;
			}

			$vlabel = is_string( $label ) && '' !== $label ? $label : __( 'required vaccine', 'kennelflow-core' );

			if ( 'Expired' === $st ) {
				$out[] = sprintf(
					/* translators: 1: pet name, 2: vaccine name */
					__( 'Checkout halted: %1$s\'s %2$s vaccination has expired.', 'kennelflow-core' ),
					esc_html( $name ),
					esc_html( $vlabel )
				);
			} else {
				$out[] = sprintf(
					/* translators: 1: pet name, 2: vaccine name */
					__( 'Checkout halted: %1$s is missing a valid %2$s vaccination.', 'kennelflow-core' ),
					esc_html( $name ),
					esc_html( $vlabel )
				);
			}
		}

		return $out;
	}
}
