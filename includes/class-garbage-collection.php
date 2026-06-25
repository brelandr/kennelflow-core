<?php
/**
 * CRON + utilities: remove abandoned pending_payment bookings (inventory holds).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class GarbageCollection
 */
class GarbageCollection {

	/**
	 * Abandon window: rows older than this are removed (seconds).
	 */
	const HOLD_MAX_AGE_SECONDS = 7200;

	/**
	 * Cron hook name.
	 */
	const CRON_HOOK = 'ltkf_hourly_cleanup';

	/**
	 * Run cleanup: delete booking posts + index rows for stale pending_payment holds.
	 *
	 * @return int Number of bookings removed.
	 */
	public static function run() {
		$table = ltkf_bookings_table_name();
		if ( ! is_string( $table ) || ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) ) {
			return 0;
		}
		if ( ! ltkf_table_exists( $table ) ) {
			return 0;
		}

		if ( ! self::table_has_created_gmt_column( $table ) ) {
			return 0;
		}

		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::HOLD_MAX_AGE_SECONDS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- GC sweep; `%i` bookings table validated above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT post_id FROM %i WHERE status = %s AND created_gmt < %s AND created_gmt NOT IN (%s, %s)',
				$table,
				'pending_payment',
				$cutoff,
				'0000-00-00 00:00:00',
				'1970-01-01 00:00:00'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $rows as $row ) {
			$post_id = isset( $row->post_id ) ? absint( $row->post_id ) : 0;
			if ( $post_id < 1 ) {
				continue;
			}

			wp_delete_post( $post_id, true );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Fallback if hooks did not remove index row; validated table identifier.
			$wpdb->delete( $table, array( 'post_id' => $post_id ), array( '%d' ) );

			++$count;
		}

		return $count;
	}

	/**
	 * Whether kf_bookings has created_gmt (KennelPress DB v3+).
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	protected static function table_has_created_gmt_column( $table ) {
		$table = (string) $table;
		if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) ) {
			return false;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Schema probe; `%i` validated table identifier.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'created_gmt' ) );

		return null !== $found && '' !== $found;
	}
}
