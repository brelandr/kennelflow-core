<?php
/**
 * Deactivation: flush rewrite rules.
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class Deactivator
 */
class Deactivator {

	/**
	 * Run on plugin deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		flush_rewrite_rules();

		wp_clear_scheduled_hook( 'ltkf_hourly_cleanup' );
		wp_clear_scheduled_hook( 'ltkf_waitlist_process_expired' );
		wp_clear_scheduled_hook( 'ltkf_daily_crm_sweep' );
		wp_clear_scheduled_hook( 'kf_hourly_cleanup' );
		wp_clear_scheduled_hook( 'kf_waitlist_process_expired' );
		wp_clear_scheduled_hook( 'kf_daily_crm_sweep' );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'ltkf_daily_crm_sweep', array(), 'kennelflow' );
			as_unschedule_all_actions( 'ltkf_send_single_crm_reminder', array(), 'kennelflow' );
			as_unschedule_all_actions( 'kf_daily_crm_sweep', array(), 'kennelflow' );
			as_unschedule_all_actions( 'kf_send_single_crm_reminder', array(), 'kennelflow' );
		}

		/**
		 * Fires after KennelFlow Core deactivation tasks.
		 *
		 * @since 0.1.0
		 */
		do_action( 'ltkf_core_deactivated' );
	}
}
