<?php
/**
 * Waitlist CRUD (wp_kf_waitlist).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class Waitlist
 */
class Waitlist {

	const STATUS_WAITING = 'waiting';

	const STATUS_NOTIFIED = 'notified';

	const STATUS_EXPIRED = 'expired';

	const STATUS_CONVERTED = 'converted';

	const STATUS_CANCELLED = 'cancelled';

	/**
	 * Insert a waiting list row.
	 *
	 * @param int    $user_id         User ID.
	 * @param int    $pet_id          Pet post ID.
	 * @param int    $location_id     Hub location post ID.
	 * @param string $start_gmt       Start Y-m-d H:i:s UTC.
	 * @param string $end_gmt         End Y-m-d H:i:s UTC.
	 * @param string $requested_dates JSON or note for requested_dates column.
	 * @return int|false Insert ID or false.
	 */
	public static function insert_waiting( $user_id, $pet_id, $location_id, $start_gmt, $end_gmt, $requested_dates = '' ) {
		global $wpdb;

		$table = ltkf_waitlist_table_name();
		if ( ! ltkf_table_exists( $table ) ) {
			return false;
		}

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Waitlist insert.
		$ok = $wpdb->insert(
			$table,
			array(
				'user_id'                 => absint( $user_id ),
				'pet_id'                  => absint( $pet_id ),
				'location_id'             => absint( $location_id ),
				'start_gmt'               => $start_gmt,
				'end_gmt'                 => $end_gmt,
				'requested_dates'         => $requested_dates,
				'status'                  => self::STATUS_WAITING,
				'offer_token'             => '',
				'offered_booking_post_id' => 0,
				'created_gmt'             => $now,
				'updated_gmt'             => $now,
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( ! $ok ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * First waiting row overlapping an interval at a location (FIFO).
	 *
	 * @param int    $location_id Location post ID.
	 * @param string $freed_start Freed interval start GMT.
	 * @param string $freed_end   Freed interval end GMT.
	 * @return object|null
	 */
	public static function get_first_waiting_overlap( $location_id, $freed_start, $freed_end ) {
		global $wpdb;

		$table = ltkf_waitlist_table_name();
		if ( ! ltkf_table_exists( $table ) ) {
			return null;
		}

		$location_id = absint( $location_id );
		if ( $location_id < 1 ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Table from helper; overlap semantics.
		$sql = $wpdb->prepare(
			"SELECT * FROM `{$table}`
			WHERE status = %s
			AND location_id = %d
			AND start_gmt < %s
			AND end_gmt > %s
			ORDER BY created_gmt ASC, id ASC
			LIMIT 1",
			self::STATUS_WAITING,
			$location_id,
			$freed_end,
			$freed_start
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		$row = $wpdb->get_row( $sql );
		return $row ? $row : null;
	}

	/**
	 * Row by offer token.
	 *
	 * @param string $token Token.
	 * @return object|null
	 */
	public static function get_by_offer_token( $token ) {
		global $wpdb;

		$table = ltkf_waitlist_table_name();
		if ( ! ltkf_table_exists( $table ) || '' === (string) $token ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Token lookup.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE offer_token = %s LIMIT 1",
				sanitize_text_field( (string) $token )
			)
		);

		return $row ? $row : null;
	}

	/**
	 * Update row after sending offer.
	 *
	 * @param int    $id              Row ID.
	 * @param string $token           Offer token.
	 * @param string $expires_gmt     Expiry Y-m-d H:i:s UTC.
	 * @param int    $booking_post_id Booking post ID.
	 * @return bool
	 */
	public static function mark_notified( $id, $token, $expires_gmt, $booking_post_id ) {
		global $wpdb;

		$table = ltkf_waitlist_table_name();
		if ( ! ltkf_table_exists( $table ) ) {
			return false;
		}

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Waitlist update.
		return false !== $wpdb->update(
			$table,
			array(
				'status'                  => self::STATUS_NOTIFIED,
				'offer_token'             => $token,
				'offer_expires_gmt'       => $expires_gmt,
				'offered_booking_post_id' => absint( $booking_post_id ),
				'updated_gmt'             => $now,
			),
			array( 'id' => absint( $id ) ),
			array( '%s', '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Mark row expired (offer timed out).
	 *
	 * @param int $id Row ID.
	 * @return bool
	 */
	public static function mark_expired( $id ) {
		global $wpdb;

		$table = ltkf_waitlist_table_name();
		if ( ! ltkf_table_exists( $table ) ) {
			return false;
		}

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Waitlist update.
		return false !== $wpdb->update(
			$table,
			array(
				'status'            => self::STATUS_EXPIRED,
				'offer_token'       => '',
				'offer_expires_gmt' => null,
				'updated_gmt'       => $now,
			),
			array( 'id' => absint( $id ) ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Rows where offer expired and still notified.
	 *
	 * @return object[]
	 */
	public static function get_expired_notified_rows() {
		global $wpdb;

		$table = ltkf_waitlist_table_name();
		if ( ! ltkf_table_exists( $table ) ) {
			return array();
		}

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Expired offers.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}`
				WHERE status = %s
				AND offer_expires_gmt IS NOT NULL
				AND offer_expires_gmt < %s
				ORDER BY id ASC",
				self::STATUS_NOTIFIED,
				$now
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Whether user already has an overlapping waiting row for pet/location.
	 *
	 * @param int    $user_id     User ID.
	 * @param int    $pet_id      Pet ID.
	 * @param int    $location_id Location ID.
	 * @param string $start_gmt   Start GMT.
	 * @param string $end_gmt     End GMT.
	 * @return bool
	 */
	public static function has_duplicate_waiting( $user_id, $pet_id, $location_id, $start_gmt, $end_gmt ) {
		global $wpdb;

		$table = ltkf_waitlist_table_name();
		if ( ! ltkf_table_exists( $table ) ) {
			return false;
		}

		$n = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}`
				WHERE user_id = %d
				AND pet_id = %d
				AND location_id = %d
				AND status = %s
				AND start_gmt = %s
				AND end_gmt = %s",
				absint( $user_id ),
				absint( $pet_id ),
				absint( $location_id ),
				self::STATUS_WAITING,
				$start_gmt,
				$end_gmt
			)
		);

		return $n > 0;
	}
}
