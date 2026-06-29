<?php
/**
 * KennelFlow Core public helpers (`ltkf_` prefix).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'LTKF_OBJECT_CACHE_GROUP_AVAILABILITY' ) ) {
	define( 'LTKF_OBJECT_CACHE_GROUP_AVAILABILITY', 'kennelflow_availability' );
}
if ( ! defined( 'LTKF_OBJECT_CACHE_GROUP_PORTAL' ) ) {
	define( 'LTKF_OBJECT_CACHE_GROUP_PORTAL', 'kennelflow_portal' );
}

/**
 * Whether KennelFlow Core is loaded (Hub active).
 *
 * @return bool
 */
function ltkf_is_core_active() {
	return defined( 'LTKF_CORE_VERSION' );
}

/**
 * Pet post type slug (Hub).
 *
 * @return string
 */
function ltkf_get_pet_post_type() {
	return 'kf_pet';
}

/**
 * Location post type slug (Hub).
 *
 * @return string
 */
function ltkf_get_location_post_type() {
	return 'kf_location';
}

/**
 * Top-level KennelFlow Hub admin menu slug (`add_menu_page` / CPT `show_in_menu` parent).
 *
 * @return string
 */
function ltkf_get_hub_menu_slug() {
	return apply_filters( 'ltkf_hub_menu_slug', 'kennelflow-hub' );
}

/**
 * Admin `$hook_suffix` / screen id fragment for a Hub submenu page.
 *
 * @param string $page_slug Submenu slug passed to `add_submenu_page`.
 * @return string
 */
function ltkf_get_hub_page_hook_suffix( $page_slug ) {
	return ltkf_get_hub_menu_slug() . '_page_' . $page_slug;
}

/**
 * User meta key: list of pet post IDs owned by this user.
 *
 * @return string
 */
function ltkf_get_owner_pet_ids_meta_key() {
	return 'kf_owner_pet_ids';
}

/**
 * Post meta key on kf_pet: owner WordPress user ID.
 *
 * @return string
 */
function ltkf_get_pet_owner_user_meta_key() {
	return 'kf_owner_user_id';
}

/**
 * KennelFlow Vet documents this `apply_filters` / `add_filter` tag name for medical upload subdirectory;
 * Core invokes it for interoperability (tag is not `ltkf_`-prefixed by historical API contract).
 *
 * @return string
 */
function ltkf_legacy_vet_medical_upload_subdir_hook() {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- KennelFlow Vet public filter tag; Core exposes via this prefixed accessor only.
	return 'kennelflow_vet_medical_upload_subdir';
}

/**
 * Owner WordPress user ID for a pet (canonical Hub meta, with legacy KennelFlow Vet fallback).
 *
 * @param int $post_id Pet post ID.
 * @return int
 */
function ltkf_get_pet_owner_user_id( $post_id ) {
	$post_id = absint( $post_id );
	if ( $post_id < 1 ) {
		return 0;
	}

	$key = ltkf_get_pet_owner_user_meta_key();
	$uid = absint( get_post_meta( $post_id, $key, true ) );
	if ( $uid > 0 ) {
		return $uid;
	}

	// Legacy KennelFlow Vet key (pre-Hub canonical field).
	return absint( get_post_meta( $post_id, '_kennelflow_vet_owner_user_id', true ) );
}

/**
 * Pet owner display name for admin dropdowns and calendar UI.
 *
 * @param int $pet_id Pet post ID.
 * @return string Empty when no owner is assigned.
 */
function ltkf_get_pet_owner_display_name( $pet_id ) {
	$owner_id = ltkf_get_pet_owner_user_id( $pet_id );
	if ( $owner_id < 1 ) {
		return '';
	}

	$user = get_userdata( $owner_id );
	if ( ! $user instanceof \WP_User ) {
		return '';
	}

	$name = trim( (string) $user->display_name );
	if ( '' !== $name ) {
		return $name;
	}

	return trim( (string) $user->user_login );
}

/**
 * Pet option label for staff selects: "Pet name · Owner name".
 *
 * @param int    $pet_id    Pet post ID.
 * @param string $pet_title Optional title (avoids extra lookup when already loaded).
 * @return string
 */
function ltkf_get_pet_select_label( $pet_id, $pet_title = '' ) {
	$pet_id = absint( $pet_id );
	if ( $pet_id < 1 ) {
		return '';
	}

	$title = trim( (string) $pet_title );
	if ( '' === $title ) {
		$title = trim( (string) get_the_title( $pet_id ) );
	}
	if ( '' === $title ) {
		$title = '#' . $pet_id;
	}

	$owner = ltkf_get_pet_owner_display_name( $pet_id );
	if ( '' === $owner ) {
		return $title;
	}

	return sprintf(
		/* translators: 1: pet name, 2: owner display name */
		__( '%1$s · %2$s', 'kennelflow-core' ),
		$title,
		$owner
	);
}

/**
 * Best-effort phone for SMS (WooCommerce billing, common user meta, Hub owner field).
 *
 * @param int $user_id WordPress user ID.
 * @return string Digits or empty.
 */
function ltkf_get_user_phone_for_sms( $user_id ) {
	$user_id = absint( $user_id );
	if ( $user_id < 1 ) {
		return '';
	}

	$phone = (string) get_user_meta( $user_id, 'billing_phone', true );
	if ( '' === trim( $phone ) ) {
		$phone = (string) get_user_meta( $user_id, 'phone', true );
	}
	if ( '' === trim( $phone ) ) {
		$phone = (string) get_user_meta( $user_id, 'kf_owner_phone', true );
	}

	$phone = trim( $phone );

	/**
	 * Filters the phone number used for KennelFlow SMS (Twilio).
	 *
	 * @since 0.2.0
	 *
	 * @param string $phone   Phone string.
	 * @param int    $user_id User ID.
	 */
	return (string) apply_filters( 'ltkf_user_phone_for_sms', $phone, $user_id );
}

/**
 * Post meta: pet allergies (boarding care defaults).
 *
 * @return string
 */
function ltkf_get_pet_meta_key_allergies() {
	return 'kf_allergies';
}

/**
 * Post meta: behavioral tag slugs (boarding care defaults).
 *
 * @return string
 */
function ltkf_get_pet_meta_key_behavioral_tags() {
	return 'kf_behavioral_tags';
}

/**
 * Post meta: default diet / feeding notes (boarding care defaults).
 *
 * @return string
 */
function ltkf_get_pet_meta_key_default_diet() {
	return 'kf_default_diet';
}

/**
 * Allergies string for a pet (boarding care defaults).
 *
 * @param int $pet_id kf_pet post ID.
 * @return string
 */
function ltkf_get_pet_care_defaults_allergies( $pet_id ) {
	$pet_id = absint( $pet_id );
	if ( $pet_id < 1 ) {
		return '';
	}
	$v = get_post_meta( $pet_id, ltkf_get_pet_meta_key_allergies(), true );
	return is_string( $v ) ? $v : '';
}

/**
 * Behavioral tag slugs for a pet (boarding care defaults).
 *
 * @param int $pet_id kf_pet post ID.
 * @return string[]
 */
function ltkf_get_pet_care_defaults_behavioral_tags( $pet_id ) {
	$pet_id = absint( $pet_id );
	if ( $pet_id < 1 ) {
		return array();
	}
	$raw = get_post_meta( $pet_id, ltkf_get_pet_meta_key_behavioral_tags(), true );
	if ( ! is_array( $raw ) ) {
		return array();
	}
	return array_values( array_filter( array_map( 'sanitize_key', $raw ) ) );
}

/**
 * Default diet / feeding notes for a pet (boarding care defaults).
 *
 * @param int $pet_id kf_pet post ID.
 * @return string
 */
function ltkf_get_pet_care_defaults_diet( $pet_id ) {
	$pet_id = absint( $pet_id );
	if ( $pet_id < 1 ) {
		return '';
	}
	$v = get_post_meta( $pet_id, ltkf_get_pet_meta_key_default_diet(), true );
	return is_string( $v ) ? $v : '';
}

/**
 * Whether the admin should show the high-visibility care warning for this pet.
 *
 * @param int $pet_id kf_pet post ID.
 * @return bool
 */
function ltkf_pet_care_warning_should_show( $pet_id ) {
	$pet_id = absint( $pet_id );
	if ( $pet_id < 1 ) {
		return false;
	}
	$show = false;
	if ( '' !== trim( ltkf_get_pet_care_defaults_allergies( $pet_id ) ) ) {
		$show = true;
	}
	if ( ! $show ) {
		$tags = ltkf_get_pet_care_defaults_behavioral_tags( $pet_id );
		$show = in_array( 'dog_aggressive', $tags, true );
	}

	/**
	 * Whether to show the bright care warning on the kf_pet edit screen.
	 *
	 * @since 0.2.0
	 *
	 * @param bool $show   Whether to show.
	 * @param int  $pet_id Pet post ID.
	 */
	return (bool) apply_filters( 'ltkf_pet_care_warning_should_show', $show, $pet_id );
}

