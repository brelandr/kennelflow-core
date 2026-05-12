<?php
/**
 * Daily CRM: 30-day medical-record expiration reminders to pet owners.
 *
 * Uses WooCommerce Action Scheduler (recurring sweep + per-pet async mail) instead of WP-Cron bulk sends.
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class AutomatedCrm
 */
class AutomatedCrm {

	const CRON_HOOK = 'ltkf_daily_crm_sweep';

	/**
	 * Per-pet reminder (Action Scheduler async action).
	 *
	 * @var string
	 */
	const SINGLE_REMINDER_HOOK = 'ltkf_send_single_crm_reminder';

	/**
	 * Action Scheduler group for CRM jobs.
	 *
	 * @var string
	 */
	const AS_GROUP = 'kennelflow';

	/**
	 * Register cron + callbacks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_loaded', array( __CLASS__, 'schedule_daily_event' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_sweep' ) );
		add_action( self::SINGLE_REMINDER_HOOK, array( __CLASS__, 'send_single_crm_reminder' ), 10, 2 );
	}

	/**
	 * Clear legacy WP-Cron and schedule daily Action Scheduler recurrence when available.
	 *
	 * @return void
	 */
	public static function schedule_daily_event() {
		if ( ! class_exists( 'ActionScheduler' ) || ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}

		self::clear_legacy_wp_cron();

		if ( false !== as_next_scheduled_action( self::CRON_HOOK, array(), self::AS_GROUP ) ) {
			return;
		}

		as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::CRON_HOOK, array(), self::AS_GROUP );
	}

	/**
	 * Remove old WP-Cron event if present (migration from pre–Action Scheduler installs).
	 *
	 * @return void
	 */
	protected static function clear_legacy_wp_cron() {
		wp_clear_scheduled_hook( 'kf_daily_crm_sweep' );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'kf_daily_crm_sweep', array(), self::AS_GROUP );
			as_unschedule_all_actions( 'kf_send_single_crm_reminder', array(), self::AS_GROUP );
		}
		if ( false !== wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * Cron callback: find kf_medical_records expiring in 30 days (GMT calendar date) and queue one async action per pet.
	 *
	 * @return void
	 */
	public static function run_sweep() {
		$table = ltkf_medical_records_table_name();
		if ( ! ltkf_table_exists( $table ) ) {
			return;
		}

		if ( class_exists( 'ComplianceRetention' ) ) {
			ComplianceRetention::maybe_upgrade_medical_records_schema();
		}

		if ( ! ltkf_db_column_exists( $table, 'expiration_gmt' ) ) {
			return;
		}

		try {
			$utc = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		} catch ( Exception $e ) {
			unset( $e );
			return;
		}

		/**
		 * Filters the GMT calendar date (Y-m-d) used to match `DATE(expiration_gmt)` for 30-day CRM reminders.
		 *
		 * @since 0.2.0
		 *
		 * @param string $target_date Default: today + 30 days UTC.
		 */
		$target_date = apply_filters(
			'ltkf_crm_expiration_target_date_gmt',
			$utc->modify( '+30 days' )->format( 'Y-m-d' )
		);
		$target_date = is_string( $target_date ) ? preg_replace( '/[^0-9\-]/', '', $target_date ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $target_date ) ) {
			return;
		}

		global $wpdb;

		$exclude = ltkf_medical_records_where_not_archived_for_prepare();

		$sql  = "SELECT * FROM `{$table}` WHERE `expiration_gmt` IS NOT NULL AND DATE( `expiration_gmt` ) = %s";
		$args = array( $target_date );

		if ( '' !== $exclude['sql'] ) {
			$sql .= $exclude['sql'];
			$args = array_merge( $args, (array) $exclude['value'] );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Built above with $wpdb->prepare.
		$prepared = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $sql ), $args ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching -- Nightly CRM batch.
		$rows = $wpdb->get_results( $prepared );

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return;
		}

		$by_pet = array();
		foreach ( $rows as $row ) {
			if ( ! is_object( $row ) || empty( $row->pet_post_id ) ) {
				continue;
			}
			$pid = absint( $row->pet_post_id );
			if ( $pid < 1 ) {
				continue;
			}
			if ( ! isset( $by_pet[ $pid ] ) ) {
				$by_pet[ $pid ] = array();
			}
			$by_pet[ $pid ][] = $row;
		}

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			foreach ( array_keys( $by_pet ) as $pet_id ) {
				as_enqueue_async_action(
					self::SINGLE_REMINDER_HOOK,
					array(
						absint( $pet_id ),
						$target_date,
					),
					self::AS_GROUP
				);
			}
			return;
		}

		foreach ( $by_pet as $pet_id => $pet_rows ) {
			self::maybe_send_pet_reminder( $pet_id, $pet_rows );
		}
	}

	/**
	 * Action Scheduler callback: load rows for one pet + target date and send mail (one failure does not block others).
	 *
	 * @param int    $pet_id      kf_pet post ID.
	 * @param string $target_date Y-m-d (GMT calendar date for DATE(expiration_gmt)).
	 * @return void
	 */
	public static function send_single_crm_reminder( $pet_id, $target_date ) {
		$pet_id      = absint( $pet_id );
		$target_date = is_string( $target_date ) ? preg_replace( '/[^0-9\-]/', '', $target_date ) : '';
		if ( $pet_id < 1 || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $target_date ) ) {
			return;
		}

		$table = ltkf_medical_records_table_name();
		if ( ! ltkf_table_exists( $table ) ) {
			return;
		}

		if ( class_exists( 'ComplianceRetention' ) ) {
			ComplianceRetention::maybe_upgrade_medical_records_schema();
		}

		if ( ! ltkf_db_column_exists( $table, 'expiration_gmt' ) ) {
			return;
		}

		global $wpdb;

		$exclude = ltkf_medical_records_where_not_archived_for_prepare();

		$sql  = "SELECT * FROM `{$table}` WHERE `expiration_gmt` IS NOT NULL AND DATE( `expiration_gmt` ) = %s AND `pet_post_id` = %d";
		$args = array( $target_date, $pet_id );

		if ( '' !== $exclude['sql'] ) {
			$sql .= $exclude['sql'];
			$args = array_merge( $args, (array) $exclude['value'] );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Built above with $wpdb->prepare.
		$prepared = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $sql ), $args ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching -- Async CRM row load.
		$rows = $wpdb->get_results( $prepared );

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return;
		}

		self::maybe_send_pet_reminder( $pet_id, $rows );
	}

	/**
	 * Send one HTML email per pet listing expiring items; audit each record when KennelFlow Vet is active.
	 *
	 * @param int      $pet_id Pet post ID (kf_pet).
	 * @param object[] $rows   Rows from kf_medical_records.
	 * @return void
	 */
	protected static function maybe_send_pet_reminder( $pet_id, array $rows ) {
		$pet_id = absint( $pet_id );
		if ( $pet_id < 1 || empty( $rows ) ) {
			return;
		}

		if ( ltkf_get_pet_post_type() !== get_post_type( $pet_id ) ) {
			return;
		}

		$owner_key = ltkf_get_pet_owner_user_meta_key();
		$owner_id  = absint( get_post_meta( $pet_id, $owner_key, true ) );
		if ( $owner_id < 1 ) {
			return;
		}

		$user = get_userdata( $owner_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return;
		}

		/**
		 * Return false to skip sending the CRM reminder for this pet.
		 *
		 * @since 0.2.0
		 *
		 * @param bool     $send    Default true.
		 * @param int      $pet_id  kf_pet ID.
		 * @param object[] $rows    Matching kf_medical_records rows.
		 * @param WP_User  $user    Owner.
		 */
		if ( false === apply_filters( 'ltkf_crm_send_30d_reminder', true, $pet_id, $rows, $user ) ) {
			return;
		}

		$pet_title = get_the_title( $pet_id );
		if ( '' === $pet_title ) {
			$pet_title = sprintf( /* translators: %d: pet post ID */ __( 'Pet #%d', 'kennelflow-core' ), $pet_id );
		}

		$subject = sprintf(
			/* translators: %s: pet name */
			__( 'Action Required: %s has expiring medical records', 'kennelflow-core' ),
			$pet_title
		);

		/**
		 * Filters the CRM 30-day reminder email subject.
		 *
		 * @since 0.2.0
		 *
		 * @param string   $subject Default subject.
		 * @param int      $pet_id  kf_pet ID.
		 * @param object[] $rows    Rows.
		 */
		$subject = apply_filters( 'ltkf_crm_30d_reminder_subject', $subject, $pet_id, $rows );

		$portal_url = ltkf_get_portal_dashboard_url();

		$lines = array();
		foreach ( $rows as $row ) {
			if ( ! is_object( $row ) ) {
				continue;
			}
			$name = isset( $row->analyte_name ) ? sanitize_text_field( (string) $row->analyte_name ) : '';
			if ( '' === $name ) {
				$name = __( '(Medical record)', 'kennelflow-core' );
			}
			$lines[] = $name;
		}

		$body = self::build_reminder_html( $pet_title, $lines, $portal_url );

		/**
		 * Filters the CRM 30-day reminder HTML body.
		 *
		 * @since 0.2.0
		 *
		 * @param string   $body       HTML.
		 * @param int      $pet_id     kf_pet ID.
		 * @param object[] $rows       Rows.
		 * @param string   $portal_url Dashboard URL.
		 */
		$body = apply_filters( 'ltkf_crm_30d_reminder_body', $body, $pet_id, $rows, $portal_url );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		$sent = wp_mail( $user->user_email, $subject, $body, $headers );

		if ( ! $sent ) {
			return;
		}

		foreach ( $rows as $row ) {
			if ( ! is_object( $row ) || empty( $row->id ) ) {
				continue;
			}
			$record_id = absint( $row->id );
			$label     = isset( $row->analyte_name ) ? sanitize_text_field( (string) $row->analyte_name ) : __( 'Medical record', 'kennelflow-core' );
			self::maybe_audit_kennelflow_vet( $pet_id, $record_id, $label );
		}
	}

	/**
	 * HTML email: list items + CTA to KennelFlow dashboard.
	 *
	 * @param string   $pet_name   Pet display name.
	 * @param string[] $item_names Analyte / vaccine labels.
	 * @param string   $portal_url Portal URL.
	 * @return string
	 */
	protected static function build_reminder_html( $pet_name, array $item_names, $portal_url ) {
		$intro = sprintf(
			/* translators: %s: pet name */
			esc_html__( 'The following medical record(s) for %s will expire in about 30 days. Please schedule an update appointment.', 'kennelflow-core' ),
			esc_html( $pet_name )
		);

		$list = '';
		foreach ( $item_names as $item ) {
			$list .= '<li style="margin:8px 0;">' . esc_html( $item ) . '</li>';
		}

		$cta_text = esc_html__( 'Open KennelFlow dashboard', 'kennelflow-core' );
		$button   = '<a href="' . esc_url( $portal_url ) . '" style="display:inline-block;padding:14px 28px;background:#2271b1;color:#ffffff;text-decoration:none;border-radius:4px;font-weight:600;">' . $cta_text . '</a>';

		return '<!DOCTYPE html><html><head><meta charset="UTF-8" /></head><body style="font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;line-height:1.5;color:#1d2327;">'
			. '<p>' . $intro . '</p>'
			. '<ul style="padding-left:20px;">' . $list . '</ul>'
			. '<p style="margin:24px 0;">' . $button . '</p>'
			. '<p style="font-size:13px;color:#646970;">' . esc_html__( 'Book or manage appointments from your portal under Vaccinations & records.', 'kennelflow-core' ) . '</p>'
			. '</body></html>';
	}

	/**
	 * Append KennelFlow Vet audit row when the plugin is active.
	 *
	 * @param int    $pet_id    kf_pet ID.
	 * @param int    $record_id kf_medical_records primary key.
	 * @param string $label     Record type label (e.g. analyte name).
	 * @return void
	 */
	protected static function maybe_audit_kennelflow_vet( $pet_id, $record_id, $label ) {
		if ( ! class_exists( 'KennelFlow_Vet_EMR_Audit' ) ) {
			return;
		}

		$message = sprintf(
			/* translators: %s: record type / vaccine name */
			__( 'System sent 30-day expiration reminder for %s', 'kennelflow-core' ),
			$label
		);

		KennelFlow_Vet_EMR_Audit::log(
			'kf_pet',
			$pet_id,
			'crm_medical_30d_reminder',
			null,
			array(
				'ltkf_medical_record_id' => $record_id,
				'message'              => $message,
			),
			0
		);
	}
}
