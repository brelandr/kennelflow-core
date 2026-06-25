<?php
/**
 * Portal queries scoped to the current user's owned pets only.
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class PortalData
 */
class PortalData {

	/**
	 * Pet post IDs owned by this user (kf_owner_pet_ids / rebuild cache).
	 *
	 * @param int $user_id User ID.
	 * @return int[]
	 */
	public static function get_owned_pet_ids_for_user( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id < 1 ) {
			return array();
		}
		return OwnerPets::get_pet_ids_for_user( $user_id );
	}

	/**
	 * Upcoming / active boarding rows from kf_bookings for given pets only.
	 *
	 * @param int[] $pet_ids Pet post IDs (kf_pet).
	 * @return object[]     Rows with booking_title when post exists.
	 */
	public static function get_upcoming_boarding_for_pets( array $pet_ids ) {
		$pet_ids = array_values( array_filter( array_map( 'absint', $pet_ids ) ) );
		if ( empty( $pet_ids ) ) {
			return array();
		}

		$table = ltkf_bookings_table_name();
		if ( ! is_string( $table ) || ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) || ! ltkf_table_exists( $table ) ) {
			return array();
		}

		global $wpdb;

		$now = current_time( 'mysql', true );

		$placeholders = implode( ',', array_fill( 0, count( $pet_ids ), '%d' ) );
		$statuses     = array( 'pending', 'pending_payment', 'confirmed', 'checked_in' );
		$st_ph        = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		$args = array_merge( array( $table, $wpdb->posts ), $pet_ids, array( $now ), $statuses );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- `%i` tables validated; bounded IN lists.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'
			SELECT b.*, p.post_title AS booking_title
			FROM %i AS b
			LEFT JOIN %i AS p ON p.ID = b.post_id AND p.post_status NOT IN ( \'trash\', \'auto-draft\' )
			WHERE b.pet_id IN ( ' . $placeholders . ' )
			AND b.end_gmt >= %s
			AND b.status IN ( ' . $st_ph . ' )
			AND ( b.booking_kind IN ( \'boarding\', \'\' ) )
			ORDER BY b.start_gmt ASC
			LIMIT 100
		',
				...$args
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Medical / lab rows from kf_medical_records (vaccination-like + other).
	 *
	 * @param int[] $pet_ids Pet post IDs.
	 * @return object[]     Rows newest first.
	 */
	public static function get_medical_records_for_pets( array $pet_ids ) {
		$pet_ids = array_values( array_filter( array_map( 'absint', $pet_ids ) ) );
		if ( empty( $pet_ids ) ) {
			return array();
		}

		$table = ltkf_medical_records_table_name();
		if ( ! is_string( $table ) || ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) || ! ltkf_table_exists( $table ) ) {
			return array();
		}

		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $pet_ids ), '%d' ) );

		$exclude = ltkf_medical_records_where_not_archived_for_prepare();

		$args_base = array_merge( array( $table ), $pet_ids );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- `%i`; archived fragment matches `ltkf_medical_records_where_not_archived_for_prepare()`.
		if ( '' !== $exclude['sql'] ) {
			$ex_vals = (array) $exclude['value'];
			$xa      = isset( $ex_vals[0] ) ? (string) $ex_vals[0] : '';
			$xb      = isset( $ex_vals[1] ) ? (string) $ex_vals[1] : '';
			$rows    = $wpdb->get_results(
				$wpdb->prepare(
					'
			SELECT *
			FROM %i
			WHERE pet_post_id IN ( ' . $placeholders . ' )
			AND ( `status` IS NULL OR ( `status` <> %s AND `status` <> %s ) )
			ORDER BY COALESCE( reported_gmt, collected_gmt, created_gmt ) DESC, id DESC
			LIMIT 200
		',
					...array_merge( $args_base, array( $xa, $xb ) )
				)
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'
			SELECT *
			FROM %i
			WHERE pet_post_id IN ( ' . $placeholders . ' )
			ORDER BY COALESCE( reported_gmt, collected_gmt, created_gmt ) DESC, id DESC
			LIMIT 200
		',
					...$args_base
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Whether a medical record row looks vaccination-related (for UI grouping).
	 *
	 * @param object $row DB row.
	 * @return bool
	 */
	public static function row_is_vaccination_like( $row ) {
		if ( ! is_object( $row ) ) {
			return false;
		}
		$name = isset( $row->analyte_name ) ? strtolower( (string) $row->analyte_name ) : '';
		$code = isset( $row->analyte_code ) ? strtolower( (string) $row->analyte_code ) : '';
		$hay  = $name . ' ' . $code;

		$needles = array( 'vaccin', 'vacc ', ' rabies', 'dhpp', 'fvrcp', 'bordet', 'lepto', 'lyme', ' titre', 'titer' );

		$default = false;
		foreach ( $needles as $n ) {
			if ( false !== strpos( $hay, $n ) ) {
				$default = true;
				break;
			}
		}

		/**
		 * Filters whether a kf_medical_records row is treated as vaccination history in the portal.
		 *
		 * @since 0.2.0
		 *
		 * @param bool   $is_vaccine Default heuristic.
		 * @param object $row        DB row.
		 */
		return (bool) apply_filters( 'ltkf_portal_is_vaccination_record', $default, $row );
	}

	/**
	 * Fetch one medical record if it belongs to one of the given pets.
	 *
	 * @param int   $record_id Primary key in kf_medical_records.
	 * @param int[] $pet_ids   Allowed pet IDs.
	 * @return object|null
	 */
	public static function get_medical_record_if_owned( $record_id, array $pet_ids ) {
		$record_id = absint( $record_id );
		$pet_ids   = array_values( array_filter( array_map( 'absint', $pet_ids ) ) );
		if ( $record_id < 1 || empty( $pet_ids ) ) {
			return null;
		}

		$table = ltkf_medical_records_table_name();
		if ( ! is_string( $table ) || ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) || ! ltkf_table_exists( $table ) ) {
			return null;
		}

		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $pet_ids ), '%d' ) );

		$exclude = ltkf_medical_records_where_not_archived_for_prepare();

		$args_base = array_merge( array( $table, $record_id ), $pet_ids );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- `%i`; archived fragment matches helper.
		if ( '' !== $exclude['sql'] ) {
			$ex_vals = (array) $exclude['value'];
			$xa      = isset( $ex_vals[0] ) ? (string) $ex_vals[0] : '';
			$xb      = isset( $ex_vals[1] ) ? (string) $ex_vals[1] : '';
			$row     = $wpdb->get_row(
				$wpdb->prepare(
					'
			SELECT *
			FROM %i
			WHERE id = %d
			AND pet_post_id IN ( ' . $placeholders . ' )
			AND ( `status` IS NULL OR ( `status` <> %s AND `status` <> %s ) )
			LIMIT 1
		',
					...array_merge( $args_base, array( $xa, $xb ) )
				)
			);
		} else {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'
			SELECT *
			FROM %i
			WHERE id = %d
			AND pet_post_id IN ( ' . $placeholders . ' )
			LIMIT 1
		',
					...$args_base
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return is_object( $row ) ? $row : null;
	}

	/**
	 * Fetch a kf_bookings row if the booking belongs to one of the user's pets.
	 *
	 * @param int $booking_post_id Booking post ID (kf_bookings.post_id).
	 * @param int $user_id         WordPress user ID.
	 * @return object|null
	 */
	public static function get_booking_row_for_user( $booking_post_id, $user_id ) {
		$booking_post_id = absint( $booking_post_id );
		$user_id         = absint( $user_id );
		if ( $booking_post_id < 1 || $user_id < 1 ) {
			return null;
		}

		$pet_ids = self::get_owned_pet_ids_for_user( $user_id );
		if ( empty( $pet_ids ) ) {
			return null;
		}

		$table = ltkf_bookings_table_name();
		if ( ! is_string( $table ) || ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) || ! ltkf_table_exists( $table ) ) {
			return null;
		}

		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $pet_ids ), '%d' ) );

		$args = array_merge( array( $table, $booking_post_id ), $pet_ids );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- `%i`; IN list matches pet IDs.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'
			SELECT *
			FROM %i
			WHERE post_id = %d
			AND pet_id IN ( ' . $placeholders . ' )
			LIMIT 1
		',
				...$args
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return is_object( $row ) ? $row : null;
	}

	/**
	 * Find a deposit order with unpaid balance for a confirmed boarding booking (owner portal).
	 *
	 * @param int $booking_post_id kf_bookings.post_id.
	 * @param int $user_id         WordPress user ID.
	 * @return array{balance: float, order_id: int}|null
	 */
	public static function get_unpaid_balance_context_for_booking( $booking_post_id, $user_id ) {
		$booking_post_id = absint( $booking_post_id );
		$user_id         = absint( $user_id );
		if ( $booking_post_id < 1 || $user_id < 1 ) {
			return null;
		}

		$map = self::get_unpaid_balance_map_for_user( $user_id, array( $booking_post_id ) );

		return isset( $map[ $booking_post_id ] ) ? $map[ $booking_post_id ] : null;
	}

	/**
	 * Map booking post ID → unpaid balance context for many bookings in one order query.
	 *
	 * @param int   $user_id          User ID.
	 * @param int[] $booking_post_ids Booking post IDs to match.
	 * @return array<int, array{balance: float, order_id: int}>
	 */
	public static function get_unpaid_balance_map_for_user( $user_id, array $booking_post_ids ) {
		$user_id          = absint( $user_id );
		$booking_post_ids = array_values( array_filter( array_map( 'absint', $booking_post_ids ) ) );
		if ( $user_id < 1 || empty( $booking_post_ids ) ) {
			return array();
		}

		if ( ! function_exists( 'wc_get_orders' ) || ! class_exists( 'Woocommerce' ) || ! class_exists( 'WC_Order_Item_Product' ) ) {
			return array();
		}

		$meta_key = '_kf_unpaid_balance';
		if ( class_exists( 'WoocommerceDeposits' ) ) {
			$meta_key = WoocommerceDeposits::ORDER_META_UNPAID_BALANCE;
		}

		$orders = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'limit'       => 100,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'status'      => array( 'processing', 'completed', 'on-hold' ),
			)
		);

		if ( ! is_array( $orders ) ) {
			return array();
		}

		$want = array_fill_keys( $booking_post_ids, true );
		$out  = array();

		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}

			$raw = $order->get_meta( $meta_key, true );
			$bal = is_numeric( $raw ) ? (float) wc_format_decimal( $raw ) : 0.0;
			if ( $bal <= 0 ) {
				continue;
			}

			$bids = array();
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product ) {
					continue;
				}

				$bid = absint( $item->get_meta( Woocommerce::ORDER_ITEM_META_BOOKING_ID, true ) );
				if ( $bid > 0 && isset( $want[ $bid ] ) && ! isset( $out[ $bid ] ) ) {
					$bids[] = $bid;
				}
			}

			$bids = array_unique( $bids );
			foreach ( $bids as $bid ) {
				$out[ $bid ] = array(
					'balance'  => $bal,
					'order_id' => (int) $order->get_id(),
				);
			}

			if ( count( $out ) >= count( $want ) ) {
				break;
			}
		}

		return $out;
	}
}
