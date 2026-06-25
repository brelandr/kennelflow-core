<?php
/**
 * Daily medical-record retention: auto-archive rows past compliance retention period.
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class ComplianceRetention
 */
class ComplianceRetention {

	const CRON_HOOK = 'ltkf_daily_retention_check';

	/**
	 * Stored on kf_medical_records rows (add-on table).
	 */
	const RECORD_STATUS_ACTIVE = 'active';

	/**
	 * Archived by retention job.
	 */
	const RECORD_STATUS_ARCHIVED = 'archived';

	/**
	 * Owner-submitted compliance document; staff must verify before it counts toward checkout.
	 */
	const RECORD_STATUS_PENDING_REVIEW = 'pending_review';

	/**
	 * Register cron + schema check.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade_medical_records_schema' ), 25 );
		add_action( 'wp_loaded', array( __CLASS__, 'schedule_daily_event' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_cron_callback' ) );
	}

	/**
	 * Ensure kf_medical_records has status + last_visit_date when the table exists (KennelFlow Vet / hub).
	 *
	 * @return void
	 */
	public static function maybe_upgrade_medical_records_schema() {
		$table = ltkf_medical_records_table_name();
		if ( ! is_string( $table ) || ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) || ! ltkf_table_exists( $table ) ) {
			return;
		}

		global $wpdb;

