<?php
/**
 * Create KennelPress booking rows for waitlist offers (soft dependency).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class WaitlistBooking
 */
class WaitlistBooking {

	/**
	 * Create a boarding booking in pending_payment for checkout.
	 *
	 * @param object $waitlist_row Row from wp_kf_waitlist.
	 * @param int    $kennel_id    Kennel post ID.
	 * @return int|\WP_Error Booking post ID or error.
	 */
	public static function create_pending_payment_booking( $waitlist_row, $kennel_id ) {
		if ( ! is_object( $waitlist_row ) ) {
			return new \WP_Error( 'ltkf_wl_bad_row', __( 'Invalid waitlist entry.', 'kennelflow-core' ) );
		}

		$pet_id = isset( $waitlist_row->pet_id ) ? absint( $waitlist_row->pet_id ) : 0;
		if ( $pet_id < 1 || 'kf_pet' !== get_post_type( $pet_id ) ) {
			return new \WP_Error( 'ltkf_wl_bad_pet', __( 'Invalid pet.', 'kennelflow-core' ) );
		}

		$kennel_id = absint( $kennel_id );
		if ( $kennel_id < 1 || 'kennelpress_kennel' !== get_post_type( $kennel_id ) ) {
			return new \WP_Error( 'ltkf_wl_bad_kennel', __( 'Invalid kennel.', 'kennelflow-core' ) );
		}

		$start_gmt = isset( $waitlist_row->start_gmt ) ? (string) $waitlist_row->start_gmt : '';
		$end_gmt   = isset( $waitlist_row->end_gmt ) ? (string) $waitlist_row->end_gmt : '';

		if ( ! class_exists( 'KennelFlow_Boarding_Availability' ) || ! class_exists( 'KennelFlow_Boarding_Post_Meta' ) ) {
			return new \WP_Error( 'ltkf_wl_no_kennelpress', __( 'Booking system is not available.', 'kennelflow-core' ) );
		}

		$ok = KennelFlow_Boarding_Availability::validate_interval( $start_gmt, $end_gmt );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}

		$location_post_id = absint( get_post_meta( $kennel_id, KennelFlow_Boarding_Post_Meta::KENNEL_LOCATION_ID, true ) );
		if ( $location_post_id < 1 ) {
			return new \WP_Error( 'ltkf_wl_kennel_no_loc', __( 'Kennel has no location assigned.', 'kennelflow-core' ) );
		}

		$available = KennelFlow_Boarding_Availability::get_available_kennel_ids( $location_post_id, $start_gmt, $end_gmt );
		if ( is_wp_error( $available ) ) {
			return $available;
		}
		if ( ! in_array( $kennel_id, $available, true ) ) {
			return new \WP_Error( 'ltkf_wl_kennel_taken', __( 'That kennel is no longer available.', 'kennelflow-core' ) );
		}

		if ( class_exists( 'KennelFlow_Boarding_Facility_Settings' ) ) {
			$facility = KennelFlow_Boarding_Facility_Settings::validate_booking_interval( $location_post_id, $start_gmt, $end_gmt );
			if ( is_wp_error( $facility ) ) {
				return $facility;
			}
		}

		$title = sprintf(
			/* translators: 1: pet title, 2: start datetime (UTC). */
			__( 'Booking: %1$s — %2$s', 'kennelflow-core' ),
			get_the_title( $pet_id ),
			$start_gmt
		);

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'kennelpress_booking',
				'post_status' => 'publish',
				'post_title'  => $title,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, KennelFlow_Boarding_Post_Meta::BOOKING_PET_ID, $pet_id );
		update_post_meta( $post_id, KennelFlow_Boarding_Post_Meta::BOOKING_KENNEL_ID, $kennel_id );
		update_post_meta( $post_id, KennelFlow_Boarding_Post_Meta::BOOKING_KIND, KennelFlow_Boarding_Post_Meta::sanitize_booking_kind( 'boarding' ) );
		update_post_meta( $post_id, KennelFlow_Boarding_Post_Meta::BOOKING_START_GMT, $start_gmt );
		update_post_meta( $post_id, KennelFlow_Boarding_Post_Meta::BOOKING_END_GMT, $end_gmt );
		update_post_meta( $post_id, KennelFlow_Boarding_Post_Meta::BOOKING_STATUS, 'pending_payment' );

		if ( class_exists( 'KennelFlow_Boarding_Cache' ) ) {
			KennelFlow_Boarding_Cache::bump_query_bust();
		}

		return (int) $post_id;
	}

	/**
	 * Cancel or trash a booking created for an offer.
	 *
	 * @param int $booking_post_id Booking post ID.
	 * @return void
	 */
	public static function cancel_unpaid_offer_booking( $booking_post_id ) {
		$booking_post_id = absint( $booking_post_id );
		if ( $booking_post_id < 1 || 'kennelpress_booking' !== get_post_type( $booking_post_id ) ) {
			return;
		}

		if ( ! class_exists( 'KennelFlow_Boarding_Post_Meta' ) ) {
			return;
		}

		$status = (string) get_post_meta( $booking_post_id, KennelFlow_Boarding_Post_Meta::BOOKING_STATUS, true );
		if ( 'pending_payment' !== $status ) {
			return;
		}

		update_post_meta( $booking_post_id, KennelFlow_Boarding_Post_Meta::BOOKING_STATUS, KennelFlow_Boarding_Post_Meta::sanitize_booking_status( 'cancelled' ) );

		if ( class_exists( 'KennelFlow_Boarding_Cache' ) ) {
			KennelFlow_Boarding_Cache::bump_query_bust();
		}
	}
}
