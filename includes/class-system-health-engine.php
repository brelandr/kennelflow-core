<?php
/**
 * System Health: lightweight diagnostics for KennelFlow Core and integrations.
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class SystemHealthEngine
 */
class SystemHealthEngine {

	/**
	 * Run all checks and return structured results.
	 *
	 * Each item: name (string), status (ok|warning|error), message (string), action_url (optional string).
	 *
	 * @return array<int, array{name:string, status:string, message:string, action_url?:string}>
	 */
	public static function get_diagnostics() {
		$results = array();

		$results[] = self::check_woocommerce();
		$results[] = self::check_database_tables();
		$results[] = self::check_crm_cron();
		$results[] = self::check_action_scheduler_log_retention();
		$results[] = self::check_stripe_pos();

		/**
		 * Filters diagnostic rows from {@see SystemHealthEngine::get_diagnostics()}.
		 *
		 * @since 0.2.0
		 *
		 * @param array<int, array{name:string, status:string, message:string, action_url?:string}> $results Diagnostic rows.
		 */
		return apply_filters( 'ltkf_system_health_diagnostics', $results );
	}

	/**
	 * WooCommerce active.
	 *
	 * @return array{name:string, status:string, message:string, action_url?:string}
	 */
	protected static function check_woocommerce() {
		if ( class_exists( 'WooCommerce' ) ) {
			return array(
				'name'    => __( 'WooCommerce', 'kennelflow-core' ),
				'status'  => 'ok',
				'message' => __( 'WooCommerce is active.', 'kennelflow-core' ),
			);
		}

		return array(
			'name'       => __( 'WooCommerce', 'kennelflow-core' ),
			'status'     => 'error',
			'message'    => __( 'WooCommerce is required for bookings and checkout.', 'kennelflow-core' ),
			'action_url' => admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' ),
		);
	}

	/**
	 * Core Hub tables: kf_bookings and kf_medical_records.
	 *
	 * @return array{name:string, status:string, message:string, action_url?:string}
	 */
	protected static function check_database_tables() {
		$bookings_ok = ltkf_table_exists( ltkf_bookings_table_name() );
		$medical_ok  = ltkf_table_exists( ltkf_medical_records_table_name() );

		if ( $bookings_ok && $medical_ok ) {
			return array(
				'name'    => __( 'Database tables', 'kennelflow-core' ),
				'status'  => 'ok',
				'message' => __( 'Custom KennelFlow tables are present.', 'kennelflow-core' ),
			);
		}

		$missing = array();
		if ( ! $bookings_ok ) {
			$missing[] = 'kf_bookings';
		}
		if ( ! $medical_ok ) {
			$missing[] = 'kf_medical_records';
		}

		$message = sprintf(
			/* translators: %s: comma-separated table names (e.g. kf_bookings, kf_medical_records). */
			__( 'Expected Hub tables are missing: %s. Run the database upgrade (KennelFlow Core creates these; KennelFlow Boarding and KennelFlow Vet may extend them).', 'kennelflow-core' ),
			implode( ', ', $missing )
		);

		return array(
			'name'       => __( 'Database tables', 'kennelflow-core' ),
			'status'     => 'error',
			'message'    => $message,
			'action_url' => add_query_arg(
				array(
					'page'   => 'kf-health',
					'action' => 'run_db_upgrade',
				),
				admin_url( 'admin.php' )
			),
		);
	}

	/**
	 * Daily CRM sweep scheduled via Action Scheduler (bundled with WooCommerce).
	 *
	 * @return array{name:string, status:string, message:string}
	 */
	protected static function check_crm_cron() {
		if ( ! class_exists( 'ActionScheduler' ) ) {
			return array(
				'name'    => __( 'Automated CRM cron', 'kennelflow-core' ),
				'status'  => 'warning',
				'message' => __( 'Action Scheduler is not loaded. WooCommerce bundles Action Scheduler; activate WooCommerce so daily CRM reminders can run in the background.', 'kennelflow-core' ),
			);
		}

		$hook  = 'ltkf_daily_crm_sweep';
		$group = 'kennelflow';

		if ( function_exists( 'as_next_scheduled_action' ) && false !== as_next_scheduled_action( $hook, array(), $group ) ) {
			return array(
				'name'    => __( 'Automated CRM cron', 'kennelflow-core' ),
				'status'  => 'ok',
				'message' => __( 'Daily CRM sweep is scheduled via Action Scheduler.', 'kennelflow-core' ),
			);
		}

		return array(
			'name'    => __( 'Automated CRM cron', 'kennelflow-core' ),
			'status'  => 'warning',
			'message' => __( 'Daily CRM sweep is not scheduled via Action Scheduler.', 'kennelflow-core' ),
		);
	}

	/**
	 * Action Scheduler log retention (KennelFlow caps completed/canceled log age at 7 days).
	 *
	 * @return array{name:string, status:string, message:string}
	 */
	protected static function check_action_scheduler_log_retention() {
		if ( ! class_exists( 'ActionScheduler' ) ) {
			return array(
				'name'    => __( 'Action Scheduler log retention', 'kennelflow-core' ),
				'status'  => 'warning',
				'message' => __( 'Action Scheduler is not loaded. When WooCommerce is active, KennelFlow keeps completed and canceled action logs for 7 days to limit database growth.', 'kennelflow-core' ),
			);
		}

		return array(
			'name'    => __( 'Action Scheduler log retention', 'kennelflow-core' ),
			'status'  => 'ok',
			'message' => __( 'Action Scheduler log retention: 7 days. Completed and canceled actions are purged automatically (self-cleaning).', 'kennelflow-core' ),
		);
	}

	/**
	 * Point of Sale Stripe secret key.
	 *
	 * @return array{name:string, status:string, message:string, action_url?:string}
	 */
	protected static function check_stripe_pos() {
		$key = get_option( 'ltkf_pos_stripe_secret_key', '' );
		$key = is_string( $key ) ? trim( $key ) : '';

		if ( '' !== $key ) {
			return array(
				'name'    => __( 'Stripe (Point of Sale)', 'kennelflow-core' ),
				'status'  => 'ok',
				'message' => __( 'Stripe API key is configured.', 'kennelflow-core' ),
			);
		}

		return array(
			'name'       => __( 'Stripe (Point of Sale)', 'kennelflow-core' ),
			'status'     => 'warning',
			'message'    => __( 'Point of Sale requires a Stripe API Key.', 'kennelflow-core' ),
			'action_url' => admin_url( 'admin.php?page=wc-settings' ),
		);
	}
}