		if ( ! self::db_column_exists( $table, 'status' ) ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- DDL; `%i` table validated above.
			$wpdb->query(
				$wpdb->prepare(
					'ALTER TABLE %i ADD COLUMN `status` varchar(32) NOT NULL DEFAULT %s AFTER `meta_json`',
					$table,
					self::RECORD_STATUS_ACTIVE
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared
		}

		if ( ! self::db_column_exists( $table, 'archived_gmt' ) ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query(
				$wpdb->prepare(
					'ALTER TABLE %i ADD COLUMN `archived_gmt` datetime NULL AFTER `status`',
					$table
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared
		}

		if ( ! self::db_column_exists( $table, 'last_visit_date' ) ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query(
				$wpdb->prepare(
					'ALTER TABLE %i ADD COLUMN `last_visit_date` datetime NULL AFTER `reported_gmt`',
					$table
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared
		}

		if ( ! self::db_index_exists( $table, 'kf_mr_status' ) ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query(
				$wpdb->prepare(
					'ALTER TABLE %i ADD INDEX `kf_mr_status` (`status`)',
					$table
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared
		}
	}

	/**
	 * Whether a column exists on a table (INFORMATION_SCHEMA).
	 *
	 * @param string $table Full table name.
	 * @param string $column Column name.
	 * @return bool
	 */
	protected static function db_column_exists( $table, $column ) {
		global $wpdb;

		$table  = (string) $table;
		$column = (string) $column;
		if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) || ! preg_match( '/^[a-zA-Z0-9_]+$/', $column ) ) {
			return false;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- INFORMATION_SCHEMA; identifiers regex-validated.
		$n = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				DB_NAME,
				$table,
				$column
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $n > 0;
	}

	/**
	 * Whether an index exists on a table (INFORMATION_SCHEMA).
	 *
	 * @param string $table Full table name.
	 * @param string $index Index name.
	 * @return bool
	 */
	protected static function db_index_exists( $table, $index ) {
		global $wpdb;

		$table = (string) $table;
		$index = (string) $index;
		if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) || ! preg_match( '/^[a-zA-Z0-9_]+$/', $index ) ) {
			return false;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- INFORMATION_SCHEMA.STATISTICS; identifiers regex-validated.
		$n = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s',
				DB_NAME,
				$table,
				$index
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $n > 0;
	}

	/**
	 * Schedule daily WP-Cron if not scheduled.
	 *
	 * @return void
	 */
	public static function schedule_daily_event() {
		while ( false !== ( $ts = wp_next_scheduled( 'kf_daily_retention_check' ) ) ) {
			wp_unschedule_event( $ts, 'kf_daily_retention_check' );
		}
		if ( false !== wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
	}

	/**
	 * Cron callback.
	 *
	 * @return void
	 */
	public static function run_cron_callback() {
		self::run();
	}

	/**
	 * Archive expired medical record rows when compliance is set to Auto-Archive.
	 *
	 * @return int Number of rows updated.
	 */
	public static function run() {
		if ( ! class_exists( 'ComplianceAdmin' ) ) {
			return 0;
		}

		$settings = ComplianceAdmin::get_settings();
		$action   = isset( $settings['end_of_retention_action'] ) ? sanitize_key( (string) $settings['end_of_retention_action'] ) : '';

		if ( ComplianceAdmin::ACTION_ARCHIVE !== $action ) {
			return 0;
		}

		$years = isset( $settings['retention_years'] ) ? absint( $settings['retention_years'] ) : (int) ComplianceAdmin::get_defaults()['retention_years'];
		if ( $years < 1 ) {
			$years = 1;
		}

		$table = ltkf_medical_records_table_name();
		if ( ! is_string( $table ) || ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) || ! ltkf_table_exists( $table ) ) {
			return 0;
		}

		self::maybe_upgrade_medical_records_schema();

		if ( ! self::db_column_exists( $table, 'status' ) ) {
			return 0;
		}

		$cutoff = self::retention_cutoff_mysql_gmt( $years );

		global $wpdb;

		$has_last_visit = self::db_column_exists( $table, 'last_visit_date' );

		$archived_ts      = gmdate( 'Y-m-d H:i:s' );
		$has_archived_gmt = self::db_column_exists( $table, 'archived_gmt' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- `%i` table validated; each UPDATE uses a fully literal SQL template (no dynamic SQL fragments).
		if ( $has_archived_gmt ) {
			if ( $has_last_visit ) {
				$wpdb->query(
					$wpdb->prepare(
						'UPDATE %i SET `status` = %s, `archived_gmt` = %s
						WHERE ( `status` IS NULL OR `status` <> %s )
						AND COALESCE(`last_visit_date`, `collected_gmt`, `reported_gmt`, `created_gmt`) < %s
						AND COALESCE(`last_visit_date`, `collected_gmt`, `reported_gmt`, `created_gmt`) IS NOT NULL
						AND COALESCE(`last_visit_date`, `collected_gmt`, `reported_gmt`, `created_gmt`) != %s',
						$table,
						self::RECORD_STATUS_ARCHIVED,
						$archived_ts,
						self::RECORD_STATUS_ARCHIVED,
						$cutoff,
						'0000-00-00 00:00:00'
					)
				);
			} else {
				$wpdb->query(
					$wpdb->prepare(
						'UPDATE %i SET `status` = %s, `archived_gmt` = %s
						WHERE ( `status` IS NULL OR `status` <> %s )
						AND COALESCE(`collected_gmt`, `reported_gmt`, `created_gmt`) < %s
						AND COALESCE(`collected_gmt`, `reported_gmt`, `created_gmt`) IS NOT NULL
						AND COALESCE(`collected_gmt`, `reported_gmt`, `created_gmt`) != %s',
						$table,
						self::RECORD_STATUS_ARCHIVED,
						$archived_ts,
						self::RECORD_STATUS_ARCHIVED,
						$cutoff,
						'0000-00-00 00:00:00'
					)
				);
			}
		} elseif ( $has_last_visit ) {
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET `status` = %s
						WHERE ( `status` IS NULL OR `status` <> %s )
						AND COALESCE(`last_visit_date`, `collected_gmt`, `reported_gmt`, `created_gmt`) < %s
						AND COALESCE(`last_visit_date`, `collected_gmt`, `reported_gmt`, `created_gmt`) IS NOT NULL
						AND COALESCE(`last_visit_date`, `collected_gmt`, `reported_gmt`, `created_gmt`) != %s',
					$table,
					self::RECORD_STATUS_ARCHIVED,
					self::RECORD_STATUS_ARCHIVED,
					$cutoff,
					'0000-00-00 00:00:00'
				)
			);
		} else {
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET `status` = %s
						WHERE ( `status` IS NULL OR `status` <> %s )
						AND COALESCE(`collected_gmt`, `reported_gmt`, `created_gmt`) < %s
						AND COALESCE(`collected_gmt`, `reported_gmt`, `created_gmt`) IS NOT NULL
						AND COALESCE(`collected_gmt`, `reported_gmt`, `created_gmt`) != %s',
					$table,
					self::RECORD_STATUS_ARCHIVED,
					self::RECORD_STATUS_ARCHIVED,
					$cutoff,
					'0000-00-00 00:00:00'
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		if ( ! empty( $wpdb->last_error ) ) {
			return 0;
		}

		return (int) $wpdb->rows_affected;
	}

	/**
	 * Cutoff datetime in MySQL GMT format: now minus N years.
	 *
	 * @param int $years Retention years.
	 * @return string
	 */
	protected static function retention_cutoff_mysql_gmt( $years ) {
		$years = max( 1, absint( $years ) );
		try {
			$d = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
			$d = $d->modify( '-' . $years . ' years' );
			return $d->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			unset( $e );
			return gmdate( 'Y-m-d H:i:s', time() - ( $years * YEAR_IN_SECONDS ) );
		}
	}
}
