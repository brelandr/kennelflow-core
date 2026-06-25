<?php
/**
 * Digital boarding liability waivers: pet meta, protected PNG storage, portal + checkout gates.
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class Waiver
 */
class Waiver {

	const META_PATH = '_kf_waiver_path';

	const META_DATE = '_kf_waiver_date';

	const AJAX_ACTION = 'ltkf_save_waiver_signature';

	const NONCE_ACTION = 'ltkf_waiver_signature';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_meta' ), 11 );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'ajax_save_signature' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'register_woocommerce_hooks' ), 20 );
	}

	/**
	 * Register waiver meta on Hub pets.
	 *
	 * @return void
	 */
	public static function register_post_meta() {
		$args = array(
			'type'              => 'string',
			'single'            => true,
			'sanitize_callback' => array( __CLASS__, 'sanitize_relative_path' ),
			'show_in_rest'      => false,
			'auth_callback'     => '__return_false',
		);

		register_post_meta( ltkf_get_pet_post_type(), self::META_PATH, $args );

		register_post_meta(
			ltkf_get_pet_post_type(),
			self::META_DATE,
			array(
				'type'              => 'string',
				'single'            => true,
				'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest'      => false,
				'auth_callback'     => '__return_false',
			)
		);
	}

	/**
	 * Sanitize stored relative path (under uploads).
	 *
	 * @param string $value Raw.
	 * @return string
	 */
	public static function sanitize_relative_path( $value ) {
		$value = (string) $value;
		$value = str_replace( array( "\0" ), '', $value );
		$value = trim( $value );
		$value = str_replace( array( '..', '\\' ), '', $value );
		return $value;
	}

	/**
	 * WooCommerce: checkout validation (classic + block / Store API cart).
	 *
	 * @return void
	 */
	public static function register_woocommerce_hooks() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'validate_checkout_classic' ), 5 );
		add_action( 'woocommerce_store_api_cart_errors', array( __CLASS__, 'validate_cart_store_api' ), 10, 2 );
	}

	/**
	 * Classic checkout: block if boarding lines need a waiver.
	 *
	 * @return void
	 */
	public static function validate_checkout_classic() {
		if ( ! function_exists( 'wc_add_notice' ) ) {
			return;
		}

		$missing = self::get_missing_waiver_pet_ids_from_cart();
		if ( empty( $missing ) ) {
			return;
		}

		$msg = self::get_checkout_error_message();
		wc_add_notice( wp_kses_post( $msg ), 'error' );
	}

	/**
	 * Block checkout / Store API: add cart errors before order placement.
	 *
	 * @param \WP_Error $errors Errors object.
	 * @param \WC_Cart  $cart   Cart.
	 * @return void
	 */
	public static function validate_cart_store_api( $errors, $cart ) {
		unset( $cart );

		if ( ! $errors instanceof \WP_Error ) {
			return;
		}

		$missing = self::get_missing_waiver_pet_ids_from_cart();
		if ( empty( $missing ) ) {
			return;
		}

		$errors->add(
			'ltkf_waiver_required',
			self::get_checkout_error_message_plain()
		);
	}

	/**
	 * Plain-text checkout message (Store API / WP_Error).
	 *
	 * @return string
	 */
	protected static function get_checkout_error_message_plain() {
		$url = apply_filters( 'ltkf_waiver_portal_url', home_url( '/' ) );

		return sprintf(
			/* translators: %s: portal URL */
			__( 'Boarding liability waiver required. Please open the Legal Waivers tab in your KennelFlow dashboard, sign for each pet on this booking, then return to checkout. Dashboard: %s', 'kennelflow-core' ),
			$url
		);
	}

	/**
	 * User-facing checkout error (HTML allowed for classic notice).
	 *
	 * @return string
	 */
	protected static function get_checkout_error_message() {
		$url = apply_filters( 'ltkf_waiver_portal_url', home_url( '/' ) );

		$link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'KennelFlow dashboard', 'kennelflow-core' )
		);

		return wp_kses(
			sprintf(
				/* translators: %s: link to portal page */
				__( 'Boarding liability waiver required. Please open the Legal Waivers tab in your %s, sign for each pet on this booking, then return to checkout.', 'kennelflow-core' ),
				$link
			),
			array(
				'a' => array(
					'href' => array(),
				),
			)
		);
	}

	/**
	 * Pet IDs on boarding cart lines that do not have a valid waiver file.
	 *
	 * @return int[]
	 */
	public static function get_missing_waiver_pet_ids_from_cart() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return array();
		}

		$need = array();

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['kf_booking_id'] ) ) {
				continue;
			}

			$booking_post_id = absint( $cart_item['kf_booking_id'] );
			if ( $booking_post_id < 1 ) {
				continue;
			}

			if ( ! self::booking_row_is_boarding( $booking_post_id ) ) {
				continue;
			}

			$pet_id = self::get_pet_id_for_booking_post( $booking_post_id );
			if ( $pet_id < 1 ) {
				continue;
			}

			if ( ! self::pet_has_valid_waiver( $pet_id ) ) {
				$need[] = $pet_id;
			}
		}

		return array_values( array_unique( array_map( 'absint', $need ) ) );
	}

	/**
	 * Whether booking_kind is boarding (not clinic visit).
	 *
	 * @param int $booking_post_id kf_bookings.post_id.
	 * @return bool
	 */
	protected static function booking_row_is_boarding( $booking_post_id ) {
		$booking_post_id = absint( $booking_post_id );
		$table           = ltkf_bookings_table_name();
		if ( $booking_post_id < 1 || ! is_string( $table ) || ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) || ! ltkf_table_exists( $table ) ) {
			return false;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- KennelFlow ledger; `%i` validated.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT booking_kind FROM %i WHERE post_id = %d LIMIT 1',
				$table,
				$booking_post_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_object( $row ) ) {
			return false;
		}

		$kind = sanitize_key( (string) $row->booking_kind );

		return 'clinic' !== $kind;
	}

	/**
	 * Pet ID for a booking post.
	 *
	 * @param int $booking_post_id kf_bookings.post_id.
	 * @return int
	 */
	protected static function get_pet_id_for_booking_post( $booking_post_id ) {
		$booking_post_id = absint( $booking_post_id );
		$table           = ltkf_bookings_table_name();
		if ( $booking_post_id < 1 || ! is_string( $table ) || ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) || ! ltkf_table_exists( $table ) ) {
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
	 * Whether the pet has a stored waiver PNG that still exists on disk.
	 *
	 * @param int $pet_id Pet post ID.
	 * @return bool
	 */
	public static function pet_has_valid_waiver( $pet_id ) {
		$pet_id = absint( $pet_id );
		if ( $pet_id < 1 ) {
			return false;
		}

		$rel = (string) get_post_meta( $pet_id, self::META_PATH, true );
		$dt  = (string) get_post_meta( $pet_id, self::META_DATE, true );
		if ( '' === $rel || '' === $dt ) {
			return false;
		}

		$full = WaiverStorage::absolute_path_from_relative( $rel );
		if ( '' === $full || ! is_readable( $full ) ) {
			return false;
		}

		return true;
	}

	/**
	 * AJAX: save PNG from canvas (data URL).
	 *
	 * @return void
	 */
	public static function ajax_save_signature() {
		$nonce_value = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		if ( '' === $nonce_value || ! wp_verify_nonce( $nonce_value, self::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'kennelflow-core' ) ), 403 );
		}

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'kennelflow-core' ) ), 403 );
		}

		if ( ! isset( $_POST['pet_id'], $_POST['image'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing data.', 'kennelflow-core' ) ), 400 );
		}

		$pet_id = absint( wp_unslash( $_POST['pet_id'] ) );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- PNG data URL; validated in WaiverStorage.
		$image = isset( $_POST['image'] ) ? wp_unslash( $_POST['image'] ) : '';
		$image = is_string( $image ) ? $image : '';

		if ( $pet_id < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid pet.', 'kennelflow-core' ) ), 400 );
		}

		if ( ltkf_get_pet_post_type() !== get_post_type( $pet_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid pet.', 'kennelflow-core' ) ), 400 );
		}

		$user_id = get_current_user_id();
		$allowed = OwnerPets::get_pet_ids_for_user( $user_id );
		if ( ! in_array( $pet_id, $allowed, true ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to sign for this pet.', 'kennelflow-core' ) ), 403 );
		}

		$result = WaiverStorage::save_png_data_url_for_pet( $pet_id, $image );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		$rel = isset( $result['relative_path'] ) ? (string) $result['relative_path'] : '';
		if ( '' === $rel ) {
			wp_send_json_error( array( 'message' => __( 'Could not store signature.', 'kennelflow-core' ) ), 500 );
		}

		update_post_meta( $pet_id, self::META_PATH, $rel );

		$now_mysql = current_time( 'mysql', true );
		update_post_meta( $pet_id, self::META_DATE, $now_mysql );

		$ts_display = strtotime( $now_mysql . ' GMT' );
		$date_out   = false !== $ts_display
			? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts_display )
			: '';

		wp_send_json_success(
			array(
				'message'           => __( 'Waiver saved. Thank you.', 'kennelflow-core' ),
				'signed_at'         => $now_mysql,
				'signed_at_display' => $date_out,
				'pet_id'            => $pet_id,
			)
		);
	}

	/**
	 * Default liability copy (HTML). Override with filter `kf_waiver_liability_html`.
	 *
	 * @return string
	 */
	public static function get_liability_html() {
		$default = '<p>' . esc_html__( 'By signing below, you acknowledge and agree that boarding and related services involve inherent risks. You release the facility, its staff, and affiliates from liability for injury, loss, or illness except to the extent caused by gross negligence or willful misconduct, subject to applicable law. You confirm that your pet is current on required vaccinations and that you have disclosed material health or behavior concerns.', 'kennelflow-core' ) . '</p>';

		/**
		 * Filters the HTML shown above the signature pad (Legal Waivers tab).
		 *
		 * @since 0.2.7
		 *
		 * @param string $html Default liability markup.
		 */
		$html = apply_filters( 'ltkf_waiver_liability_html', $default );

		return is_string( $html ) ? $html : $default;
	}
}
