<?php
/**
 * Activation: rewrites, version option.
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class Activator
 */
class Activator {

	/**
	 * Copy options stored under legacy `kf_*` keys into `ltkf_*` (one-time per site).
	 *
	 * @return void
	 */
	public static function migrate_legacy_options() {
		if ( '1' === get_option( 'ltkf_legacy_opts_migrated', '' ) ) {
			return;
		}

		$map = array(
			'kf_core_db_version'                 => 'ltkf_core_db_version',
			'kf_total_kennel_capacity'           => 'ltkf_total_kennel_capacity',
			'kf_allow_owner_clinician_selection' => 'ltkf_allow_owner_clinician_selection',
			'kf_required_vaccines'               => 'ltkf_required_vaccines',
			'kf_boarding_required_vaccines'      => 'ltkf_boarding_required_vaccines',
			'kf_compliance_gatekeeper_e2e_allow_noncompliant_checkout' => 'ltkf_compliance_gatekeeper_e2e_allow_noncompliant_checkout',
			'kf_wc_product_ids'                  => 'ltkf_wc_product_ids',
			'kf_webhook_endpoints'               => 'ltkf_webhook_endpoints',
			'kf_deposit_percentage'              => 'ltkf_deposit_percentage',
			'kf_pos_stripe_secret_key'           => 'ltkf_pos_stripe_secret_key',
			'kf_demo_seed_state'                 => 'ltkf_demo_seed_state',
			'kf_surge_enabled'                   => 'ltkf_surge_enabled',
			'kf_surge_threshold'                 => 'ltkf_surge_threshold',
			'kf_surge_increase_percentage'       => 'ltkf_surge_increase_percentage',
			'kf_vip_discount_percentage'         => 'ltkf_vip_discount_percentage',
			'kf_compliance'                      => 'ltkf_compliance',
			'kf_twilio_account_sid'              => 'ltkf_twilio_account_sid',
			'kf_twilio_auth_token'               => 'ltkf_twilio_auth_token',
			'kf_twilio_from_number'              => 'ltkf_twilio_from_number',
			'kf_waitlist_schema_version'         => 'ltkf_waitlist_schema_version',
		);

		foreach ( $map as $legacy_key => $new_key ) {
			if ( ! array_key_exists( $legacy_key, wp_load_alloptions() ) ) {
				continue;
			}
			if ( array_key_exists( $new_key, wp_load_alloptions() ) ) {
				continue;
			}
			$val = get_option( $legacy_key, null );
			update_option( $new_key, $val, false );
			delete_option( $legacy_key );
		}

		update_option( 'ltkf_legacy_opts_migrated', '1', true );
	}

	/**
	 * Run on plugin activation.
	 *
	 * @return void
	 */
	public static function activate() {

		self::migrate_legacy_options();

		PostTypes::register();
		flush_rewrite_rules();

		if ( false === get_option( 'ltkf_core_db_version', false ) ) {
			add_option( 'ltkf_core_db_version', LTKF_CORE_VERSION );
		} else {
			update_option( 'ltkf_core_db_version', LTKF_CORE_VERSION );
		}

		if ( false === get_option( 'ltkf_total_kennel_capacity', false ) ) {
			add_option( 'ltkf_total_kennel_capacity', 20, '', false );
		}

		if ( false === get_option( 'ltkf_allow_owner_clinician_selection', false ) ) {
			add_option( 'ltkf_allow_owner_clinician_selection', '0', '', false );
		}

		require_once LTKF_PLUGIN_DIR . 'includes/class-core-db.php';
		CoreDb::install();

		if ( class_exists( 'WaitlistDb' ) ) {
			WaitlistDb::install();
		}

		/**
		 * Fires after KennelFlow Core activation tasks.
		 *
		 * @since 0.1.0
		 */
		do_action( 'ltkf_core_activated' );
	}
}
