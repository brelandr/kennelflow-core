<?php
/**
 * Automated waitlist: cancellation hook, offers, email, cron.
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class WaitlistEngine
 */
class WaitlistEngine {

	const CRON_HOOK = 'ltkf_waitlist_process_expired';

	const ACTION_PROCESS_RELEASE = 'ltkf_waitlist_process_slot_release';

	const OFFER_TTL_SECONDS = 14400;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'ltkf_booking_cancelled', array( __CLASS__, 'on_booking_cancelled' ), 10, 1 );
		add_action( self::ACTION_PROCESS_RELEASE, array( __CLASS__, 'process_slot_release' ), 10, 1 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'process_expired_offers' ) );

		add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_schedule' ) );
		add_action( 'init', array( __CLASS__, 'schedule_cron' ), 30 );

		if ( class_exists( 'KennelFlow_Boarding_Availability' ) ) {
			add_filter( 'kennelpress_availability_blocking_statuses', array( __CLASS__, 'include_pending_payment_blocking' ) );
		}
	}

	/**
	 * Unpaid waitlist holds should block the slot.
	 *
	 * @param string[] $statuses Statuses.
	 * @return string[]
	 */
	public static function include_pending_payment_blocking( $statuses ) {
		if ( ! is_array( $statuses ) ) {
			$statuses = array();
		}
		if ( ! in_array( 'pending_payment', $statuses, true ) ) {
			$statuses[] = 'pending_payment';
		}
		return $statuses;
	}

	/**
	 * Schedule cron if missing.
	 *
	 * @return void
	 */
	public static function schedule_cron() {
		while ( false !== ( $ts = wp_next_scheduled( 'kf_waitlist_process_expired' ) ) ) {
			wp_unschedule_event( $ts, 'kf_waitlist_process_expired' );
		}
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'ltkf_every_fifteen_minutes', self::CRON_HOOK );
	}

	/**
	 * @param string[] $schedules Schedules.
	 * @return string[]
	 */
	public static function register_cron_schedule( $schedules ) {
		if ( ! is_array( $schedules ) ) {
			$schedules = array();
		}
		if ( ! isset( $schedules['ltkf_every_fifteen_minutes'] ) ) {
			$schedules['ltkf_every_fifteen_minutes'] = array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every fifteen minutes (KennelFlow waitlist)', 'kennelflow-core' ),
			);
		}
		return $schedules;
	}

	/**
	 * Defer slot processing to next cron tick.
	 *
	 * @param array $context Context from kf_booking_cancelled.
	 * @return void
	 */
	public static function on_booking_cancelled( $context ) {
		if ( ! apply_filters( 'ltkf_waitlist_engine_enabled', true ) ) {
			return;
		}

		if ( ! is_array( $context ) ) {
			return;
		}

		wp_schedule_single_event( time() + 5, self::ACTION_PROCESS_RELEASE, array( $context ) );
	}

	/**
	 * Find first waitlist match and send offer.
	 *
	 * @param array $context Booking cancellation context.
	 * @return void
	 */
	public static function process_slot_release( $context ) {
		if ( ! is_array( $context ) ) {
			return;
		}

		$location_id = isset( $context['location_id'] ) ? absint( $context['location_id'] ) : 0;
		$start_gmt   = isset( $context['start_gmt'] ) ? (string) $context['start_gmt'] : '';
		$end_gmt     = isset( $context['end_gmt'] ) ? (string) $context['end_gmt'] : '';

		if ( $location_id < 1 || '' === $start_gmt || '' === $end_gmt ) {
			return;
		}

		self::offer_next_for_interval( $location_id, $start_gmt, $end_gmt );
	}

	/**
	 * Offer the next queued customer for an overlapping interval.
	 *
	 * @param int    $location_id Location post ID.
	 * @param string $start_gmt   Interval start GMT.
	 * @param string $end_gmt     Interval end GMT.
	 * @return void
	 */
	public static function offer_next_for_interval( $location_id, $start_gmt, $end_gmt ) {
		if ( ! ltkf_table_exists( ltkf_waitlist_table_name() ) ) {
			return;
		}

		$row = Waitlist::get_first_waiting_overlap( $location_id, $start_gmt, $end_gmt );
		if ( null === $row ) {
			return;
		}

		if ( ! class_exists( 'KennelFlow_Boarding_Availability' ) ) {
			return;
		}

		$w_start = isset( $row->start_gmt ) ? (string) $row->start_gmt : '';
		$w_end   = isset( $row->end_gmt ) ? (string) $row->end_gmt : '';
		$loc     = isset( $row->location_id ) ? absint( $row->location_id ) : 0;

		$ids = KennelFlow_Boarding_Availability::get_available_kennel_ids( $loc, $w_start, $w_end );
		if ( is_wp_error( $ids ) || empty( $ids ) ) {
			return;
		}

		$kennel_id = (int) $ids[0];

		/**
		 * Filters which kennel ID to assign for a waitlist offer.
		 *
		 * @since 0.2.0
		 *
		 * @param int     $kennel_id   First available kennel ID.
		 * @param int[]   $ids         All available kennel IDs.
		 * @param object  $waitlist_row Row.
		 */
		$kennel_id = (int) apply_filters( 'ltkf_waitlist_offer_kennel_id', $kennel_id, $ids, $row );

		$booking_id = WaitlistBooking::create_pending_payment_booking( $row, $kennel_id );
		if ( is_wp_error( $booking_id ) ) {
			return;
		}

		$booking_id  = absint( $booking_id );
		$token       = bin2hex( random_bytes( 32 ) );
		$expires_ts  = time() + (int) apply_filters( 'ltkf_waitlist_offer_ttl_seconds', self::OFFER_TTL_SECONDS );
		$expires_gmt = gmdate( 'Y-m-d H:i:s', $expires_ts );

		Waitlist::mark_notified( (int) $row->id, $token, $expires_gmt, $booking_id );

		$pet_id = isset( $row->pet_id ) ? (int) $row->pet_id : 0;
		self::send_spot_open_email( (int) $row->id, (int) $row->user_id, $token, $expires_gmt, $booking_id, $pet_id );

		/**
		 * Fires after a waitlist customer was notified.
		 *
		 * @since 0.2.0
		 *
		 * @param int    $waitlist_id Waitlist row ID.
		 * @param int    $booking_id  Booking post ID.
		 * @param string $token       Offer token.
		 */
		do_action( 'ltkf_waitlist_offer_sent', (int) $row->id, $booking_id, $token );
	}

	/**
	 * Email owner with checkout link.
	 *
	 * @param int    $waitlist_id Waitlist row ID.
	 * @param int    $user_id     User ID.
	 * @param string $token       Token.
	 * @param string $expires_gmt Expiry GMT string.
	 * @param int    $booking_id  Booking post ID.
	 * @param int    $pet_id      Pet post ID (waitlist row).
	 * @return void
	 */
	protected static function send_spot_open_email( $waitlist_id, $user_id, $token, $expires_gmt, $booking_id, $pet_id = 0 ) {
		unset( $waitlist_id, $booking_id );

		$user = get_userdata( $user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return;
		}

		$url = add_query_arg(
			array(
				'ltkf_waitlist_offer' => rawurlencode( $token ),
			),
			home_url( '/' )
		);

		$blogname = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );

		/* translators: %s: site name */
		$subject = sprintf( __( '[%s] Spot opened — complete checkout within 4 hours', 'kennelflow-core' ), $blogname );

		$expires_local = $expires_gmt;
		$ts            = strtotime( $expires_gmt . ' UTC' );
		if ( false !== $ts ) {
			$expires_local = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts, wp_timezone() );
		}

		$body  = '<p>';
		$body .= sprintf(
			/* translators: %s: site name */
			esc_html__( 'A boarding spot that matches your waitlist request is available on %s.', 'kennelflow-core' ),
			esc_html( $blogname )
		);
		$body .= '</p><p><strong>';
		$body .= sprintf(
			/* translators: %s: local expiry date/time */
			esc_html__( 'This link expires at %s. If you do not complete checkout in time, we will offer the spot to the next person on the list.', 'kennelflow-core' ),
			esc_html( $expires_local )
		);
		$body .= '</strong></p><p><a href="' . esc_url( $url ) . '">';
		$body .= esc_html__( 'Complete checkout', 'kennelflow-core' );
		$body .= '</a></p>';

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		/**
		 * Filters waitlist “spot opened” email before send.
		 *
		 * @since 0.2.0
		 *
		 * @param array $mail {
		 *   @type string $subject
		 *   @type string $body
		 *   @type string $headers
		 *   @type string $to
		 * }
		 * @param string $token Offer token.
		 */
		$mail = apply_filters(
			'ltkf_waitlist_spot_open_email',
			array(
				'to'      => $user->user_email,
				'subject' => $subject,
				'body'    => $body,
				'headers' => $headers,
			),
			$token
		);

		if ( empty( $mail['to'] ) || empty( $mail['subject'] ) || empty( $mail['body'] ) ) {
			return;
		}

		wp_mail( $mail['to'], $mail['subject'], $mail['body'], isset( $mail['headers'] ) ? $mail['headers'] : $headers );

		if ( class_exists( 'TwilioService' ) && function_exists( 'ltkf_get_user_phone_for_sms' ) ) {
			$phone = ltkf_get_user_phone_for_sms( $user_id );
			if ( '' !== $phone ) {
				$pet_id   = absint( $pet_id );
				$pet_name = '';
				if ( $pet_id > 0 ) {
					$pet_name = get_the_title( $pet_id );
				}
				if ( '' === $pet_name ) {
					$pet_name = __( 'your pet', 'kennelflow-core' );
				}
				TwilioService::send_sms(
					$phone,
					'A spot has opened up for ' . $pet_name . '! Check your email or portal to claim it before it expires.'
				);
			}
		}
	}

	/**
	 * Cron: expire unpaid offers and advance queue.
	 *
	 * @return void
	 */
	public static function process_expired_offers() {
		if ( ! ltkf_table_exists( ltkf_waitlist_table_name() ) ) {
			return;
		}

		$rows = Waitlist::get_expired_notified_rows();
		foreach ( $rows as $row ) {
			if ( ! isset( $row->id ) ) {
				continue;
			}

			$booking_id = isset( $row->offered_booking_post_id ) ? absint( $row->offered_booking_post_id ) : 0;

			Waitlist::mark_expired( (int) $row->id );

			if ( $booking_id > 0 ) {
				WaitlistBooking::cancel_unpaid_offer_booking( $booking_id );
			}
		}
	}
}
