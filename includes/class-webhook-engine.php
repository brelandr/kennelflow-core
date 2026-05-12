<?php
/**
 * Outbound webhooks: queue JSON payloads to subscriber URLs (Action Scheduler).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class WebhookEngine
 */
class WebhookEngine {

	const OPTION_KEY = 'ltkf_webhook_endpoints';

	const DELIVER_HOOK = 'ltkf_webhook_deliver';

	const AS_GROUP = 'kennelflow';

	const EVENT_BOOKING_CREATED = 'booking_created';

	const EVENT_BOOKING_UPDATED = 'booking_updated';

	const EVENT_BOOKING_COMPLETED = 'booking_completed';

	const EVENT_PET_CREATED = 'pet_created';

	const EVENT_PET_UPDATED = 'pet_updated';

	/**
	 * Register hooks and AS worker.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'kennelpress_booking_saved', array( __CLASS__, 'on_booking_saved' ), 10, 3 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_wc_order_completed' ), 10, 1 );
		add_action( 'save_post_' . ltkf_get_pet_post_type(), array( __CLASS__, 'on_pet_saved' ), 10, 3 );
		add_action( self::DELIVER_HOOK, array( __CLASS__, 'deliver_webhook_async' ), 10, 1 );
	}

	/**
	 * Event slug => human label (admin + docs).
	 *
	 * @return array<string, string>
	 */
	public static function get_event_labels() {
		return array(
			self::EVENT_BOOKING_CREATED   => __( 'Booking created', 'kennelflow-core' ),
			self::EVENT_BOOKING_UPDATED   => __( 'Booking updated', 'kennelflow-core' ),
			self::EVENT_BOOKING_COMPLETED => __( 'Booking completed (order paid)', 'kennelflow-core' ),
			self::EVENT_PET_CREATED       => __( 'Pet profile created', 'kennelflow-core' ),
			self::EVENT_PET_UPDATED       => __( 'Pet profile updated', 'kennelflow-core' ),
		);
	}

	/**
	 * Stored endpoints (sanitized on save).
	 *
	 * @return array<int, array{url:string, events:string[]}>
	 */
	public static function get_endpoints() {
		$raw = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		return $raw;
	}

	/**
	 * KennelPress REST booking save.
	 *
	 * @param int                  $post_id Booking post ID.
	 * @param array<string, mixed> $params  Request params.
	 * @param string               $context create|update.
	 * @return void
	 */
	public static function on_booking_saved( $post_id, $params, $context ) {
		$post_id = absint( $post_id );
		if ( $post_id < 1 ) {
			return;
		}

		$event = ( 'create' === $context ) ? self::EVENT_BOOKING_CREATED : self::EVENT_BOOKING_UPDATED;

		$data = self::build_booking_payload( $post_id, $params );
		self::broadcast_event( $event, $data );
	}

	/**
	 * WooCommerce order completed: notify per linked booking.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public static function on_wc_order_completed( $order_id ) {
		if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Order' ) ) {
			return;
		}

		$order_id = absint( $order_id );
		if ( $order_id < 1 ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$meta_key = class_exists( 'Woocommerce' ) ? Woocommerce::ORDER_ITEM_META_BOOKING_ID : 'kf_booking_id';

		$booking_ids = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
				continue;
			}
			$bid = absint( $item->get_meta( $meta_key, true ) );
			if ( $bid > 0 ) {
				$booking_ids[] = $bid;
			}
		}

		$booking_ids = array_values( array_unique( array_map( 'absint', $booking_ids ) ) );
		if ( empty( $booking_ids ) ) {
			return;
		}

		$order_data = self::build_order_summary_for_webhook( $order );

		foreach ( $booking_ids as $booking_post_id ) {
			$data          = self::build_booking_payload( $booking_post_id, array() );
			$data['order'] = $order_data;
			self::broadcast_event( self::EVENT_BOOKING_COMPLETED, $data );
		}
	}

	/**
	 * Pet post saved (create/update).
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an update.
	 * @return void
	 */
	public static function on_pet_saved( $post_id, $post, $update ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! $post instanceof \WP_Post || ltkf_get_pet_post_type() !== $post->post_type ) {
			return;
		}

