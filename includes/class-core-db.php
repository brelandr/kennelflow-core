<?php
/**
 * Hub database tables referenced by KennelFlow Core (`kf_bookings`, `kf_medical_records`).
 *
 * KennelFlow Boarding / KennelFlow Vet may run their own dbDelta afterward; schemas must stay aligned
 * with {@see KennelFlow_Boarding_Install::install()} and {@see KennelFlow_Vet_Install::install()}.
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class CoreDb
 */
class CoreDb {

	/**
	 * Create or upgrade Hub tables via dbDelta (safe to run multiple times).
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$bookings_table = ltkf_bookings_table_name();

		// Keep in sync with KennelFlow_Boarding_Install bookings CREATE (KennelFlow Boarding).
		$sql_bookings = "CREATE TABLE {$bookings_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			pet_id bigint(20) unsigned NOT NULL DEFAULT 0,
			kennel_id bigint(20) unsigned NOT NULL DEFAULT 0,
			location_id bigint(20) unsigned NOT NULL DEFAULT 0,
			start_gmt datetime NOT NULL,
			end_gmt datetime NOT NULL,
			status varchar(32) NOT NULL DEFAULT 'pending',
			booking_kind varchar(32) NOT NULL DEFAULT '',
			created_gmt datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY post_id (post_id),
			KEY location_id (location_id),
			KEY start_gmt (start_gmt),
			KEY end_gmt (end_gmt),
			KEY kennel_time (kennel_id, start_gmt, end_gmt),
			KEY status_created (status, created_gmt)
		) {$charset_collate};";

		dbDelta( $sql_bookings );

		$mr_table = ltkf_medical_records_table_name();

		// Keep in sync with KennelFlow_Vet_Install::install() kf_medical_records CREATE (KennelFlow Vet).
		$sql_mr = "CREATE TABLE {$mr_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			pet_post_id bigint(20) unsigned NOT NULL,
			pid_primary_id varchar(191) NOT NULL DEFAULT '',
			hl7_message_type varchar(64) NOT NULL DEFAULT '',
			hl7_control_id varchar(191) NOT NULL DEFAULT '',
			obx_set_id varchar(16) NOT NULL DEFAULT '',
			obx_sequence smallint unsigned NOT NULL DEFAULT 0,
			analyte_code varchar(64) NULL,
			analyte_name varchar(191) NOT NULL DEFAULT '',
			value_text varchar(255) NULL,
			unit varchar(64) NULL,
			reference_text varchar(255) NULL,
			flag varchar(16) NULL,
			collected_gmt datetime NULL,
			reported_gmt datetime NULL,
			expiration_gmt datetime NULL,
			meta_json longtext NULL,
			created_gmt datetime NOT NULL,
			created_by bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (id),
			KEY pet_post (pet_post_id),
			KEY pid (pid_primary_id),
			KEY hl7_control (hl7_control_id),
			KEY created (created_gmt),
			KEY expiration_gmt (expiration_gmt)
		) {$charset_collate};";

		dbDelta( $sql_mr );

		if ( class_exists( 'ComplianceRetention' ) ) {
			ComplianceRetention::maybe_upgrade_medical_records_schema();
		}
	}
}
