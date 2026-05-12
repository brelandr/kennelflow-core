<?php
/**
 * REST: Owner compliance document upload (protected storage, pending staff review).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class ComplianceUploadApi
 */
class ComplianceUploadApi {

	const REST_NAMESPACE = 'kennelflow/v1';

	const ROUTE = '/compliance/upload';

	/**
	 * Max upload size (5 MB).
	 */
	const MAX_FILE_BYTES = 5242880;

	/**
	 * Protected uploads subpath (under wp-content/uploads).
	 */
	const PROTECTED_SUBDIR = 'kennelflow-vet-medical/compliance-uploads';

	/**
	 * Attachment meta (aligned with KennelFlow_Vet_Protected_Uploads for staff tools).
	 */
	const META_PROTECTED = '_kennelflow_vet_protected_attachment';

	const META_PET_POST_ID = '_kennelflow_vet_medical_pet_id';

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
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'upload' ),
				'permission_callback' => array( __CLASS__, 'permissions_logged_in' ),
				'args'                => array(
					'pet_id'       => array(
						'description' => __( 'Pet post ID (kf_pet).', 'kennelflow-core' ),
						'type'        => 'integer',
						'required'    => true,
					),
					'vaccine_name' => array(
						'description' => __( 'Vaccine / compliance item label (e.g. Rabies).', 'kennelflow-core' ),
						'type'        => 'string',
						'required'    => true,
					),
				),
			)
		);
	}

	/**
	 * Require a logged-in user; ownership is checked in the callback.
	 *
	 * @return bool
	 */
	public static function permissions_logged_in() {
		return is_user_logged_in();
	}

	/**
	 * POST /kennelflow/v1/compliance/upload — multipart field "file", pet_id, vaccine_name.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function upload( $request ) {
		$table = ltkf_medical_records_table_name();
		if ( ! ltkf_table_exists( $table ) ) {
			return new \WP_Error(
				'ltkf_no_medical_table',
				__( 'Medical records table is not available.', 'kennelflow-core' ),
				array( 'status' => 503 )
			);
		}

		if ( class_exists( 'ComplianceRetention' ) ) {
			ComplianceRetention::maybe_upgrade_medical_records_schema();
		}

		$pet_id = absint( $request->get_param( 'pet_id' ) );
		if ( $pet_id < 1 ) {
			return new \WP_Error(
				'ltkf_bad_pet',
				__( 'Invalid pet ID.', 'kennelflow-core' ),
				array( 'status' => 400 )
			);
		}

		if ( ltkf_get_pet_post_type() !== get_post_type( $pet_id ) ) {
			return new \WP_Error(
				'ltkf_not_pet',
				__( 'Post is not a hub pet.', 'kennelflow-core' ),
				array( 'status' => 400 )
			);
		}

		$uid = get_current_user_id();
		if ( $uid < 1 ) {
			return new \WP_Error(
				'ltkf_auth',
				__( 'You must be logged in.', 'kennelflow-core' ),
				array( 'status' => 401 )
			);
		}

		$owner_id = ltkf_get_pet_owner_user_id( $pet_id );
		if ( $owner_id < 1 || (int) $owner_id !== (int) $uid ) {
			return new \WP_Error(
				'ltkf_forbidden',
				__( 'You do not have access to this pet.', 'kennelflow-core' ),
				array( 'status' => 403 )
			);
		}

		$vaccine_name = sanitize_text_field( (string) $request->get_param( 'vaccine_name' ) );
		if ( '' === $vaccine_name ) {
			$vaccine_name = sanitize_text_field( (string) $request->get_param( 'analyte_name' ) );
		}
		if ( '' === $vaccine_name ) {
			return new \WP_Error(
				'ltkf_missing_vaccine',
				__( 'vaccine_name is required.', 'kennelflow-core' ),
				array( 'status' => 400 )
			);
		}

		if ( function_exists( 'mb_substr' ) ) {
			$vaccine_name = mb_substr( $vaccine_name, 0, 191, 'UTF-8' );
		} else {
			$vaccine_name = substr( $vaccine_name, 0, 191 );
		}

		$files = $request->get_file_params();
		if ( empty( $files['file'] ) || ! is_array( $files['file'] ) ) {
			return new \WP_Error(
				'ltkf_no_file',
				__( 'Missing file; use multipart field name "file".', 'kennelflow-core' ),
				array( 'status' => 400 )
			);
		}

		$file = $files['file'];
		if ( ! empty( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new \WP_Error(
				'ltkf_upload_php_error',
				__( 'File upload failed.', 'kennelflow-core' ),
				array( 'status' => 400 )
			);
		}

		$size = isset( $file['size'] ) ? (int) $file['size'] : 0;
		if ( $size < 1 || $size > self::MAX_FILE_BYTES ) {
			return new \WP_Error(
				'ltkf_file_too_large',
				sprintf(
					/* translators: %s: max size like "5 MB" */
					__( 'File must be %s or smaller.', 'kennelflow-core' ),
					'5 MB'
				),
				array( 'status' => 400 )
			);
		}

		if ( class_exists( 'KennelFlow_Vet_Protected_Uploads' ) ) {
			add_filter( 'kennelflow_vet_medical_upload_subdir', array( __CLASS__, 'filter_compliance_upload_subdir' ), 20 );
			add_filter( 'kennelflow_vet_handle_upload_medical_mimes', array( __CLASS__, 'filter_compliance_upload_mimes' ), 20, 3 );

			$result = KennelFlow_Vet_Protected_Uploads::handle_upload_for_pet( $file, $pet_id );

			remove_filter( 'kennelflow_vet_handle_upload_medical_mimes', array( __CLASS__, 'filter_compliance_upload_mimes' ), 20 );
			remove_filter( 'kennelflow_vet_medical_upload_subdir', array( __CLASS__, 'filter_compliance_upload_subdir' ), 20 );
		} else {
			$result = self::handle_upload_core_fallback( $file, $pet_id );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$attachment_id = isset( $result['attachment_id'] ) ? absint( $result['attachment_id'] ) : 0;
		if ( $attachment_id < 1 ) {
			return new \WP_Error(
				'ltkf_upload_failed',
				__( 'Upload did not return an attachment.', 'kennelflow-core' ),
				array( 'status' => 500 )
			);
		}

		$upload   = wp_upload_dir();
		$abs_file = get_attached_file( $attachment_id );
		$relative = '';
		if ( $abs_file && ! empty( $upload['basedir'] ) ) {
			$relative = str_replace( trailingslashit( wp_normalize_path( $upload['basedir'] ) ), '', wp_normalize_path( $abs_file ) );
		}

		$meta_payload = array(
			'source'        => 'owner_compliance_upload',
			'attachment_id' => $attachment_id,
			'relative_path' => $relative,
			'vaccine_name'  => $vaccine_name,
		);

		$meta_json = wp_json_encode( $meta_payload );
		if ( false === $meta_json ) {
			$meta_json = '{}';
		}

		$now_gmt = current_time( 'mysql', true );

		$data = array(
			'pet_post_id'      => $pet_id,
			'pid_primary_id'   => '',
			'hl7_message_type' => '',
			'hl7_control_id'   => '',
			'obx_set_id'       => '',
			'obx_sequence'     => 0,
			'analyte_code'     => 'OWNER_UPLOAD',
			'analyte_name'     => $vaccine_name,
			'value_text'       => '',
			'unit'             => '',
			'reference_text'   => '',
			'flag'             => '',
			'collected_gmt'    => null,
			'reported_gmt'     => null,
			'expiration_gmt'   => null,
			'meta_json'        => $meta_json,
			'created_gmt'      => $now_gmt,
			'created_by'       => $uid,
		);

		$formats = array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' );

		if ( ltkf_db_column_exists( $table, 'status' ) ) {
			$data['status'] = class_exists( 'ComplianceRetention' )
				? ComplianceRetention::RECORD_STATUS_PENDING_REVIEW
				: 'pending_review';
			$formats[]      = '%s';
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Hub medical index row for pending review.
		$ok = $wpdb->insert( $table, $data, $formats );

		if ( false === $ok ) {
			wp_delete_attachment( $attachment_id, true );
			return new \WP_Error(
				'ltkf_db_insert_failed',
				__( 'Could not save the medical record.', 'kennelflow-core' ),
				array( 'status' => 500 )
			);
		}

		$record_id = (int) $wpdb->insert_id;

		$audit_message = sprintf(
			/* translators: %s: vaccine or compliance label */
			__( 'Owner uploaded compliance document for %s.', 'kennelflow-core' ),
			$vaccine_name
		);

		if ( class_exists( 'KennelFlow_Vet_EMR_Audit' ) ) {
			KennelFlow_Vet_EMR_Audit::log(
				'kf_pet',
				$pet_id,
				'compliance_doc_upload',
				null,
				array(
					'message'    => $audit_message,
					'record_id'  => $record_id,
					'attachment' => $attachment_id,
				),
				$uid
			);
		}

		/**
		 * Fires after an owner compliance document was stored and indexed as pending review.
		 *
		 * @since 0.2.0
		 *
		 * @param int $record_id     kf_medical_records row ID.
		 * @param int $pet_id        kf_pet post ID.
		 * @param int $attachment_id Attachment post ID (protected).
		 */
		do_action( 'ltkf_compliance_upload_after_insert', $record_id, $pet_id, $attachment_id );

		$res = rest_ensure_response(
			array(
				'record_id'     => $record_id,
				'attachment_id' => $attachment_id,
				'status'        => class_exists( 'ComplianceRetention' ) ? ComplianceRetention::RECORD_STATUS_PENDING_REVIEW : 'pending_review',
				'pet_id'        => $pet_id,
				'vaccine_name'  => $vaccine_name,
			)
		);
		$res->set_status( 201 );

		return $res;
	}

	/**
	 * Upload without KennelFlow Vet: same subtree, PDF/images only, protected meta for staff tooling.
	 *
	 * @param array $file   Single uploaded file.
	 * @param int   $pet_id kf_pet post ID.
	 * @return array<string, mixed>|WP_Error
	 */
	protected static function handle_upload_core_fallback( $file, $pet_id ) {
		if ( ! is_array( $file ) || ! isset( $file['tmp_name'] ) ) {
			return new \WP_Error(
				'ltkf_no_file',
				__( 'No file was uploaded.', 'kennelflow-core' ),
				array( 'status' => 400 )
			);
		}

		$dir_res = self::ensure_compliance_upload_directory();
		if ( is_wp_error( $dir_res ) ) {
			return $dir_res;
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		add_filter( 'upload_dir', array( __CLASS__, 'filter_upload_dir_compliance' ), 99 );

		$mimes = array(
			'pdf'          => 'application/pdf',
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations -- Core uploader.
		$upload = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => $mimes,
			)
		);

		remove_filter( 'upload_dir', array( __CLASS__, 'filter_upload_dir_compliance' ), 99 );

		if ( isset( $upload['error'] ) && '' !== $upload['error'] ) {
			return new \WP_Error(
				'ltkf_upload_error',
				$upload['error'],
				array( 'status' => 400 )
			);
		}

		if ( empty( $upload['file'] ) || empty( $upload['type'] ) ) {
			return new \WP_Error(
				'ltkf_upload_error',
				__( 'Upload did not return a file path.', 'kennelflow-core' ),
				array( 'status' => 500 )
			);
		}

		$filename = wp_basename( $upload['file'] );
		$title    = sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) );

		$attachment_post = array(
			'post_mime_type' => $upload['type'],
			'post_title'     => $title ? $title : $filename,
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_author'    => get_current_user_id(),
		);

		$attach_id = wp_insert_attachment( $attachment_post, $upload['file'], 0, true );
		if ( is_wp_error( $attach_id ) ) {
			if ( file_exists( $upload['file'] ) ) {
				wp_delete_file( $upload['file'] );
			}
			return $attach_id;
		}
		if ( ! $attach_id ) {
			if ( file_exists( $upload['file'] ) ) {
				wp_delete_file( $upload['file'] );
			}
			return new \WP_Error(
				'ltkf_attachment_failed',
				__( 'Could not create attachment.', 'kennelflow-core' ),
				array( 'status' => 500 )
			);
		}

		$attach_id = absint( $attach_id );

		$metadata = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
		if ( ! is_wp_error( $metadata ) && ! empty( $metadata ) ) {
			wp_update_attachment_metadata( $attach_id, $metadata );
		}

		update_post_meta( $attach_id, self::META_PROTECTED, 1 );
		update_post_meta( $attach_id, self::META_PET_POST_ID, absint( $pet_id ) );

		$url = wp_get_attachment_url( $attach_id );
		if ( ! $url ) {
			$url = '';
		}

		return array(
			'attachment_id' => $attach_id,
			'url'           => $url,
			'mime_type'     => $upload['type'],
		);
	}

	/**
	 * Point uploads at the compliance subdirectory under wp-content/uploads.
	 *
	 * @param array $dirs Upload dir array.
	 * @return array
	 */
	public static function filter_upload_dir_compliance( $dirs ) {
		if ( ! is_array( $dirs ) ) {
			return $dirs;
		}

		$subdir = apply_filters( 'ltkf_medical_upload_subdir', apply_filters( 'kennelflow_vet_medical_upload_subdir', self::PROTECTED_SUBDIR ) );
		$subdir = trim( str_replace( array( '..', '\\' ), '', (string) $subdir ), '/' );
		if ( '' === $subdir ) {
			$subdir = self::PROTECTED_SUBDIR;
		}

		$dirs['subdir'] = '/' . $subdir;
		$dirs['path']   = trailingslashit( $dirs['basedir'] ) . $subdir;
		$dirs['url']    = trailingslashit( $dirs['baseurl'] ) . $subdir;

		return $dirs;
	}

	/**
	 * Create compliance upload dirs and deny direct HTTP access (Apache .htaccess).
	 *
	 * @return true|WP_Error
	 */
	protected static function ensure_compliance_upload_directory() {
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return new \WP_Error(
				'ltkf_upload_dir',
				$upload['error'],
				array( 'status' => 500 )
			);
		}

		$basedir = trailingslashit( $upload['basedir'] );
		$full    = $basedir . self::PROTECTED_SUBDIR;

		if ( ! wp_mkdir_p( $full ) ) {
			return new \WP_Error(
				'ltkf_mkdir',
				__( 'Could not create upload directory.', 'kennelflow-core' ),
				array( 'status' => 500 )
			);
		}

		$medical_root = $basedir . 'kennelflow-vet-medical';
		if ( ! wp_mkdir_p( $medical_root ) ) {
			return new \WP_Error(
				'ltkf_mkdir',
				__( 'Could not create upload directory.', 'kennelflow-core' ),
				array( 'status' => 500 )
			);
		}

		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem( false, false, true );
		}

		if ( $wp_filesystem ) {
			$index = trailingslashit( $medical_root ) . 'index.php';
			if ( ! $wp_filesystem->exists( $index ) ) {
				$wp_filesystem->put_contents( $index, "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
			}

			$htaccess = trailingslashit( $medical_root ) . '.htaccess';
			if ( ! $wp_filesystem->exists( $htaccess ) ) {
				$rules = "# KennelFlow — deny direct access to medical uploads\nDeny from all\n";
				$wp_filesystem->put_contents( $htaccess, $rules, FS_CHMOD_FILE );
			}

			$index_deep = trailingslashit( $full ) . 'index.php';
			if ( ! $wp_filesystem->exists( $index_deep ) ) {
				$wp_filesystem->put_contents( $index_deep, "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
			}
		}

		return true;
	}

	/**
	 * Force uploads into kennelflow-vet-medical/compliance-uploads (protected tree).
	 *
	 * @param string $subdir Default subdir.
	 * @return string
	 */
	public static function filter_compliance_upload_subdir( $subdir ) {
		unset( $subdir );
		return self::PROTECTED_SUBDIR;
	}

	/**
	 * Allow only PDF and images for compliance uploads.
	 *
	 * @param array<string, string> $merged_mimes Extension => MIME.
	 * @param array                 $file         Uploaded file.
	 * @param int                   $pet_post_id  Pet ID.
	 * @return array<string, string>
	 */
	public static function filter_compliance_upload_mimes( $merged_mimes, $file, $pet_post_id ) {
		unset( $merged_mimes, $file, $pet_post_id );

		return array(
			'pdf'          => 'application/pdf',
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
		);
	}
}
