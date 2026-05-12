<?php
/**
 * REST: Public clinician list (gated by KennelFlow Settings).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class PublicCliniciansApi
 */
class PublicCliniciansApi {

	const REST_NAMESPACE = 'kennelflow/v1';

	const ROUTE = '/public-clinicians';

	/**
	 * Default role slugs for public clinician directory (lowercase WordPress role keys).
	 *
	 * @return string[]
	 */
	public static function get_default_role_slugs() {
		return array( 'veterinarian', 'clinician' );
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_items' ),
				'permission_callback' => array( __CLASS__, 'permission_public_clinicians' ),
				'args'                => array(
					'location_id' => array(
						'description'       => __( 'Location post ID (KennelFlow location).', 'kennelflow-core' ),
						'type'              => 'integer',
						'required'          => true,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Allow GET only when the master toggle is on.
	 *
	 * @return bool|WP_Error
	 */
	public static function permission_public_clinicians() {
		$raw = get_option( 'ltkf_allow_owner_clinician_selection', '0' );
		if ( ! wp_validate_boolean( $raw ) ) {
			return new \WP_Error(
				'clinician_selection_disabled',
				__( 'Clinician selection is disabled.', 'kennelflow-core' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * GET /kennelflow/v1/public-clinicians
	 *
	 * Query: location_id (required) — only clinicians with at least one roster day at that location are returned.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_items( $request ) {
		$location_id = absint( $request->get_param( 'location_id' ) );
		if ( $location_id < 1 ) {
			return new \WP_Error(
				'rest_invalid_param',
				__( 'A valid location_id query parameter is required.', 'kennelflow-core' ),
				array( 'status' => 400 )
			);
		}

		$loc_pt = function_exists( 'ltkf_get_location_post_type' ) ? ltkf_get_location_post_type() : 'kf_location';
		if ( $loc_pt !== get_post_type( $location_id ) ) {
			return new \WP_Error(
				'rest_invalid_param',
				__( 'location_id does not refer to a valid location.', 'kennelflow-core' ),
				array( 'status' => 400 )
			);
		}

		$role_slugs = apply_filters( 'ltkf_public_clinicians_role_slugs', self::get_default_role_slugs() );
		$role_slugs = array_filter( array_map( 'sanitize_key', (array) $role_slugs ) );

		if ( empty( $role_slugs ) ) {
			return rest_ensure_response( array() );
		}

		$users = get_users(
			array(
				'role__in' => $role_slugs,
				'orderby'  => 'display_name',
				'order'    => 'ASC',
				'fields'   => 'all',
			)
		);

		$out = array();
		foreach ( $users as $user ) {
			if ( ! $user instanceof \WP_User ) {
				continue;
			}
			$uid = absint( $user->ID );
			if ( $uid < 1 ) {
				continue;
			}

			if ( ! AdminClinicianProfiles::user_has_location_roster_day( $uid, $location_id ) ) {
				continue;
			}

			$name = $user->display_name;
			if ( '' === (string) $name ) {
				$name = $user->user_nicename;
			}
			if ( '' === (string) $name ) {
				$name = $user->user_login;
			}
			$name = sanitize_text_field( (string) $name );

			$avatar = get_avatar_url( $uid, array( 'size' => 96 ) );
			if ( ! is_string( $avatar ) || '' === $avatar ) {
				$avatar = '';
			} else {
				$avatar = esc_url_raw( $avatar );
			}

			$bio_raw = get_user_meta( $uid, AdminClinicianProfiles::META_PUBLIC_BIO, true );
			$bio_raw = is_string( $bio_raw ) ? $bio_raw : '';
			$bio_out = wp_kses_post( $bio_raw );

			$spec_raw = get_user_meta( $uid, AdminClinicianProfiles::META_SPECIALTIES, true );
			$spec_raw = is_string( $spec_raw ) ? $spec_raw : '';
			$spec_out = sanitize_text_field( $spec_raw );

			$row = array(
				'id'             => $uid,
				'name'           => $name,
				'kf_public_bio'  => $bio_out,
				'kf_specialties' => $spec_out,
			);
			if ( '' !== $avatar ) {
				$row['avatar_url'] = $avatar;
			}

			/**
			 * Filter a single public clinician row before REST output.
			 *
			 * @since 0.2.0
			 *
			 * @param array    $row {
			 *     @type int    $id               User ID.
			 *     @type string $name             Display name.
			 *     @type string $kf_public_bio    Sanitized public bio (HTML allowed per wp_kses_post).
			 *     @type string $kf_specialties   Sanitized specialties line.
			 *     @type string $avatar_url       Optional avatar URL.
			 * }
			 * @param WP_User $user User object.
			 */
			$row = apply_filters( 'ltkf_public_clinician_rest_item', $row, $user );

			$safe = array(
				'id'             => absint( isset( $row['id'] ) ? $row['id'] : 0 ),
				'name'           => sanitize_text_field( isset( $row['name'] ) ? (string) $row['name'] : '' ),
				'kf_public_bio'  => isset( $row['kf_public_bio'] ) ? wp_kses_post( (string) $row['kf_public_bio'] ) : '',
				'kf_specialties' => isset( $row['kf_specialties'] ) ? sanitize_text_field( (string) $row['kf_specialties'] ) : '',
			);
			if ( isset( $row['avatar_url'] ) && '' !== (string) $row['avatar_url'] ) {
				$safe['avatar_url'] = esc_url_raw( (string) $row['avatar_url'] );
			}

			if ( $safe['id'] < 1 ) {
				continue;
			}

			$out[] = $safe;
		}

		return rest_ensure_response( $out );
	}
}
