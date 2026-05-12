<?php
/**
 * REST: owner creates hub pets and reads compliance vaccine rows (booking wizard, portal-adjacent).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class OwnerPetsRestApi
 */
class OwnerPetsRestApi {

	const REST_NAMESPACE = 'kennelflow/v1';

	/**
	 * Max length for pet display name (post title).
	 */
	const MAX_TITLE_LENGTH = 200;

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
			'/me/pets',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_pet' ),
					'permission_callback' => array( __CLASS__, 'permissions_logged_in' ),
					'args'                => array(
						'title' => array(
							'description' => __( 'Pet name (post title).', 'kennelflow-core' ),
							'type'        => 'string',
							'required'    => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/me/pets/(?P<id>\d+)/compliance-vaccines',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_compliance_vaccines' ),
					'permission_callback' => array( __CLASS__, 'permissions_logged_in' ),
					'args'                => array(
						'id' => array(
							'description' => __( 'Pet post ID (kf_pet).', 'kennelflow-core' ),
							'type'        => 'integer',
							'required'    => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Require a logged-in user for REST routes.
	 *
	 * @return bool|WP_Error
	 */
	public static function permissions_logged_in() {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'ltkf_owner_pets_auth',
				__( 'You must be logged in.', 'kennelflow-core' ),
				array( 'status' => 401 )
			);
		}
		return true;
	}

	/**
	 * POST /kennelflow/v1/me/pets
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_pet( $request ) {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return new \WP_Error(
				'ltkf_owner_pets_auth',
				__( 'You must be logged in.', 'kennelflow-core' ),
				array( 'status' => 401 )
			);
		}

		if ( ! apply_filters( 'ltkf_allow_owner_create_pet', true, $user_id ) ) {
			return new \WP_Error(
				'ltkf_owner_pets_forbidden',
				__( 'You are not allowed to add a pet here.', 'kennelflow-core' ),
				array( 'status' => 403 )
			);
		}

		$title = isset( $request['title'] ) ? sanitize_text_field( (string) $request['title'] ) : '';
		if ( function_exists( 'mb_substr' ) ) {
			$title = mb_substr( $title, 0, self::MAX_TITLE_LENGTH, 'UTF-8' );
		} else {
			$title = substr( $title, 0, self::MAX_TITLE_LENGTH );
		}

		if ( '' === $title ) {
			return new \WP_Error(
				'ltkf_owner_pets_title',
				__( 'Pet name is required.', 'kennelflow-core' ),
				array( 'status' => 400 )
			);
		}

		$pt = function_exists( 'ltkf_get_pet_post_type' ) ? ltkf_get_pet_post_type() : 'kf_pet';
		if ( ! post_type_exists( $pt ) ) {
			return new \WP_Error(
				'ltkf_owner_pets_no_pt',
				__( 'Pet profiles are not available.', 'kennelflow-core' ),
				array( 'status' => 503 )
			);
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => $pt,
				'post_status' => 'publish',
				'post_title'  => $title,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$post_id = absint( $post_id );
		if ( $post_id < 1 ) {
			return new \WP_Error(
				'ltkf_owner_pets_create',
				__( 'Could not create pet.', 'kennelflow-core' ),
				array( 'status' => 500 )
			);
		}

		update_post_meta( $post_id, ltkf_get_pet_owner_user_meta_key(), $user_id );

		if ( class_exists( 'KennelFlow_Vet_Post_Meta' ) ) {
			update_post_meta( $post_id, KennelFlow_Vet_Post_Meta::PET_OWNER_USER_ID, $user_id );
		} elseif ( class_exists( 'KennelFlow_Vet_Post_Meta' ) ) {
			update_post_meta( $post_id, KennelFlow_Vet_Post_Meta::PET_OWNER_USER_ID, $user_id );
		}

		if ( class_exists( 'OwnerPets' ) ) {
			OwnerPets::rebuild_user_pet_ids( $user_id );
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error(
				'ltkf_owner_pets_missing',
				__( 'Pet was created but could not be loaded.', 'kennelflow-core' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'id'    => (int) $post->ID,
				'title' => get_the_title( $post ),
				'slug'  => $post->post_name,
			)
		);
	}

	/**
	 * GET /kennelflow/v1/me/pets/{id}/compliance-vaccines
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_compliance_vaccines( $request ) {
		$pet_id = absint( $request['id'] );
		if ( $pet_id < 1 ) {
			return new \WP_Error(
				'ltkf_owner_pets_bad_id',
				__( 'Invalid pet ID.', 'kennelflow-core' ),
				array( 'status' => 400 )
			);
		}

		$pt = function_exists( 'ltkf_get_pet_post_type' ) ? ltkf_get_pet_post_type() : 'kf_pet';
		if ( get_post_type( $pet_id ) !== $pt ) {
			return new \WP_Error(
				'ltkf_owner_pets_not_pet',
				__( 'Post is not a hub pet.', 'kennelflow-core' ),
				array( 'status' => 400 )
			);
		}

		$uid      = get_current_user_id();
		$owner_id = function_exists( 'ltkf_get_pet_owner_user_id' ) ? (int) ltkf_get_pet_owner_user_id( $pet_id ) : 0;
		if ( $owner_id < 1 || (int) $owner_id !== (int) $uid ) {
			return new \WP_Error(
				'ltkf_owner_pets_forbidden',
				__( 'You do not have access to this pet.', 'kennelflow-core' ),
				array( 'status' => 403 )
			);
		}

		$rows = function_exists( 'ltkf_get_boarding_wizard_pet_compliance_vaccines' )
			? ltkf_get_boarding_wizard_pet_compliance_vaccines( $pet_id )
			: array();

		return rest_ensure_response(
			array(
				'vaccines' => $rows,
			)
		);
	}
}