/**
 * Whether a database table exists (for optional add-on tables).
 *
 * @param string $table Full table name including prefix.
 * @return bool
 */
function ltkf_table_exists( $table ) {
	global $wpdb;
	$table = sanitize_key( str_replace( '`', '', (string) $table ) );
	if ( '' === $table ) {
		return false;
	}

	/*
	 * Do not use wp_table_exists(): it compares SHOW TABLES results with strict ===,
	 * which fails when MySQL returns a different identifier case than sanitize_key().
	 * INFORMATION_SCHEMA + LOWER() is reliable across lower_case_table_names settings.
	 */
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema probe; sanitized table name.
	$n     = $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND LOWER(table_name) = LOWER(%s)',
			$table
		)
	);
	$found = null;
	if ( null === $n || (int) $n < 1 ) {
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	if ( null !== $n && (int) $n > 0 ) {
		return true;
	}

	return null !== $found && '' !== $found && 0 === strcasecmp( (string) $found, $table );
}

/**
 * Bookings index table (KennelPress / hub): {$wpdb->prefix}kf_bookings.
 *
 * @return string
 */
function ltkf_bookings_table_name() {
	global $wpdb;
	return $wpdb->prefix . 'kf_bookings';
}

/**
 * IANA timezone for a Hub `kf_location` (KennelFlow Boarding facility settings when available).
 *
 * Cached 24 hours per location; invalidated when the location post is saved or deleted.
 *
 * @param int $location_post_id Hub location post ID.
 * @return string
 */
function ltkf_get_hub_location_timezone_string( $location_post_id ) {
	$location_post_id = absint( $location_post_id );
	if ( $location_post_id < 1 ) {
		$s = wp_timezone_string();
		return is_string( $s ) && '' !== $s ? $s : 'UTC';
	}

	$cache_key = 'kennelflow_core_hub_timezone_' . $location_post_id;
	$cached    = get_transient( $cache_key );
	if ( is_string( $cached ) && '' !== $cached ) {
		return $cached;
	}

	$tz = null;
	if ( class_exists( '\KennelFlow_Boarding_Facility_Settings' ) ) {
		$cfg = \KennelFlow_Boarding_Facility_Settings::get_for_location( $location_post_id );
		if ( is_array( $cfg ) && ! empty( $cfg['timezone'] ) && is_string( $cfg['timezone'] ) ) {
			$tz = $cfg['timezone'];
		}
	}
	if ( null === $tz || '' === (string) $tz ) {
		$s  = wp_timezone_string();
		$tz = ( is_string( $s ) && '' !== $s ) ? $s : 'UTC';
	}

	set_transient( $cache_key, (string) $tz, DAY_IN_SECONDS );
	return (string) $tz;
}

/**
 * Published `kf_location` rows for the portal waitlist (title + id), cached to avoid repeat queries.
 *
 * Invalidated with hub timezone when a location is saved or deleted; TTL 12 hours.
 *
 * @return array<int, array{id:int, title:string}>
 */
function ltkf_get_cached_portal_location_rows() {
	$key    = 'kennelflow_core_portal_locations_v1';
	$cached = get_transient( $key );
	if ( false !== $cached && is_array( $cached ) ) {
		return $cached;
	}

	$pt = ltkf_get_location_post_type();
	// phpcs:disable WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Capped list for a single form.
	$posts = get_posts(
		array(
			'post_type'              => $pt,
			'post_status'            => 'publish',
			'posts_per_page'         => 100,
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
		)
	);
	// phpcs:enable WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
	$out = array();
	foreach ( $posts as $p ) {
		if ( $p instanceof \WP_Post ) {
			$out[] = array(
				'id'    => (int) $p->ID,
				'title' => get_the_title( $p->ID ),
			);
		}
	}

	set_transient( $key, $out, 12 * HOUR_IN_SECONDS );

	return $out;
}

/**
 * Clears hub timezone + portal location list caches when a Hub location changes.
 *
 * @param int          $post_id Post ID.
 * @param WP_Post|null $post    Post object (may be null in some contexts).
 * @return void
 */
function ltkf_on_hub_location_post_change( $post_id, $post = null ) {
	$pt = function_exists( 'ltkf_get_location_post_type' ) ? ltkf_get_location_post_type() : 'kf_location';

	if ( $post instanceof \WP_Post ) {
		if ( $pt !== $post->post_type ) {
			return;
		}
		$pid = (int) $post->ID;
		if ( 'save_post' === current_filter() && in_array( $post->post_status, array( 'auto-draft', 'inherit' ), true ) ) {
			return;
		}
	} else {
		$pid = absint( $post_id );
		if ( $pid < 1 || get_post_type( $pid ) !== $pt ) {
			return;
		}
	}

	delete_transient( 'kennelflow_core_hub_timezone_' . $pid );
	delete_transient( 'kennelflow_core_portal_locations_v1' );
}

add_action( 'save_post', 'ltkf_on_hub_location_post_change', 20, 2 );
add_action( 'before_delete_post', 'ltkf_on_hub_location_post_change', 20, 2 );

/**
 * Map a KennelFlow Vet `kennelflow_vet_location` term to a Hub `kf_location` post ID (roster / facility timezone).
 *
 * Uses term meta `_kf_hub_location_id` when set; otherwise the `ltkf_hub_location_id_for_kennelflow_vet_location_term` filter.
 *
 * @param int $term_id kennelflow_vet_location term ID.
 * @return int Hub location post ID or 0 if unknown.
 */
function ltkf_get_hub_location_id_for_kennelflow_vet_location_term( $term_id ) {
	$term_id = absint( $term_id );
	if ( $term_id < 1 ) {
		return 0;
	}

	$direct = absint( get_term_meta( $term_id, '_kf_hub_location_id', true ) );
	if ( $direct > 0 ) {
		return $direct;
	}

	/**
	 * Filters Hub `kf_location` post ID for a KennelFlow Vet location term (when term meta is unset).
	 *
	 * @since 0.2.0
	 *
	 * @param int $location_id Default 0.
	 * @param int $term_id     kennelflow_vet_location term ID.
	 */
	return (int) apply_filters( 'ltkf_hub_location_id_for_kennelflow_vet_location_term', 0, $term_id );
}

/**
 * Whether a clinician (WordPress user ID stored in kf_bookings.kennel_id) has any blocking row overlapping the interval.
 *
 * Ignores booking_kind and location — global provider conflict (Check 3).
 *
 * @param int    $user_id         User ID.
 * @param string $start_gmt       Start Y-m-d H:i:s UTC.
 * @param string $end_gmt         End Y-m-d H:i:s UTC (exclusive half-open pair with start).
 * @param int    $exclude_post_id Optional booking post ID to exclude (editing same row).
 * @return bool
 */
function ltkf_clinician_has_global_booking_overlap( $user_id, $start_gmt, $end_gmt, $exclude_post_id = 0 ) {
	global $wpdb;

	$user_id = absint( $user_id );
	if ( $user_id < 1 ) {
		return false;
	}

	$table = ltkf_bookings_table_name();
	if ( ! is_string( $table ) || ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) || ! ltkf_table_exists( $table ) ) {
		return false;
	}

	$start_gmt = trim( (string) $start_gmt );
	$end_gmt   = trim( (string) $end_gmt );
	if ( '' === $start_gmt || '' === $end_gmt ) {
		return false;
	}

	$exclude_post_id = absint( $exclude_post_id );

	/**
	 * Statuses that block clinician time in the hub booking index.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $statuses Default aligns with KennelPress availability blocking.
	 */
	$statuses = apply_filters(
		'ltkf_clinician_overlap_blocking_statuses',
		array( 'pending', 'confirmed', 'checked_in' )
	);
	$statuses = array_filter( array_map( 'sanitize_key', (array) $statuses ) );
	if ( empty( $statuses ) ) {
		return false;
	}

	$status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

	if ( $exclude_post_id > 0 ) {
		$prepare_values = array_merge(
			array( $table, $user_id, $exclude_post_id ),
			$statuses,
			array( $end_gmt, $start_gmt )
		);
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- `%i` bookings table validated; sanitised `%s` IN list matches `$statuses`.
		$hit = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM %i WHERE kennel_id = %d AND post_id <> %d AND status IN ({$status_placeholders}) AND start_gmt < %s AND end_gmt > %s LIMIT 1",
				...$prepare_values
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	} else {
		$prepare_values = array_merge(
			array( $table, $user_id ),
			$statuses,
			array( $end_gmt, $start_gmt )
		);
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$hit = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM %i WHERE kennel_id = %d AND status IN ({$status_placeholders}) AND start_gmt < %s AND end_gmt > %s LIMIT 1",
				...$prepare_values
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	return (bool) $hit;
}

