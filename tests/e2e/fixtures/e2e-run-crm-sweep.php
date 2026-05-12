<?php
/**
 * Run the daily CRM sweep (`ltkf_daily_crm_sweep`) then drain Action Scheduler pending jobs so reminders send in-process.
 *
 * Uses `pre_wp_mail` short-circuit so E2E does not require SMTP.
 *
 * @package KennelFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'pre_wp_mail',
	static function ( $pre, $atts ) {
		unset( $atts );
		return true;
	},
	999,
	2
);

do_action( 'ltkf_daily_crm_sweep' );

if ( class_exists( 'ActionScheduler_QueueRunner' ) ) {
	ActionScheduler_QueueRunner::instance()->run( 'E2E' );
}

echo "OK\n";
