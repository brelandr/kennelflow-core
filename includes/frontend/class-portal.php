<?php
/**
 * Shortcode [ltkf_dashboard] — owner portal (bookings + medical records).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class Portal
 */
class Portal {

	const SHORTCODE = 'ltkf_dashboard';

	/**
	 * Whether portal script was localized for this request.
	 *
	 * @var bool
	 */
	protected static $portal_script_localized = false;

	/**
	 * Register shortcode and assets.
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'wp_ajax_ltkf_portal_pay_booking', array( __CLASS__, 'ajax_pay_booking' ) );
		add_action( 'wp_ajax_ltkf_portal_pay_balance', array( __CLASS__, 'ajax_pay_balance' ) );
		add_action( 'wp_ajax_ltkf_waitlist_join', array( __CLASS__, 'ajax_waitlist_join' ) );
	}

	/**
	 * Register styles/scripts (enqueued when shortcode runs).
	 *
	 * @return void
	 */
	public static function register_assets() {
		wp_register_style(
			'kf-portal',
			LTKF_PLUGIN_URL . 'assets/css/kf-portal.css',
			array( 'kf-toast' ),
			LTKF_CORE_VERSION
		);
		wp_register_script(
			'signature-pad',
			'https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js',
			array(),
			'4.1.7',
			true
		);
		wp_register_script(
			'kf-portal',
			LTKF_PLUGIN_URL . 'assets/js/kf-portal.js',
			array( 'signature-pad', 'kf-toast' ),
			LTKF_CORE_VERSION,
			true
		);
	}

	/**
	 * Enqueue portal assets when rendering shortcode.
	 *
	 * @return void
	 */
	protected static function enqueue_assets() {
		wp_enqueue_style( 'kf-toast' );
		wp_enqueue_style( 'kf-portal' );
		wp_enqueue_script( 'kf-toast' );
		wp_enqueue_script( 'kf-portal' );
	}

