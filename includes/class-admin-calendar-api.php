<?php
/**
 * REST: admin occupancy / calendar feed (kf_bookings + kf_pet).
 *
 * Omni-Booking: `POST|GET|PATCH /kennelflow/v1/bookings` proxies to Kennel Press and
 * registers `_kf_*` contextual meta for the Hub.
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class AdminCalendarApi
 */
class AdminCalendarApi {

	const REST_NAMESPACE = 'kennelflow/v1';

	const ROUTE = '/calendar';

	/**
	 * Hub booking contextual meta (stored on `kennelpress_booking` post; mirrors Kennel Press meta keys).
	 */
	const META_GROOMING_STYLE_NOTES = '_kf_grooming_style_notes';

	const META_COAT_CONDITION = '_kf_coat_condition';

	const META_REASON_FOR_VISIT = '_kf_reason_for_visit';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register REST routes for the admin calendar.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_calendar' ),
				'permission_callback' => array( __CLASS__, 'permissions' ),
				'args'                => array(
					'start_date'   => array(
						'description' => __( 'Start of range (Y-m-d, UTC date).', 'kennelflow-core' ),
						'type'        => 'string',
						'required'    => true,
						'format'      => 'date',
					),
					'end_date'     => array(
						'description' => __( 'End of range (Y-m-d, UTC date).', 'kennelflow-core' ),
						'type'        => 'string',
						'required'    => true,
						'format'      => 'date',
					),
					'booking_kind' => array(
						'description'       => __( 'Optional: only return bookings with this booking_kind (e.g. grooming).', 'kennelflow-core' ),
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/calendar/booking/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'patch_booking' ),
				'permission_callback' => array( __CLASS__, 'permissions' ),
				'args'                => array(
					'id' => array(
						'description' => __( 'kf_bookings row id.', 'kennelflow-core' ),
						'type'        => 'integer',
						'required'    => true,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/calendar/booking/(?P<id>\d+)/check-in',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'check_in_booking' ),
				'permission_callback' => array( __CLASS__, 'permissions_check_in_booking' ),
				'args'                => array(
					'id' => array(
						'description' => __( 'kf_bookings row id.', 'kennelflow-core' ),
						'type'        => 'integer',
						'required'    => true,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/bookings/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'proxy_get_booking' ),
					'permission_callback' => array( __CLASS__, 'permissions' ),
					'args'                => array(
						'id' => array(
							'description' => __( 'Booking post ID (`kennelpress_booking`).', 'kennelflow-core' ),
							'type'        => 'integer',
							'required'    => true,
						),
					),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( __CLASS__, 'proxy_patch_booking' ),
					'permission_callback' => array( __CLASS__, 'permissions_edit_booking_post' ),
					'args'                => array_merge(
						array(
							'id' => array(
								'description' => __( 'Booking post ID (`kennelpress_booking`).', 'kennelflow-core' ),
								'type'        => 'integer',
								'required'    => true,
							),
						),
						self::get_hub_booking_context_meta_args()
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/bookings',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'proxy_create_booking' ),
					'permission_callback' => array( __CLASS__, 'permissions' ),
					'args'                => self::get_hub_booking_context_meta_args(),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/clinicians/(?P<id>\d+)/location-roster',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_clinician_location_roster' ),
				'permission_callback' => array( __CLASS__, 'permissions' ),
				'args'                => array(
					'id' => array(
						'description' => __( 'WordPress user ID (clinician).', 'kennelflow-core' ),
						'type'        => 'integer',
						'required'    => true,
					),
				),
			)
		);
	}

	/**
	 * Staff who can see the calendar.
	 *
	 * @return bool
	 */
	public static function permissions() {
		return ltkf_user_can_view_hub_calendar();
	}

	/**
	 * May edit the booking post (PATCH Hub booking).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function permissions_edit_booking_post( $request ) {
		$id = isset( $request['id'] ) ? absint( $request['id'] ) : 0;
		if ( $id < 1 ) {
			return false;
		}
		return current_user_can( 'edit_post', $id );
	}

	/**
	 * May check in a calendar booking (view calendar + edit linked appointment post).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function permissions_check_in_booking( $request ) {
		if ( ! self::permissions() ) {
			return false;
		}

		$table = ltkf_bookings_table_name();
		if ( ! is_string( $table ) || ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) || ! ltkf_table_exists( $table ) ) {
			return false;
		}

		$id = isset( $request['id'] ) ? absint( $request['id'] ) : 0;
		if ( $id < 1 ) {
			return false;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- REST permission gate.
		$post_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT post_id FROM %i WHERE id = %d LIMIT 1',
				$table,
				$id
			)
		);

		if ( $post_id < 1 ) {
			return false;
		}

		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * GET /kennelflow/v1/clinicians/{id}/location-roster
	 *
	 * Staff-only: `_kf_location_roster` for the booking modal (default location + mismatch warning).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_clinician_location_roster( $request ) {
		$user_id = absint( $request->get_param( 'id' ) );
		if ( $user_id < 1 || ! get_userdata( $user_id ) ) {
			return new \WP_Error(
				'ltkf_invalid_user',
				__( 'Invalid user.', 'kennelflow-core' ),
				array( 'status' => 400 )
			);
		}

		$meta_key = class_exists( 'AdminClinicianProfiles' )
			? AdminClinicianProfiles::META_LOCATION_ROSTER
			: '_kf_location_roster';

		$raw = get_user_meta( $user_id, $meta_key, true );
		if ( ! is_array( $raw ) ) {
			return rest_ensure_response( array( 'roster' => (object) array() ) );
		}

		$out = array();
		foreach ( $raw as $loc_key => $days ) {
			$loc_id = absint( $loc_key );
			if ( $loc_id < 1 || ! is_array( $days ) ) {
				continue;
			}
			$out[ (string) $loc_id ] = $days;
		}

		return rest_ensure_response( array( 'roster' => $out ) );
	}

	/**
	 * Request schema: contextual booking meta (sanitized as textarea).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected static function get_hub_booking_context_meta_args() {
		return array(
			self::META_GROOMING_STYLE_NOTES => array(
				'description'       => __( 'Grooming style notes (post meta).', 'kennelflow-core' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => array( __CLASS__, 'sanitize_hub_booking_textarea' ),
			),
			self::META_COAT_CONDITION       => array(
				'description'       => __( 'Coat condition (post meta).', 'kennelflow-core' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => array( __CLASS__, 'sanitize_hub_booking_textarea' ),
			),
			self::META_REASON_FOR_VISIT     => array(
				'description'       => __( 'Reason for visit (post meta).', 'kennelflow-core' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => array( __CLASS__, 'sanitize_hub_booking_textarea' ),
			),
		);
	}

	/**
	 * Sanitize Hub booking textarea meta.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_hub_booking_textarea( $value ) {
		return sanitize_textarea_field( wp_unslash( (string) $value ) );
	}

	/**
	 * Map Hub `_kf_*` keys to Kennel Press REST body keys (same post meta on save).
	 *
	 * @param array<string, mixed> $params Request params.
	 * @return array<string, mixed>
	 */
	protected static function map_hub_prefixed_meta_to_kennelpress_keys( array $params ) {
		$map = array(
			self::META_GROOMING_STYLE_NOTES => 'grooming_style_notes',
			self::META_COAT_CONDITION       => 'coat_condition',
			self::META_REASON_FOR_VISIT     => 'reason_for_visit',
		);
		foreach ( $map as $hub_key => $kp_key ) {
			if ( array_key_exists( $hub_key, $params ) ) {
				$params[ $kp_key ] = self::sanitize_hub_booking_textarea( $params[ $hub_key ] );
				unset( $params[ $hub_key ] );
			}
		}
		return $params;
	}

	/**
	 * Add `_kf_*` keys to booking payload for Hub clients (edit modal).
	 *
	 * @param array<string, mixed> $data Kennel Press booking array.
	 * @return array<string, mixed>
	 */
	protected static function enrich_booking_response_with_hub_meta_keys( array $data ) {
		$data[ self::META_GROOMING_STYLE_NOTES ] = isset( $data['grooming_style_notes'] ) ? (string) $data['grooming_style_notes'] : '';
		$data[ self::META_COAT_CONDITION ]       = isset( $data['coat_condition'] ) ? (string) $data['coat_condition'] : '';
		$data[ self::META_REASON_FOR_VISIT ]     = isset( $data['reason_for_visit'] ) ? (string) $data['reason_for_visit'] : '';
		return $data;
	}

	/**
	 * Add Hub `_kf_*` keys to a Kennel Press booking REST response.
	 *
	 * @param WP_REST_Response|WP_Error $response Response from Kennel Press.
	 * @return WP_REST_Response|WP_Error
	 */
	protected static function maybe_enrich_booking_rest_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! $response instanceof \WP_REST_Response ) {
			return $response;
		}
		$data = $response->get_data();
		if ( ! is_array( $data ) || ! isset( $data['id'] ) ) {
			return $response;
		}
		$response->set_data( self::enrich_booking_response_with_hub_meta_keys( $data ) );
		return $response;
	}

	/**
	 * KennelFlow Boarding REST path for booking CRUD proxy (legacy kennelpress/v1 alias also registered).
	 *
	 * @param string $suffix Path suffix after /bookings (e.g. '' or '/123').
	 * @return string REST route path beginning with /.
	 */
	protected static function boarding_rest_bookings_path( $suffix = '' ) {
		$base = defined( 'KENNELFLOW_BOARDING_VERSION' ) ? 'kennelflow-boarding/v1' : 'kennelpress/v1';
		return '/' . $base . '/bookings' . $suffix;
	}

	/**
	 * GET kennelflow/v1/bookings/{id} — single booking (Hub meta keys included).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function proxy_get_booking( $request ) {
		if ( ! class_exists( '\KennelFlow_Boarding_REST_Bookings_Controller' ) ) {
			return new \WP_Error(
				'ltkf_kennelpress_required',
				__( 'Kennel Press must be active to load bookings.', 'kennelflow-core' ),
				array( 'status' => 503 )
			);
		}

		$id  = absint( $request['id'] );
		$sub = new \WP_REST_Request( 'GET', self::boarding_rest_bookings_path( '/' . $id ) );
		$sub->set_param( 'id', $id );

		return self::maybe_enrich_booking_rest_response( rest_do_request( $sub ) );
	}

	/**
	 * PATCH kennelflow/v1/bookings/{id} — update booking (contextual meta via `_kf_*` or Kennel Press keys).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function proxy_patch_booking( $request ) {
		if ( ! class_exists( '\KennelFlow_Boarding_REST_Bookings_Controller' ) ) {
			return new \WP_Error(
				'ltkf_kennelpress_required',
				__( 'Kennel Press must be active to update bookings.', 'kennelflow-core' ),
				array( 'status' => 503 )
			);
		}

		$id  = absint( $request['id'] );
		$sub = new \WP_REST_Request( 'PATCH', self::boarding_rest_bookings_path( '/' . $id ) );
		$sub->set_param( 'id', $id );

		$params = $request->get_params();
		unset( $params['id'] );
		$params = self::map_hub_prefixed_meta_to_kennelpress_keys( $params );

		foreach ( $params as $key => $value ) {
			$sub->set_param( $key, $value );
		}

		return self::maybe_enrich_booking_rest_response( rest_do_request( $sub ) );
	}

	/**
	 * POST kennelflow/v1/bookings — Omni-Booking: forward body to Kennel Press booking create.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function proxy_create_booking( $request ) {
		if ( ! class_exists( '\KennelFlow_Boarding_REST_Bookings_Controller' ) ) {
			return new \WP_Error(
				'ltkf_kennelpress_required',
				__( 'Kennel Press must be active to create bookings.', 'kennelflow-core' ),
				array( 'status' => 503 )
			);
		}

		$params = $request->get_params();
		$params = self::map_hub_prefixed_meta_to_kennelpress_keys( $params );

		$sub = new \WP_REST_Request( 'POST', self::boarding_rest_bookings_path() );
		foreach ( $params as $key => $value ) {
			$sub->set_param( $key, $value );
		}

		return self::maybe_enrich_booking_rest_response( rest_do_request( $sub ) );
	}

	/**
	 * GET kennelflow/v1/calendar
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_calendar( $request ) {
		$table = ltkf_bookings_table_name();
		if ( ! is_string( $table ) || ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) || ! ltkf_table_exists( $table ) ) {
			return new \WP_Error(
				'ltkf_no_bookings_table',
				__( 'Bookings table is not available.', 'kennelflow-core' ),
				array( 'status' => 503 )
			);
		}

		$start_date = sanitize_text_field( (string) $request->get_param( 'start_date' ) );
		$end_date   = sanitize_text_field( (string) $request->get_param( 'end_date' ) );

		if ( ! self::is_ymd( $start_date ) || ! self::is_ymd( $end_date ) ) {
			return new \WP_Error(
				'ltkf_invalid_dates',
				__( 'start_date and end_date must be Y-m-d.', 'kennelflow-core' ),
				array( 'status' => 400 )
			);
		}

		if ( strcmp( $end_date, $start_date ) < 0 ) {
			return new \WP_Error(
				'ltkf_invalid_range',
				__( 'end_date must be on or after start_date.', 'kennelflow-core' ),
				array( 'status' => 400 )
			);
		}

		$max_days = (int) apply_filters( 'ltkf_calendar_api_max_range_days', 120 );
		if ( $max_days < 1 ) {
			$max_days = 120;
		}

		try {
			$start_d = new \DateTimeImmutable( $start_date . ' 00:00:00', new \DateTimeZone( 'UTC' ) );
			$end_d   = new \DateTimeImmutable( $end_date . ' 00:00:00', new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			unset( $e );
			return new \WP_Error(
				'ltkf_invalid_dates',
				__( 'Could not parse date range.', 'kennelflow-core' ),
				array( 'status' => 400 )
			);
		}

		$diff_days = (int) $start_d->diff( $end_d )->format( '%a' );
		if ( $diff_days > $max_days ) {
			return new \WP_Error(
				'ltkf_range_too_large',
				sprintf(
					/* translators: %d: max days */
					__( 'Date range cannot exceed %d days.', 'kennelflow-core' ),
					$max_days
				),
				array( 'status' => 400 )
			);
		}

		$window_start = $start_d->format( 'Y-m-d H:i:s' );
		$window_end   = $end_d->modify( '+1 day' )->format( 'Y-m-d H:i:s' );

		$booking_kind_filter = sanitize_key( (string) $request->get_param( 'booking_kind' ) );

		$rows = self::query_bookings( $table, $window_start, $window_end, $booking_kind_filter );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$bookings = array();
		foreach ( $rows as $row ) {
			$bookings[] = array(
				'id'              => isset( $row->id ) ? (int) $row->id : 0,
				'booking_post_id' => isset( $row->post_id ) ? (int) $row->post_id : 0,
				'pet_id'          => isset( $row->pet_id ) ? (int) $row->pet_id : 0,
				'pet_name'        => isset( $row->pet_name ) ? (string) $row->pet_name : '',
				'owner_name'      => isset( $row->owner_name ) ? (string) $row->owner_name : '',
				'owner_user_id'   => isset( $row->owner_user_id ) ? (int) $row->owner_user_id : 0,
				'start_gmt'       => isset( $row->start_gmt ) ? (string) $row->start_gmt : '',
				'end_gmt'         => isset( $row->end_gmt ) ? (string) $row->end_gmt : '',
				'resource_id'     => isset( $row->resource_id ) ? (int) $row->resource_id : 0,
				'status'          => isset( $row->status ) ? (string) $row->status : '',
				'booking_kind'    => isset( $row->booking_kind ) ? (string) $row->booking_kind : '',
			);
		}

		/**
		 * Filters calendar REST items before response.
		 *
		 * @since 0.2.6
		 *
		 * @param array[]         $bookings Normalized booking arrays.
		 * @param object[]        $rows     Raw query rows.
		 * @param WP_REST_Request $request  Request.
		 */
		$bookings = apply_filters( 'ltkf_rest_calendar_bookings', $bookings, $rows, $request );

		/**
		 * Optional Y-axis resources (e.g. groomer user rows). Return null to omit.
		 *
		 * @since 0.2.7
		 *
		 * @param null|array[]  $resources Null or list of [ 'id' => int, 'title' => string ].
		 * @param array[]         $bookings Normalized booking arrays.
		 * @param WP_REST_Request $request  Request.
		 */
		$resources = apply_filters( 'ltkf_rest_calendar_resources', null, $bookings, $request );

		$response = array(
			'bookings'      => $bookings,
			'start_date'    => $start_date,
			'end_date'      => $end_date,
			'generated_gmt' => gmdate( 'Y-m-d H:i:s' ),
		);

		if ( null !== $resources && is_array( $resources ) ) {
			$response['resources'] = $resources;
		}

		return rest_ensure_response( $response );
	}

	/**
	 * POST kennelflow/v1/calendar/booking/{id}/check-in
	 *
	 * Sets the linked appointment post to `checked_in` and refreshes index rows.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function check_in_booking( $request ) {
		$table = ltkf_bookings_table_name();
		if ( ! is_string( $table ) || ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) || ! ltkf_table_exists( $table ) ) {
			return new \WP_Error(
				'ltkf_no_bookings_table',
				__( 'Bookings table is not available.', 'kennelflow-core' ),
				array( 'status' => 503 )
			);
		}

		$id = absint( $request['id'] );
		if ( $id < 1 ) {
			return new \WP_Error(
				'ltkf_invalid_id',
				__( 'Invalid booking id.', 'kennelflow-core' ),
				array( 'status' => 400 )
			);
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- REST load row.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d LIMIT 1',
				$table,
				$id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $row || ! isset( $row->post_id ) ) {
			return new \WP_Error(
				'ltkf_booking_not_found',
				__( 'Booking not found.', 'kennelflow-core' ),
				array( 'status' => 404 )
			);
		}

		$post_id = absint( $row->post_id );
		if ( $post_id < 1 ) {
			return new \WP_Error(
				'ltkf_no_booking_post',
				__( 'This calendar row is not linked to an appointment record.', 'kennelflow-core' ),
				array( 'status' => 400 )
			);
		}

		$booking_item = self::get_booking_calendar_item( $table, $id );
		if ( null === $booking_item ) {
			return new \WP_Error(
				'ltkf_booking_load_failed',
				__( 'Booking could not be loaded.', 'kennelflow-core' ),
				array( 'status' => 500 )
			);
		}

		if ( ! ltkf_calendar_booking_can_check_in( $booking_item ) ) {
			return new \WP_Error(
				'ltkf_check_in_not_allowed',
				__( 'This appointment cannot be checked in from its current status.', 'kennelflow-core' ),
				array( 'status' => 409 )
			);
		}

		$updated = ltkf_update_booking_post_status( $post_id, 'checked_in' );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$booking = self::get_booking_calendar_item( $table, $id );
		if ( null === $booking ) {
			$booking = self::get_booking_calendar_item( $table, $post_id, 'post_id' );
		}
		if ( null === $booking ) {
			return new \WP_Error(
				'ltkf_booking_load_failed',
				__( 'Booking was checked in but could not be reloaded.', 'kennelflow-core' ),
				array( 'status' => 500 )
			);
		}

		/**
		 * Fires after a calendar booking is checked in via REST.
		 *
		 * @since 0.3.16
		 *
		 * @param array           $booking Normalized booking row.
		 * @param WP_REST_Request $request Request.
		 * @param int             $post_id Appointment post ID.
		 */
		do_action( 'ltkf_rest_calendar_booking_checked_in', $booking, $request, $post_id );

		return rest_ensure_response(
			array(
				'ok'      => true,
				'status'  => $updated,
				'booking' => $booking,
			)
		);
	}

	/**
	 * PATCH kennelflow/v1/calendar/booking/{id}
	 *
	 * Updates kennel_id (resource), start_gmt, end_gmt. Blocks overlap with other
	 * `confirmed` rows on the same kennel (409 Conflict).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function patch_booking( $request ) {
		$table = ltkf_bookings_table_name();
		if ( ! is_string( $table ) || ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) || ! ltkf_table_exists( $table ) ) {
			return new \WP_Error(
				'ltkf_no_bookings_table',
				__( 'Bookings table is not available.', 'kennelflow-core' ),
				array( 'status' => 503 )
			);
		}

		$id = absint( $request['id'] );
		if ( $id < 1 ) {
			return new \WP_Error(
				'ltkf_invalid_id',
				__( 'Invalid booking id.', 'kennelflow-core' ),
				array( 'status' => 400 )
			);
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$data = array();
		$has  = false;

		if ( array_key_exists( 'resource_id', $params ) ) {
			$data['kennel_id'] = absint( $params['resource_id'] );
			$has               = true;
		}

		if ( array_key_exists( 'start_gmt', $params ) ) {
			$g = self::parse_to_mysql_gmt( $params['start_gmt'] );
			if ( null === $g ) {
				return new \WP_Error(
					'ltkf_invalid_start',
					__( 'Invalid start_gmt.', 'kennelflow-core' ),
					array( 'status' => 400 )
				);
			}
			$data['start_gmt'] = $g;
			$has               = true;
		}

		if ( array_key_exists( 'end_gmt', $params ) ) {
			$g = self::parse_to_mysql_gmt( $params['end_gmt'] );
			if ( null === $g ) {
				return new \WP_Error(
					'ltkf_invalid_end',
					__( 'Invalid end_gmt.', 'kennelflow-core' ),
					array( 'status' => 400 )
				);
			}
			$data['end_gmt'] = $g;
			$has             = true;
		}

		if ( ! $has ) {
			return new \WP_Error(
				'ltkf_no_fields',
				__( 'No updatable fields provided.', 'kennelflow-core' ),
				array( 'status' => 400 )
			);
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- REST load row; `%i` validated above.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d LIMIT 1',
				$table,
				$id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $row || ! isset( $row->id ) ) {
			return new \WP_Error(
				'ltkf_booking_not_found',
				__( 'Booking not found.', 'kennelflow-core' ),
				array( 'status' => 404 )
			);
		}

		$merged_kennel = array_key_exists( 'kennel_id', $data )
			? (int) $data['kennel_id']
			: ( isset( $row->kennel_id ) ? (int) $row->kennel_id : 0 );

		$merged_start = array_key_exists( 'start_gmt', $data )
			? (string) $data['start_gmt']
			: ( isset( $row->start_gmt ) ? (string) $row->start_gmt : '' );

		$merged_end = array_key_exists( 'end_gmt', $data )
			? (string) $data['end_gmt']
			: ( isset( $row->end_gmt ) ? (string) $row->end_gmt : '' );

		if ( '' === $merged_start || '' === $merged_end ) {
			return new \WP_Error(
				'ltkf_invalid_booking_window',
				__( 'Booking has invalid start or end times.', 'kennelflow-core' ),
				array( 'status' => 400 )
			);
		}

		if ( strcmp( $merged_end, $merged_start ) <= 0 ) {
			return new \WP_Error(
				'ltkf_invalid_range',
				__( 'End time must be after start time.', 'kennelflow-core' ),
				array( 'status' => 400 )
			);
		}

		$overlap_kind = isset( $row->booking_kind ) ? (string) $row->booking_kind : '';

		$overlap = self::has_confirmed_kennel_overlap(
			$table,
			$id,
			$merged_kennel,
			$merged_start,
			$merged_end,
			$overlap_kind
		);
		if ( is_wp_error( $overlap ) ) {
			return $overlap;
		}
		if ( $overlap ) {
			return new \WP_Error(
				'ltkf_booking_kennel_conflict',
				__( 'That kennel already has a confirmed booking in this time range.', 'kennelflow-core' ),
				array( 'status' => 409 )
			);
		}

		$has_k = array_key_exists( 'kennel_id', $data );
		$has_s = array_key_exists( 'start_gmt', $data );
		$has_e = array_key_exists( 'end_gmt', $data );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- REST PATCH; whitelisted columns; `%i` validated; static SET templates per branch.
		if ( $has_k && $has_s && $has_e ) {
			$updated = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET `kennel_id` = %d, `start_gmt` = %s, `end_gmt` = %s WHERE `id` = %d',
					$table,
					absint( $data['kennel_id'] ),
					$data['start_gmt'],
					$data['end_gmt'],
					$id
				)
			);
		} elseif ( $has_k && $has_s ) {
			$updated = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET `kennel_id` = %d, `start_gmt` = %s WHERE `id` = %d',
					$table,
					absint( $data['kennel_id'] ),
					$data['start_gmt'],
					$id
				)
			);
		} elseif ( $has_k && $has_e ) {
			$updated = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET `kennel_id` = %d, `end_gmt` = %s WHERE `id` = %d',
					$table,
					absint( $data['kennel_id'] ),
					$data['end_gmt'],
					$id
				)
			);
		} elseif ( $has_s && $has_e ) {
			$updated = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET `start_gmt` = %s, `end_gmt` = %s WHERE `id` = %d',
					$table,
					$data['start_gmt'],
					$data['end_gmt'],
					$id
				)
			);
		} elseif ( $has_k ) {
			$updated = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET `kennel_id` = %d WHERE `id` = %d',
					$table,
					absint( $data['kennel_id'] ),
					$id
				)
			);
		} elseif ( $has_s ) {
			$updated = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET `start_gmt` = %s WHERE `id` = %d',
					$table,
					$data['start_gmt'],
					$id
				)
			);
		} elseif ( $has_e ) {
			$updated = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET `end_gmt` = %s WHERE `id` = %d',
					$table,
					$data['end_gmt'],
					$id
				)
			);
		} else {
			$updated = false;
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		if ( false === $updated ) {
			return new \WP_Error(
				'ltkf_booking_update_failed',
				__( 'Could not update booking.', 'kennelflow-core' ),
				array( 'status' => 500 )
			);
		}

		$booking = self::get_booking_calendar_item( $table, $id );
		if ( null === $booking ) {
			return new \WP_Error(
				'ltkf_booking_load_failed',
				__( 'Booking was updated but could not be reloaded.', 'kennelflow-core' ),
				array( 'status' => 500 )
			);
		}

		/**
		 * Filters a single calendar booking after PATCH (same shape as GET items).
		 *
		 * @since 0.2.6
		 *
		 * @param array           $booking    Normalized booking.
		 * @param WP_REST_Request $request    Request.
		 * @param object|null     $row_before Row from kf_bookings before UPDATE (for add-ons: detect resource_id changes).
		 */
		$booking = apply_filters( 'ltkf_rest_calendar_booking_patched', $booking, $request, $row );

		return rest_ensure_response(
			array(
				'ok'      => true,
				'booking' => $booking,
			)
		);
	}

	/**
	 * Whether another confirmed booking on the same resource overlaps [start, end).
	 *
	 * @param string $table          Bookings table name.
	 * @param int    $exclude_id     Booking id to exclude.
	 * @param int    $kennel_id      Kennel id, room id, or groomer user id (stored in kennel_id).
	 * @param string $start_gmt      Y-m-d H:i:s UTC.
	 * @param string $end_gmt        Y-m-d H:i:s UTC.
	 * @param string $booking_kind   Only rows with this exact booking_kind (avoids boarding vs grooming id collisions).
	 * @return bool|WP_Error True if overlap exists.
	 */
	protected static function has_confirmed_kennel_overlap( $table, $exclude_id, $kennel_id, $start_gmt, $end_gmt, $booking_kind = '' ) {
		global $wpdb;

		if ( ! is_string( $table ) || ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) || ! ltkf_table_exists( $table ) ) {
			return new \WP_Error(
				'ltkf_overlap_invalid_table',
				__( 'Could not verify kennel availability.', 'kennelflow-core' ),
				array( 'status' => 500 )
			);
		}

		$status = apply_filters( 'ltkf_calendar_patch_conflict_status', 'confirmed' );
		if ( ! is_string( $status ) || '' === $status ) {
			$status = 'confirmed';
		}

		$kind = is_string( $booking_kind ) ? $booking_kind : '';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- REST overlap; `%i` validated.
		$hit = $wpdb->get_var(
			$wpdb->prepare(
				'
				SELECT 1
				FROM %i
				WHERE id <> %d
				AND kennel_id = %d
				AND status = %s
				AND booking_kind = %s
				AND start_gmt < %s
				AND end_gmt > %s
				LIMIT 1
				',
				$table,
				$exclude_id,
				$kennel_id,
				$status,
				$kind,
				$end_gmt,
				$start_gmt
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		return (bool) $hit;
	}

	/**
	 * One booking row formatted like GET /calendar items (pet + owner names).
	 *
	 * @param string $table     Bookings table name.
	 * @param int    $id        Booking row id or post id (see $lookup_by).
	 * @param string $lookup_by `id` (default) or `post_id`.
	 * @return array|null
	 */
	protected static function get_booking_calendar_item( $table, $id, $lookup_by = 'id' ) {
		global $wpdb;

		if ( ! is_string( $table ) || ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) || ! ltkf_table_exists( $table ) ) {
			return null;
		}

		$id = absint( $id );
		if ( $id < 1 ) {
			return null;
		}

		$lookup_by = 'post_id' === $lookup_by ? 'post_id' : 'id';
		$where_sql = 'post_id' === $lookup_by ? 'b.post_id = %d' : 'b.id = %d';

		$pet_type  = ltkf_get_pet_post_type();
		$owner_key = ltkf_get_pet_owner_user_meta_key();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Single booking REST row; `%i` tables validated.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'
				SELECT
					b.id,
					b.post_id,
					b.pet_id,
					b.start_gmt,
					b.end_gmt,
					b.status,
					b.booking_kind,
					b.kennel_id AS resource_id,
					pet.post_title AS pet_name,
					owner.display_name AS owner_name,
					CAST( NULLIF( pm_owner.meta_value, \'\' ) AS UNSIGNED ) AS owner_user_id
				FROM %i AS b
				INNER JOIN %i AS pet
					ON pet.ID = b.pet_id
					AND pet.post_type = %s
					AND pet.post_status NOT IN ( \'trash\', \'auto-draft\' )
				LEFT JOIN %i AS pm_owner
					ON pm_owner.post_id = pet.ID
					AND pm_owner.meta_key = %s
				LEFT JOIN %i AS owner
					ON owner.ID = CAST( NULLIF( pm_owner.meta_value, \'\' ) AS UNSIGNED )
				WHERE ' . $where_sql . '
				LIMIT 1
				',
				$table,
				$wpdb->posts,
				$pet_type,
				$wpdb->postmeta,
				$owner_key,
				$wpdb->users,
				$id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $row ) {
			return null;
		}

		return self::normalize_booking_calendar_item( $row );
	}

	/**
	 * Format a kf_bookings query row for calendar REST consumers.
	 *
	 * @param object $row DB row.
	 * @return array<string, mixed>
	 */
	protected static function normalize_booking_calendar_item( $row ) {
		$booking = array(
			'id'              => isset( $row->id ) ? (int) $row->id : 0,
			'booking_post_id' => isset( $row->post_id ) ? (int) $row->post_id : 0,
			'pet_id'          => isset( $row->pet_id ) ? (int) $row->pet_id : 0,
			'pet_name'        => isset( $row->pet_name ) ? (string) $row->pet_name : '',
			'owner_name'      => isset( $row->owner_name ) ? (string) $row->owner_name : '',
			'owner_user_id'   => isset( $row->owner_user_id ) ? (int) $row->owner_user_id : 0,
			'start_gmt'       => isset( $row->start_gmt ) ? (string) $row->start_gmt : '',
			'end_gmt'         => isset( $row->end_gmt ) ? (string) $row->end_gmt : '',
			'resource_id'     => isset( $row->resource_id ) ? (int) $row->resource_id : 0,
			'status'          => isset( $row->status ) ? (string) $row->status : '',
			'booking_kind'    => isset( $row->booking_kind ) ? (string) $row->booking_kind : '',
		);

		if ( empty( $booking['owner_user_id'] ) && $booking['pet_id'] > 0 ) {
			$booking['owner_user_id'] = absint( ltkf_get_pet_owner_user_id( $booking['pet_id'] ) );
		}

		$booking['record_links'] = ltkf_get_calendar_booking_record_links( $booking );

		return $booking;
	}

	/**
	 * Parse ISO 8601 or MySQL datetime to UTC Y-m-d H:i:s.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	protected static function parse_to_mysql_gmt( $value ) {
		if ( null === $value || is_array( $value ) ) {
			return null;
		}
		$s = trim( (string) $value );
		if ( '' === $s ) {
			return null;
		}
		try {
			$d = new \DateTimeImmutable( $s );
			$d = $d->setTimezone( new \DateTimeZone( 'UTC' ) );
			return $d->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			unset( $e );
			return null;
		}
	}

	/**
	 * Whether the string is a valid calendar date (Y-m-d, UTC).
	 *
	 * @param string $ymd Date string.
	 * @return bool
	 */
	protected static function is_ymd( $ymd ) {
		if ( ! is_string( $ymd ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ymd ) ) {
			return false;
		}
		$ts = strtotime( $ymd . ' 00:00:00 UTC' );
		return false !== $ts && gmdate( 'Y-m-d', $ts ) === $ymd;
	}

	/**
	 * Overlap query: booking intersects [window_start, window_end) in GMT.
	 *
	 * @param string $table         Bookings table name.
	 * @param string $window_start  Inclusive Y-m-d H:i:s UTC.
	 * @param string $window_end       Exclusive Y-m-d H:i:s UTC.
	 * @param string $booking_kind     Optional: restrict to this booking_kind (empty = all kinds).
	 * @return object[]|WP_Error
	 */
	protected static function query_bookings( $table, $window_start, $window_end, $booking_kind = '' ) {
		global $wpdb;

		if ( ! is_string( $table ) || ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) || ! ltkf_table_exists( $table ) ) {
			return new \WP_Error(
				'ltkf_calendar_prepare_failed',
				__( 'Could not build calendar query.', 'kennelflow-core' ),
				array( 'status' => 500 )
			);
		}

		$pet_type  = ltkf_get_pet_post_type();
		$owner_key = ltkf_get_pet_owner_user_meta_key();

		$limit = (int) apply_filters( 'ltkf_calendar_api_max_rows', 5000 );
		if ( $limit < 1 ) {
			$limit = 5000;
		}
		if ( $limit > 20000 ) {
			$limit = 20000;
		}

		$kind = is_string( $booking_kind ) ? sanitize_key( $booking_kind ) : '';
		$kind = apply_filters( 'ltkf_rest_calendar_query_booking_kind', $kind, $table, $window_start, $window_end );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- REST calendar; `%i` validated; bounded LIMIT.
		if ( '' !== $kind ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'
					SELECT
						b.id,
						b.post_id,
						b.pet_id,
						b.start_gmt,
						b.end_gmt,
						b.status,
						b.booking_kind,
						b.kennel_id AS resource_id,
						pet.post_title AS pet_name,
						owner.display_name AS owner_name,
						CAST( NULLIF( pm_owner.meta_value, \'\' ) AS UNSIGNED ) AS owner_user_id
					FROM %i AS b
					INNER JOIN %i AS pet
						ON pet.ID = b.pet_id
						AND pet.post_type = %s
						AND pet.post_status NOT IN ( \'trash\', \'auto-draft\' )
					LEFT JOIN %i AS pm_owner
						ON pm_owner.post_id = pet.ID
						AND pm_owner.meta_key = %s
					LEFT JOIN %i AS owner
						ON owner.ID = CAST( NULLIF( pm_owner.meta_value, \'\' ) AS UNSIGNED )
					WHERE b.start_gmt < %s
					AND b.end_gmt > %s
					AND b.booking_kind = %s
					ORDER BY b.start_gmt ASC, b.id ASC
					LIMIT %d
					',
					$table,
					$wpdb->posts,
					$pet_type,
					$wpdb->postmeta,
					$owner_key,
					$wpdb->users,
					$window_end,
					$window_start,
					$kind,
					$limit
				)
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'
					SELECT
						b.id,
						b.post_id,
						b.pet_id,
						b.start_gmt,
						b.end_gmt,
						b.status,
						b.booking_kind,
						b.kennel_id AS resource_id,
						pet.post_title AS pet_name,
						owner.display_name AS owner_name,
						CAST( NULLIF( pm_owner.meta_value, \'\' ) AS UNSIGNED ) AS owner_user_id
					FROM %i AS b
					INNER JOIN %i AS pet
						ON pet.ID = b.pet_id
						AND pet.post_type = %s
						AND pet.post_status NOT IN ( \'trash\', \'auto-draft\' )
					LEFT JOIN %i AS pm_owner
						ON pm_owner.post_id = pet.ID
						AND pm_owner.meta_key = %s
					LEFT JOIN %i AS owner
						ON owner.ID = CAST( NULLIF( pm_owner.meta_value, \'\' ) AS UNSIGNED )
					WHERE b.start_gmt < %s
					AND b.end_gmt > %s
					ORDER BY b.start_gmt ASC, b.id ASC
					LIMIT %d
					',
					$table,
					$wpdb->posts,
					$pet_type,
					$wpdb->postmeta,
					$owner_key,
					$wpdb->users,
					$window_end,
					$window_start,
					$limit
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $rows ) ) {
			return new \WP_Error(
				'ltkf_calendar_db_error',
				__( 'Could not load calendar data.', 'kennelflow-core' ),
				array( 'status' => 500 )
			);
		}

		return $rows;
	}
}
