<?php
/**
 * Admin: review owner-uploaded compliance documents (kf_medical_records pending_review).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class AdminPendingRecords
 */
class AdminPendingRecords {

	const PAGE_SLUG = 'kf-pending-records';

	const AJAX_APPROVE = 'ltkf_pending_record_approve';

	const AJAX_REJECT = 'ltkf_pending_record_reject';

	const AJAX_DOWNLOAD = 'ltkf_pending_record_download';

	const NONCE_ACTION = 'ltkf_pending_record_action';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 14 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_APPROVE, array( __CLASS__, 'ajax_approve' ) );
		add_action( 'wp_ajax_' . self::AJAX_REJECT, array( __CLASS__, 'ajax_reject' ) );
		add_action( 'wp_ajax_' . self::AJAX_DOWNLOAD, array( __CLASS__, 'ajax_download' ) );
	}

	/**
	 * Capability for this screen and AJAX.
	 *
	 * @return string
	 */
	public static function required_cap() {
		return apply_filters( 'ltkf_pending_records_capability', 'manage_options' );
	}

	/**
	 * Submenu under KennelFlow (kf_pet).
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_submenu_page(
			ltkf_get_hub_menu_slug(),
			__( 'Pending Records', 'kennelflow-core' ),
			__( 'Pending Records', 'kennelflow-core' ),
			self::required_cap(),
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Scripts and styles for this screen only.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( ltkf_get_hub_page_hook_suffix( self::PAGE_SLUG ) !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'kf-toast' );
		wp_enqueue_style(
			'kf-admin-pending-records',
			LTKF_PLUGIN_URL . 'assets/css/kf-admin-pending-records.css',
			array( 'kf-toast' ),
			LTKF_CORE_VERSION
		);

		wp_enqueue_script( 'kf-toast' );
		wp_enqueue_script(
			'kf-admin-pending-records',
			LTKF_PLUGIN_URL . 'assets/js/kf-admin-pending-records.js',
			array( 'kf-toast' ),
			LTKF_CORE_VERSION,
			true
		);

		wp_localize_script(
			'kf-admin-pending-records',
			'kfPendingRecords',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( self::NONCE_ACTION ),
				'actionApprove' => self::AJAX_APPROVE,
				'actionReject'  => self::AJAX_REJECT,
				'strings'       => array(
					'confirmReject'       => __( 'Reject this document? The owner will be notified and must upload again.', 'kennelflow-core' ),
					'confirmRejectOk'     => __( 'Reject', 'kennelflow-core' ),
					'confirmRejectCancel' => __( 'Cancel', 'kennelflow-core' ),
					'needExpiration'      => __( 'Please choose an expiration date before approving.', 'kennelflow-core' ),
					'working'             => __( 'Saving…', 'kennelflow-core' ),
					'error'               => __( 'Something went wrong. Please try again.', 'kennelflow-core' ),
					'network'             => __( 'Network error. Please try again.', 'kennelflow-core' ),
				),
			)
		);
	}

	/**
	 * Render admin page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( self::required_cap() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'kennelflow-core' ) );
		}

		if ( class_exists( 'ComplianceRetention' ) ) {
			ComplianceRetention::maybe_upgrade_medical_records_schema();
		}

		$table = ltkf_medical_records_table_name();
		if ( ! ltkf_table_exists( $table ) ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Pending Records', 'kennelflow-core' ) . '</h1>';
			echo '<p>' . esc_html__( 'The medical records table is not available.', 'kennelflow-core' ) . '</p></div>';
			return;
		}

		if ( ! ltkf_db_column_exists( $table, 'status' ) ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Pending Records', 'kennelflow-core' ) . '</h1>';
			echo '<p>' . esc_html__( 'The status column is missing. Please update KennelFlow Core and reload.', 'kennelflow-core' ) . '</p></div>';
			return;
		}

		$pending = 'pending_review';
		if ( class_exists( 'ComplianceRetention' ) ) {
			$pending = ComplianceRetention::RECORD_STATUS_PENDING_REVIEW;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from ltkf_medical_records_table_name(); placeholders used for values.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE `status` = %s ORDER BY `created_gmt` DESC",
				$pending
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		echo '<div class="wrap kf-pending-records">';
		echo '<h1>' . esc_html__( 'Pending Records', 'kennelflow-core' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Review owner-uploaded vaccination documents. Approve after verifying dates, or reject to ask for a new upload.', 'kennelflow-core' ) . '</p>';

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No documents are awaiting review.', 'kennelflow-core' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped kf-pending-records__table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Pet', 'kennelflow-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Document type', 'kennelflow-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Date uploaded (UTC)', 'kennelflow-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'kennelflow-core' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) ) {
				continue;
			}
			$record_id = absint( $row['id'] );
			$pet_id    = isset( $row['pet_post_id'] ) ? absint( $row['pet_post_id'] ) : 0;
			$pet_title = $pet_id > 0 ? get_the_title( $pet_id ) : '';
			if ( '' === $pet_title ) {
				$pet_title = '#' . (string) $pet_id;
			}
			$doc_type    = isset( $row['analyte_name'] ) ? sanitize_text_field( (string) $row['analyte_name'] ) : '';
			$created     = isset( $row['created_gmt'] ) ? (string) $row['created_gmt'] : '';
			$created_out = '';
			if ( '' !== $created ) {
				$ts = strtotime( $created . ' UTC' );
				if ( false !== $ts ) {
					$created_out = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts, wp_timezone() );
				}
			}
			if ( '' === $created_out ) {
				$created_out = $created;
			}

			$meta      = self::parse_meta_json( isset( $row['meta_json'] ) ? $row['meta_json'] : '' );
			$attach_id = isset( $meta['attachment_id'] ) ? absint( $meta['attachment_id'] ) : 0;
			$download  = '';
			if ( $attach_id > 0 ) {
				$download = add_query_arg(
					array(
						'action'    => self::AJAX_DOWNLOAD,
						'record_id' => $record_id,
						'_wpnonce'  => wp_create_nonce( 'ltkf_pending_download_' . $record_id ),
					),
					admin_url( 'admin-ajax.php' )
				);
			}

			echo '<tr class="kf-pending-records__row">';
			echo '<td>' . esc_html( $pet_title ) . '</td>';
			echo '<td>' . esc_html( $doc_type ) . '</td>';
			echo '<td>' . esc_html( $created_out ) . '</td>';
			echo '<td><button type="button" class="button kf-pending-records__toggle" data-kf-pr-record="' . esc_attr( (string) $record_id ) . '">' . esc_html__( 'Review', 'kennelflow-core' ) . '</button></td>';
			echo '</tr>';

			echo '<tr class="kf-pending-records__detail" id="kf-pending-detail-' . esc_attr( (string) $record_id ) . '" hidden>';
			echo '<td colspan="4">';
			echo '<div class="kf-pending-records__panel">';

			if ( '' !== $download ) {
				echo '<p><a class="button button-secondary" href="' . esc_url( $download ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View / download document', 'kennelflow-core' ) . '</a></p>';
			} else {
				echo '<p class="kf-pending-records__warn">' . esc_html__( 'No attachment is linked to this row.', 'kennelflow-core' ) . '</p>';
			}

			echo '<p><label for="kf-pr-exp-' . esc_attr( (string) $record_id ) . '">' . esc_html__( 'Expiration date', 'kennelflow-core' ) . '</label> ';
			echo '<input type="date" id="kf-pr-exp-' . esc_attr( (string) $record_id ) . '" class="kf-pending-records__exp" data-kf-pr-exp="' . esc_attr( (string) $record_id ) . '" required /></p>';

			echo '<p class="kf-pending-records__actions">';
			echo '<button type="button" class="button button-primary kf-pending-records__approve" data-kf-pr-record="' . esc_attr( (string) $record_id ) . '">' . esc_html__( 'Approve', 'kennelflow-core' ) . '</button> ';
			echo '<button type="button" class="button kf-pending-records__reject" data-kf-pr-record="' . esc_attr( (string) $record_id ) . '">' . esc_html__( 'Reject', 'kennelflow-core' ) . '</button>';
			echo '</p>';
			echo '<p class="kf-pending-records__msg" data-kf-pr-msg="' . esc_attr( (string) $record_id ) . '" role="status" aria-live="polite"></p>';

			echo '</div></td></tr>';
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Parse meta_json from a row.
	 *
	 * @param string $json Raw JSON.
	 * @return array<string, mixed>
	 */
	protected static function parse_meta_json( $json ) {
		$json = (string) $json;
		if ( '' === $json ) {
			return array();
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Convert Y-m-d (staff-selected calendar date) to expiration_gmt (noon site-local → UTC).
	 *
	 * @param string $date_ymd Date string.
	 * @return string MySQL datetime UTC or empty.
	 */
	protected static function expiration_date_to_gmt_mysql( $date_ymd ) {
		$date_ymd = sanitize_text_field( $date_ymd );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_ymd ) ) {
			return '';
		}
		try {
			$tz = wp_timezone();
			$d  = new DateTimeImmutable( $date_ymd . ' 12:00:00', $tz );
			return $d->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			unset( $e );
			return '';
		}
	}

	/**
	 * AJAX: stream protected file for a pending row.
	 *
	 * @return void
	 */
	public static function ajax_download() {
		$record_id = isset( $_GET['record_id'] ) ? absint( wp_unslash( $_GET['record_id'] ) ) : 0;
		if ( $record_id < 1 ) {
			wp_die( esc_html__( 'Invalid request.', 'kennelflow-core' ), '', array( 'response' => 400 ) );
		}

		if ( ! check_ajax_referer( 'ltkf_pending_download_' . $record_id, '_wpnonce', false ) ) {
			wp_die( esc_html__( 'Invalid security token.', 'kennelflow-core' ), '', array( 'response' => 403 ) );
		}

		if ( ! current_user_can( self::required_cap() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this file.', 'kennelflow-core' ), '', array( 'response' => 403 ) );
		}

		$table = ltkf_medical_records_table_name();
		if ( ! ltkf_table_exists( $table ) ) {
			wp_die( esc_html__( 'Not found.', 'kennelflow-core' ), '', array( 'response' => 404 ) );
		}

		$pending = class_exists( 'ComplianceRetention' ) ? ComplianceRetention::RECORD_STATUS_PENDING_REVIEW : 'pending_review';

		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from ltkf_medical_records_table_name().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE `id` = %d AND `status` = %s",
				$record_id,
				$pending
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $row ) || empty( $row['id'] ) ) {
			wp_die( esc_html__( 'Not found.', 'kennelflow-core' ), '', array( 'response' => 404 ) );
		}

		$meta      = self::parse_meta_json( isset( $row['meta_json'] ) ? $row['meta_json'] : '' );
		$attach_id = isset( $meta['attachment_id'] ) ? absint( $meta['attachment_id'] ) : 0;
		$relative  = isset( $meta['relative_path'] ) ? (string) $meta['relative_path'] : '';

		if ( $attach_id > 0 ) {
			$path = get_attached_file( $attach_id );
		} else {
			$path = '';
		}

		if ( ( ! $path || ! is_readable( $path ) ) && '' !== $relative ) {
			$upload = wp_upload_dir();
			if ( empty( $upload['error'] ) && ! empty( $upload['basedir'] ) ) {
				$candidate = trailingslashit( $upload['basedir'] ) . ltrim( str_replace( '..', '', $relative ), '/' );
				$candidate = wp_normalize_path( $candidate );
				$base      = wp_normalize_path( trailingslashit( $upload['basedir'] ) );
				if ( 0 === strpos( $candidate, $base ) && is_readable( $candidate ) ) {
					$path = $candidate;
				}
			}
		}

		if ( ! $path || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'Not found.', 'kennelflow-core' ), '', array( 'response' => 404 ) );
		}

		$resolved = wp_normalize_path( $path );
		$ok_base  = false;
		if ( class_exists( 'KennelFlow_Vet_Protected_Uploads' ) ) {
			$medical_base = trailingslashit( wp_normalize_path( KennelFlow_Vet_Protected_Uploads::get_medical_dir() ) );
			if ( 0 === strpos( $resolved, $medical_base ) ) {
				$ok_base = true;
			}
		} elseif ( class_exists( 'KennelFlow_Vet_Protected_Uploads' ) ) {
			$medical_base = trailingslashit( wp_normalize_path( KennelFlow_Vet_Protected_Uploads::get_medical_dir() ) );
			if ( 0 === strpos( $resolved, $medical_base ) ) {
				$ok_base = true;
			}
		}
		if ( ! $ok_base ) {
			$upload = wp_upload_dir();
			if ( empty( $upload['error'] ) && ! empty( $upload['basedir'] ) ) {
				$ub = trailingslashit( wp_normalize_path( $upload['basedir'] ) );
				if ( 0 === strpos( $resolved, $ub ) ) {
					$ok_base = true;
				}
			}
		}

		if ( ! $ok_base ) {
			wp_die( esc_html__( 'Not found.', 'kennelflow-core' ), '', array( 'response' => 404 ) );
		}

		$mime = $attach_id > 0 ? get_post_mime_type( $attach_id ) : 'application/octet-stream';
		if ( ! $mime ) {
			$mime = 'application/octet-stream';
		}

		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: inline; filename="' . basename( $path ) . '"' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- binary stream.
		readfile( $path );
		exit;
	}

	/**
	 * AJAX: approve pending record.
	 *
	 * @return void
	 */
	public static function ajax_approve() {
		if ( ! check_ajax_referer( self::NONCE_ACTION, '_wpnonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'kennelflow-core' ) ), 403 );
		}

		if ( ! current_user_can( self::required_cap() ) ) {
			wp_send_json_error( array( 'message' => __( 'Sorry, you are not allowed to do this.', 'kennelflow-core' ) ), 403 );
		}

		$record_id = isset( $_POST['record_id'] ) ? absint( wp_unslash( $_POST['record_id'] ) ) : 0;
		if ( $record_id < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid record.', 'kennelflow-core' ) ), 400 );
		}

		$exp_raw = isset( $_POST['expiration_date'] ) ? sanitize_text_field( wp_unslash( $_POST['expiration_date'] ) ) : '';
		$exp_gmt = self::expiration_date_to_gmt_mysql( $exp_raw );
		if ( '' === $exp_gmt ) {
			wp_send_json_error( array( 'message' => __( 'Please provide a valid expiration date.', 'kennelflow-core' ) ), 400 );
		}

		$table = ltkf_medical_records_table_name();
		if ( ! ltkf_table_exists( $table ) || ! ltkf_db_column_exists( $table, 'status' ) ) {
			wp_send_json_error( array( 'message' => __( 'Database is not ready.', 'kennelflow-core' ) ), 503 );
		}

		$pending = class_exists( 'ComplianceRetention' ) ? ComplianceRetention::RECORD_STATUS_PENDING_REVIEW : 'pending_review';
		$active  = class_exists( 'ComplianceRetention' ) ? ComplianceRetention::RECORD_STATUS_ACTIVE : 'active';

		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from ltkf_medical_records_table_name().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE `id` = %d AND `status` = %s",
				$record_id,
				$pending
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $row ) || empty( $row['id'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Record not found or already processed.', 'kennelflow-core' ) ), 404 );
		}

		$pet_id = isset( $row['pet_post_id'] ) ? absint( $row['pet_post_id'] ) : 0;
		$meta   = self::parse_meta_json( isset( $row['meta_json'] ) ? $row['meta_json'] : '' );

		$meta['review_approved_gmt'] = current_time( 'mysql', true );
		$meta['review_approved_by']  = get_current_user_id();
		$meta_json                   = wp_json_encode( $meta );
		if ( false === $meta_json ) {
			$meta_json = '{}';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Single-row approval.
		$updated = $wpdb->update(
			$table,
			array(
				'status'         => $active,
				'expiration_gmt' => $exp_gmt,
				'meta_json'      => $meta_json,
			),
			array(
				'id' => $record_id,
			),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			wp_send_json_error( array( 'message' => __( 'Could not update the record.', 'kennelflow-core' ) ), 500 );
		}
		if ( 0 === (int) $updated ) {
			wp_send_json_error( array( 'message' => __( 'Record not found or already processed.', 'kennelflow-core' ) ), 404 );
		}

		if ( $pet_id > 0 ) {
			if ( class_exists( 'KennelFlow_Vet_EMR_Audit' ) ) {
				KennelFlow_Vet_EMR_Audit::log(
					'kf_pet',
					$pet_id,
					'compliance_pending_approved',
					array( 'record_id' => $record_id ),
					array(
						'record_id'      => $record_id,
						'expiration_gmt' => $exp_gmt,
					),
					get_current_user_id()
				);
			} elseif ( class_exists( 'KennelFlow_Vet_EMR_Audit' ) ) {
				KennelFlow_Vet_EMR_Audit::log(
					'kf_pet',
					$pet_id,
					'compliance_pending_approved',
					array( 'record_id' => $record_id ),
					array(
						'record_id'      => $record_id,
						'expiration_gmt' => $exp_gmt,
					),
					get_current_user_id()
				);
			}
		}

		/**
		 * Fires after a pending compliance document was approved.
		 *
		 * @since 0.2.0
		 *
		 * @param int    $record_id Row ID in kf_medical_records.
		 * @param int    $pet_id    kf_pet post ID.
		 * @param string $exp_gmt   Expiration datetime UTC.
		 */
		do_action( 'ltkf_compliance_pending_approved', $record_id, $pet_id, $exp_gmt );

		wp_send_json_success(
			array(
				'message' => __( 'Record approved.', 'kennelflow-core' ),
			)
		);
	}

	/**
	 * AJAX: reject pending record (delete row, notify owner).
	 *
	 * @return void
	 */
	public static function ajax_reject() {
		if ( ! check_ajax_referer( self::NONCE_ACTION, '_wpnonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'kennelflow-core' ) ), 403 );
		}

		if ( ! current_user_can( self::required_cap() ) ) {
			wp_send_json_error( array( 'message' => __( 'Sorry, you are not allowed to do this.', 'kennelflow-core' ) ), 403 );
		}

		$record_id = isset( $_POST['record_id'] ) ? absint( wp_unslash( $_POST['record_id'] ) ) : 0;
		if ( $record_id < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid record.', 'kennelflow-core' ) ), 400 );
		}

		$table = ltkf_medical_records_table_name();
		if ( ! ltkf_table_exists( $table ) || ! ltkf_db_column_exists( $table, 'status' ) ) {
			wp_send_json_error( array( 'message' => __( 'Database is not ready.', 'kennelflow-core' ) ), 503 );
		}

		$pending = class_exists( 'ComplianceRetention' ) ? ComplianceRetention::RECORD_STATUS_PENDING_REVIEW : 'pending_review';

		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from ltkf_medical_records_table_name().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE `id` = %d AND `status` = %s",
				$record_id,
				$pending
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $row ) || empty( $row['id'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Record not found or already processed.', 'kennelflow-core' ) ), 404 );
		}

		$pet_id  = isset( $row['pet_post_id'] ) ? absint( $row['pet_post_id'] ) : 0;
		$meta    = self::parse_meta_json( isset( $row['meta_json'] ) ? $row['meta_json'] : '' );
		$attach  = isset( $meta['attachment_id'] ) ? absint( $meta['attachment_id'] ) : 0;
		$doc_lab = isset( $row['analyte_name'] ) ? sanitize_text_field( (string) $row['analyte_name'] ) : '';

		if ( $attach > 0 ) {
			wp_delete_attachment( $attach, true );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reject deletes row.
		$deleted = $wpdb->delete(
			$table,
			array( 'id' => $record_id ),
			array( '%d' )
		);

		if ( false === $deleted || $deleted < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Could not delete the record.', 'kennelflow-core' ) ), 500 );
		}

		if ( $pet_id > 0 ) {
			if ( class_exists( 'KennelFlow_Vet_EMR_Audit' ) ) {
				KennelFlow_Vet_EMR_Audit::log(
					'kf_pet',
					$pet_id,
					'compliance_pending_rejected',
					array(
						'record_id'    => $record_id,
						'analyte_name' => $doc_lab,
					),
					array(
						'record_id' => $record_id,
					),
					get_current_user_id()
				);
			} elseif ( class_exists( 'KennelFlow_Vet_EMR_Audit' ) ) {
				KennelFlow_Vet_EMR_Audit::log(
					'kf_pet',
					$pet_id,
					'compliance_pending_rejected',
					array(
						'record_id'    => $record_id,
						'analyte_name' => $doc_lab,
					),
					array(
						'record_id' => $record_id,
					),
					get_current_user_id()
				);
			}
		}

		self::mail_owner_rejection( $pet_id, $doc_lab );

		/**
		 * Fires after a pending compliance document was rejected and removed.
		 *
		 * @since 0.2.0
		 *
		 * @param int    $record_id Deleted row ID (no longer in DB).
		 * @param int    $pet_id    kf_pet post ID.
		 * @param string $doc_lab   Vaccine / document label.
		 */
		do_action( 'ltkf_compliance_pending_rejected', $record_id, $pet_id, $doc_lab );

		wp_send_json_success(
			array(
				'message' => __( 'Record rejected and removed.', 'kennelflow-core' ),
			)
		);
	}

	/**
	 * Email pet owner about rejection.
	 *
	 * @param int    $pet_id  Pet post ID.
	 * @param string $doc_lab Document label.
	 * @return void
	 */
	protected static function mail_owner_rejection( $pet_id, $doc_lab ) {
		$pet_id = absint( $pet_id );
		if ( $pet_id < 1 ) {
			return;
		}

		$owner_id = ltkf_get_pet_owner_user_id( $pet_id );
		if ( $owner_id < 1 ) {
			return;
		}

		$user = get_userdata( $owner_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return;
		}

		$pet_name = get_the_title( $pet_id );
		if ( '' === $pet_name ) {
			$pet_name = '#' . (string) $pet_id;
		}

		$portal = function_exists( 'ltkf_get_portal_dashboard_url' ) ? ltkf_get_portal_dashboard_url() : home_url( '/' );

		/* translators: %s: site name */
		$subject = sprintf( __( '[%s] Document not accepted — action needed', 'kennelflow-core' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );

		$body = sprintf(
			/* translators: 1: pet name, 2: document type, 3: portal URL */
			__(
				"Hello,\n\nThe document you uploaded for %1\$s (%2\$s) could not be accepted. Please sign in and upload a new document that clearly shows the required information and dates.\n\nPortal: %3\$s\n\nThank you.",
				'kennelflow-core'
			),
			$pet_name,
			$doc_lab,
			$portal
		);

		/**
		 * Filters the plain-text body of the compliance rejection email.
		 *
		 * @since 0.2.0
		 *
		 * @param string $body    Email body.
		 * @param int    $pet_id  Pet post ID.
		 * @param string $doc_lab Vaccine / document label.
		 */
		$body = apply_filters( 'ltkf_pending_records_rejection_email_body', $body, $pet_id, $doc_lab );

		wp_mail( $user->user_email, $subject, $body );
	}
}
