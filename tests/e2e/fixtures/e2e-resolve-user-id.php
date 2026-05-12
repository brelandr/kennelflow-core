<?php
/**
 * Print WordPress user ID for E2E_RESOLVE_USER (email or login).
 *
 * @package KennelFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$raw = getenv( 'E2E_RESOLVE_USER' );
$raw = is_string( $raw ) ? trim( $raw ) : '';
if ( '' === $raw ) {
	fwrite( STDERR, "E2E_RESOLVE_USER is required.\n" );
	exit( 1 );
}

$user = false;
if ( is_email( $raw ) ) {
	$user = get_user_by( 'email', $raw );
} else {
	$user = get_user_by( 'login', $raw );
}

if ( ! $user || $user->ID < 1 ) {
	fwrite( STDERR, "User not found.\n" );
	exit( 1 );
}

echo (string) (int) $user->ID;
