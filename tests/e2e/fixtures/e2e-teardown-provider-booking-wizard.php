<?php
/**
 * E2E: remove test clinician, latest owner booking (KennelFlow Vet), and disable clinician selection.
 *
 * Environment:
 * - E2E_VET_CLINICIAN_EMAIL (optional)
 * - E2E_OWNER_USER (required to delete a booking — pet owner email)
 *
 * @package KennelFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ABSPATH . 'wp-admin/includes/user.php';

update_option( 'ltkf_allow_owner_clinician_selection', '0' );

$vet_email = getenv( 'E2E_VET_CLINICIAN_EMAIL' );
$vet_email = is_string( $vet_email ) && '' !== trim( $vet_email ) ? trim( $vet_email ) : 'e2e_vet_clinician@example.test';

$vet = get_user_by( 'email', $vet_email );
if ( $vet && $vet->ID > 0 ) {
	wp_delete_user( $vet->ID );
}

$owner_email = getenv( 'E2E_OWNER_USER' );
$owner_email = is_string( $owner_email ) ? trim( $owner_email ) : '';
if ( '' === $owner_email ) {
	echo wp_json_encode(
		array(
			'ok'              => true,
			'booking_deleted' => false,
			'note'            => 'no E2E_OWNER_USER',
		)
	);
	exit;
}

$owner = get_user_by( 'email', $owner_email );
if ( ! $owner || $owner->ID < 1 ) {
	echo wp_json_encode(
		array(
			'ok'              => true,
			'booking_deleted' => false,
			'note'            => 'owner not found',
		)
	);
	exit;
}

$pet_types = array();
if ( class_exists( 'KennelFlow_Vet_Pet_Types' ) ) {
	$pet_types = array_values(
		array_filter(
			KennelFlow_Vet_Pet_Types::emr_pet_post_types(),
			'post_type_exists'
		)
	);
}
if ( empty( $pet_types ) ) {
	$pet_types = array( 'kf_pet' );
}

$kf_owner_key = function_exists( 'ltkf_get_pet_owner_user_meta_key' ) ? ltkf_get_pet_owner_user_meta_key() : 'kf_owner_user_id';

$vp_owner_key = '_kennelflow_vet_owner_user_id';
if ( class_exists( 'KennelFlow_Vet_Post_Meta' ) ) {
	$vp_owner_key = KennelFlow_Vet_Post_Meta::PET_OWNER_USER_ID;
}

$pets = get_posts(
	array(
		'post_type'              => $pet_types,
		'post_status'            => array( 'publish', 'private' ),
		'posts_per_page'         => 100,
		'fields'                 => 'ids',
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'meta_query'             => array(
			'relation' => 'OR',
			array(
				'key'   => $vp_owner_key,
				'value' => (string) $owner->ID,
			),
			array(
				'key'   => $kf_owner_key,
				'value' => (string) $owner->ID,
			),
		),
	)
);

$pet_ids = array_map( 'absint', is_array( $pets ) ? $pets : array() );
$pet_ids = array_filter( $pet_ids );

$deleted = 0;
if ( ! empty( $pet_ids ) && class_exists( 'KennelFlow_Vet_Post_Meta' ) && post_type_exists( 'kennelflow_vet_booking' ) ) {
	$bookings = get_posts(
		array(
			'post_type'              => 'kennelflow_vet_booking',
			'post_status'            => 'any',
			'posts_per_page'         => 1,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'meta_query'             => array(
				array(
					'key'     => KennelFlow_Vet_Post_Meta::BOOKING_PET_ID,
					'value'   => $pet_ids,
					'compare' => 'IN',
				),
			),
		)
	);
	if ( ! empty( $bookings[0] ) && $bookings[0] instanceof WP_Post ) {
		$ok = wp_delete_post( (int) $bookings[0]->ID, true );
		if ( $ok ) {
			$deleted = 1;
		}
	}
}

echo wp_json_encode(
	array(
		'ok'              => true,
		'booking_deleted' => $deleted > 0,
	)
);
