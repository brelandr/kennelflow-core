<?php
/**
 * E2E: create a veterinarian user for public clinician API + KennelFlow Vet booking wizard.
 *
 * Environment:
 * - E2E_VET_CLINICIAN_EMAIL (optional, default e2e_vet_clinician@example.test)
 * - E2E_VET_CLINICIAN_LOGIN (optional, default e2e_vet_clinician)
 *
 * @package KennelFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ABSPATH . 'wp-admin/includes/user.php';

$email = getenv( 'E2E_VET_CLINICIAN_EMAIL' );
$email = is_string( $email ) && '' !== trim( $email ) ? trim( $email ) : 'e2e_vet_clinician@example.test';

$login = getenv( 'E2E_VET_CLINICIAN_LOGIN' );
$login = is_string( $login ) && '' !== trim( $login ) ? trim( $login ) : 'e2e_vet_clinician';

if ( ! get_role( 'veterinarian' ) ) {
	add_role(
		'veterinarian',
		'Veterinarian',
		array(
			'read' => true,
		)
	);
}

$existing = get_user_by( 'email', $email );
if ( $existing && $existing->ID > 0 ) {
	wp_delete_user( $existing->ID );
}
$existing_login = get_user_by( 'login', $login );
if ( $existing_login && $existing_login->ID > 0 ) {
	wp_delete_user( $existing_login->ID );
}

$pass    = wp_generate_password( 24, true, true );
$user_id = wp_create_user( $login, $pass, $email );
if ( is_wp_error( $user_id ) ) {
	fwrite( STDERR, $user_id->get_error_message() . "\n" );
	exit( 1 );
}

$user = new WP_User( $user_id );
$user->set_role( 'veterinarian' );

wp_update_user(
	array(
		'ID'           => $user_id,
		'display_name' => 'Dr. E2E Test',
		'first_name'   => 'E2E',
		'last_name'    => 'Test',
	)
);

$bio  = '<p>E2E public bio paragraph for Playwright. This text must appear in the provider card preview when truncated.</p>';
$spec = 'Surgery, Dentistry E2E';

update_user_meta( $user_id, 'kf_public_bio', $bio );
update_user_meta( $user_id, 'kf_specialties', $spec );

$loc_pt  = function_exists( 'ltkf_get_location_post_type' ) ? ltkf_get_location_post_type() : 'kf_location';
$loc_ids = get_posts(
	array(
		'post_type'      => $loc_pt,
		'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
$roster  = array();
foreach ( $loc_ids as $lid ) {
	$lid = absint( $lid );
	if ( $lid < 1 ) {
		continue;
	}
	$roster[ $lid ] = array(
		'mon' => array(
			'start' => '08:00',
			'end'   => '17:00',
		),
		'tue' => array(
			'start' => '08:00',
			'end'   => '17:00',
		),
		'wed' => array(
			'start' => '08:00',
			'end'   => '17:00',
		),
		'thu' => array(
			'start' => '08:00',
			'end'   => '17:00',
		),
		'fri' => array(
			'start' => '08:00',
			'end'   => '17:00',
		),
	);
}
update_user_meta( $user_id, '_kf_location_roster', $roster );

echo wp_json_encode(
	array(
		'user_id' => (int) $user_id,
		'email'   => $email,
		'login'   => $login,
	)
);
