<?php
/**
 * Waitlist table schema (dbDelta).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class WaitlistDb
 */
class WaitlistDb {

	const SCHEMA_VERSION = '1';

	const OPTION_SCHEMA_VERSION = 'ltkf_waitlist_schema_version';

	/**
	 * Create or upgrade table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = ltkf_waitlist_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			pet_id bigint(20) unsigned NOT NULL,
			location_id bigint(20) unsigned NOT NULL DEFAULT 0,
			start_gmt datetime NOT NULL,
			end_gmt datetime NOT NULL,
			requested_dates longtext NULL,
			status varchar(32) NOT NULL DEFAULT 'waiting',
			offer_token varchar(64) NOT NULL DEFAULT '',
			offer_expires_gmt datetime NULL,
			offered_booking_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_gmt datetime NOT NULL,
			updated_gmt datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY user_pet (user_id, pet_id),
			KEY status_created (status, created_gmt),
			KEY location_time (location_id, start_gmt, end_gmt),
			KEY offer_token (offer_token),
			KEY offer_expires (offer_expires_gmt)
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( self::OPTION_SCHEMA_VERSION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Run install when schema version changes.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$v = get_option( self::OPTION_SCHEMA_VERSION, '' );
		if ( self::SCHEMA_VERSION !== $v ) {
			self::install();
		}
	}
}