/**
 * Waitlist table: {$wpdb->prefix}kf_waitlist.
 *
 * @return string
 */
function ltkf_waitlist_table_name() {
	global $wpdb;
	return $wpdb->prefix . 'kf_waitlist';
}

/**
 * Medical / lab index table (KennelFlow Vet / hub): {$wpdb->prefix}kf_medical_records.
 *
 * @return string
 */
function ltkf_medical_records_table_name() {
	global $wpdb;
	return $wpdb->prefix . 'kf_medical_records';
}

/**
 * Total kennel capacity (option `kf_total_kennel_capacity`, filterable).
 *
 * @return int Positive integer; defaults when unset.
 */
function ltkf_get_total_kennel_capacity() {
	$raw = get_option( 'ltkf_total_kennel_capacity', 20 );
	$cap = absint( $raw );
	if ( $cap < 1 ) {
		$cap = 20;
	}

	/**
	 * Filters total kennel capacity used for occupancy / surge pricing.
	 *
	 * @since 0.2.0
	 *
	 * @param int $capacity Slot count.
	 */
	return (int) apply_filters( 'ltkf_total_kennel_capacity', $cap );
}

/**
 * Current occupancy as a percentage of {@see ltkf_get_total_kennel_capacity()}
 * from confirmed boarding stays overlapping “today” (GMT calendar day).
 *
 * @return float 0–100.
 */
