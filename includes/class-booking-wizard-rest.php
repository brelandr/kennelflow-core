<?php
/**
 * Registers `kennelflow-vet/v1` REST routes used by the booking wizard when KennelFlow Vet is inactive
 * (delegates to Kennel Press Hub data when available).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class BookingWizardRest
 */
class BookingWizardRest {

	/**
	 * Cached Kennel Press bookings controller (for permissions + create).
	 *
	 * @var KennelFlow_Boarding_REST_Bookings_Controller|null
	 */
	protected static $bookings_controller = null;

	/**
	 * Boot REST routes.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ), 5 );
	}

	/**
	 * Register kennelflow-vet/v1 compatibility routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		if ( class_exists( 'KennelFlow_Vet_Frontend' ) ) {
			return;
		}
		if ( ! class_exists( 'KennelFlow_Boarding_REST_Locations_Controller' )
			|| ! class_exists( 'KennelFlow_Boarding_REST_Availability_Controller' )
			|| ! class_exists( 'KennelFlow_Boarding_REST_Bookings_Controller' ) ) {
			return;
		}

		$loc_ctrl = new KennelFlow_Boarding_REST_Locations_Controller();
		$avail    = new KennelFlow_Boarding_REST_Availability_Controller();

		register_rest_route(
			'kennelflow-vet/v1',
			'/locations',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $loc_ctrl, 'get_items' ),
					'permission_callback' => array( $loc_ctrl, 'get_items_permissions_check' ),
					'args'                => $loc_ctrl->get_collection_params(),
				),
			)
		);

		register_rest_route(
			'kennelflow-vet/v1',
			'/me/pets',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_me_pets' ),
					'permission_callback' => array( __CLASS__, 'me_pets_permission' ),
				),
			)
		);

		register_rest_route(
			'kennelflow-vet/v1',
			'/availability',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_availability' ),
					'permission_callback' => array( $avail, 'get_items_permissions_check' ),
					'args'                => $avail->get_collection_params(),
				),
			)
		);

		register_rest_route(
			'kennelflow-vet/v1',
			'/bookings',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_booking' ),
					'permission_callback' => array( self::get_bookings_controller(), 'permissions_create_booking' ),
				),
			)
		);
	}

	/**
	 * KennelFlow_Boarding_REST_Bookings_Controller instance (for delegation).
	 *
	 * @return KennelFlow_Boarding_REST_Bookings_Controller
	 */
	protected static function get_bookings_controller() {
		if ( null === self::$bookings_controller ) {
			self::$bookings_controller = new KennelFlow_Boarding_REST_Bookings_Controller();
		}
		return self::$bookings_controller;
	}

	/**
	 * GET /kennelflow-vet/v1/me/pets
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_me_pets( $request ) {
		unset( $request );
		$user_id = get_current_user_id();

		$owner_key = function_exists( 'ltkf_get_pet_owner_user_meta_key' ) ? ltkf_get_pet_owner_user_meta_key() : 'kf_owner_user_id';

		$pet_types = array( 'kf_pet' );
		if ( ! post_type_exists( 'kf_pet' ) ) {
			return rest_ensure_response( array( 'pets' => array() ) );
		}

		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- REST /me/pets; current user + owner meta + posts_per_page 100 cap.
		$query = new \WP_Query(
			array(
				'post_type'              => $pet_types,
				'post_status'            => 'publish',
				'posts_per_page'         => 100,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'meta_query'             => array(
					array(
						'key'   => $owner_key,
						'value' => (string) $user_id,
					),
				),
			)
		);
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query

		$out = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$out[] = array(
				'id'    => (int) $post->ID,
				'title' => get_the_title( $post ),
				'slug'  => $post->post_name,
			);
		}

		return rest_ensure_response( array( 'pets' => $out ) );
	}

	/**
	 * @return bool|WP_Error
	 */
	public static function me_pets_permission() {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'ltkf_booking_wizard_not_logged_in',
				__( 'You must be logged in.', 'kennelflow-core' ),
				array( 'status' => 401 )
			);
		}
		return true;
	}

	/**
	 * GET /kennelflow-vet/v1/availability — Kennel Press payload + wizard-compatible `rooms` / `room_ids`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_availability( $request ) {
		$avail = new KennelFlow_Boarding_REST_Availability_Controller();
		$res   = $avail->get_items( $request );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$data = $res->get_data();
		if ( ! is_array( $data ) ) {
			return $res;
		}

		$data['rooms']    = isset( $data['kennels'] ) ? $data['kennels'] : array();
		$data['room_ids'] = isset( $data['kennel_ids'] ) ? $data['kennel_ids'] : array();

		return rest_ensure_response( $data );
	}

	/**
	 * POST /kennelflow-vet/v1/bookings — delegates to Kennel Press create (same validation).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_booking( $request ) {
		return self::get_bookings_controller()->create_item( $request );
	}
}
