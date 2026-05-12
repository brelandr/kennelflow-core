<?php
/**
 * Print first kf_pet ID for the user matching E2E_OWNER_USER (email).
 *
 * @package KennelFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$email = getenv( 'E2E_OWNER_USER' );
$email = is_string( $email ) ? trim( $email ) : '';
if ( '' === $email ) {
	fwrite( STDERR, "E2E_OWNER_USER is required.\n" );
	exit( 1 );
}

if ( ! function_exists( 'ltkf_get_pet_post_type' ) ) {
	fwrite( STDERR, "KennelFlow not loaded.\n" );
	exit( 1 );
}

$user = get_user_by( 'email', $email );
if ( ! $user || $user->ID < 1 ) {
	fwrite( STDERR, "User not found for E2E_OWNER_USER.\n" );
	exit( 1 );
}

$owner_key = function_exists( 'ltkf_get_pet_owner_user_meta_key' ) ? ltkf_get_pet_owner_user_meta_key() : 'kf_owner_user_id';
$pet_id    = 0;

$ids = get_user_meta( $user->ID, 'kf_owner_pet_ids', true );
if ( is_array( $ids ) ) {
	foreach ( $ids as $pid ) {
		$pid = absint( $pid );
		if ( $pid > 0 && ltkf_get_pet_post_type() === get_post_type( $pid ) ) {
			$pet_id = $pid;
			break;
		}
	}
}

if ( $pet_id < 1 ) {
	$q = new WP_Query(
		array(
			'post_type'              => ltkf_get_pet_post_type(),
			'post_status'            => array( 'publish', 'private' ),
			'posts_per_page'         => 1,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'meta_key'               => $owner_key,
			'meta_value'             => (string) $user->ID,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
		)
	);
	if ( ! empty( $q->posts[0] ) && $q->posts[0] instanceof WP_Post ) {
		$pet_id = (int) $q->posts[0]->ID;
	}
}

if ( $pet_id < 1 ) {
	fwrite( STDERR, "No kf_pet found for owner.\n" );
	exit( 1 );
}

echo (string) (int) $pet_id;
