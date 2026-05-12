<?php
/**
 * Client migration: CSV import of owners (users) and kf_pet profiles (batched AJAX).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class MigrationAdmin
 */
class MigrationAdmin {

	const PAGE_SLUG = 'kf-client-migration';

	const ACTION_UPLOAD = 'ltkf_migration_upload';

	const AJAX_BATCH = 'ltkf_migration_batch';

	const BATCH_SIZE = 25;

	const TRANSIENT_PREFIX = 'ltkf_mig_job_';

	const JOB_TTL = DAY_IN_SECONDS;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_scripts' ) );
		add_action( 'admin_post_' . self::ACTION_UPLOAD, array( __CLASS__, 'handle_upload' ) );
		add_action( 'wp_ajax_' . self::AJAX_BATCH, array( __CLASS__, 'ajax_process_batch' ) );
	}

	/**
	 * Required capability.
	 *
	 * @return string
	 */
	public static function required_cap() {
		return apply_filters( 'ltkf_migration_capability', 'manage_options' );
	}

	/**
	 * Submenu under KennelFlow (kf_pet).
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_submenu_page(
			ltkf_get_hub_menu_slug(),
			__( 'Client migration', 'kennelflow-core' ),
			__( 'Client migration', 'kennelflow-core' ),
			self::required_cap(),
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue script on migration page only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function maybe_enqueue_scripts( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_script(
			'kf-migration-admin',
			LTKF_PLUGIN_URL . 'assets/js/kf-migration-admin.js',
			array( 'jquery' ),
			LTKF_CORE_VERSION,
			true
		);

		wp_localize_script(
			'kf-migration-admin',
			'kfMigration',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::AJAX_BATCH ),
				'action'  => self::AJAX_BATCH,
				'i18n'    => array(
					'done'    => __( 'Migration finished.', 'kennelflow-core' ),
					'error'   => __( 'Request failed.', 'kennelflow-core' ),
					'working' => __( 'Processing…', 'kennelflow-core' ),
				),
			)
		);
	}

	/**
	 * Handle CSV upload (multipart POST).
	 *
	 * @return void
	 */
	public static function handle_upload() {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::ACTION_UPLOAD ) ) {
			wp_die( esc_html__( 'Invalid security token.', 'kennelflow-core' ) );
		}

		if ( ! current_user_can( self::required_cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to run migrations.', 'kennelflow-core' ) );
		}

		if ( ! isset( $_FILES['ltkf_csv'] ) || ! is_array( $_FILES['ltkf_csv'] ) ) {
			self::redirect_with_arg( 'ltkf_mig_err', 'no_file' );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated via wp_check_filetype and move_uploaded_file.
		$file = $_FILES['ltkf_csv'];
		if ( ! empty( $file['error'] ) ) {
			self::redirect_with_arg( 'ltkf_mig_err', 'upload' );
		}

		$check = wp_check_filetype( $file['name'], array( 'csv' => 'text/csv' ) );
		if ( empty( $check['ext'] ) || 'csv' !== strtolower( (string) $check['ext'] ) ) {
			self::redirect_with_arg( 'ltkf_mig_err', 'type' );
		}

		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			self::redirect_with_arg( 'ltkf_mig_err', 'dir' );
		}

		$dir = trailingslashit( $upload_dir['basedir'] ) . 'kf-migration';
		if ( ! wp_mkdir_p( $dir ) ) {
			self::redirect_with_arg( 'ltkf_mig_err', 'dir' );
		}

		$job_id = wp_generate_password( 16, false, false );
		$dest   = $dir . '/' . sanitize_file_name( $job_id ) . '.csv';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_move_uploaded_file -- Validated CSV to uploads subtree.
		if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
			self::redirect_with_arg( 'ltkf_mig_err', 'move' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local validated CSV in uploads.
		$fh = fopen( $dest, 'rb' );
		if ( false === $fh ) {
			wp_delete_file( $dest );
			self::redirect_with_arg( 'ltkf_mig_err', 'read' );
		}

		$header_line = fgets( $fh );
		if ( false === $header_line ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fh );
			wp_delete_file( $dest );
			self::redirect_with_arg( 'ltkf_mig_err', 'empty' );
		}

		$headers = self::parse_csv_line( $header_line );
		$map     = self::map_headers( $headers );
		if ( is_wp_error( $map ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fh );
			wp_delete_file( $dest );
			self::redirect_with_arg( 'ltkf_mig_err', 'headers' );
		}

		$data_rows = 0;
		while ( ! feof( $fh ) ) {
			$line = fgets( $fh );
			if ( false === $line ) {
				break;
			}
			++$data_rows;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $fh );

		$job = array(
			'user_id'        => get_current_user_id(),
			'file'           => $dest,
			'header_map'     => $map,
			'total_rows'     => $data_rows,
			'next_offset'    => 0,
			'created_owners' => 0,
			'created_pets'   => 0,
			'errors'         => array(),
		);

		set_transient( self::TRANSIENT_PREFIX . $job_id, $job, self::JOB_TTL );

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type' => ltkf_get_pet_post_type(),
					'page'      => self::PAGE_SLUG,
					'ltkf_job'    => $job_id,
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * AJAX: process next batch of rows.
	 *
	 * @return void
	 */
	public static function ajax_process_batch() {
		if ( ! check_ajax_referer( self::AJAX_BATCH, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'kennelflow-core' ) ), 403 );
		}

		if ( ! current_user_can( self::required_cap() ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'kennelflow-core' ) ), 403 );
		}

		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		if ( strlen( $job_id ) < 8 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid job.', 'kennelflow-core' ) ), 400 );
		}

		$job = get_transient( self::TRANSIENT_PREFIX . $job_id );
		if ( ! is_array( $job ) || empty( $job['file'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Job expired or not found. Upload the CSV again.', 'kennelflow-core' ) ), 404 );
		}

		if ( absint( $job['user_id'] ) !== get_current_user_id() ) {
			wp_send_json_error( array( 'message' => __( 'This import belongs to another user.', 'kennelflow-core' ) ), 403 );
		}

		$path = (string) $job['file'];
		if ( ! is_readable( $path ) ) {
			wp_send_json_error( array( 'message' => __( 'CSV file is no longer available.', 'kennelflow-core' ) ), 410 );
		}

		$result = self::process_rows( $job, self::BATCH_SIZE, $job_id );

		if ( ! $result['done'] ) {
			set_transient( self::TRANSIENT_PREFIX . $job_id, $result['job'], self::JOB_TTL );
		}

		wp_send_json_success(
			array(
				'done'             => $result['done'],
				'next_offset'      => (int) $result['job']['next_offset'],
				'total_rows'       => (int) $result['job']['total_rows'],
				'created_owners'   => (int) $result['job']['created_owners'],
				'created_pets'     => (int) $result['job']['created_pets'],
				'batch_rows'       => $result['batch_rows'],
				'errors'           => array_slice( $result['job']['errors'], -20 ),
				'progress_percent' => $result['job']['total_rows'] > 0
					? min( 100, round( 100 * $result['job']['next_offset'] / max( 1, $result['job']['total_rows'] ) ) )
					: 100,
			)
		);
	}

	/**
	 * Process up to $limit data rows starting at job next_offset.
	 *
	 * @param array  $job    Job transient payload.
	 * @param int    $limit  Max rows this request.
	 * @param string $job_id Job ID (transient key).
	 * @return array{ done: bool, batch_rows: int, job: array }
	 */
	protected static function process_rows( array $job, $limit, $job_id ) {
		$path = $job['file'];
		$map  = $job['header_map'];

		$email_idx = isset( $map['email'] ) ? (int) $map['email'] : -1;
		$pet_idx   = isset( $map['pet_name'] ) ? (int) $map['pet_name'] : -1;
		$name_idx  = isset( $map['owner_name'] ) ? (int) $map['owner_name'] : -1;

		add_filter( 'wp_send_new_user_notification_to_user', '__return_false', 99 );
		add_filter( 'wp_send_new_user_notification_to_admin', '__return_false', 99 );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Batch reader for uploaded CSV job.
		$fh = fopen( $path, 'rb' );
		if ( false === $fh ) {
			remove_filter( 'wp_send_new_user_notification_to_user', '__return_false', 99 );
			remove_filter( 'wp_send_new_user_notification_to_admin', '__return_false', 99 );
			$job['errors'][] = __( 'Could not read CSV file.', 'kennelflow-core' );
			delete_transient( self::TRANSIENT_PREFIX . $job_id );
			return array(
				'done'       => true,
				'batch_rows' => 0,
				'job'        => $job,
			);
		}

		// Header row.
		fgets( $fh );

		$to_skip = (int) $job['next_offset'];
		while ( $to_skip > 0 && ! feof( $fh ) ) {
			if ( false === fgets( $fh ) ) {
				break;
			}
			--$to_skip;
		}

		$batch = 0;
		while ( $batch < $limit && ! feof( $fh ) ) {
			$line = fgets( $fh );
			if ( false === $line ) {
				break;
			}

			++$job['next_offset'];
			++$batch;

			if ( '' === trim( $line ) ) {
				continue;
			}

			$row_number = (int) $job['next_offset'];

			$row = self::parse_csv_line( $line );
			if ( empty( $row ) ) {
				continue;
			}

			$email = isset( $row[ $email_idx ] ) ? sanitize_email( $row[ $email_idx ] ) : '';
			$pet   = isset( $row[ $pet_idx ] ) ? sanitize_text_field( $row[ $pet_idx ] ) : '';
			$owner = ( $name_idx >= 0 && isset( $row[ $name_idx ] ) ) ? sanitize_text_field( $row[ $name_idx ] ) : '';

			if ( '' === $email || ! is_email( $email ) ) {
				$job['errors'][] = sprintf(
					/* translators: %d: CSV row number (1-based). */
					__( 'Row %d: invalid email.', 'kennelflow-core' ),
					$row_number
				);
				continue;
			}

			if ( '' === $pet ) {
				$job['errors'][] = sprintf(
					/* translators: %d: CSV row number (1-based). */
					__( 'Row %d: missing pet name.', 'kennelflow-core' ),
					$row_number
				);
				continue;
			}

			$uid = email_exists( $email );
			if ( ! $uid ) {
				$login_base = sanitize_user( strstr( $email, '@', true ), true );
				if ( '' === $login_base ) {
					$login_base = 'owner';
				}
				$login = $login_base;
				$n     = 0;
				while ( username_exists( $login ) ) {
					++$n;
					$login = $login_base . '_' . $n;
				}
				$login = substr( $login, 0, 60 );

				$uid = wp_insert_user(
					array(
						'user_login'   => $login,
						'user_email'   => $email,
						'user_pass'    => wp_generate_password( 24, true, true ),
						'display_name' => '' !== $owner ? $owner : $login,
						'first_name'   => self::maybe_first_name( $owner ),
						'last_name'    => self::maybe_last_name( $owner ),
						'role'         => apply_filters( 'ltkf_migration_owner_role', ltkf_get_migration_owner_role_default() ),
					)
				);

				if ( is_wp_error( $uid ) ) {
					$job['errors'][] = sprintf(
						/* translators: 1: email, 2: error message */
						__( 'Could not create user %1$s: %2$s', 'kennelflow-core' ),
						$email,
						$uid->get_error_message()
					);
					continue;
				}
				++$job['created_owners'];
			}

			$uid = absint( $uid );

			ltkf_migration_ensure_pet_owner_role( $uid );

			$pet_id = wp_insert_post(
				array(
					'post_type'   => ltkf_get_pet_post_type(),
					'post_status' => 'publish',
					'post_title'  => $pet,
					'post_author' => $uid,
				),
				true
			);

			if ( is_wp_error( $pet_id ) ) {
				$job['errors'][] = sprintf(
					/* translators: 1: owner email, 2: WordPress error message */
					__( 'Could not create pet for %1$s: %2$s', 'kennelflow-core' ),
					$email,
					$pet_id->get_error_message()
				);
			} else {
				update_post_meta( (int) $pet_id, ltkf_get_pet_owner_user_meta_key(), $uid );
				OwnerPets::rebuild_user_pet_ids( $uid );
				++$job['created_pets'];
			}
		}

		$at_eof = feof( $fh );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $fh );

		remove_filter( 'wp_send_new_user_notification_to_user', '__return_false', 99 );
		remove_filter( 'wp_send_new_user_notification_to_admin', '__return_false', 99 );

		$done = $at_eof || $job['next_offset'] >= $job['total_rows'];

		if ( $done ) {
			wp_delete_file( $path );
			delete_transient( self::TRANSIENT_PREFIX . $job_id );
		}

		return array(
			'done'       => $done,
			'batch_rows' => $batch,
			'job'        => $job,
		);
	}

	/**
	 * Split owner display string into first name (best effort).
	 *
	 * @param string $owner Owner display string.
	 * @return string
	 */
	protected static function maybe_first_name( $owner ) {
		$owner = trim( (string) $owner );
		if ( '' === $owner ) {
			return '';
		}
		$parts = preg_split( '/\s+/', $owner, 2 );
		return isset( $parts[0] ) ? sanitize_text_field( $parts[0] ) : '';
	}

	/**
	 * Split owner display string into last name (best effort).
	 *
	 * @param string $owner Owner display string.
	 * @return string
	 */
	protected static function maybe_last_name( $owner ) {
		$owner = trim( (string) $owner );
		if ( '' === $owner ) {
			return '';
		}
		$parts = preg_split( '/\s+/', $owner, 2 );
		return isset( $parts[1] ) ? sanitize_text_field( $parts[1] ) : '';
	}

	/**
	 * Parse one CSV line (handles quoted fields).
	 *
	 * @param string $line Line.
	 * @return string[]
	 */
	protected static function parse_csv_line( $line ) {
		$line = (string) $line;
		$line = str_replace( "\r\n", "\n", $line );
		$line = rtrim( $line, "\n" );
		if ( '' === $line ) {
			return array();
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory CSV parse.
		$fp = fopen( 'php://memory', 'rb+' );
		if ( false === $fp ) {
			return str_getcsv( $line );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- In-memory buffer.
		fwrite( $fp, $line );
		rewind( $fp );
		$row = fgetcsv( $fp );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $fp );
		return is_array( $row ) ? $row : array();
	}

	/**
	 * Map CSV header labels to canonical keys.
	 *
	 * @param string[] $headers Header cells.
	 * @return array|WP_Error
	 */
	protected static function map_headers( array $headers ) {
		$map = array();
		foreach ( $headers as $i => $label ) {
			$key = self::normalize_header_label( $label );
			if ( '' === $key ) {
				continue;
			}
			if ( ! isset( $map[ $key ] ) ) {
				$map[ $key ] = (int) $i;
			}
		}

		if ( ! isset( $map['email'] ) || ! isset( $map['pet_name'] ) ) {
			return new \WP_Error(
				'ltkf_mig_headers',
				__( 'CSV must include an Email column and a Pet Name column (see documentation for accepted header names).', 'kennelflow-core' )
			);
		}

		return $map;
	}

	/**
	 * Normalize a header cell to a canonical key or empty.
	 *
	 * @param string $label Raw header.
	 * @return string
	 */
	protected static function normalize_header_label( $label ) {
		$label = trim( (string) $label );
		// Strip UTF-8 BOM (common in Excel / “Save as CSV”) so the first column maps correctly.
		if ( preg_match( '/^\xEF\xBB\xBF/', $label ) ) {
			$label = substr( $label, 3 );
		}
		$label = strtolower( $label );
		// Human headers use spaces ("Pet Name"); canonical keys use underscores ("pet_name").
		$label = str_replace( array( '-', '.' ), '_', $label );
		$label = preg_replace( '/\s+/', '_', $label );
		$label = preg_replace( '/_+/', '_', $label );
		$label = trim( $label, '_' );

		$email_keys = array( 'email', 'e_mail', 'owner_email', 'client_email', 'customer_email' );
		$pet_keys   = array( 'pet_name', 'pet', 'animal_name', 'animal', 'patient_name', 'patient' );
		$name_keys  = array( 'owner_name', 'owner', 'client_name', 'customer_name', 'name' );

		if ( in_array( $label, $email_keys, true ) ) {
			return 'email';
		}
		if ( in_array( $label, $pet_keys, true ) ) {
			return 'pet_name';
		}
		if ( in_array( $label, $name_keys, true ) ) {
			return 'owner_name';
		}

		return '';
	}

	/**
	 * Redirect back to migration page with error code.
	 *
	 * @param string $arg  Query arg name.
	 * @param string $code Error code.
	 * @return void
	 */
	protected static function redirect_with_arg( $arg, $code ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type' => ltkf_get_pet_post_type(),
					'page'      => self::PAGE_SLUG,
					$arg        => rawurlencode( (string) $code ),
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Admin UI.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( self::required_cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'kennelflow-core' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display args after upload redirect.
		$job_id = isset( $_GET['ltkf_job'] ) ? sanitize_text_field( wp_unslash( $_GET['ltkf_job'] ) ) : '';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only error code from safe redirect.
		$err = isset( $_GET['ltkf_mig_err'] ) ? sanitize_key( wp_unslash( $_GET['ltkf_mig_err'] ) ) : '';

		$messages = array(
			'no_file' => __( 'No file was uploaded.', 'kennelflow-core' ),
			'upload'  => __( 'Upload failed.', 'kennelflow-core' ),
			'type'    => __( 'Only .csv files are allowed.', 'kennelflow-core' ),
			'dir'     => __( 'Could not use uploads directory.', 'kennelflow-core' ),
			'move'    => __( 'Could not store the uploaded file.', 'kennelflow-core' ),
			'read'    => __( 'Could not read the file.', 'kennelflow-core' ),
			'empty'   => __( 'The file is empty.', 'kennelflow-core' ),
			'headers' => __( 'Missing required columns: Email and Pet Name (check header row spelling).', 'kennelflow-core' ),
		);

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Client migration (CSV)', 'kennelflow-core' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Import owners and pets from competitor exports. Required columns: Email, Pet Name. Optional: Owner Name.', 'kennelflow-core' ); ?>
			</p>

			<?php if ( '' !== $err && isset( $messages[ $err ] ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $messages[ $err ] ); ?></p></div>
			<?php endif; ?>

			<?php if ( '' === $job_id ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<?php wp_nonce_field( self::ACTION_UPLOAD ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_UPLOAD ); ?>" />
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="ltkf_csv"><?php esc_html_e( 'CSV file', 'kennelflow-core' ); ?></label></th>
							<td>
								<input type="file" name="ltkf_csv" id="ltkf_csv" accept=".csv,text/csv" required />
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Upload and start', 'kennelflow-core' ) ); ?>
				</form>
			<?php else : ?>
				<div id="kf-migration-progress" data-job-id="<?php echo esc_attr( $job_id ); ?>">
					<p><strong><?php esc_html_e( 'Processing…', 'kennelflow-core' ); ?></strong></p>
					<p class="kf-migration-status"></p>
					<progress id="kf-migration-bar" max="100" value="0" style="width: min(100%, 480px);"></progress>
					<ul class="kf-migration-log" style="margin-top:1em;max-height:240px;overflow:auto;background:#fff;border:1px solid #ccd0d4;padding:8px;"></ul>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
