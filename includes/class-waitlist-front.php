<?php
/**
 * Public waitlist offer URL → WooCommerce checkout.
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class WaitlistFront
 */
class WaitlistFront {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect_offer_to_checkout' ), 5 );
	}

	/**
	 * Handle ?kf_waitlist_offer=TOKEN
	 *
	 * @return void
	 */
	public static function maybe_redirect_offer_to_checkout() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Emailed GET links use opaque `ltkf_waitlist_offer` validated against DB (not admin forms).
		if ( ! isset( $_GET['ltkf_waitlist_offer'] ) ) {
			return;
		}

		$token = isset( $_GET['ltkf_waitlist_offer'] ) ? sanitize_text_field( wp_unslash( $_GET['ltkf_waitlist_offer'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( '' === $token ) {
			return;
		}

		if ( ! ltkf_table_exists( ltkf_waitlist_table_name() ) ) {
			wp_die( esc_html__( 'Waitlist is not available.', 'kennelflow-core' ), esc_html__( 'KennelFlow', 'kennelflow-core' ), array( 'response' => 503 ) );
		}

		$row = Waitlist::get_by_offer_token( $token );
		if ( null === $row || Waitlist::STATUS_NOTIFIED !== (string) $row->status ) {
			wp_die( esc_html__( 'This offer link is invalid or has already been used.', 'kennelflow-core' ), esc_html__( 'KennelFlow', 'kennelflow-core' ), array( 'response' => 404 ) );
		}

		$expires = isset( $row->offer_expires_gmt ) ? (string) $row->offer_expires_gmt : '';
		if ( '' !== $expires ) {
			$ts = strtotime( $expires . ' UTC' );
			if ( false !== $ts && $ts < time() ) {
				wp_die( esc_html__( 'This checkout link has expired.', 'kennelflow-core' ), esc_html__( 'KennelFlow', 'kennelflow-core' ), array( 'response' => 410 ) );
			}
		}

		if ( ! is_user_logged_in() ) {
			$return = add_query_arg(
				'ltkf_waitlist_offer',
				rawurlencode( $token ),
				home_url( '/' )
			);
			wp_safe_redirect( wp_login_url( $return ) );
			exit;
		}

		$uid = isset( $row->user_id ) ? absint( $row->user_id ) : 0;
		if ( get_current_user_id() !== $uid ) {
			wp_die( esc_html__( 'Please log in as the account that joined the waitlist.', 'kennelflow-core' ), esc_html__( 'KennelFlow', 'kennelflow-core' ), array( 'response' => 403 ) );
		}

		if ( ! class_exists( 'Woocommerce' ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
			wp_die( esc_html__( 'Checkout is not available.', 'kennelflow-core' ), esc_html__( 'KennelFlow', 'kennelflow-core' ), array( 'response' => 503 ) );
		}

		$booking_id = isset( $row->offered_booking_post_id ) ? absint( $row->offered_booking_post_id ) : 0;
		if ( $booking_id < 1 ) {
			wp_die( esc_html__( 'Booking could not be found.', 'kennelflow-core' ), esc_html__( 'KennelFlow', 'kennelflow-core' ), array( 'response' => 404 ) );
		}

		Woocommerce::clear_kennelflow_cart_items();

		$result = Woocommerce::add_booking_to_cart( $booking_id );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), esc_html__( 'KennelFlow', 'kennelflow-core' ), array( 'response' => 400 ) );
		}

		$checkout = wc_get_checkout_url();
		wp_safe_redirect( $checkout );
		exit;
	}
}