function ltkf_get_current_occupancy_percentage() {
	$table = ltkf_bookings_table_name();
	if ( ! is_string( $table ) || ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) || ! ltkf_table_exists( $table ) ) {
		return 0.0;
	}

	$capacity = ltkf_get_total_kennel_capacity();
	if ( $capacity < 1 ) {
		return 0.0;
	}

	$cache_key = 'ltkf_occ_pct_' . gmdate( 'Y-m-d' );
	$cached    = wp_cache_get( $cache_key, LTKF_OBJECT_CACHE_GROUP_AVAILABILITY );
	if ( false !== $cached && is_numeric( $cached ) ) {
		$pct = (float) $cached;
		return min( 100.0, max( 0.0, $pct ) );
	}

	global $wpdb;

	$window_start = gmdate( 'Y-m-d 00:00:00' );
	try {
		$start_d = new \DateTimeImmutable( $window_start, new \DateTimeZone( 'UTC' ) );
	} catch ( \Exception $e ) {
		unset( $e );
		return 0.0;
	}
	$window_end = $start_d->modify( '+1 day' )->format( 'Y-m-d H:i:s' );

	$kinds = apply_filters( 'ltkf_surge_occupancy_booking_kinds', array( 'boarding', '' ) );
	$kinds = array_map(
		static function ( $k ) {
			return sanitize_key( (string) $k );
		},
		(array) $kinds
	);
	$kinds = array_values( array_unique( $kinds, SORT_STRING ) );
	if ( empty( $kinds ) ) {
		$kinds = array( 'boarding' );
	}

	$placeholders = implode( ',', array_fill( 0, count( $kinds ), '%s' ) );

	$prepare_values = array_merge(
		array( $table, 'confirmed', $window_end, $window_start ),
		$kinds
	);

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- `%i` validated; booking_kind IN list matches `$kinds`.
	$count = (int) $wpdb->get_var(
		$wpdb->prepare(
			'
			SELECT COUNT(*) FROM %i AS b
			WHERE b.status = %s
			AND b.start_gmt < %s
			AND b.end_gmt > %s
			AND b.booking_kind IN ( ' . $placeholders . ' )
			',
			...$prepare_values
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	if ( $count < 0 ) {
		$count = 0;
	}

	$pct = ( $count / $capacity ) * 100.0;
	$pct = min( 100.0, max( 0.0, $pct ) );

	/**
	 * Filters computed occupancy percentage (before caching).
	 *
	 * @since 0.2.0
	 *
	 * @param float  $percentage Occupancy 0–100.
	 * @param int    $count      Confirmed boarding rows overlapping today (GMT).
	 * @param int    $capacity   Total kennel capacity.
	 */
	$pct = (float) apply_filters( 'ltkf_occupancy_percentage', $pct, $count, $capacity );

	$pct = min( 100.0, max( 0.0, $pct ) );

	wp_cache_set( $cache_key, $pct, LTKF_OBJECT_CACHE_GROUP_AVAILABILITY, 2 * MINUTE_IN_SECONDS );

	return $pct;
}

/**
 * Deposit percentage for boarding checkout (0 = full pay, 100 = full pay, 1–99 = split).
 *
 * @return int 0–100.
 */
function ltkf_get_deposit_percentage() {
	$raw = get_option( 'ltkf_deposit_percentage', 20 );
	$n   = absint( $raw );
	if ( $n > 100 ) {
		$n = 100;
	}

	/**
	 * Filters deposit percentage for boarding WooCommerce checkout.
	 *
	 * @since 0.2.0
	 *
	 * @param int $percentage 0–100.
	 */
	return (int) apply_filters( 'ltkf_deposit_percentage', $n );
}

/**
 * Clear KennelFlow cart lines (service products, booking lines, balance lines).
 *
 * @return void
 */
function ltkf_clear_kennelflow_cart_items() {
	if ( class_exists( 'Woocommerce' ) ) {
		Woocommerce::clear_kennelflow_cart_items();
	}
}

/**
 * Add a booking’s service product to the cart.
 *
 * @param int $booking_id Booking post ID (kf_bookings.post_id) or row id.
 * @return string|false|\WP_Error Cart item key on success.
 */
function ltkf_add_booking_to_cart( $booking_id ) {
	if ( ! class_exists( 'Woocommerce' ) ) {
		return new \WP_Error( 'ltkf_wc_missing', __( 'WooCommerce integration is not available.', 'kennelflow-core' ) );
	}
	return Woocommerce::add_booking_to_cart( $booking_id );
}

/**
 * Add a balance payment line for a prior deposit order.
 *
 * @param int   $parent_order_id WooCommerce order ID that holds _kf_unpaid_balance.
 * @param float $amount          Amount to collect.
 * @param int   $booking_post_id Booking post ID (for order item meta).
 * @return string|false|\WP_Error Cart item key on success.
 */
function ltkf_add_balance_to_cart( $parent_order_id, $amount, $booking_post_id ) {
	if ( ! class_exists( 'Woocommerce' ) ) {
		return new \WP_Error( 'ltkf_wc_missing', __( 'WooCommerce integration is not available.', 'kennelflow-core' ) );
	}
	return Woocommerce::add_balance_to_cart( $parent_order_id, $amount, $booking_post_id );
}

/**
 * Whether a column exists on a MySQL table (INFORMATION_SCHEMA).
 *
 * @param string $table  Full table name including prefix.
 * @param string $column Column name.
 * @return bool
 */
function ltkf_db_column_exists( $table, $column ) {
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
 * SQL fragment + placeholder value to exclude archived kf_medical_records rows (Data Vault).
 *
 * @return array{sql:string,value:string|string[]} Empty sql when the table or status column is missing.
 */
function ltkf_medical_records_where_not_archived_for_prepare() {
	$table = ltkf_medical_records_table_name();
	if ( ! ltkf_table_exists( $table ) || ! ltkf_db_column_exists( $table, 'status' ) ) {
		return array(
			'sql'   => '',
			'value' => '',
		);
	}

	$archived = 'archived';
	if ( class_exists( ComplianceRetention::class ) ) {
		$archived = ComplianceRetention::RECORD_STATUS_ARCHIVED;
	}

	$pending = 'pending_review';
	if ( class_exists( ComplianceRetention::class ) ) {
		$pending = ComplianceRetention::RECORD_STATUS_PENDING_REVIEW;
	}

	return array(
		'sql'   => ' AND ( `status` IS NULL OR ( `status` <> %s AND `status` <> %s ) ) ',
		'value' => array( $archived, $pending ),
	);
}

/**
 * Public URL for the owner portal page containing [ltkf_dashboard] (or legacy [kennelflow_dashboard]).
 *
 * @return string Escaped absolute URL (fallback: home_url).
 */
function ltkf_get_portal_dashboard_url() {
	$filtered = apply_filters( 'ltkf_portal_dashboard_url', '' );
	if ( is_string( $filtered ) && '' !== $filtered ) {
		return esc_url_raw( $filtered );
	}

	$cached = wp_cache_get( 'ltkf_portal_dashboard_url_resolved', LTKF_OBJECT_CACHE_GROUP_PORTAL );
	if ( is_string( $cached ) && '' !== $cached ) {
		return esc_url_raw( $cached );
	}

	global $wpdb;

	$primary = '%' . $wpdb->esc_like( '[ltkf_dashboard]' ) . '%';
	$legacy  = '%' . $wpdb->esc_like( '[kennelflow_dashboard]' ) . '%';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Single lookup; table names from $wpdb.
	$post_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND ( post_content LIKE %s OR post_content LIKE %s ) ORDER BY ID ASC LIMIT 1", $primary, $legacy ) );

	if ( $post_id > 0 ) {
		$permalink = get_permalink( $post_id );
		if ( is_string( $permalink ) && '' !== $permalink ) {
			wp_cache_set( 'ltkf_portal_dashboard_url_resolved', $permalink, LTKF_OBJECT_CACHE_GROUP_PORTAL, 12 * HOUR_IN_SECONDS );
			return esc_url_raw( $permalink );
		}
	}

	$fallback = home_url( '/' );
	wp_cache_set( 'ltkf_portal_dashboard_url_resolved', $fallback, LTKF_OBJECT_CACHE_GROUP_PORTAL, 12 * HOUR_IN_SECONDS );

	return esc_url_raw( $fallback );
}

/**
 * Public URL for the booking wizard page ([kennelflow_vet_booking], [ltkf_booking], or legacy tags).
 *
 * @return string Escaped absolute URL (fallback: home_url).
 */
function ltkf_get_public_booking_page_url() {
	$filtered = apply_filters( 'ltkf_public_booking_page_url', '' );
	if ( is_string( $filtered ) && '' !== $filtered ) {
		return esc_url_raw( $filtered );
	}

	$cached = wp_cache_get( 'ltkf_public_booking_page_url_resolved', LTKF_OBJECT_CACHE_GROUP_PORTAL );
	if ( is_string( $cached ) && '' !== $cached ) {
		return esc_url_raw( $cached );
	}

	global $wpdb;

	$patterns = array(
		'%' . $wpdb->esc_like( '[kennelflow_vet_booking]' ) . '%',
		'%' . $wpdb->esc_like( '[kfvet_booking]' ) . '%',
		'%' . $wpdb->esc_like( '[ltkf_booking]' ) . '%',
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Single lookup; table names from $wpdb.
	$post_id = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND ( post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s ) ORDER BY ID ASC LIMIT 1",
			$patterns[0],
			$patterns[1],
			$patterns[2]
		)
	);

	if ( $post_id > 0 ) {
		$permalink = get_permalink( $post_id );
		if ( is_string( $permalink ) && '' !== $permalink ) {
			wp_cache_set( 'ltkf_public_booking_page_url_resolved', $permalink, LTKF_OBJECT_CACHE_GROUP_PORTAL, 12 * HOUR_IN_SECONDS );
			return esc_url_raw( $permalink );
		}
	}

	$fallback = home_url( '/' );
	wp_cache_set( 'ltkf_public_booking_page_url_resolved', $fallback, LTKF_OBJECT_CACHE_GROUP_PORTAL, 12 * HOUR_IN_SECONDS );

	return esc_url_raw( $fallback );
}

/**
 * Required vaccine names configured under KennelFlow → Compliance Rules.
 *
 * @return string[]
 */
function ltkf_get_required_vaccines() {
	$v = get_option( 'ltkf_required_vaccines', array() );
	return is_array( $v ) ? $v : array();
}

/**
 * Boarding-only required vaccine list (KennelFlow Hub → Compliance Rules).
 *
 * @return string[]
 */
function ltkf_get_boarding_required_vaccines() {
	$v = get_option( 'ltkf_boarding_required_vaccines', array() );
	return is_array( $v ) ? $v : array();
}

/**
 * Vaccines required for boarding booking wizard uploads and display.
 *
 * Uses the boarding-specific list when configured; otherwise the general facility list.
 *
 * @return string[]
 */
function ltkf_get_effective_boarding_required_vaccines() {
	$boarding = ltkf_get_boarding_required_vaccines();
	if ( ! empty( $boarding ) ) {
		return $boarding;
	}
	return ltkf_get_required_vaccines();
}

/**
 * Compliance status for a pet: each required vaccine is Valid, Expired, or Missing vs kf_medical_records.
 *
 * Uses the most recent row per analyte_name (by created_gmt) and compares expiration_gmt to current UTC.
 *
 * @param int $pet_id kf_pet post ID.
 * @return array<string, mixed>|WP_Error {
 *     @type int                        $pet_id
 *     @type string                     $checked_at_gmt Y-m-d H:i:s UTC
 *     @type array<string, array<string, mixed>> $vaccines Label => status, expiration_gmt, record_id
 * }
 */
function ltkf_get_pet_compliance_status( $pet_id, $required_labels = null ) {
	if ( ! class_exists( ComplianceRulesEngine::class ) ) {
		return new \WP_Error(
			'ltkf_compliance_engine_missing',
			__( 'Compliance rules engine is not available.', 'kennelflow-core' ),
			array( 'status' => 500 )
		);
	}

	return ComplianceRulesEngine::get_pet_status( $pet_id, $required_labels );
}

/**
 * Normalized analyte names (keys) that have a pending owner compliance upload for this pet.
 *
 * @param int $pet_id kf_pet post ID.
 * @return array<string, bool> Map of normalized analyte => true.
 */
function ltkf_get_pet_pending_compliance_vaccine_norms( $pet_id ) {
	$pet_id = absint( $pet_id );
	$table  = ltkf_medical_records_table_name();
	if ( $pet_id < 1
		|| ! is_string( $table ) || ! preg_match( '/^[a-zA-Z0-9_]+$/', $table )
		|| ! ltkf_table_exists( $table )
		|| ! class_exists( ComplianceRulesEngine::class ) ) {
		return array();
	}

	global $wpdb;

	$map = array();

	if ( ltkf_db_column_exists( $table, 'status' ) ) {
		$pending = 'pending_review';
		if ( class_exists( ComplianceRetention::class ) ) {
			$pending = ComplianceRetention::RECORD_STATUS_PENDING_REVIEW;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- kf_medical_records pending rows; `%i` table.
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT analyte_name FROM %i WHERE pet_post_id = %d AND status = %s',
				$table,
				$pet_id,
				$pending
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
	} else {
		$like = '%owner_compliance_upload%';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- kf_medical_records legacy meta_json scan; `%i` table.
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT analyte_name FROM %i WHERE pet_post_id = %d AND meta_json LIKE %s',
				$table,
				$pet_id,
				$like
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
	}

	if ( ! is_array( $rows ) ) {
		return $map;
	}

	foreach ( $rows as $name ) {
		$norm = ComplianceRulesEngine::normalize_analyte( (string) $name );
		if ( '' !== $norm ) {
			$map[ $norm ] = true;
		}
	}

	return $map;
}

/**
 * Whether the pet has a pending staff review on any required vaccine (blocks booking until cleared).
 *
 * @param int $pet_id kf_pet post ID.
 * @return bool
 */
function ltkf_pet_has_pending_compliance_upload_for_labels( $pet_id, array $required_labels ) {
	$pet_id = absint( $pet_id );
	if ( $pet_id < 1 ) {
		return false;
	}

	$pending = ltkf_get_pet_pending_compliance_vaccine_norms( $pet_id );
	if ( empty( $pending ) ) {
		return false;
	}

	foreach ( $required_labels as $label ) {
		$norm = ComplianceRulesEngine::normalize_analyte( (string) $label );
		if ( '' !== $norm && isset( $pending[ $norm ] ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Whether the pet has a pending staff review on any required vaccine (blocks booking until cleared).
 *
 * @param int $pet_id kf_pet post ID.
 * @return bool
 */
function ltkf_pet_has_pending_compliance_upload_for_required_vaccines( $pet_id ) {
	return ltkf_pet_has_pending_compliance_upload_for_labels( $pet_id, ltkf_get_required_vaccines() );
}

/**
 * Whether a pet fails vaccine compliance for a given required-vaccine label list.
 *
 * @param int      $pet_id          kf_pet post ID.
 * @param string[] $required_labels Vaccine display names.
 * @return bool
 */
function ltkf_pet_requires_vaccine_compliance_for_labels( $pet_id, array $required_labels ) {
	$pet_id = absint( $pet_id );
	if ( $pet_id < 1 ) {
		return false;
	}

	$labels = array();
	foreach ( $required_labels as $label ) {
		$label = sanitize_text_field( (string) $label );
		if ( '' !== $label ) {
			$labels[] = $label;
		}
	}

	if ( empty( $labels ) ) {
		return false;
	}

	if ( ltkf_pet_has_pending_compliance_upload_for_labels( $pet_id, $labels ) ) {
		return true;
	}

	$status = ltkf_get_pet_compliance_status( $pet_id, $labels );
	if ( is_wp_error( $status ) ) {
		return true;
	}

	$vaccines = isset( $status['vaccines'] ) && is_array( $status['vaccines'] ) ? $status['vaccines'] : array();
	foreach ( $vaccines as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$st = isset( $row['status'] ) ? (string) $row['status'] : '';
		if ( 'Valid' !== $st ) {
			return true;
		}
	}

	return false;
}

/**
 * Portal / wizard rows for a pet against a configured required-vaccine list.
 *
 * @param int      $pet_id          kf_pet post ID.
 * @param string[] $required_labels Vaccine display names (must match medical record analyte labels).
 * @return array<int, array<string, string>> List of rows with keys label, norm, state.
 */
function ltkf_get_pet_compliance_vaccine_rows_for_labels( $pet_id, array $required_labels ) {
	$pet_id = absint( $pet_id );
	if ( $pet_id < 1 || ! class_exists( ComplianceRulesEngine::class ) ) {
		return array();
	}

	$required = array();
	foreach ( $required_labels as $label ) {
		$label = sanitize_text_field( (string) $label );
		if ( '' !== $label ) {
			$required[] = $label;
		}
	}

	if ( empty( $required ) ) {
		return array();
	}

	$pending = ltkf_get_pet_pending_compliance_vaccine_norms( $pet_id );

	$status = ltkf_get_pet_compliance_status( $pet_id, $required );
	$vrows  = array();
	if ( ! is_wp_error( $status ) && isset( $status['vaccines'] ) && is_array( $status['vaccines'] ) ) {
		$vrows = $status['vaccines'];
	}

	$out = array();
	foreach ( $required as $label ) {
		$label = sanitize_text_field( (string) $label );
		if ( '' === $label ) {
			continue;
		}
		$norm = ComplianceRulesEngine::normalize_analyte( $label );
		if ( isset( $pending[ $norm ] ) ) {
			$out[] = array(
				'label' => $label,
				'norm'  => $norm,
				'state' => 'pending_review',
			);
			continue;
		}

		$st = 'Missing';
		if ( isset( $vrows[ $label ] ) && is_array( $vrows[ $label ] ) && isset( $vrows[ $label ]['status'] ) ) {
			$st = (string) $vrows[ $label ]['status'];
		}

		if ( 'Valid' === $st ) {
			$out[] = array(
				'label' => $label,
				'norm'  => $norm,
				'state' => 'valid',
			);
		} elseif ( 'Expired' === $st ) {
			$out[] = array(
				'label' => $label,
				'norm'  => $norm,
				'state' => 'expired',
			);
		} else {
			$out[] = array(
				'label' => $label,
				'norm'  => $norm,
				'state' => 'missing',
			);
		}
	}

	return $out;
}

/**
 * Portal UI rows for required vaccines: valid, missing, expired, or pending_review.
 *
 * @param int $pet_id kf_pet post ID.
 * @return array<int, array<string, string>> List of rows with keys label, norm, state.
 */
function ltkf_get_portal_pet_compliance_vaccines( $pet_id ) {
	return ltkf_get_pet_compliance_vaccine_rows_for_labels( $pet_id, ltkf_get_required_vaccines() );
}

/**
 * Booking wizard: rows using boarding-specific requirements (or general facility list when boarding list is empty).
 *
 * @param int $pet_id kf_pet post ID.
 * @return array<int, array<string, string>> List of rows with keys label, norm, state.
 */
function ltkf_get_boarding_wizard_pet_compliance_vaccines( $pet_id ) {
	return ltkf_get_pet_compliance_vaccine_rows_for_labels( $pet_id, ltkf_get_effective_boarding_required_vaccines() );
}

/**
 * Whether a pet has any required vaccine that is not Valid (Expired, Missing, engine error),
 * or a pending owner upload awaiting staff review on a required vaccine.
 *
 * @param int $pet_id kf_pet post ID.
 * @return bool
 */
function ltkf_pet_requires_compliance_action( $pet_id ) {
	return ltkf_pet_requires_vaccine_compliance_for_labels( $pet_id, ltkf_get_required_vaccines() );
}

/**
 * Whether a pet must complete boarding compliance (signed waiver + boarding required vaccines).
 *
 * @param int $pet_id kf_pet post ID.
 * @return bool
 */
function ltkf_pet_requires_boarding_compliance_action( $pet_id ) {
	$pet_id = absint( $pet_id );
	if ( $pet_id < 1 ) {
		return false;
	}

	if ( class_exists( Waiver::class ) && ! Waiver::pet_has_valid_waiver( $pet_id ) ) {
		return true;
	}

	return ltkf_pet_requires_vaccine_compliance_for_labels( $pet_id, ltkf_get_effective_boarding_required_vaccines() );
}

/**
 * Owner-facing messages describing what a pet owner must do before booking or paying.
 *
 * @param int $pet_id kf_pet post ID.
 * @return string[] Short action strings (empty when no action is required).
 */
function ltkf_get_pet_owner_compliance_action_messages( $pet_id ) {
	$pet_id = absint( $pet_id );
	if ( $pet_id < 1 || ! ltkf_pet_requires_boarding_compliance_action( $pet_id ) ) {
		return array();
	}

	$messages = array();

	if ( class_exists( ComplianceRulesEngine::class ) ) {
		$vaccines = ltkf_get_boarding_wizard_pet_compliance_vaccines( $pet_id );
		foreach ( $vaccines as $vrow ) {
			if ( ! is_array( $vrow ) || empty( $vrow['state'] ) || empty( $vrow['label'] ) ) {
				continue;
			}
			$label = (string) $vrow['label'];
			if ( 'missing' === $vrow['state'] ) {
				$messages[] = sprintf(
					/* translators: %s: vaccine name */
					__( 'Upload %s vaccination record', 'kennelflow-core' ),
					$label
				);
			} elseif ( 'expired' === $vrow['state'] ) {
				$messages[] = sprintf(
					/* translators: %s: vaccine name */
					__( 'Renew expired %s vaccination', 'kennelflow-core' ),
					$label
				);
			} elseif ( 'pending_review' === $vrow['state'] ) {
				$messages[] = sprintf(
					/* translators: %s: vaccine name */
					__( 'Wait for staff to review %s document', 'kennelflow-core' ),
					$label
				);
			}
		}
	}

	if ( class_exists( Waiver::class ) && ! Waiver::pet_has_valid_waiver( $pet_id ) ) {
		$messages[] = __( 'Sign boarding waiver in the Legal Waivers tab', 'kennelflow-core' );
	}

	if ( empty( $messages ) ) {
		$messages[] = __( 'Update vaccination records or contact the facility for help', 'kennelflow-core' );
	}

	/**
	 * Filters owner portal action-required messages for a pet.
	 *
	 * @since 0.2.6
	 *
	 * @param string[] $messages Action strings.
	 * @param int      $pet_id   Pet post ID.
	 */
	return apply_filters( 'ltkf_pet_owner_compliance_action_messages', $messages, $pet_id );
}

/**
 * Whether pet owners may submit boarding bookings through the public booking wizard.
 *
 * @return bool
 */
function ltkf_is_owner_online_boarding_enabled() {
	return '1' === get_option( 'ltkf_allow_owner_online_boarding', '1' );
}

/**
 * Whether a pet owner may request an online boarding stay for a pet (setting + compliance + filter).
 *
 * Kennel availability is validated separately when the booking is created.
 *
 * @param int $pet_id  kf_pet post ID.
 * @param int $user_id WordPress user ID (defaults to current user).
 * @return bool
 */
function ltkf_owner_may_request_online_boarding( $pet_id, $user_id = 0 ) {
	$pet_id  = absint( $pet_id );
	$user_id = $user_id > 0 ? absint( $user_id ) : get_current_user_id();
	if ( $pet_id < 1 || $user_id < 1 ) {
		return false;
	}
	if ( ! ltkf_is_owner_online_boarding_enabled() ) {
		return false;
	}
	if ( function_exists( 'ltkf_get_pet_owner_user_id' ) ) {
		$owner_id = (int) ltkf_get_pet_owner_user_id( $pet_id );
		if ( $owner_id < 1 || $owner_id !== $user_id ) {
			return false;
		}
	}
	if ( ltkf_pet_requires_boarding_compliance_action( $pet_id ) ) {
		return false;
	}

	/**
	 * Final gate for owner online boarding eligibility (pet + owner criteria met).
	 *
	 * @since 0.2.7
	 *
	 * @param bool $allowed  Default true when setting is on and boarding compliance is satisfied.
	 * @param int  $pet_id   Pet post ID.
	 * @param int  $user_id  Owner user ID.
	 */
	return (bool) apply_filters( 'ltkf_allow_owner_boarding_booking', true, $pet_id, $user_id );
}

/**
 * REST guard for owner-initiated boarding bookings (returns WP_Error or null).
 *
 * @param int $pet_id  kf_pet post ID.
 * @param int $user_id WordPress user ID (defaults to current user).
 * @return \WP_Error|null
 */
function ltkf_rest_guard_owner_online_boarding( $pet_id, $user_id = 0 ) {
	$pet_id  = absint( $pet_id );
	$user_id = $user_id > 0 ? absint( $user_id ) : get_current_user_id();

	if ( ! ltkf_is_owner_online_boarding_enabled() ) {
		return new \WP_Error(
			'ltkf_online_boarding_disabled',
			__( 'Online boarding booking is not available on this site. Please contact the facility.', 'kennelflow-core' ),
			array( 'status' => 403 )
		);
	}

	if ( ltkf_pet_requires_boarding_compliance_action( $pet_id ) ) {
		$msgs = ltkf_get_pet_owner_compliance_action_messages( $pet_id );
		$msg  = ! empty( $msgs )
			? implode( ' ', array_map( 'strval', $msgs ) )
			: __( 'Complete boarding requirements before booking online.', 'kennelflow-core' );
		return new \WP_Error(
			'ltkf_boarding_not_eligible',
			$msg,
			array(
				'status'          => 403,
				'action_messages' => $msgs,
			)
		);
	}

	if ( ! ltkf_owner_may_request_online_boarding( $pet_id, $user_id ) ) {
		return new \WP_Error(
			'ltkf_boarding_not_eligible',
			__( 'This pet is not eligible for online boarding booking.', 'kennelflow-core' ),
			array( 'status' => 403 )
		);
	}

	return null;
}

/**
 * Whether a kf_bookings row is a boarding stay that requires vaccine compliance at checkout.
 *
 * @param object $row Row with optional booking_kind (empty or boarding; not clinic/grooming).
 * @return bool
 */
function ltkf_booking_row_is_boarding_stay_for_vaccine_compliance( $row ) {
	if ( ! is_object( $row ) ) {
		return false;
	}

	$kind = isset( $row->booking_kind ) ? sanitize_key( (string) $row->booking_kind ) : '';

	return ( '' === $kind || 'boarding' === $kind );
}

/**
 * E2E / staging only: when true, portal UI and portal AJAX do not block reaching checkout for
 * non-compliant pets; {@see BookingComplianceGate} still blocks at WooCommerce checkout.
 *
 * Set via option `ltkf_compliance_gatekeeper_e2e_allow_noncompliant_checkout` or filter
 * `ltkf_compliance_gatekeeper_e2e_allow_noncompliant_checkout`.
 *
 * @return bool
 */
function ltkf_compliance_gatekeeper_e2e_allow_noncompliant_checkout() {
	if ( apply_filters( 'ltkf_compliance_gatekeeper_e2e_bypass_portal', false ) ) {
		return true;
	}

	return (bool) get_option( 'ltkf_compliance_gatekeeper_e2e_allow_noncompliant_checkout', false );
}

/**
 * Whether the compiled Hub calendar bundle exists on disk.
 *
 * @return bool
 */
function ltkf_hub_calendar_bundle_readable() {
	return is_readable( LTKF_PLUGIN_DIR . 'build/index.js' )
		&& is_readable( LTKF_PLUGIN_DIR . 'build/index.asset.php' );
}

/**
 * Enqueue compiled Hub calendar React bundle (`build/index.js` / `build/index.css`).
 *
 * @param string $script_handle Unique script handle (e.g. `kf-hub-admin-calendar`).
 * @return bool True when the bundle was enqueued.
 */
function ltkf_enqueue_hub_calendar_bundle( $script_handle ) {
	$script_handle = sanitize_key( (string) $script_handle );
	if ( '' === $script_handle || ! ltkf_hub_calendar_bundle_readable() ) {
		return false;
	}

	// Ensure WordPress package scripts (React, api-fetch) are registered on front-end and admin.
	wp_enqueue_script( 'wp-element' );
	wp_enqueue_script( 'wp-api-fetch' );
	wp_enqueue_script( 'wp-i18n' );

	$asset = array(
		'dependencies' => array( 'wp-element', 'wp-api-fetch', 'wp-i18n' ),
		'version'      => LTKF_CORE_VERSION,
	);
	$loaded = require LTKF_PLUGIN_DIR . 'build/index.asset.php';
	if ( is_array( $loaded ) ) {
		$asset = array_merge( $asset, $loaded );
	}

	$deps = array_values(
		array_unique(
			array_merge(
				array( 'wp-element', 'wp-api-fetch', 'wp-i18n' ),
				array_map( 'strval', (array) $asset['dependencies'] )
			)
		)
	);

	wp_enqueue_script(
		$script_handle,
		LTKF_PLUGIN_URL . 'build/index.js',
		$deps,
		$asset['version'],
		true
	);

	wp_localize_script(
		$script_handle,
		'kfCalendarSettings',
		ltkf_get_calendar_localized_settings()
	);

	wp_set_script_translations( $script_handle, 'kennelflow-core', LTKF_PLUGIN_DIR . 'languages' );

	wp_enqueue_style(
		$script_handle,
		LTKF_PLUGIN_URL . 'build/index.css',
		array(),
		$asset['version']
	);

	wp_add_inline_script(
		$script_handle,
		'document.addEventListener("DOMContentLoaded",function(){if(window.kfMountHubCalendars){window.kfMountHubCalendars();}});',
		'after'
	);

	return true;
}

/**
 * Markup for a Hub calendar mount node (React replaces the loading shell on boot).
 *
 * @param array<string, string> $args id, class, start_date, end_date, booking_kind, corner_label.
 * @return string
 */
function ltkf_get_hub_calendar_shell_markup( $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'id'           => 'kf-admin-calendar-root',
			'class'        => 'kf-admin-calendar-root',
			'start_date'   => '',
			'end_date'     => '',
			'booking_kind' => '',
			'corner_label' => '',
		)
	);

	$id           = sanitize_html_class( (string) $args['id'] );
	$class_tokens = array_filter( array_map( 'trim', explode( ' ', (string) $args['class'] ) ) );
	$class        = implode(
		' ',
		array_map( 'sanitize_html_class', $class_tokens )
	);
	$start_date   = sanitize_text_field( (string) $args['start_date'] );
	$end_date     = sanitize_text_field( (string) $args['end_date'] );
	$booking_kind = sanitize_key( (string) $args['booking_kind'] );
	$corner_label = sanitize_text_field( (string) $args['corner_label'] );

	$extra_attrs = '';
	if ( '' !== $booking_kind ) {
		$extra_attrs .= sprintf( ' data-booking-kind="%s"', esc_attr( $booking_kind ) );
	}
	if ( '' !== $corner_label ) {
		$extra_attrs .= sprintf( ' data-corner-label="%s"', esc_attr( $corner_label ) );
	}

	return sprintf(
		'<div id="%1$s" class="%2$s" data-start-date="%3$s" data-end-date="%4$s"%5$s aria-live="polite"><p class="kf-cal-shell-loading">%6$s</p></div>',
		esc_attr( $id ),
		esc_attr( $class ),
		esc_attr( $start_date ),
		esc_attr( $end_date ),
		$extra_attrs,
		esc_html__( 'Loading calendar…', 'kennelflow-core' )
	);
}

/**
 * Whether verbose calendar diagnostics / console logging is enabled.
 *
 * @return bool
 */
function ltkf_calendar_debug_enabled() {
	/**
	 * Enable Hub calendar debug output in the browser console and UI.
	 *
	 * @since 0.3.7
	 *
	 * @param bool $enabled Default: WP_DEBUG.
	 */
	return (bool) apply_filters(
		'ltkf_calendar_debug',
		defined( 'WP_DEBUG' ) && WP_DEBUG
	);
}

/**
 * Why Add booking may be unavailable (for admin UI + browser diagnostics).
 *
 * @param array<string, mixed> $localized Calendar script settings after spoke filters.
 * @return array<string, mixed>
 */
function ltkf_get_calendar_add_booking_diagnostics( $localized = array() ) {
	if ( ! is_array( $localized ) ) {
		$localized = array();
	}

	$issues = array();
	$ready  = true;

	if ( ! ltkf_hub_calendar_bundle_readable() ) {
		$issues[] = __( 'Calendar JavaScript bundle is missing from KennelFlow Core (run npm run build and deploy the build/ folder).', 'kennelflow-core' );
		$ready    = false;
	}

	if ( ! defined( 'KENNELFLOW_BOARDING_VERSION' ) ) {
		$issues[] = __( 'KennelFlow Boarding is not active. Install and activate the kennelflow-boarding plugin — Add booking requires Boarding for kennels, intake, and REST create.', 'kennelflow-core' );
		$ready    = false;
	}

	if ( ! class_exists( '\KennelFlow_Boarding_REST_Bookings_Controller' ) ) {
		$issues[] = __( 'KennelFlow Boarding bookings REST is not loaded. Update KennelFlow Boarding to the latest version.', 'kennelflow-core' );
		$ready    = false;
	}

	if ( empty( $localized['kennelpress_bookings_url'] ) ) {
		$issues[] = __( 'Boarding calendar bridge did not register kennelpress_bookings_url. Update KennelFlow Boarding (includes class-kennelflow-boarding-calendar-bridge.php).', 'kennelflow-core' );
		$ready    = false;
	}

	$table = function_exists( 'ltkf_bookings_table_name' ) ? ltkf_bookings_table_name() : '';
	if ( ! is_string( $table ) || '' === $table || ! ltkf_table_exists( $table ) ) {
		$issues[] = __( 'Bookings database table is missing. Activate KennelFlow Core and Boarding so migrations can create wp_kf_bookings.', 'kennelflow-core' );
		$ready    = false;
	}

	if ( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'manage_options' ) ) {
		$issues[] = __( 'Your user account lacks edit_posts (staff calendar access).', 'kennelflow-core' );
		$ready    = false;
	}

	return array(
		'ready'             => $ready,
		'issues'            => $issues,
		'boarding_active'   => defined( 'KENNELFLOW_BOARDING_VERSION' ),
		'boarding_version'  => defined( 'KENNELFLOW_BOARDING_VERSION' ) ? (string) KENNELFLOW_BOARDING_VERSION : '',
		'core_version'      => defined( 'LTKF_CORE_VERSION' ) ? (string) LTKF_CORE_VERSION : '',
		'has_bookings_url'  => ! empty( $localized['kennelpress_bookings_url'] ),
		'bookings_rest_base'=> isset( $localized['kennelflow_boarding_rest_base'] )
			? (string) $localized['kennelflow_boarding_rest_base']
			: '',
		'debug'             => ltkf_calendar_debug_enabled(),
	);
}

/**
 * Localized settings for Hub calendar React (`kfCalendarSettings`) — admin screen and `[kf_hub_calendar]` shortcode.
 *
 * @return array<string, mixed>
 */
function ltkf_get_calendar_localized_settings() {
	$tz = function_exists( 'wp_timezone_string' ) ? (string) wp_timezone_string() : 'UTC';
	if ( '' === $tz ) {
		$tz = 'UTC';
	}

	$debug_enabled = ltkf_calendar_debug_enabled();
	$show_debug    = $debug_enabled;
	if ( ! $show_debug && is_admin() && current_user_can( 'manage_options' ) ) {
		$show_debug = true;
	}

	/**
	 * Show the in-page KennelFlow calendar debug panel (admins / WP_DEBUG).
	 *
	 * @since 0.3.8
	 *
	 * @param bool $show_debug Default: site admins in wp-admin or when calendar debug is on.
	 */
	$show_debug = (bool) apply_filters( 'ltkf_calendar_show_debug_panel', $show_debug );

	$localized = array(
		'rest_url'             => esc_url_raw( rest_url() ),
		'nonce'                => wp_create_nonce( 'wp_rest' ),
		'admin_url'            => admin_url(),
		'bookings_create_path' => '/kennelflow/v1/bookings',
		'site_timezone'        => $tz,
		'debug'                => $debug_enabled,
		'show_debug_panel'     => $show_debug,
		'force_debug_log'      => $show_debug || $debug_enabled,
		'script_version'       => defined( 'LTKF_CORE_VERSION' ) ? (string) LTKF_CORE_VERSION : '',
	);

	/**
	 * Localized data for the Hub calendar script (REST root, nonce, Kennel Press URLs, etc.).
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $localized Settings passed to `kfCalendarSettings`.
	 */
	$localized = apply_filters( 'ltkf_admin_calendar_localized_settings', $localized );

	$localized['add_booking_diagnostics'] = ltkf_get_calendar_add_booking_diagnostics( $localized );

	return $localized;
}

/**
 * Whether the user may view the Hub calendar (admin screen, shortcode, REST reads).
 *
 * @param int $user_id WordPress user ID (0 = current user).
 * @return bool
 */
function ltkf_user_can_view_hub_calendar( $user_id = 0 ) {
	$user_id = absint( $user_id );
	$cap     = apply_filters( 'ltkf_admin_calendar_capability', 'edit_posts' );

	if ( $user_id > 0 ) {
		$can = user_can( $user_id, $cap ) || user_can( $user_id, 'manage_options' );
	} else {
		$can = current_user_can( $cap ) || current_user_can( 'manage_options' );
	}

	// Spoke-specific caps (e.g. groompress_view_calendar) must not block staff who still have edit_posts.
	if ( ! $can && 'edit_posts' !== $cap ) {
		if ( $user_id > 0 ) {
			$can = user_can( $user_id, 'edit_posts' );
		} else {
			$can = current_user_can( 'edit_posts' );
		}
	}

	/**
	 * Final gate for Hub calendar UI and read-only calendar REST routes.
	 *
	 * @since 0.3.2
	 *
	 * @param bool $can     Whether the user passed the base calendar capability.
	 * @param int  $user_id User ID (0 = current user).
	 */
	return (bool) apply_filters( 'ltkf_user_can_view_hub_calendar', $can, $user_id );
}

/**
 * Register shared toast / non-blocking notice script (used by portal and admin screens).
 *
 * @return void
 */
function ltkf_register_toast_assets() {
	wp_register_style(
		'kf-toast',
		LTKF_PLUGIN_URL . 'assets/css/kf-toast.css',
		array(),
		LTKF_CORE_VERSION
	);
	wp_register_script(
		'kf-toast',
		LTKF_PLUGIN_URL . 'assets/js/kf-toast.js',
		array(),
		LTKF_CORE_VERSION,
		true
	);
	wp_localize_script(
		'kf-toast',
		'kfToastConfig',
		array(
			'dismissSr' => __( 'Dismiss this notice.', 'kennelflow-core' ),
			'cancel'    => __( 'Cancel', 'kennelflow-core' ),
			'confirm'   => __( 'OK', 'kennelflow-core' ),
		)
	);
}
add_action( 'init', 'ltkf_register_toast_assets', 1 );

/**
 * Default WordPress role for migrated CSV owner accounts.
 *
 * @return string Role slug.
 */
function ltkf_get_migration_owner_role_default() {
	return 'subscriber';
}

/**
 * Ensure the Pet Owner role is assigned when migration creates users.
 *
 * @param int $user_id WordPress user ID.
 * @return void
 */
function ltkf_migration_ensure_pet_owner_role( $user_id ) {
	$user_id = absint( $user_id );
	if ( $user_id < 1 ) {
		return;
	}
	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return;
	}
	if ( ! in_array( 'pet_owner', (array) $user->roles, true ) ) {
		$user->add_role( 'pet_owner' );
	}
}

/**
 * Admin or public URL for a post shown in calendar record-link popovers.
 *
 * Non-public CPT rows (bookings) must not use front-end permalinks.
 *
 * @param int $post_id Post ID.
 * @return string Empty when the user cannot view the post.
 */
function ltkf_get_admin_post_record_link( $post_id ) {
	$post_id = absint( $post_id );
	if ( $post_id < 1 ) {
		return '';
	}

	$post = get_post( $post_id );
	if ( ! $post instanceof \WP_Post ) {
		return '';
	}

	if ( current_user_can( 'edit_post', $post_id ) ) {
		$edit = get_edit_post_link( $post_id, 'raw' );
		if ( is_string( $edit ) && '' !== $edit ) {
			return esc_url_raw( $edit );
		}
	}

	if ( ! current_user_can( 'read_post', $post_id ) ) {
		return '';
	}

	if ( ! is_post_publicly_viewable( $post ) ) {
		return esc_url_raw( admin_url( 'post.php?post=' . $post_id . '&action=edit' ) );
	}

	$permalink = get_permalink( $post_id );
	if ( is_string( $permalink ) && '' !== $permalink ) {
		return esc_url_raw( $permalink );
	}

	return '';
}

/**
 * Update booking status on the underlying appointment post and sync index rows.
 *
 * @param int    $post_id Booking post ID (`kennelpress_booking` or `kf_vet_booking`).
 * @param string $status  Target status (sanitized per spoke).
 * @return string|\WP_Error Sanitized status on success.
 */
function ltkf_update_booking_post_status( $post_id, $status ) {
	$post_id = absint( $post_id );
	if ( $post_id < 1 ) {
		return new \WP_Error(
			'ltkf_invalid_booking',
			__( 'Invalid booking.', 'kennelflow-core' ),
			array( 'status' => 400 )
		);
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new \WP_Error(
			'ltkf_forbidden',
			__( 'You cannot update this booking.', 'kennelflow-core' ),
			array( 'status' => 403 )
		);
	}

	$post = get_post( $post_id );
	if ( ! $post instanceof \WP_Post ) {
		return new \WP_Error(
			'ltkf_not_found',
			__( 'Booking not found.', 'kennelflow-core' ),
			array( 'status' => 404 )
		);
	}

	if ( 'kennelpress_booking' === $post->post_type && class_exists( '\KennelFlow_Boarding_Post_Meta' ) ) {
		$status = \KennelFlow_Boarding_Post_Meta::sanitize_booking_status( (string) $status );
		update_post_meta( $post_id, \KennelFlow_Boarding_Post_Meta::BOOKING_STATUS, $status );
		if ( class_exists( '\KennelFlow_Boarding_Booking_Index' ) ) {
			\KennelFlow_Boarding_Booking_Index::sync_from_post( $post_id, $post );
		}
		if ( class_exists( '\KennelFlow_Boarding_Cache' ) ) {
			\KennelFlow_Boarding_Cache::bump_query_bust();
		}
		return $status;
	}

	if ( function_exists( 'kennelflow_vet_is_booking_post' ) && kennelflow_vet_is_booking_post( $post_id ) && class_exists( '\KennelFlow_Vet_Post_Meta' ) ) {
		$status = \KennelFlow_Vet_Post_Meta::sanitize_booking_status( (string) $status );
		update_post_meta( $post_id, \KennelFlow_Vet_Post_Meta::BOOKING_STATUS, $status );
		if ( class_exists( '\KennelFlow_Vet_Bookings_Index' ) ) {
			\KennelFlow_Vet_Bookings_Index::sync_from_post( $post_id );
		}
		if ( class_exists( '\KennelFlow_Vet_Cache' ) ) {
			\KennelFlow_Vet_Cache::bump_query_bust();
		}

		global $wpdb;
		$table = ltkf_bookings_table_name();
		if ( is_string( $table ) && preg_match( '/^[a-zA-Z0-9_]+$/', $table ) && ltkf_table_exists( $table ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Hub calendar row when vet CPT is indexed in kf_bookings.
			$wpdb->update(
				$table,
				array( 'status' => $status ),
				array( 'post_id' => $post_id ),
				array( '%s' ),
				array( '%d' )
			);
		}

		return $status;
	}

	return new \WP_Error(
		'ltkf_unknown_booking_type',
		__( 'Unsupported booking type.', 'kennelflow-core' ),
		array( 'status' => 400 )
	);
}

/**
 * Whether a calendar booking row can be checked in (status transition to checked_in).
 *
 * @param array<string, mixed> $booking Normalized calendar booking.
 * @return bool
 */
function ltkf_calendar_booking_can_check_in( $booking ) {
	$booking = is_array( $booking ) ? $booking : array();

	$post_id = isset( $booking['booking_post_id'] ) ? absint( $booking['booking_post_id'] ) : 0;
	if ( $post_id < 1 || ! current_user_can( 'edit_post', $post_id ) ) {
		return false;
	}

	$status = isset( $booking['status'] ) ? sanitize_key( (string) $booking['status'] ) : '';
	$allowed_from = array( 'pending', 'pending_payment', 'confirmed' );

	/**
	 * Statuses that show the calendar “Check in” action.
	 *
	 * @since 0.3.16
	 *
	 * @param string[]             $allowed_from Current statuses.
	 * @param array<string, mixed> $booking      Calendar booking row.
	 */
	$allowed_from = apply_filters( 'ltkf_calendar_check_in_from_statuses', $allowed_from, $booking );

	return in_array( $status, $allowed_from, true );
}

/**
 * Admin URLs for a calendar booking row (appointment, pet, owner, patient history).
 *
 * @param array<string, mixed> $booking Normalized calendar booking (pet_id, booking_post_id, booking_kind, owner_user_id).
 * @return array<string, mixed>
 */
function ltkf_get_calendar_booking_record_links( $booking ) {
	$booking = is_array( $booking ) ? $booking : array();

	$pet_id          = isset( $booking['pet_id'] ) ? absint( $booking['pet_id'] ) : 0;
	$booking_post_id = isset( $booking['booking_post_id'] ) ? absint( $booking['booking_post_id'] ) : 0;
	$owner_user_id   = isset( $booking['owner_user_id'] ) ? absint( $booking['owner_user_id'] ) : 0;

	if ( $owner_user_id < 1 && $pet_id > 0 ) {
		$owner_user_id = absint( ltkf_get_pet_owner_user_id( $pet_id ) );
	}

	$links = array(
		'booking'         => array(
			'view' => '',
			'edit' => '',
		),
		'pet'             => array(
			'view' => '',
			'edit' => '',
		),
		'owner'           => array(
			'view' => '',
			'edit' => '',
		),
		'patient_history' => '',
	);

	if ( $booking_post_id > 0 ) {
		$booking_url = ltkf_get_admin_post_record_link( $booking_post_id );
		if ( '' !== $booking_url ) {
			if ( current_user_can( 'edit_post', $booking_post_id ) ) {
				$links['booking']['edit'] = $booking_url;
			}
			$links['booking']['view'] = $booking_url;
		}
	}

	if ( $pet_id > 0 ) {
		$pet_url = ltkf_get_admin_post_record_link( $pet_id );
		if ( '' !== $pet_url ) {
			if ( current_user_can( 'edit_post', $pet_id ) ) {
				$links['pet']['edit'] = $pet_url;
			}
			$links['pet']['view'] = $pet_url;
		}

		if ( class_exists( '\KennelFlow_Vet_Capabilities' )
			&& class_exists( '\KennelFlow_Vet_Admin_EMR_Pages' )
			&& current_user_can( \KennelFlow_Vet_Capabilities::CAP_READ_EMR ) ) {
			$links['patient_history'] = esc_url_raw(
				admin_url( 'admin.php?page=' . \KennelFlow_Vet_Admin_EMR_Pages::PAGE_HISTORY . '&pet_id=' . $pet_id )
			);
		}
	}

	if ( $owner_user_id > 0 && current_user_can( 'edit_user', $owner_user_id ) ) {
		$user_edit = get_edit_user_link( $owner_user_id );
		if ( is_string( $user_edit ) && '' !== $user_edit ) {
			$links['owner']['edit'] = esc_url_raw( $user_edit );
			$links['owner']['view']   = $links['owner']['edit'];
		}
	}

	/**
	 * Filters admin record links shown on Hub calendar booking popovers.
	 *
	 * @since 0.3.12
	 *
	 * @param array<string, mixed> $links   Link groups (booking, pet, owner, patient_history).
	 * @param array<string, mixed> $booking Calendar booking row.
	 */
	return apply_filters( 'ltkf_calendar_booking_record_links', $links, $booking );
}

/**
 * Attach record_links to calendar REST booking rows.
 *
 * @param array[]         $bookings Normalized bookings.
 * @param object[]        $rows     Raw DB rows.
 * @param \WP_REST_Request $request Request.
 * @return array[]
 */
function ltkf_rest_calendar_enrich_bookings( $bookings, $rows, $request ) {
	unset( $rows, $request );

	if ( ! is_array( $bookings ) ) {
		return array();
	}

	foreach ( $bookings as $index => $booking ) {
		if ( ! is_array( $booking ) ) {
			continue;
		}
		if ( empty( $booking['owner_user_id'] ) && ! empty( $booking['pet_id'] ) ) {
			$bookings[ $index ]['owner_user_id'] = absint( ltkf_get_pet_owner_user_id( (int) $booking['pet_id'] ) );
		}
		$bookings[ $index ]['record_links'] = ltkf_get_calendar_booking_record_links( $bookings[ $index ] );
	}

	return $bookings;
}

add_filter( 'ltkf_rest_calendar_bookings', __NAMESPACE__ . '\\ltkf_rest_calendar_enrich_bookings', 20, 3 );