	/**
	 * Localize script for Pay Now (once per request).
	 *
	 * @param string $portal_page_url Page with [ltkf_dashboard] (for waiver / checkout links).
	 * @return void
	 */
	protected static function localize_portal_script( $portal_page_url = '' ) {
		if ( self::$portal_script_localized ) {
			return;
		}
		self::$portal_script_localized = true;

		$portal_page_url = is_string( $portal_page_url ) ? $portal_page_url : '';
		if ( '' === $portal_page_url ) {
			$portal_page_url = home_url( '/' );
		}

		$user_id = get_current_user_id();
		$pet_ids = PortalData::get_owned_pet_ids_for_user( $user_id );
		$w_pets  = array();
		foreach ( $pet_ids as $pid ) {
			$signed = Waiver::pet_has_valid_waiver( $pid );
			$date   = (string) get_post_meta( $pid, Waiver::META_DATE, true );
			$label  = get_the_title( $pid );
			if ( '' === $label ) {
				$label = '#' . (int) $pid;
			}
			$date_out = '';
			if ( '' !== $date ) {
				$ts = strtotime( $date . ' GMT' );
				if ( false !== $ts ) {
					$date_out = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
				}
			}
			$w_pets[] = array(
				'id'         => (int) $pid,
				'label'      => $label,
				'signed'     => $signed,
				'signedDate' => $date_out,
			);
		}

		$waitlist_vars = array();
		if ( post_type_exists( 'kennelpress_booking' ) && ltkf_table_exists( ltkf_waitlist_table_name() ) ) {
			$waitlist_vars = array(
				'nonce'    => wp_create_nonce( 'ltkf_waitlist_join' ),
				'action'   => 'ltkf_waitlist_join',
				'restBase' => esc_url_raw( rest_url( 'kennelflow-boarding/v1/' ) ),
				'strings'  => array(
					'check'     => __( 'Check availability', 'kennelflow-core' ),
					'join'      => __( 'Join waitlist', 'kennelflow-core' ),
					'checking'  => __( 'Checking…', 'kennelflow-core' ),
					'joining'   => __( 'Joining…', 'kennelflow-core' ),
					'full'      => __( 'No kennels are available for those dates. You can join the waitlist to be notified if a spot opens.', 'kennelflow-core' ),
					'available' => __( 'Kennels are available for those dates — book through your facility or staff.', 'kennelflow-core' ),
					'joined'    => __( 'You are on the waitlist. We will email you if a spot opens.', 'kennelflow-core' ),
					'duplicate' => __( 'You already have a waitlist request for these dates.', 'kennelflow-core' ),
					'needDates' => __( 'Please choose a location, start, and end.', 'kennelflow-core' ),
					'error'     => __( 'Something went wrong. Please try again.', 'kennelflow-core' ),
					'network'   => __( 'Network error. Please try again.', 'kennelflow-core' ),
					'selectPet' => __( 'Pet', 'kennelflow-core' ),
					'selectLoc' => __( 'Location', 'kennelflow-core' ),
					'start'     => __( 'Start (local)', 'kennelflow-core' ),
					'end'       => __( 'End (local)', 'kennelflow-core' ),
				),
			);
		}

		$portal_vars = array(
			'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
			'nonce'            => wp_create_nonce( 'ltkf_portal_pay' ),
			'action'           => 'ltkf_portal_pay_booking',
			'balanceNonce'     => wp_create_nonce( 'ltkf_portal_pay_balance' ),
			'balanceAction'    => 'ltkf_portal_pay_balance',
			'checkoutUrl'      => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '',
			'portalPageUrl'    => esc_url_raw( $portal_page_url ),
			'waitlist'         => ! empty( $waitlist_vars ) ? $waitlist_vars : false,
			'waiver'           => array(
				'ajaxAction' => Waiver::AJAX_ACTION,
				'nonce'      => wp_create_nonce( Waiver::NONCE_ACTION ),
				'pets'       => $w_pets,
				'strings'    => array(
					'signed'      => __( 'Waiver on file', 'kennelflow-core' ),
					'signedOn'    => __( 'Signed:', 'kennelflow-core' ),
					'needSign'    => __( 'Please sign below to continue.', 'kennelflow-core' ),
					'emptySig'    => __( 'Draw your signature first.', 'kennelflow-core' ),
					'saving'      => __( 'Saving…', 'kennelflow-core' ),
					'saved'       => __( 'Waiver saved. Thank you.', 'kennelflow-core' ),
					'selectPet'   => __( 'Select a pet', 'kennelflow-core' ),
					'network'     => __( 'Network error. Please try again.', 'kennelflow-core' ),
					'invalidResp' => __( 'Unexpected response from server.', 'kennelflow-core' ),
				),
			),
			'strings'          => array(
				'error'      => __( 'Something went wrong. Please try again.', 'kennelflow-core' ),
				'paying'     => __( 'Redirecting…', 'kennelflow-core' ),
				'network'    => __( 'Network error. Please try again.', 'kennelflow-core' ),
				'payBalance' => __( 'Pay remaining balance', 'kennelflow-core' ),
			),
			'complianceUpload' => array(
				'restUrl'   => esc_url_raw( rest_url( 'kennelflow/v1/compliance/upload' ) ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'strings'   => array(
					'uploading'     => __( 'Uploading…', 'kennelflow-core' ),
					'uploadDoc'     => __( 'Upload Document', 'kennelflow-core' ),
					'pendingReview' => __( 'Pending Staff Review', 'kennelflow-core' ),
					'error'         => __( 'Upload failed. Please try again.', 'kennelflow-core' ),
					'network'       => __( 'Network error. Please try again.', 'kennelflow-core' ),
					'fileTooLarge'  => __( 'File must be 5 MB or smaller.', 'kennelflow-core' ),
					'fileType'      => __( 'Please choose a PDF, PNG, or JPEG file.', 'kennelflow-core' ),
				),
			),
		);

		/**
		 * Filters localized data for the [ltkf_dashboard] script (`kfPortalVars`).
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, mixed> $portal_vars Portal localization data.
		 */
		$portal_vars = apply_filters( 'ltkf_portal_localize_vars', $portal_vars );

		wp_localize_script(
			'kf-portal',
			'kfPortalVars',
			$portal_vars
		);
	}

	/**
	 * AJAX: clear KennelFlow cart lines, add booking, return checkout URL.
	 *
	 * @return void
	 */
	public static function ajax_pay_booking() {
		if ( ! check_ajax_referer( 'ltkf_portal_pay', '_wpnonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'kennelflow-core' ) ), 403 );
		}

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'kennelflow-core' ) ), 403 );
		}

		if ( ! isset( $_POST['booking_id'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing booking.', 'kennelflow-core' ) ), 400 );
		}

		$booking_id = absint( wp_unslash( $_POST['booking_id'] ) );
		if ( $booking_id < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid booking.', 'kennelflow-core' ) ), 400 );
		}

		$user_id = get_current_user_id();
		$row     = PortalData::get_booking_row_for_user( $booking_id, $user_id );
		if ( null === $row ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to pay for this booking.', 'kennelflow-core' ) ), 403 );
		}

		if ( 'pending_payment' !== (string) $row->status ) {
			wp_send_json_error( array( 'message' => __( 'This booking is not awaiting payment.', 'kennelflow-core' ) ), 400 );
		}

		if ( ! ltkf_compliance_gatekeeper_e2e_allow_noncompliant_checkout() && ltkf_booking_row_is_boarding_stay_for_vaccine_compliance( $row ) && ltkf_pet_requires_compliance_action( (int) $row->pet_id ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Payment is blocked until required vaccinations are valid. Please complete records under Vaccinations & records or contact your facility.', 'kennelflow-core' ),
				),
				400
			);
		}

		if ( ! function_exists( 'wc_get_checkout_url' ) ) {
			wp_send_json_error( array( 'message' => __( 'Checkout is not available.', 'kennelflow-core' ) ), 503 );
		}

		ltkf_clear_kennelflow_cart_items();

		$result = ltkf_add_booking_to_cart( $booking_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		if ( ! $result ) {
			wp_send_json_error( array( 'message' => __( 'Could not add booking to cart.', 'kennelflow-core' ) ), 400 );
		}

		wp_send_json_success(
			array(
				'checkout_url' => wc_get_checkout_url(),
			)
		);
	}

	/**
	 * AJAX: clear KennelFlow cart lines, add balance payment line, return checkout URL.
	 *
	 * @return void
	 */
	public static function ajax_pay_balance() {
		if ( ! check_ajax_referer( 'ltkf_portal_pay_balance', '_wpnonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'kennelflow-core' ) ), 403 );
		}

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'kennelflow-core' ) ), 403 );
		}

		if ( ! isset( $_POST['booking_id'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing booking.', 'kennelflow-core' ) ), 400 );
		}

		$booking_id = absint( wp_unslash( $_POST['booking_id'] ) );
		if ( $booking_id < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid booking.', 'kennelflow-core' ) ), 400 );
		}

		$user_id = get_current_user_id();
		$row     = PortalData::get_booking_row_for_user( $booking_id, $user_id );
		if ( null === $row ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to pay for this booking.', 'kennelflow-core' ) ), 403 );
		}

		if ( 'confirmed' !== (string) $row->status ) {
			wp_send_json_error( array( 'message' => __( 'This booking is not confirmed.', 'kennelflow-core' ) ), 400 );
		}

		$ctx = PortalData::get_unpaid_balance_context_for_booking( $booking_id, $user_id );
		if ( null === $ctx || empty( $ctx['order_id'] ) || ! isset( $ctx['balance'] ) || (float) $ctx['balance'] <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'No remaining balance is due for this booking.', 'kennelflow-core' ) ), 400 );
		}

		if ( ! ltkf_compliance_gatekeeper_e2e_allow_noncompliant_checkout() && ltkf_booking_row_is_boarding_stay_for_vaccine_compliance( $row ) && ltkf_pet_requires_compliance_action( (int) $row->pet_id ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Payment is blocked until required vaccinations are valid. Please complete records under Vaccinations & records or contact your facility.', 'kennelflow-core' ),
				),
				400
			);
		}

		if ( ! function_exists( 'wc_get_checkout_url' ) ) {
			wp_send_json_error( array( 'message' => __( 'Checkout is not available.', 'kennelflow-core' ) ), 503 );
		}

		ltkf_clear_kennelflow_cart_items();

		$result = ltkf_add_balance_to_cart( (int) $ctx['order_id'], (float) $ctx['balance'], $booking_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		if ( ! $result ) {
			wp_send_json_error( array( 'message' => __( 'Could not add balance payment to cart.', 'kennelflow-core' ) ), 400 );
		}

		wp_send_json_success(
			array(
				'checkout_url' => wc_get_checkout_url(),
			)
		);
	}

	/**
	 * AJAX: join waitlist when dates are full.
	 *
	 * @return void
	 */
	public static function ajax_waitlist_join() {
		if ( ! check_ajax_referer( 'ltkf_waitlist_join', '_wpnonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'kennelflow-core' ) ), 403 );
		}

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'kennelflow-core' ) ), 403 );
		}

		if ( ! ltkf_table_exists( ltkf_waitlist_table_name() ) || ! class_exists( 'Waitlist' ) ) {
			wp_send_json_error( array( 'message' => __( 'Waitlist is not available.', 'kennelflow-core' ) ), 503 );
		}

		$user_id = get_current_user_id();

		$pet_id = isset( $_POST['pet_id'] ) ? absint( wp_unslash( $_POST['pet_id'] ) ) : 0;
		if ( $pet_id < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Please choose a pet.', 'kennelflow-core' ) ), 400 );
		}

		$allowed = OwnerPets::get_pet_ids_for_user( $user_id );
		if ( ! in_array( $pet_id, $allowed, true ) ) {
			wp_send_json_error( array( 'message' => __( 'That pet is not linked to your account.', 'kennelflow-core' ) ), 403 );
		}

		$location_id = isset( $_POST['location_id'] ) ? absint( wp_unslash( $_POST['location_id'] ) ) : 0;
		if ( $location_id < 1 || ltkf_get_location_post_type() !== get_post_type( $location_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Please choose a valid location.', 'kennelflow-core' ) ), 400 );
		}

		$start_raw = isset( $_POST['start_gmt'] ) ? sanitize_text_field( wp_unslash( $_POST['start_gmt'] ) ) : '';
		$end_raw   = isset( $_POST['end_gmt'] ) ? sanitize_text_field( wp_unslash( $_POST['end_gmt'] ) ) : '';

		if ( ! class_exists( 'KennelFlow_Boarding_Availability' ) ) {
			wp_send_json_error( array( 'message' => __( 'Booking system is not available.', 'kennelflow-core' ) ), 503 );
		}

		$start_gmt = KennelFlow_Boarding_Availability::parse_gmt_mysql( $start_raw );
		if ( is_wp_error( $start_gmt ) ) {
			wp_send_json_error( array( 'message' => $start_gmt->get_error_message() ), 400 );
		}

		$end_gmt = KennelFlow_Boarding_Availability::parse_gmt_mysql( $end_raw );
		if ( is_wp_error( $end_gmt ) ) {
			wp_send_json_error( array( 'message' => $end_gmt->get_error_message() ), 400 );
		}

		$interval = KennelFlow_Boarding_Availability::validate_interval( $start_gmt, $end_gmt );
		if ( is_wp_error( $interval ) ) {
			wp_send_json_error( array( 'message' => $interval->get_error_message() ), 400 );
		}

		$available = KennelFlow_Boarding_Availability::get_available_kennel_ids( $location_id, $start_gmt, $end_gmt );
		if ( is_wp_error( $available ) ) {
			wp_send_json_error( array( 'message' => $available->get_error_message() ), 400 );
		}

		if ( ! empty( $available ) ) {
			wp_send_json_error( array( 'message' => __( 'Those dates still have availability — use the normal booking flow.', 'kennelflow-core' ) ), 409 );
		}

		if ( Waitlist::has_duplicate_waiting( $user_id, $pet_id, $location_id, $start_gmt, $end_gmt ) ) {
			wp_send_json_error( array( 'message' => __( 'You already have a waitlist request for these dates.', 'kennelflow-core' ) ), 409 );
		}

		$payload = wp_json_encode(
			array(
				'start_gmt' => $start_gmt,
				'end_gmt'   => $end_gmt,
				'location'  => $location_id,
			)
		);

		$id = Waitlist::insert_waiting( $user_id, $pet_id, $location_id, $start_gmt, $end_gmt, $payload ? $payload : '' );
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Could not save waitlist request.', 'kennelflow-core' ) ), 500 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'You are on the waitlist. We will email you if a spot opens.', 'kennelflow-core' ),
			)
		);
	}

	/**
	 * Compliance badges for each owned pet (Boarding tab).
	 *
	 * @param int[] $pet_ids Owned pet IDs.
	 * @return void
	 */
	protected static function render_pet_compliance_list( $pet_ids ) {
		if ( empty( $pet_ids ) ) {
			return;
		}

		echo '<h3 class="kf-portal__subheading">' . esc_html__( 'Your pets', 'kennelflow-core' ) . '</h3>';
		echo '<ul class="kf-portal__pet-compliance">';
		foreach ( $pet_ids as $pid ) {
			$pid   = absint( $pid );
			$title = get_the_title( $pid );
			if ( '' === $title ) {
				$title = '#' . (string) $pid;
			}
			$action_req  = ltkf_pet_requires_compliance_action( $pid );
			$badge_class = $action_req ? 'kf-portal__badge kf-portal__badge--action' : 'kf-portal__badge kf-portal__badge--healthy';
			$badge_text  = $action_req ? __( 'Action Required', 'kennelflow-core' ) : __( 'Healthy', 'kennelflow-core' );

			echo '<li class="kf-portal__pet-compliance-item" data-kf-pet-id="' . esc_attr( (string) $pid ) . '">';
			echo '<span class="kf-portal__pet-name">' . esc_html( $title ) . '</span> ';
			echo '<span class="' . esc_attr( $badge_class ) . '">' . esc_html( $badge_text ) . '</span>';

			if ( $action_req && class_exists( 'ComplianceRulesEngine' ) ) {
				$vaccines = ltkf_get_portal_pet_compliance_vaccines( $pid );
				$missing  = array();
				$expired  = array();
				foreach ( $vaccines as $vrow ) {
					if ( ! is_array( $vrow ) || empty( $vrow['state'] ) || empty( $vrow['label'] ) ) {
						continue;
					}
					if ( 'missing' === $vrow['state'] ) {
						$missing[] = $vrow['label'];
					}
					if ( 'expired' === $vrow['state'] ) {
						$expired[] = $vrow['label'];
					}
				}

				$summary_parts = array();
				if ( ! empty( $missing ) ) {
					$summary_parts[] = sprintf(
						/* translators: %s: comma-separated vaccine names */
						__( 'Missing: %s', 'kennelflow-core' ),
						implode( ', ', $missing )
					);
				}
				if ( ! empty( $expired ) ) {
					$summary_parts[] = sprintf(
						/* translators: %s: comma-separated vaccine names */
						__( 'Expired: %s', 'kennelflow-core' ),
						implode( ', ', $expired )
					);
				}

				$has_issue_rows = false;
				foreach ( $vaccines as $vrow ) {
					if ( ! empty( $vrow['state'] ) && in_array( $vrow['state'], array( 'missing', 'expired', 'pending_review' ), true ) ) {
						$has_issue_rows = true;
						break;
					}
				}

				if ( ! empty( $summary_parts ) || $has_issue_rows ) {
					echo '<div class="kf-portal__compliance-detail" data-kf-compliance-detail>';

					if ( ! empty( $summary_parts ) ) {
						echo '<p class="kf-portal__compliance-summary">' . esc_html( implode( ' · ', $summary_parts ) ) . '</p>';
					} elseif ( $has_issue_rows ) {
						echo '<p class="kf-portal__compliance-summary">' . esc_html__( 'Documents awaiting staff review before booking.', 'kennelflow-core' ) . '</p>';
					}

					echo '<ul class="kf-portal__compliance-vaccines">';
					foreach ( $vaccines as $vrow ) {
						if ( ! is_array( $vrow ) || empty( $vrow['state'] ) || empty( $vrow['label'] ) || empty( $vrow['norm'] ) ) {
							continue;
						}
						if ( 'valid' === $vrow['state'] ) {
							continue;
						}
						$norm  = (string) $vrow['norm'];
						$label = $vrow['label'];
						echo '<li class="kf-portal__compliance-vaccine-row" data-kf-vaccine-norm="' . esc_attr( $norm ) . '" data-kf-vaccine-label="' . esc_attr( $label ) . '">';
						echo '<span class="kf-portal__compliance-vaccine-name">' . esc_html( $label ) . '</span> ';
						if ( 'pending_review' === $vrow['state'] ) {
							echo '<span class="kf-portal__badge kf-portal__badge--pending">' . esc_html__( 'Pending Staff Review', 'kennelflow-core' ) . '</span>';
						} else {
							echo '<span class="kf-portal__compliance-vaccine-actions">';
							echo '<button type="button" class="button kf-portal__btn-upload" data-kf-compliance-upload-trigger>' . esc_html__( 'Upload Document', 'kennelflow-core' ) . '</button>';
							echo '<input type="file" class="kf-portal__compliance-file" accept=".pdf,image/png,image/jpeg" tabindex="-1" aria-hidden="true" />';
							echo '</span>';
						}
						echo '</li>';
					}
					echo '</ul>';

					echo '</div>';
				}
			}

			echo '</li>';
		}
		echo '</ul>';
	}

	/**
	 * “Book a stay” + waitlist when Kennel Press is active.
	 *
	 * @param int[] $pet_ids Owned pet IDs.
	 * @return void
	 */
	protected static function render_waitlist_booking_section( $pet_ids ) {
		if ( ! ltkf_table_exists( ltkf_waitlist_table_name() ) ) {
			return;
		}

		if ( ! post_type_exists( 'kennelpress_booking' ) || ! class_exists( 'KennelFlow_Boarding_Availability' ) ) {
			return;
		}

		$locations = ltkf_get_cached_portal_location_rows();
		if ( empty( $locations ) ) {
			return;
		}

		echo '<div class="kf-portal__waitlist" data-kf-waitlist>';

		echo '<h3 class="kf-portal__subheading">' . esc_html__( 'Book a stay', 'kennelflow-core' ) . '</h3>';
		echo '<p class="kf-portal__hint">' . esc_html__( 'Check if kennels are free for your dates. If the facility is full, you can join the waitlist.', 'kennelflow-core' ) . '</p>';

		echo '<div class="kf-portal__waitlist-fields">';
		echo '<p><label for="kf-wl-pet">' . esc_html__( 'Pet', 'kennelflow-core' ) . '</label> ';
		echo '<select id="kf-wl-pet" data-kf-wl-pet required>';
		echo '<option value="">' . esc_html__( '— Select —', 'kennelflow-core' ) . '</option>';
		foreach ( $pet_ids as $pid ) {
			$pid          = absint( $pid );
			$needs_action = ltkf_pet_requires_compliance_action( $pid );
			$label        = get_the_title( $pid );
			if ( '' === $label ) {
				$label = '#' . (string) $pid;
			}
			if ( $needs_action ) {
				$label .= ' — ' . __( 'Action Required', 'kennelflow-core' );
			}
			echo '<option value="' . esc_attr( (string) $pid ) . '"' . ( $needs_action ? ' disabled="disabled"' : '' ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p>';

		echo '<p><label for="kf-wl-location">' . esc_html__( 'Location', 'kennelflow-core' ) . '</label> ';
		echo '<select id="kf-wl-location" data-kf-wl-location required>';
		echo '<option value="">' . esc_html__( '— Select —', 'kennelflow-core' ) . '</option>';
		foreach ( $locations as $loc ) {
			$loc_id    = isset( $loc['id'] ) ? absint( $loc['id'] ) : 0;
			$loc_title = isset( $loc['title'] ) ? (string) $loc['title'] : '';
			if ( $loc_id < 1 ) {
				continue;
			}
			echo '<option value="' . esc_attr( (string) $loc_id ) . '">' . esc_html( $loc_title ) . '</option>';
		}
		echo '</select></p>';

		echo '<p><label for="kf-wl-start">' . esc_html__( 'Start', 'kennelflow-core' ) . '</label> ';
		echo '<input type="datetime-local" id="kf-wl-start" data-kf-wl-start required /></p>';
		echo '<p><label for="kf-wl-end">' . esc_html__( 'End', 'kennelflow-core' ) . '</label> ';
		echo '<input type="datetime-local" id="kf-wl-end" data-kf-wl-end required /></p>';

		echo '<p><button type="button" class="button" data-kf-wl-check>' . esc_html__( 'Check availability', 'kennelflow-core' ) . '</button> ';
		echo '<button type="button" class="button button-primary" data-kf-wl-join hidden disabled>' . esc_html__( 'Join waitlist', 'kennelflow-core' ) . '</button></p>';
		echo '<p class="kf-portal__waitlist-msg" data-kf-wl-msg aria-live="polite"></p>';
		echo '</div></div>';
	}

	/**
	 * Shortcode output.
	 *
	 * @param string[] $atts Shortcode attributes (unused).
	 * @return string
	 */
	public static function render_shortcode( $atts ) {
		unset( $atts );

		if ( ! is_user_logged_in() ) {
			return sprintf(
				'<div class="kf-portal kf-portal--guest"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'Please log in to view your KennelFlow dashboard.', 'kennelflow-core' ),
				esc_url( wp_login_url( get_permalink() ) ),
				esc_html__( 'Log in', 'kennelflow-core' )
			);
		}

		self::enqueue_assets();
		$page_url = get_permalink() ? get_permalink() : home_url( '/' );
		self::localize_portal_script( $page_url );

		$user_id = get_current_user_id();
		$pet_ids = PortalData::get_owned_pet_ids_for_user( $user_id );

		$bookings     = PortalData::get_upcoming_boarding_for_pets( $pet_ids );
		$medical      = PortalData::get_medical_records_for_pets( $pet_ids );
		$show_pay_now = function_exists( 'wc_get_checkout_url' ) && class_exists( 'Woocommerce' );

		$vacc_rows  = array();
		$other_rows = array();
		foreach ( $medical as $mr ) {
			if ( PortalData::row_is_vaccination_like( $mr ) ) {
				$vacc_rows[] = $mr;
			} else {
				$other_rows[] = $mr;
			}
		}

		ob_start();
		?>
		<div class="kf-portal" data-kf-portal>
			<div class="kf-portal__tabs" role="tablist" aria-label="<?php esc_attr_e( 'Dashboard sections', 'kennelflow-core' ); ?>">
				<button type="button" class="kf-portal__tab is-active" role="tab" aria-selected="true" aria-controls="kf-tab-boarding" id="kf-tab-btn-boarding" data-kf-tab="boarding">
					<?php esc_html_e( 'Boarding reservations', 'kennelflow-core' ); ?>
				</button>
				<button type="button" class="kf-portal__tab" role="tab" aria-selected="false" aria-controls="kf-tab-medical" id="kf-tab-btn-medical" data-kf-tab="medical" tabindex="-1">
					<?php esc_html_e( 'Vaccinations & records', 'kennelflow-core' ); ?>
				</button>
				<button type="button" class="kf-portal__tab" role="tab" aria-selected="false" aria-controls="kf-tab-medications" id="kf-tab-btn-medications" data-kf-tab="medications" tabindex="-1">
					<?php esc_html_e( 'Medications', 'kennelflow-core' ); ?>
				</button>
				<button type="button" class="kf-portal__tab" role="tab" aria-selected="false" aria-controls="kf-tab-waivers" id="kf-tab-btn-waivers" data-kf-tab="waivers" tabindex="-1">
					<?php esc_html_e( 'Legal Waivers', 'kennelflow-core' ); ?>
				</button>
			</div>

			<div id="kf-tab-boarding" class="kf-portal__panel is-active" role="tabpanel" aria-labelledby="kf-tab-btn-boarding" data-kf-panel="boarding" aria-hidden="false">
				<?php self::render_bookings_panel( $bookings, $pet_ids, $show_pay_now, $user_id ); ?>
			</div>
			<div id="kf-tab-medical" class="kf-portal__panel" role="tabpanel" aria-labelledby="kf-tab-btn-medical" data-kf-panel="medical" aria-hidden="true">
				<?php self::render_medical_panel( $vacc_rows, $other_rows ); ?>
			</div>
			<div id="kf-tab-medications" class="kf-portal__panel" role="tabpanel" aria-labelledby="kf-tab-btn-medications" data-kf-panel="medications" aria-hidden="true">
				<?php
				/**
				 * Filters HTML for the Medications tab on [ltkf_dashboard] (e.g. KennelFlow Vet prescriptions).
				 *
				 * @since 0.1.0
				 *
				 * @param string $html    Default empty.
				 * @param int[]  $pet_ids Pet post IDs owned by the current user.
				 * @param int    $user_id Current user ID.
				 */
				$kf_medications_html = apply_filters( 'ltkf_portal_medications_panel', '', $pet_ids, $user_id );
				if ( '' === trim( (string) $kf_medications_html ) ) {
					echo '<p class="kf-portal__empty">' . esc_html__( 'Medications are not available on this site.', 'kennelflow-core' ) . '</p>';
				} else {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Returned markup is built by trusted add-ons (e.g. KennelFlow Vet with esc_html).
					echo $kf_medications_html;
				}
				?>
			</div>
			<div id="kf-tab-waivers" class="kf-portal__panel" role="tabpanel" aria-labelledby="kf-tab-btn-waivers" data-kf-panel="waivers" aria-hidden="true">
				<?php self::render_waiver_panel( $pet_ids ); ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Boarding tab content.
	 *
	 * @param object[] $bookings     Rows.
	 * @param int[]    $pet_ids      Allowed pet IDs (for labels).
	 * @param bool     $show_pay_now Whether WooCommerce Pay Now is available.
	 * @param int      $user_id      Current user (for balance lookup).
	 * @return void
	 */
	protected static function render_bookings_panel( $bookings, $pet_ids, $show_pay_now = false, $user_id = 0 ) {
		if ( ! ltkf_table_exists( ltkf_bookings_table_name() ) ) {
			echo '<p class="kf-portal__empty">' . esc_html__( 'Boarding reservations are not available on this site yet.', 'kennelflow-core' ) . '</p>';
			return;
		}

		if ( empty( $pet_ids ) ) {
			echo '<p class="kf-portal__empty">' . esc_html__( 'No pets are linked to your account yet.', 'kennelflow-core' ) . '</p>';
			return;
		}

		self::render_pet_compliance_list( $pet_ids );

		self::render_waitlist_booking_section( $pet_ids );

		if ( empty( $bookings ) ) {
			echo '<p class="kf-portal__empty">' . esc_html__( 'No upcoming boarding reservations.', 'kennelflow-core' ) . '</p>';
			return;
		}

		echo '<table class="kf-portal__table"><thead><tr>';
		echo '<th>' . esc_html__( 'Pet', 'kennelflow-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Booking', 'kennelflow-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Start (UTC)', 'kennelflow-core' ) . '</th>';
		echo '<th>' . esc_html__( 'End (UTC)', 'kennelflow-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'kennelflow-core' ) . '</th>';
		if ( $show_pay_now ) {
			echo '<th>' . esc_html__( 'Actions', 'kennelflow-core' ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		$user_id = absint( $user_id );

		$confirmed_ids = array();
		foreach ( $bookings as $b ) {
			if ( 'confirmed' === (string) $b->status ) {
				$confirmed_ids[] = (int) $b->post_id;
			}
		}

		$balance_map = array();
		if ( $user_id > 0 && ! empty( $confirmed_ids ) ) {
			$balance_map = PortalData::get_unpaid_balance_map_for_user( $user_id, $confirmed_ids );
		}

		foreach ( $bookings as $b ) {
			$pet_title = get_the_title( (int) $b->pet_id );
			if ( '' === $pet_title ) {
				$pet_title = '#' . (int) $b->pet_id;
			}
			$b_title = isset( $b->booking_title ) && '' !== $b->booking_title ? $b->booking_title : sprintf(
				/* translators: %d: booking post ID */
				__( 'Booking #%d', 'kennelflow-core' ),
				(int) $b->post_id
			);

			$balance_ctx = null;
			if ( 'confirmed' === (string) $b->status && isset( $balance_map[ (int) $b->post_id ] ) ) {
				$balance_ctx = $balance_map[ (int) $b->post_id ];
			}

			echo '<tr>';
			echo '<td>' . esc_html( $pet_title ) . '</td>';
			echo '<td>' . esc_html( $b_title ) . '</td>';
			echo '<td>' . esc_html( (string) $b->start_gmt ) . '</td>';
			echo '<td>' . esc_html( (string) $b->end_gmt ) . '</td>';
			echo '<td>' . esc_html( (string) $b->status ) . '</td>';
			if ( $show_pay_now ) {
				echo '<td>';
				$pet_id_for_row = isset( $b->pet_id ) ? absint( $b->pet_id ) : 0;
				$pay_blocked    = $pet_id_for_row > 0 && ltkf_pet_requires_compliance_action( $pet_id_for_row );
				if ( ltkf_compliance_gatekeeper_e2e_allow_noncompliant_checkout() ) {
					$pay_blocked = false;
				}

				if ( 'pending_payment' === (string) $b->status ) {
					echo '<button type="button" class="kf-portal__pay" data-kf-pay-booking="' . esc_attr( (string) $b->post_id ) . '"' . ( $pay_blocked ? ' disabled="disabled"' : '' ) . '>';
					echo esc_html__( 'Pay now', 'kennelflow-core' );
					echo '</button>';
					if ( $pay_blocked ) {
						echo ' <span class="kf-portal__pay-hint">' . esc_html__( 'Vaccination compliance required.', 'kennelflow-core' ) . '</span>';
					}
				} elseif ( null !== $balance_ctx && isset( $balance_ctx['balance'] ) && (float) $balance_ctx['balance'] > 0 ) {
					echo '<button type="button" class="kf-portal__pay kf-portal__pay--balance" data-kf-pay-balance="' . esc_attr( (string) $b->post_id ) . '"' . ( $pay_blocked ? ' disabled="disabled"' : '' ) . '>';
					echo esc_html__( 'Pay remaining balance', 'kennelflow-core' );
					echo '</button>';
					if ( $pay_blocked ) {
						echo ' <span class="kf-portal__pay-hint">' . esc_html__( 'Vaccination compliance required.', 'kennelflow-core' ) . '</span>';
					}
				} else {
					echo '<span class="kf-portal__dash" aria-hidden="true">—</span>';
				}
				echo '</td>';
			}
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Medical / vaccination tab.
	 *
	 * @param object[] $vacc_rows Vaccination-like rows.
	 * @param object[] $other_rows Other lab rows.
	 * @return void
	 */
	protected static function render_medical_panel( $vacc_rows, $other_rows ) {

		if ( ! ltkf_table_exists( ltkf_medical_records_table_name() ) ) {
			echo '<p class="kf-portal__empty">' . esc_html__( 'Medical records are not available on this site yet.', 'kennelflow-core' ) . '</p>';
			return;
		}

		if ( empty( $vacc_rows ) && empty( $other_rows ) ) {
			echo '<p class="kf-portal__empty">' . esc_html__( 'No lab or vaccination records found for your pets.', 'kennelflow-core' ) . '</p>';
			return;
		}

		if ( ! empty( $vacc_rows ) ) {
			echo '<h3 class="kf-portal__subheading">' . esc_html__( 'Vaccination history', 'kennelflow-core' ) . '</h3>';
			self::render_medical_table( $vacc_rows );
		}

		if ( ! empty( $other_rows ) ) {
			echo '<h3 class="kf-portal__subheading">' . esc_html__( 'Other lab results', 'kennelflow-core' ) . '</h3>';
			self::render_medical_table( $other_rows );
		}
	}

	/**
	 * Medical records table with certificate links.
	 *
	 * @param object[] $rows Rows.
	 * @return void
	 */
	protected static function render_medical_table( $rows ) {
		echo '<table class="kf-portal__table"><thead><tr>';
		echo '<th>' . esc_html__( 'Pet', 'kennelflow-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Test / vaccine', 'kennelflow-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Result', 'kennelflow-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Date', 'kennelflow-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Certificate', 'kennelflow-core' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $r ) {
			$pet_title = get_the_title( (int) $r->pet_post_id );
			if ( '' === $pet_title ) {
				$pet_title = '#' . (int) $r->pet_post_id;
			}
			$name = isset( $r->analyte_name ) ? (string) $r->analyte_name : '';
			$val  = isset( $r->value_text ) ? (string) $r->value_text : '';
			$unit = isset( $r->unit ) ? (string) $r->unit : '';
			$when = '';
			if ( ! empty( $r->reported_gmt ) ) {
				$when = (string) $r->reported_gmt;
			} elseif ( ! empty( $r->collected_gmt ) ) {
				$when = (string) $r->collected_gmt;
			} else {
				$when = (string) $r->created_gmt;
			}
			$when_out = '';
			if ( '' !== $when ) {
				$ts = strtotime( $when . ' UTC' );
				if ( false !== $ts ) {
					$when_out = wp_date( get_option( 'date_format' ), $ts, wp_timezone() );
				}
			}

			$cert_url = add_query_arg(
				array(
					'ltkf_cert'   => '1',
					'record_id' => (int) $r->id,
					'_wpnonce'  => wp_create_nonce( 'ltkf_cert_' . (int) $r->id ),
				),
				home_url( '/' )
			);

			echo '<tr>';
			echo '<td>' . esc_html( $pet_title ) . '</td>';
			echo '<td>' . esc_html( $name ) . '</td>';
			echo '<td>' . esc_html( trim( $val . ( $unit ? ' ' . $unit : '' ) ) ) . '</td>';
			echo '<td>' . esc_html( $when_out ) . '</td>';
			echo '<td><a href="' . esc_url( $cert_url ) . '">' . esc_html__( 'Download', 'kennelflow-core' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Legal waivers / e-sign tab.
	 *
	 * @param int[] $pet_ids Owned pet IDs.
	 * @return void
	 */
	protected static function render_waiver_panel( $pet_ids ) {
		$pet_ids = array_values( array_filter( array_map( 'absint', (array) $pet_ids ) ) );
		if ( empty( $pet_ids ) ) {
			echo '<p class="kf-portal__empty">' . esc_html__( 'Add a pet to your account to sign boarding waivers.', 'kennelflow-core' ) . '</p>';
			return;
		}

		echo '<div class="kf-waiver" data-kf-waiver>';
		echo '<div class="kf-waiver__liability">' . wp_kses_post( Waiver::get_liability_html() ) . '</div>';
		echo '<p><label for="kf-waiver-pet">' . esc_html__( 'Pet', 'kennelflow-core' ) . '</label> ';
		echo '<select id="kf-waiver-pet" class="kf-waiver__pet" data-kf-waiver-pet>';
		foreach ( $pet_ids as $pid ) {
			$title = get_the_title( $pid );
			if ( '' === $title ) {
				$title = '#' . (int) $pid;
			}
			echo '<option value="' . esc_attr( (string) $pid ) . '">' . esc_html( $title ) . '</option>';
		}
		echo '</select></p>';
		echo '<p class="kf-waiver__signed-msg" data-kf-waiver-signed hidden></p>';
		echo '<div class="kf-waiver__pad-wrap"><canvas id="kf-waiver-canvas" class="kf-waiver__canvas" width="600" height="200" aria-label="' . esc_attr__( 'Signature', 'kennelflow-core' ) . '"></canvas></div>';
		echo '<p class="kf-waiver__actions">';
		echo '<button type="button" class="button" data-kf-waiver-clear>' . esc_html__( 'Clear', 'kennelflow-core' ) . '</button> ';
		echo '<button type="button" class="button button-primary" data-kf-waiver-submit>' . esc_html__( 'Sign & Agree', 'kennelflow-core' ) . '</button>';
		echo '</p>';
		echo '<p class="kf-waiver__msg" data-kf-waiver-msg hidden role="status"></p>';
		echo '</div>';
	}
}
