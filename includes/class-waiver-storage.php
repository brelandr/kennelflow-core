<?php
/**
 * Store waiver PNGs next to KennelFlow Vet medical uploads (or same path without KennelFlow Vet).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class WaiverStorage
 */
class WaiverStorage {

	/**
	 * Subdirectory under protected medical uploads for waiver PNGs.
	 */
	const WAIVER_SUBDIR = 'kf-waivers';

	/**
	 * Max decoded PNG size (bytes).
	 */
	const MAX_BYTES = 2097152;

	/**
	 * Ensure WP_Filesystem is ready and FS_CHMOD_* constants exist (frontend AJAX safe).
	 *
	 * @return \WP_Filesystem_Base|false
	 */
	private static function get_filesystem() {
		global $wp_filesystem;

		require_once ABSPATH . 'wp-admin/includes/file.php';

		if ( empty( $wp_filesystem ) ) {
			WP_Filesystem( false, false, true );
		}

		if ( ! defined( 'FS_CHMOD_FILE' ) ) {
			$perms = @fileperms( ABSPATH . 'index.php' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort default when FS constants were never bootstrapped.
			define( 'FS_CHMOD_FILE', ( false !== $perms ? ( $perms & 0777 ) : 0 ) | 0644 );
		}

		if ( ! defined( 'FS_CHMOD_DIR' ) ) {
			$perms = @fileperms( ABSPATH ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort default when FS constants were never bootstrapped.
			define( 'FS_CHMOD_DIR', ( false !== $perms ? ( $perms & 0777 ) : 0 ) | 0755 );
		}

		return ! empty( $wp_filesystem ) ? $wp_filesystem : false;
	}

	/**
	 * Write a file via WP_Filesystem.
	 *
	 * @param string $path    Absolute path.
	 * @param string $content File contents.
	 * @return bool
	 */
	private static function put_file_contents( $path, $content ) {
		$wp_filesystem = self::get_filesystem();
		if ( ! $wp_filesystem ) {
			return false;
		}

		return (bool) $wp_filesystem->put_contents( $path, $content, FS_CHMOD_FILE );
	}

	/**
	 * Absolute uploads base + relative protected path (matches KennelFlow Vet default).
	 *
	 * @return string
	 */
	public static function get_protected_base_dir() {
		if ( class_exists( '\KennelFlow_Vet_Protected_Uploads' ) ) {
			return untrailingslashit( \KennelFlow_Vet_Protected_Uploads::get_medical_dir() );
		}

		$upload = wp_upload_dir();
		if ( empty( $upload['basedir'] ) ) {
			return '';
		}

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- KennelFlow Vet legacy documented tag (`ltkf_legacy_vet_medical_upload_subdir_hook()`).
		$subdir = apply_filters( 'ltkf_medical_upload_subdir', apply_filters( 'kennelflow_vet_medical_upload_subdir', 'kennelflow-vet-medical' ) );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$subdir = trim( str_replace( array( '..', '\\' ), '', (string) $subdir ), '/' );
		if ( '' === $subdir ) {
			$subdir = 'kennelflow-vet-medical';
		}

		return trailingslashit( $upload['basedir'] ) . $subdir;
	}

	/**
	 * Ensure directory exists and is not directly web-readable (Apache).
	 *
	 * @return void
	 */
	public static function maybe_bootstrap_protected_dir() {
		self::get_filesystem();

		if ( class_exists( '\KennelFlow_Vet_Protected_Uploads' ) ) {
			\KennelFlow_Vet_Protected_Uploads::maybe_create_htaccess();
			return;
		}

		$base = self::get_protected_base_dir();
		if ( '' === $base ) {
			return;
		}

		if ( ! wp_mkdir_p( $base ) ) {
			return;
		}

		$index = trailingslashit( $base ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			self::put_file_contents( $index, '' );
		}

		$ht = trailingslashit( $base ) . '.htaccess';
		if ( file_exists( $ht ) ) {
			return;
		}

		self::put_file_contents( $ht, "# KennelFlow — deny direct access to protected uploads\nDeny from all\n" );
	}

	/**
	 * Relative path under wp-content/uploads (not leading slash).
	 *
	 * @param string $filename Basename.
	 * @return string
	 */
	public static function relative_path_for_file( $filename ) {
		$upload = wp_upload_dir();
		if ( empty( $upload['basedir'] ) ) {
			return '';
		}

		$base = self::get_protected_base_dir();
		if ( '' === $base ) {
			return '';
		}

		$full = trailingslashit( $base ) . self::WAIVER_SUBDIR . '/' . $filename;

		return ltrim( str_replace( wp_normalize_path( $upload['basedir'] ), '', wp_normalize_path( $full ) ), '/' );
	}

	/**
	 * Build absolute path from meta relative path.
	 *
	 * @param string $relative Relative under uploads.
	 * @return string
	 */
	public static function absolute_path_from_relative( $relative ) {
		$relative = Waiver::sanitize_relative_path( $relative );
		if ( '' === $relative ) {
			return '';
		}

		$upload = wp_upload_dir();
		if ( empty( $upload['basedir'] ) ) {
			return '';
		}

		$path = trailingslashit( $upload['basedir'] ) . $relative;
		$path = wp_normalize_path( $path );
		$base = wp_normalize_path( trailingslashit( $upload['basedir'] ) );

		if ( 0 !== strpos( $path, $base ) ) {
			return '';
		}

		return $path;
	}

	/**
	 * Decode data URL, validate PNG, write file, update meta path.
	 *
	 * @param int    $pet_id   Pet post ID.
	 * @param string $data_url data:image/png;base64,....
	 * @return array|\WP_Error {
	 *     @type string $relative_path Path under uploads.
	 * }
	 */
	public static function save_png_data_url_for_pet( $pet_id, $data_url ) {
		$pet_id = absint( $pet_id );
		if ( $pet_id < 1 ) {
			return new \WP_Error( 'ltkf_waiver_bad_pet', __( 'Invalid pet.', 'kennelflow-core' ) );
		}

		$data_url = is_string( $data_url ) ? trim( $data_url ) : '';
		if ( '' === $data_url ) {
			return new \WP_Error( 'ltkf_waiver_empty', __( 'No signature data was sent.', 'kennelflow-core' ) );
		}

		if ( ! preg_match( '#^data:image/png;base64,#i', $data_url ) ) {
			return new \WP_Error( 'ltkf_waiver_format', __( 'Signature must be a PNG image.', 'kennelflow-core' ) );
		}

		$b64 = substr( $data_url, strpos( $data_url, ',' ) + 1 );
		$b64 = preg_replace( '/\s+/', '', $b64 );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding client PNG; validated immediately.
		$raw = base64_decode( $b64, true );

		if ( false === $raw ) {
			return new \WP_Error( 'ltkf_waiver_decode', __( 'Could not read signature data.', 'kennelflow-core' ) );
		}

		$len = strlen( $raw );
		if ( $len < 24 || $len > self::MAX_BYTES ) {
			return new \WP_Error( 'ltkf_waiver_size', __( 'Signature image is too large or too small.', 'kennelflow-core' ) );
		}

		if ( substr( $raw, 0, 8 ) !== pack( 'H*', '89504e470d0a1a0a' ) ) {
			return new \WP_Error( 'ltkf_waiver_png', __( 'Invalid PNG data.', 'kennelflow-core' ) );
		}

		self::maybe_bootstrap_protected_dir();

		$base = self::get_protected_base_dir();
		if ( '' === $base ) {
			return new \WP_Error( 'ltkf_waiver_dir', __( 'Upload directory is not available.', 'kennelflow-core' ) );
		}

		$dir = trailingslashit( $base ) . self::WAIVER_SUBDIR;
		if ( ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'ltkf_waiver_mkdir', __( 'Could not create storage directory.', 'kennelflow-core' ) );
		}

		$filename = 'kf-waiver-' . $pet_id . '-' . wp_generate_password( 8, false, false ) . '.png';
		$filename = sanitize_file_name( $filename );
		$full     = trailingslashit( $dir ) . $filename;

		$old_rel = (string) get_post_meta( $pet_id, Waiver::META_PATH, true );
		if ( '' !== $old_rel ) {
			$old_full = self::absolute_path_from_relative( $old_rel );
			if ( '' !== $old_full && is_readable( $old_full ) ) {
				wp_delete_file( $old_full );
			}
		}

		if ( ! self::put_file_contents( $full, $raw ) ) {
			return new \WP_Error( 'ltkf_waiver_write', __( 'Could not save signature file.', 'kennelflow-core' ) );
		}

		$rel = self::relative_path_for_file( $filename );
		if ( '' === $rel ) {
			wp_delete_file( $full );
			return new \WP_Error( 'ltkf_waiver_rel', __( 'Could not resolve stored path.', 'kennelflow-core' ) );
		}

		return array(
			'relative_path' => $rel,
		);
	}
}
