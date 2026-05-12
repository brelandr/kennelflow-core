<?php
/**
 * Global wrappers for namespaced KennelFlow Core API (spokes and function_exists checks).
 *
 * @package KennelFlow
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'ltkf_is_core_active' ) ) {
	function ltkf_is_core_active() {
		return \Landtech\KennelFlow\Core\ltkf_is_core_active();
	}
}

if ( ! function_exists( 'ltkf_get_pet_post_type' ) ) {
	function ltkf_get_pet_post_type() {
		return \Landtech\KennelFlow\Core\ltkf_get_pet_post_type();
	}
}

if ( ! function_exists( 'ltkf_get_location_post_type' ) ) {
	function ltkf_get_location_post_type() {
		return \Landtech\KennelFlow\Core\ltkf_get_location_post_type();
	}
}

if ( ! function_exists( 'ltkf_get_hub_menu_slug' ) ) {
	function ltkf_get_hub_menu_slug() {
		return \Landtech\KennelFlow\Core\ltkf_get_hub_menu_slug();
	}
}

if ( ! function_exists( 'ltkf_get_hub_page_hook_suffix' ) ) {
	function ltkf_get_hub_page_hook_suffix( $page_slug ) {
		return \Landtech\KennelFlow\Core\ltkf_get_hub_page_hook_suffix( $page_slug );
	}
}

if ( ! function_exists( 'ltkf_get_owner_pet_ids_meta_key' ) ) {
	function ltkf_get_owner_pet_ids_meta_key() {
		return \Landtech\KennelFlow\Core\ltkf_get_owner_pet_ids_meta_key();
	}
}

if ( ! function_exists( 'ltkf_get_pet_owner_user_meta_key' ) ) {
	function ltkf_get_pet_owner_user_meta_key() {
		return \Landtech\KennelFlow\Core\ltkf_get_pet_owner_user_meta_key();
	}
}

if ( ! function_exists( 'ltkf_get_pet_owner_user_id' ) ) {
	function ltkf_get_pet_owner_user_id( $post_id ) {
		return \Landtech\KennelFlow\Core\ltkf_get_pet_owner_user_id( $post_id );
	}
}

if ( ! function_exists( 'ltkf_get_user_phone_for_sms' ) ) {
	function ltkf_get_user_phone_for_sms( $user_id ) {
		return \Landtech\KennelFlow\Core\ltkf_get_user_phone_for_sms( $user_id );
	}
}

if ( ! function_exists( 'ltkf_get_pet_meta_key_allergies' ) ) {
	function ltkf_get_pet_meta_key_allergies() {
		return \Landtech\KennelFlow\Core\ltkf_get_pet_meta_key_allergies();
	}
}

if ( ! function_exists( 'ltkf_get_pet_meta_key_behavioral_tags' ) ) {
	function ltkf_get_pet_meta_key_behavioral_tags() {
		return \Landtech\KennelFlow\Core\ltkf_get_pet_meta_key_behavioral_tags();
	}
}

if ( ! function_exists( 'ltkf_get_pet_meta_key_default_diet' ) ) {
	function ltkf_get_pet_meta_key_default_diet() {
		return \Landtech\KennelFlow\Core\ltkf_get_pet_meta_key_default_diet();
	}
}

if ( ! function_exists( 'ltkf_get_pet_care_defaults_allergies' ) ) {
	function ltkf_get_pet_care_defaults_allergies( $pet_id ) {
		return \Landtech\KennelFlow\Core\ltkf_get_pet_care_defaults_allergies( $pet_id );
	}
}

if ( ! function_exists( 'ltkf_get_pet_care_defaults_behavioral_tags' ) ) {
	function ltkf_get_pet_care_defaults_behavioral_tags( $pet_id ) {
		return \Landtech\KennelFlow\Core\ltkf_get_pet_care_defaults_behavioral_tags( $pet_id );
	}
}

if ( ! function_exists( 'ltkf_get_pet_care_defaults_diet' ) ) {
	function ltkf_get_pet_care_defaults_diet( $pet_id ) {
		return \Landtech\KennelFlow\Core\ltkf_get_pet_care_defaults_diet( $pet_id );
	}
}

if ( ! function_exists( 'ltkf_pet_care_warning_should_show' ) ) {
	function ltkf_pet_care_warning_should_show( $pet_id ) {
		return \Landtech\KennelFlow\Core\ltkf_pet_care_warning_should_show( $pet_id );
	}
}

if ( ! function_exists( 'ltkf_table_exists' ) ) {
	function ltkf_table_exists( $table ) {
		return \Landtech\KennelFlow\Core\ltkf_table_exists( $table );
	}
}

if ( ! function_exists( 'ltkf_bookings_table_name' ) ) {
	function ltkf_bookings_table_name() {
		return \Landtech\KennelFlow\Core\ltkf_bookings_table_name();
	}
}

if ( ! function_exists( 'ltkf_get_hub_location_timezone_string' ) ) {
	function ltkf_get_hub_location_timezone_string( $location_post_id ) {
		return \Landtech\KennelFlow\Core\ltkf_get_hub_location_timezone_string( $location_post_id );
	}
}

if ( ! function_exists( 'ltkf_get_cached_portal_location_rows' ) ) {
	function ltkf_get_cached_portal_location_rows() {
		return \Landtech\KennelFlow\Core\ltkf_get_cached_portal_location_rows();
	}
}

if ( ! function_exists( 'ltkf_on_hub_location_post_change' ) ) {
	function ltkf_on_hub_location_post_change( $post_id, $post = null ) {
		return \Landtech\KennelFlow\Core\ltkf_on_hub_location_post_change( $post_id, $post );
	}
}

if ( ! function_exists( 'ltkf_get_hub_location_id_for_kennelflow_vet_location_term' ) ) {
	function ltkf_get_hub_location_id_for_kennelflow_vet_location_term( $term_id ) {
		return \Landtech\KennelFlow\Core\ltkf_get_hub_location_id_for_kennelflow_vet_location_term( $term_id );
	}
}

if ( ! function_exists( 'ltkf_clinician_has_global_booking_overlap' ) ) {
	function ltkf_clinician_has_global_booking_overlap( $user_id, $start_gmt, $end_gmt, $exclude_post_id = 0 ) {
		return \Landtech\KennelFlow\Core\ltkf_clinician_has_global_booking_overlap( $user_id, $start_gmt, $end_gmt, $exclude_post_id );
	}
}

if ( ! function_exists( 'ltkf_waitlist_table_name' ) ) {
	function ltkf_waitlist_table_name() {
		return \Landtech\KennelFlow\Core\ltkf_waitlist_table_name();
	}
}

if ( ! function_exists( 'ltkf_medical_records_table_name' ) ) {
	function ltkf_medical_records_table_name() {
		return \Landtech\KennelFlow\Core\ltkf_medical_records_table_name();
	}
}

if ( ! function_exists( 'ltkf_get_total_kennel_capacity' ) ) {
	function ltkf_get_total_kennel_capacity() {
		return \Landtech\KennelFlow\Core\ltkf_get_total_kennel_capacity();
	}
}

if ( ! function_exists( 'ltkf_get_current_occupancy_percentage' ) ) {
	function ltkf_get_current_occupancy_percentage() {
		return \Landtech\KennelFlow\Core\ltkf_get_current_occupancy_percentage();
	}
}

if ( ! function_exists( 'ltkf_get_deposit_percentage' ) ) {
	function ltkf_get_deposit_percentage() {
		return \Landtech\KennelFlow\Core\ltkf_get_deposit_percentage();
	}
}

if ( ! function_exists( 'ltkf_clear_kennelflow_cart_items' ) ) {
	function ltkf_clear_kennelflow_cart_items() {
		return \Landtech\KennelFlow\Core\ltkf_clear_kennelflow_cart_items();
	}
}

if ( ! function_exists( 'ltkf_add_booking_to_cart' ) ) {
	function ltkf_add_booking_to_cart( $booking_id ) {
		return \Landtech\KennelFlow\Core\ltkf_add_booking_to_cart( $booking_id );
	}
}

if ( ! function_exists( 'ltkf_add_balance_to_cart' ) ) {
	function ltkf_add_balance_to_cart( $parent_order_id, $amount, $booking_post_id ) {
		return \Landtech\KennelFlow\Core\ltkf_add_balance_to_cart( $parent_order_id, $amount, $booking_post_id );
	}
}

if ( ! function_exists( 'ltkf_db_column_exists' ) ) {
	function ltkf_db_column_exists( $table, $column ) {
		return \Landtech\KennelFlow\Core\ltkf_db_column_exists( $table, $column );
	}
}

if ( ! function_exists( 'ltkf_medical_records_where_not_archived_for_prepare' ) ) {
	function ltkf_medical_records_where_not_archived_for_prepare() {
		return \Landtech\KennelFlow\Core\ltkf_medical_records_where_not_archived_for_prepare();
	}
}

if ( ! function_exists( 'ltkf_get_portal_dashboard_url' ) ) {
	function ltkf_get_portal_dashboard_url() {
		return \Landtech\KennelFlow\Core\ltkf_get_portal_dashboard_url();
	}
}

if ( ! function_exists( 'ltkf_get_required_vaccines' ) ) {
	function ltkf_get_required_vaccines() {
		return \Landtech\KennelFlow\Core\ltkf_get_required_vaccines();
	}
}

if ( ! function_exists( 'ltkf_get_boarding_required_vaccines' ) ) {
	function ltkf_get_boarding_required_vaccines() {
		return \Landtech\KennelFlow\Core\ltkf_get_boarding_required_vaccines();
	}
}

if ( ! function_exists( 'ltkf_get_effective_boarding_required_vaccines' ) ) {
	function ltkf_get_effective_boarding_required_vaccines() {
		return \Landtech\KennelFlow\Core\ltkf_get_effective_boarding_required_vaccines();
	}
}

if ( ! function_exists( 'ltkf_get_pet_compliance_status' ) ) {
	function ltkf_get_pet_compliance_status( $pet_id ) {
		return \Landtech\KennelFlow\Core\ltkf_get_pet_compliance_status( $pet_id );
	}
}

if ( ! function_exists( 'ltkf_get_pet_pending_compliance_vaccine_norms' ) ) {
	function ltkf_get_pet_pending_compliance_vaccine_norms( $pet_id ) {
		return \Landtech\KennelFlow\Core\ltkf_get_pet_pending_compliance_vaccine_norms( $pet_id );
	}
}

if ( ! function_exists( 'ltkf_pet_has_pending_compliance_upload_for_required_vaccines' ) ) {
	function ltkf_pet_has_pending_compliance_upload_for_required_vaccines( $pet_id ) {
		return \Landtech\KennelFlow\Core\ltkf_pet_has_pending_compliance_upload_for_required_vaccines( $pet_id );
	}
}

if ( ! function_exists( 'ltkf_get_pet_compliance_vaccine_rows_for_labels' ) ) {
	function ltkf_get_pet_compliance_vaccine_rows_for_labels( $pet_id, array $required_labels ) {
		return \Landtech\KennelFlow\Core\ltkf_get_pet_compliance_vaccine_rows_for_labels( $pet_id, $required_labels );
	}
}

if ( ! function_exists( 'ltkf_get_portal_pet_compliance_vaccines' ) ) {
	function ltkf_get_portal_pet_compliance_vaccines( $pet_id ) {
		return \Landtech\KennelFlow\Core\ltkf_get_portal_pet_compliance_vaccines( $pet_id );
	}
}

if ( ! function_exists( 'ltkf_get_boarding_wizard_pet_compliance_vaccines' ) ) {
	function ltkf_get_boarding_wizard_pet_compliance_vaccines( $pet_id ) {
		return \Landtech\KennelFlow\Core\ltkf_get_boarding_wizard_pet_compliance_vaccines( $pet_id );
	}
}

if ( ! function_exists( 'ltkf_pet_requires_compliance_action' ) ) {
	function ltkf_pet_requires_compliance_action( $pet_id ) {
		return \Landtech\KennelFlow\Core\ltkf_pet_requires_compliance_action( $pet_id );
	}
}

if ( ! function_exists( 'ltkf_booking_row_is_boarding_stay_for_vaccine_compliance' ) ) {
	function ltkf_booking_row_is_boarding_stay_for_vaccine_compliance( $row ) {
		return \Landtech\KennelFlow\Core\ltkf_booking_row_is_boarding_stay_for_vaccine_compliance( $row );
	}
}

if ( ! function_exists( 'ltkf_compliance_gatekeeper_e2e_allow_noncompliant_checkout' ) ) {
	function ltkf_compliance_gatekeeper_e2e_allow_noncompliant_checkout() {
		return \Landtech\KennelFlow\Core\ltkf_compliance_gatekeeper_e2e_allow_noncompliant_checkout();
	}
}

if ( ! function_exists( 'ltkf_get_calendar_localized_settings' ) ) {
	function ltkf_get_calendar_localized_settings() {
		return \Landtech\KennelFlow\Core\ltkf_get_calendar_localized_settings();
	}
}

if ( ! function_exists( 'ltkf_register_toast_assets' ) ) {
	function ltkf_register_toast_assets() {
		return \Landtech\KennelFlow\Core\ltkf_register_toast_assets();
	}
}

if ( ! function_exists( 'ltkf_get_migration_owner_role_default' ) ) {
	function ltkf_get_migration_owner_role_default() {
		return \Landtech\KennelFlow\Core\ltkf_get_migration_owner_role_default();
	}
}

if ( ! function_exists( 'ltkf_migration_ensure_pet_owner_role' ) ) {
	function ltkf_migration_ensure_pet_owner_role( $user_id ) {
		return \Landtech\KennelFlow\Core\ltkf_migration_ensure_pet_owner_role( $user_id );
	}
}
