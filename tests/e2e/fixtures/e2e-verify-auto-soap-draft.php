<?php
/**
 * Verify KennelFlow Vet auto-draft SOAP encounter for a KennelPress clinic booking (Omni-Booking E2E).
 *
 * Env:
 * - E2E_BOOKING_ID (required) — `kennelpress_booking` post ID.
 * - E2E_EXPECTED_SUBJECTIVE (required) — exact subjective text stored on the encounter row.
 *
 * Asserts:
 * - `_kennelflow_vet_encounter_id` (or legacy `_kp_kennelflow_vet_encounter_id`) on the booking post.
 * - Row in `wp_kennelflow_vet_emr_encounters` with status `draft` and matching `subjective`.
 *
 * Stdout: JSON `{ "ok": true, "booking_id": int, "encounter_id": int }` on success.
 * Exit 1 on failure (stderr message).
 *
 * @package KennelFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$booking_id = absint( getenv( 'E2E_BOOKING_ID' ) );
$expected   = getenv( 'E2E_EXPECTED_SUBJECTIVE' );
$expected   = is_string( $expected ) ? trim( wp_unslash( $expected ) ) : '';

if ( $booking_id < 1 || '' === $expected ) {
	fwrite( STDERR, "E2E_BOOKING_ID and E2E_EXPECTED_SUBJECTIVE are required.\n" );
	exit( 1 );
}

if ( ! class_exists( 'KennelFlow_Vet_EMR_Encounters' ) ) {
	fwrite( STDERR, "KennelFlow_Vet_EMR_Encounters not loaded.\n" );
	exit( 1 );
}

if ( 'kennelpress_booking' !== get_post_type( $booking_id ) ) {
	fwrite( STDERR, "Post is not kennelpress_booking.\n" );
	exit( 1 );
}

$enc_id = absint( get_post_meta( $booking_id, '_kennelflow_vet_encounter_id', true ) );
if ( $enc_id < 1 ) {
	$enc_id = absint( get_post_meta( $booking_id, '_kp_kennelflow_vet_encounter_id', true ) );
}

if ( $enc_id < 1 ) {
	fwrite( STDERR, "No _kennelflow_vet_encounter_id on booking (auto-draft may not have run).\n" );
	exit( 1 );
}

$row = KennelFlow_Vet_EMR_Encounters::get( $enc_id );
if ( is_wp_error( $row ) || null === $row ) {
	fwrite( STDERR, "Encounter row not found.\n" );
	exit( 1 );
}

$status = isset( $row['status'] ) ? (string) $row['status'] : '';
if ( KennelFlow_Vet_EMR_Encounters::STATUS_DRAFT !== $status ) {
	fwrite( STDERR, sprintf( "Expected status draft, got %s.\n", $status ) );
	exit( 1 );
}

$subjective = isset( $row['subjective'] ) ? trim( (string) $row['subjective'] ) : '';
if ( $subjective !== $expected ) {
	fwrite(
		STDERR,
		sprintf(
			"Subjective mismatch.\nExpected: %s\nGot: %s\n",
			$expected,
			$subjective
		)
	);
	exit( 1 );
}

echo wp_json_encode(
	array(
		'ok'           => true,
		'booking_id'   => $booking_id,
		'encounter_id' => $enc_id,
	)
);