		$event = $update ? self::EVENT_PET_UPDATED : self::EVENT_PET_CREATED;
		$data  = self::build_pet_payload( $post_id );
		self::broadcast_event( $event, $data );
	}

	/**
	 * Build envelope and queue deliveries for subscribed URLs.
	 *
	 * @param string               $event Event slug.
	 * @param array<string, mixed> $data  Entity payload.
	 * @return void
	 */
	public static function broadcast_event( $event, $data ) {
		if ( ! is_string( $event ) || '' === $event ) {
			return;
		}

		$data = apply_filters( 'ltkf_webhook_payload_data', $data, $event );
		if ( ! is_array( $data ) ) {
			return;
		}

		$envelope = array(
			'event'       => $event,
			'site_url'    => home_url( '/' ),
			'occurred_at' => gmdate( 'c' ),
			'data'        => $data,
		);

		/**
		 * Filters the full outbound webhook envelope before JSON encoding.
		 *
		 * @since 0.2.0
		 *
		 * @param array<string, mixed> $envelope Full payload.
		 * @param string               $event    Event slug.
		 */
		$envelope = apply_filters( 'ltkf_webhook_envelope', $envelope, $event );
		if ( ! is_array( $envelope ) ) {
			return;
		}

		$body = wp_json_encode( $envelope );
		if ( false === $body ) {
			return;
		}

		$endpoints = self::get_endpoints();
		foreach ( $endpoints as $row ) {
			if ( ! is_array( $row ) || empty( $row['url'] ) || empty( $row['events'] ) || ! is_array( $row['events'] ) ) {
				continue;
			}
			if ( ! in_array( $event, array_map( 'strval', $row['events'] ), true ) ) {
				continue;
			}

			$url = esc_url_raw( $row['url'] );
			if ( '' === $url || ! wp_http_validate_url( $url ) ) {
				continue;
			}

			self::enqueue_delivery( $url, $event, $body );
		}
	}

	/**
	 * Queue one HTTP POST (or run synchronously if Action Scheduler is unavailable).
	 *
	 * @param string $url   Target URL.
	 * @param string $event Event slug (header).
	 * @param string $body  JSON string.
	 * @return void
	 */
	protected static function enqueue_delivery( $url, $event, $body ) {
		$args = array(
			'url'   => $url,
			'event' => $event,
			'body'  => $body,
		);

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action(
				self::DELIVER_HOOK,
				array( $args ),
				self::AS_GROUP
			);
			return;
		}

		self::deliver_http( $args );
	}

	/**
	 * Action Scheduler callback: POST JSON body.
	 *
	 * @param array<string, string> $args Delivery args.
	 * @return void
	 */
	public static function deliver_webhook_async( $args ) {
		if ( ! is_array( $args ) ) {
			return;
		}
		self::deliver_http( $args );
	}

	/**
	 * Perform wp_remote_post.
	 *
	 * @param array<string, string> $args url, event, body.
	 * @return void
	 */
	protected static function deliver_http( $args ) {
		$url = isset( $args['url'] ) ? esc_url_raw( $args['url'] ) : '';
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return;
		}

		$event = isset( $args['event'] ) ? sanitize_text_field( (string) $args['event'] ) : '';
		$body  = isset( $args['body'] ) ? $args['body'] : '';
		if ( ! is_string( $body ) || '' === $body ) {
			return;
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout'  => 15,
				'blocking' => true,
				'headers'  => array(
					'Content-Type'       => 'application/json; charset=' . get_bloginfo( 'charset' ),
					'X-KennelFlow-Event' => $event,
				),
				'body'     => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			/**
			 * Fires when a webhook endpoint returns a non-2xx status (for logging integrations).
			 *
			 * @since 0.2.0
			 *
			 * @param string               $url      URL.
			 * @param string               $event    Event slug.
			 * @param int                  $code     HTTP status.
			 * @param array<string, mixed> $response wp_remote_* response.
			 */
			do_action( 'ltkf_webhook_delivery_failed', $url, $event, (int) $code, $response );
		}
	}

	/**
	 * Booking entity for JSON (post + hub row).
	 *
	 * @param int                  $post_id Booking post ID.
	 * @param array<string, mixed> $params  Optional REST params from KennelPress.
	 * @return array<string, mixed>
	 */
	protected static function build_booking_payload( $post_id, $params ) {
		$post_id = absint( $post_id );
		$out     = array(
			'booking_post_id' => $post_id,
			'request_params'  => self::sanitize_request_params_for_webhook( $params ),
		);

		$post = get_post( $post_id );
		if ( $post instanceof \WP_Post ) {
			$out['post'] = array(
				'id'           => (int) $post->ID,
				'type'         => $post->post_type,
				'status'       => $post->post_status,
				'title'        => get_the_title( $post ),
				'modified_gmt' => $post->post_modified_gmt,
			);
		}

		$row = self::get_booking_row( $post_id );
		if ( null !== $row ) {
			$out['booking_row'] = self::object_to_array( $row );
		}

		return $out;
	}

	/**
	 * Strip or trim request params for outbound JSON.
	 *
	 * @param array<string, mixed> $params Raw params.
	 * @return array<string, mixed>
	 */
	protected static function sanitize_request_params_for_webhook( $params ) {
		if ( ! is_array( $params ) ) {
			return array();
		}
		$clean = map_deep( $params, 'sanitize_text_field' );
		return is_array( $clean ) ? $clean : array();
	}

	/**
	 * @param int $post_id kf_bookings.post_id.
	 * @return object|null
	 */
	protected static function get_booking_row( $post_id ) {
		$post_id = absint( $post_id );
		if ( $post_id < 1 ) {
			return null;
		}

		$table = ltkf_bookings_table_name();
		if ( ! ltkf_table_exists( $table ) ) {
			return null;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Single-row lookup for webhook payload.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE post_id = %d LIMIT 1", $post_id ) );

		return is_object( $row ) ? $row : null;
	}

	/**
	 * @param object $obj DB row.
	 * @return array<string, mixed>
	 */
	protected static function object_to_array( $obj ) {
		if ( ! is_object( $obj ) ) {
			return array();
		}
		$arr = get_object_vars( $obj );
		return is_array( $arr ) ? $arr : array();
	}

	/**
	 * Order summary for booking_completed payloads.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string, mixed>
	 */
	protected static function build_order_summary_for_webhook( $order ) {
		if ( ! class_exists( 'WC_Order' ) || ! $order instanceof \WC_Order ) {
			return array();
		}

		return array(
			'id'               => $order->get_id(),
			'order_key'        => $order->get_order_key(),
			'status'           => $order->get_status(),
			'currency'         => $order->get_currency(),
			'total'            => $order->get_total(),
			'date_created_gmt' => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : '',
		);
	}

	/**
	 * Pet entity for JSON.
	 *
	 * @param int $post_id Pet post ID.
	 * @return array<string, mixed>
	 */
	protected static function build_pet_payload( $post_id ) {
		$post_id = absint( $post_id );
		$out     = array(
			'pet_post_id' => $post_id,
		);

		$post = get_post( $post_id );
		if ( $post instanceof \WP_Post ) {
			$out['post'] = array(
				'id'           => (int) $post->ID,
				'type'         => $post->post_type,
				'status'       => $post->post_status,
				'title'        => get_the_title( $post ),
				'modified_gmt' => $post->post_modified_gmt,
			);
		}

		$owner_key   = ltkf_get_pet_owner_user_meta_key();
		$out['meta'] = array(
			$owner_key        => ltkf_get_pet_owner_user_id( $post_id ),
			'kf_allergies'    => ltkf_get_pet_care_defaults_allergies( $post_id ),
			'kf_default_diet' => get_post_meta( $post_id, ltkf_get_pet_meta_key_default_diet(), true ),
		);

		return $out;
	}
}
