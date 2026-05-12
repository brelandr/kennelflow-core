<?php
/**
 * Action Scheduler: tighter log retention and cleanup tuning (WooCommerce background jobs).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class ActionSchedulerOptimization
 */
class ActionSchedulerOptimization {

	/**
	 * Retention for completed/canceled actions (seconds). Default 30 days upstream; we use 7 days.
	 *
	 * @var int
	 */
	const RETENTION_SECONDS = WEEK_IN_SECONDS;

	/**
	 * Register filters after WooCommerce (and bundled Action Scheduler) load.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'action_scheduler_retention_period', array( __CLASS__, 'filter_retention_period' ), 10, 1 );

		/**
		 * Maximum past-due actions considered in a single admin “past due” check (forward-compatible).
		 *
		 * Upstream Action Scheduler may read this filter in future versions; registering it keeps a single
		 * knob for large webhook/SMS backlogs. KennelFlow also uses it for cleanup batch sizing below.
		 */
		add_filter( 'action_scheduler_pastdue_actions_check_pastdue_limit', array( __CLASS__, 'filter_pastdue_check_limit' ), 10, 1 );

		add_filter( 'action_scheduler_cleanup_batch_size', array( __CLASS__, 'filter_cleanup_batch_size' ), 10, 1 );
	}

	/**
	 * Shorter retention than Action Scheduler default (~30 days) to limit wp_actionscheduler_* table growth.
	 *
	 * @param int $period_seconds Minimum age in seconds before completed/canceled rows can be purged.
	 * @return int
	 */
	public static function filter_retention_period( $period_seconds ) {
		unset( $period_seconds );

		/**
		 * Filters Action Scheduler completed/canceled log retention (seconds).
		 *
		 * @since 0.2.0
		 *
		 * @param int $seconds Default {@see ActionSchedulerOptimization::RETENTION_SECONDS}.
		 */
		return (int) apply_filters( 'ltkf_action_scheduler_retention_period', self::RETENTION_SECONDS );
	}

	/**
	 * Cap for past-due action checks when upstream applies this filter (default 500).
	 *
	 * @param int $limit Default passed by Action Scheduler when the filter is used.
	 * @return int
	 */
	public static function filter_pastdue_check_limit( $limit ) {
		$default = 500;
		if ( is_numeric( $limit ) && (int) $limit > 0 ) {
			$default = (int) $limit;
		}

		/**
		 * Filters the past-due check ceiling (KennelFlow default 500).
		 *
		 * @since 0.2.0
		 *
		 * @param int $limit Max past-due actions to consider per check when supported.
		 */
		return (int) apply_filters( 'ltkf_action_scheduler_pastdue_check_limit', $default );
	}

	/**
	 * Larger cleanup batches than the Action Scheduler default (20) so purges keep up with busy sites.
	 *
	 * @param int $batch_size Default batch size from Action Scheduler.
	 * @return int
	 */
	public static function filter_cleanup_batch_size( $batch_size ) {
		$batch_size = absint( $batch_size );
		if ( $batch_size < 1 ) {
			$batch_size = 20;
		}

		$tuned = max( $batch_size, 50 );

		/**
		 * Filters Action Scheduler cleanup batch size (rows deleted per status per cleanup step).
		 *
		 * @since 0.2.0
		 *
		 * @param int $batch_size Tuned batch (at least 50).
		 */
		return (int) apply_filters( 'ltkf_action_scheduler_cleanup_batch_size', $tuned );
	}
}
