<?php
/**
 * REST: Pet Report Card — boarded pets list + email dispatch.
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class ReportCardApi
 */
class ReportCardApi {

	const REST_NAMESPACE = 'kennelflow/v1';

	const ROUTE_BASE = '/report-card';

	/**
	 * Max decoded image size (bytes).
	 */
	const MAX_IMAGE_BYTES = 5242880;

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
			self::ROUTE_BASE . '/boarded-pets',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_boarded_pets' ),
				'permission_callback' => array( __CLASS__, 'permissions' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'send_report_card' ),
				'permission_callback' => array( __CLASS__, 'permissions' ),
				'args'                => array(
					'pet_id' => array(
						'description' => __( 'Pet post ID (kf_pet).', 'kennelflow-core' ),
						'type'        => 'integer',
						'required'    => true,
					),
					'tags'   => array(
						'description' => __( 'Status tags (e.g. Ate Well, Played).', 'kennelflow-core' ),
						'type'        => 'array',
						'required'    => false,
					),
					'notes'  => array(
						'description' => __( 'Free-form notes.', 'kennelflow-core' ),
						'type'        => 'string',
						'required'    => false,
					),
					'photo'  => array(
						'description' => __( 'Image as data URL (base64) or raw base64.', 'kennelflow-core' ),
						'type'        => 'string',
						'required'    => true,
					),
				),
			)
		);
	}

	/**
	 * Staff who can send report cards.
	 *
	 * @return bool
	 */
	public static function permissions() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * GET /kennelflow/v1/report-card/boarded-pets
	 *
	 * Pets with an active booking window containing “now” (UTC).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_boarded_pets( $request ) {
		unset( $request );

		$table = ltkf_bookings_table_name();
		if ( ! is_string( $table ) || ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) || ! ltkf_table_exists( $table ) ) {
			return new \WP_Error(
				'ltkf_no_bookings_table',
				__( 'Bookings table is not available.', 'kennelflow-core' ),
				array( 'status' => 503 )
			);
		}

		$now_gmt = current_time( 'mysql', true );

		global $wpdb;

		$exclude_statuses = apply_filters(
			'ltkf_report_card_excluded_statuses',
			array( 'cancelled', 'expired' )
		);
		$exclude_statuses = array_map( 'sanitize_key', (array) $exclude_statuses );
		$exclude_statuses = array_values( array_filter( array_unique( $exclude_statuses ) ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- `%i`; KennelFlow ledger; optional NOT IN from sanitized statuses.
		if ( empty( $exclude_statuses ) ) {
			$pet_ids = $wpdb->get_col(
				$wpdb->prepare(
					'
			SELECT DISTINCT b.pet_id
			FROM %i AS b
			WHERE b.start_gmt <= %s
			AND b.end_gmt >= %s
			AND b.pet_id > 0
			AND ( b.booking_kind IS NULL OR b.booking_kind = \'\' OR b.booking_kind <> %s )
			ORDER BY b.pet_id ASC',
					$table,
					$now_gmt,
					$now_gmt,
					'clinic'
				)
			);
		} else {
			$st_ph   = implode( ',', array_fill( 0, count( $exclude_statuses ), '%s' ) );
			$pet_ids = $wpdb->get_col(
				$wpdb->prepare(
					'
			SELECT DISTINCT b.pet_id
			FROM %i AS b
			WHERE b.start_gmt <= %s
			AND b.end_gmt >= %s
			AND b.pet_id > 0
			AND ( b.booking_kind IS NULL OR b.booking_kind = \'\' OR b.booking_kind <> %s )
			AND b.status NOT IN ( ' . $st_ph . ' )
			ORDER BY b.pet_id ASC',
					...array_merge(
						array( $table, $now_gmt, $now_gmt, 'clinic' ),
						$exclude_statuses
					)
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$pet_ids = array_values( array_filter( array_map( 'absint', is_array( $pet_ids ) ? $pet_ids : array() ) ) );

		$out = array();
		foreach ( $pet_ids as $pid ) {
			if ( ltkf_get_pet_post_type() !== get_post_type( $pid ) ) {
				continue;
			}
			$title = get_the_title( $pid );
			if ( '' === $title ) {
				$title = '#' . $pid;
			}
			$out[] = array(
				'id'   => $pid,
				'name' => $title,
			);
		}

		return rest_ensure_response(
			array(
				'pets'    => $out,
				'now_gmt' => $now_gmt,
			)
		);
	}

	/**
	 * POST /kennelflow/v1/report-card
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function send_report_card( $request ) {
		$pet_id = absint( $request->get_param( 'pet_id' ) );
		if ( $pet_id < 1 ) {
			return new \WP_Error(
				'ltkf_report_card_bad_pet',
				__( 'Invalid pet.', 'kennelflow-core' ),
				array( 'status' => 400 )
			);
		}

		if ( ltkf_get_pet_post_type() !== get_post_type( $pet_id ) ) {
			return new \WP_Error(
				'ltkf_report_card_bad_pet',
				__( 'Invalid pet.', 'kennelflow-core' ),
				array( 'status' => 400 )
			);
		}

		if ( ! self::pet_is_currently_boarded( $pet_id ) ) {
			return new \WP_Error(
				'ltkf_report_card_not_boarded',
				__( 'This pet does not have an active boarding stay right now.', 'kennelflow-core' ),
				array( 'status' => 400 )
			);
		}

		$owner_id = absint( get_post_meta( $pet_id, ltkf_get_pet_owner_user_meta_key(), true ) );
		if ( $owner_id < 1 ) {
			return new \WP_Error(
				'ltkf_report_card_no_owner',
				__( 'No owner is linked to this pet.', 'kennelflow-core' ),
				array( 'status' => 400 )
			);
		}

		$user = get_userdata( $owner_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return new \WP_Error(
				'ltkf_report_card_no_email',
				__( 'Owner does not have a valid email address.', 'kennelflow-core' ),
				array( 'status' => 400 )
			);
		}

		$tags = $request->get_param( 'tags' );
		if ( ! is_array( $tags ) ) {
			$tags = array();
		}
		$tags = array_values(
			array_filter(
				array_map(
					function ( $t ) {
						return sanitize_text_field( (string) $t );
					},
					$tags
				)
			)
		);
		$tags = array_slice( $tags, 0, 20 );

		$notes = $request->get_param( 'notes' );
		$notes = is_string( $notes ) ? sanitize_textarea_field( $notes ) : '';
		$notes = mb_substr( $notes, 0, 5000 );

		$photo_raw = $request->get_param( 'photo' );
		$photo_raw = is_string( $photo_raw ) ? $photo_raw : '';
		if ( '' === trim( $photo_raw ) ) {
			return new \WP_Error(
				'ltkf_report_card_no_photo',
				__( 'Please add a photo.', 'kennelflow-core' ),
				array( 'status' => 400 )
			);
		}

		$upload = self::save_photo_to_media_library( $pet_id, $photo_raw );
		if ( is_wp_error( $upload ) ) {
			return $upload;
		}

		$attachment_id = isset( $upload['attachment_id'] ) ? absint( $upload['attachment_id'] ) : 0;
		$image_url     = isset( $upload['url'] ) ? (string) $upload['url'] : '';
		if ( $attachment_id < 1 || '' === $image_url ) {
			return new \WP_Error(
				'ltkf_report_card_upload',
				__( 'Could not save the image.', 'kennelflow-core' ),
				array( 'status' => 500 )
			);
		}

		$pet_name = get_the_title( $pet_id );
		if ( '' === $pet_name ) {
			$pet_name = '#' . $pet_id;
		}

		$subject = sprintf(
			/* translators: %s: pet name */
			__( 'Daily Update for %s', 'kennelflow-core' ),
			$pet_name
		);

		/**
		 * URL for the “visit portal” link (defaults to home; same hook as waivers unless overridden).
		 *
		 * @since 0.2.9
		 *
		 * @param string $url Default portal URL.
		 */
		$default_portal = apply_filters( 'ltkf_waiver_portal_url', home_url( '/' ) );
		$portal_url     = apply_filters( 'ltkf_report_card_portal_url', $default_portal );
		$portal_url     = is_string( $portal_url ) ? esc_url_raw( $portal_url ) : esc_url_raw( home_url( '/' ) );

		$html = self::build_email_html( $pet_name, $image_url, $tags, $notes, $portal_url );

		/**
		 * Filters the HTML body of the pet report card email.
		 *
		 * @since 0.2.8
		 *
		 * @param string $html       HTML message.
		 * @param int    $pet_id     Pet post ID.
		 * @param int    $owner_id   Owner user ID.
		 * @param array  $tags       Tag strings.
		 * @param string $notes      Notes.
		 * @param string $portal_url Portal URL used in the template.
		 */
		$html = apply_filters( 'ltkf_report_card_email_html', $html, $pet_id, $owner_id, $tags, $notes, $portal_url );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		$sent = wp_mail( $user->user_email, $subject, $html, $headers );

		if ( ! $sent ) {
			return new \WP_Error(
				'ltkf_report_card_mail',
				__( 'The email could not be sent.', 'kennelflow-core' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success'       => true,
				'message'       => __( 'Report sent to the owner.', 'kennelflow-core' ),
				'attachment_id' => $attachment_id,
				'sent_to'       => $user->user_email,
			)
		);
	}

	/**
	 * Whether pet_id has at least one booking row covering “now”.
	 *
	 * @param int $pet_id Pet ID.
	 * @return bool
	 */
	protected static function pet_is_currently_boarded( $pet_id ) {
		$pet_id = absint( $pet_id );
		$table  = ltkf_bookings_table_name();
		if ( $pet_id < 1 || ! is_string( $table ) || ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) || ! ltkf_table_exists( $table ) ) {
			return false;
		}

		$now_gmt = current_time( 'mysql', true );

		global $wpdb;

		$exclude_statuses = apply_filters(
			'ltkf_report_card_excluded_statuses',
			array( 'cancelled', 'expired' )
		);
		$exclude_statuses = array_map( 'sanitize_key', (array) $exclude_statuses );
		$exclude_statuses = array_values( array_filter( array_unique( $exclude_statuses ) ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- `%i`; KennelFlow ledger.
		if ( empty( $exclude_statuses ) ) {
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					'
			SELECT COUNT(*) FROM %i AS b
			WHERE b.pet_id = %d
			AND b.start_gmt <= %s
			AND b.end_gmt >= %s
			AND ( b.booking_kind IS NULL OR b.booking_kind = \'\' OR b.booking_kind <> %s )',
					$table,
					$pet_id,
					$now_gmt,
					$now_gmt,
					'clinic'
				)
			);
		} else {
			$st_ph = implode( ',', array_fill( 0, count( $exclude_statuses ), '%s' ) );
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					'
			SELECT COUNT(*) FROM %i AS b
			WHERE b.pet_id = %d
			AND b.start_gmt <= %s
			AND b.end_gmt >= %s
			AND ( b.booking_kind IS NULL OR b.booking_kind = \'\' OR b.booking_kind <> %s )
			AND b.status NOT IN ( ' . $st_ph . ' )',
					...array_merge(
						array( $table, $pet_id, $now_gmt, $now_gmt, 'clinic' ),
						$exclude_statuses
					)
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return $count > 0;
	}

	/**
	 * Decode base64 / data URL and save to uploads; create attachment.
	 *
	 * @param int    $pet_id Pet post ID (for filename context).
	 * @param string $raw    Data URL or raw base64.
	 * @return array|\WP_Error {
	 *     @type int    $attachment_id Attachment ID.
	 *     @type string $url           Public URL.
	 * }
	 */
	protected static function save_photo_to_media_library( $pet_id, $raw ) {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return new \WP_Error( 'ltkf_rc_empty', __( 'Empty image data.', 'kennelflow-core' ) );
		}

		$binary = null;
		$ext    = 'jpg';

		if ( preg_match( '#^data:image/(jpeg|jpg|png|gif|webp);base64,#i', $raw, $m ) ) {
			$b64 = substr( $raw, strpos( $raw, ',' ) + 1 );
			$b64 = preg_replace( '/\s+/', '', $b64 );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding uploaded image; validated below.
			$binary = base64_decode( $b64, true );
			$type   = strtolower( $m[1] );
			if ( 'jpeg' === $type ) {
				$ext = 'jpg';
			} elseif ( in_array( $type, array( 'jpg', 'png', 'gif', 'webp' ), true ) ) {
				$ext = $type;
			}
		} else {
			$b64 = preg_replace( '/\s+/', '', $raw );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding uploaded image; validated below.
			$binary = base64_decode( $b64, true );
		}

		if ( false === $binary || strlen( $binary ) < 32 ) {
			return new \WP_Error( 'ltkf_rc_decode', __( 'Could not read the image.', 'kennelflow-core' ) );
		}

		if ( strlen( $binary ) > self::MAX_IMAGE_BYTES ) {
			return new \WP_Error( 'ltkf_rc_size', __( 'Image is too large.', 'kennelflow-core' ) );
		}

		if ( ! self::is_allowed_image_binary( $binary ) ) {
			return new \WP_Error( 'ltkf_rc_type', __( 'Please upload a JPEG, PNG, GIF, or WebP image.', 'kennelflow-core' ) );
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		$filename = 'kf-report-card-' . $pet_id . '-' . gmdate( 'Ymd-His' ) . '.' . $ext;

		$upload = wp_upload_bits( $filename, null, $binary );
		if ( ! empty( $upload['error'] ) ) {
			return new \WP_Error( 'ltkf_rc_upload_bits', $upload['error'] );
		}

		if ( empty( $upload['file'] ) || empty( $upload['url'] ) ) {
			return new \WP_Error( 'ltkf_rc_upload_bits', __( 'Upload failed.', 'kennelflow-core' ) );
		}

		$filetype = wp_check_filetype( $upload['file'], null );
		$mime     = isset( $filetype['type'] ) ? $filetype['type'] : 'image/jpeg';

		$attachment = array(
			'post_mime_type' => $mime,
			'post_title'     => sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_author'    => get_current_user_id(),
		);

		$attach_id = wp_insert_attachment( $attachment, $upload['file'], 0, true );
		if ( is_wp_error( $attach_id ) ) {
			if ( ! empty( $upload['file'] ) && file_exists( $upload['file'] ) ) {
				wp_delete_file( $upload['file'] );
			}
			return $attach_id;
		}

		$attach_id = absint( $attach_id );
		$metadata  = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
		if ( ! is_wp_error( $metadata ) && ! empty( $metadata ) ) {
			wp_update_attachment_metadata( $attach_id, $metadata );
		}

		$url = wp_get_attachment_url( $attach_id );
		if ( ! $url ) {
			$url = $upload['url'];
		}

		return array(
			'attachment_id' => $attach_id,
			'url'           => $url,
		);
	}

	/**
	 * Basic image signature check (JPEG / PNG / GIF / WebP).
	 *
	 * @param string $binary File contents.
	 * @return bool
	 */
	protected static function is_allowed_image_binary( $binary ) {
		if ( strlen( $binary ) < 12 ) {
			return false;
		}
		// JPEG.
		if ( "\xff\xd8\xff" === substr( $binary, 0, 3 ) ) {
			return true;
		}
		// PNG.
		if ( substr( $binary, 0, 8 ) === pack( 'H*', '89504e470d0a1a0a' ) ) {
			return true;
		}
		// GIF.
		if ( 'GIF87a' === substr( $binary, 0, 6 ) || 'GIF89a' === substr( $binary, 0, 6 ) ) {
			return true;
		}
		// WebP RIFF....WEBP.
		if ( 'RIFF' === substr( $binary, 0, 4 ) && 'WEBP' === substr( $binary, 8, 4 ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Default HTML email template (placeholders: {{pet_name}}, {{pet_name_attr}}, {{image_url}}, {{tags}}, {{notes}}, {{portal_url}}).
	 *
	 * Override via filter `kf_report_card_email_template_html`.
	 *
	 * @return string
	 */
	protected static function get_default_email_template() {
		return <<<'HTML'
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0" />
	<meta http-equiv="X-UA-Compatible" content="IE=edge" />
	<!--[if mso]><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml><![endif]-->
	<title>{{pet_name}}</title>
	<style type="text/css">
		.tag { display:inline-block; margin:4px 6px 4px 0; padding:8px 14px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; font-size:14px; font-weight:600; color:#1e40af; background:#eff6ff; border:1px solid #bfdbfe; border-radius:999px; }
		@media only screen and (max-width:600px) { .kf-rc-inner { width:100% !important; } .kf-rc-pad { padding-left:16px !important; padding-right:16px !important; } }
	</style>
</head>
<body style="margin:0;padding:0;background-color:#e2e8f0;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">
	<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#e2e8f0;">
		<tr>
			<td align="center" style="padding:24px 12px;">
				<table role="presentation" class="kf-rc-inner" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 10px 40px rgba(15,23,42,0.12);">
					<tr>
						<td class="kf-rc-pad" style="padding:32px 28px 12px;font-family:Georgia,'Times New Roman',serif;">
							<p style="margin:0 0 8px;font-size:12px;letter-spacing:0.12em;text-transform:uppercase;color:#64748b;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">{{report_label}}</p>
							<h1 style="margin:0;font-size:26px;line-height:1.25;font-weight:700;color:#0f172a;">{{headline}}</h1>
							<p style="margin:12px 0 0;font-size:15px;line-height:1.5;color:#475569;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">{{subhead}}</p>
						</td>
					</tr>
					<tr>
						<td style="padding:0 20px 24px;">
							<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-radius:12px;overflow:hidden;background:#f8fafc;">
								<tr>
									<td align="center" style="padding:0;">
										<img src="{{image_url}}" alt="{{pet_name_attr}}" width="560" style="display:block;width:100%;max-width:560px;height:auto;border:0;line-height:0;font-size:0;" />
									</td>
								</tr>
							</table>
						</td>
					</tr>
					<tr>
						<td class="kf-rc-pad" style="padding:0 28px 8px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
							<p style="margin:0 0 10px;font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;color:#334155;">{{tags_label}}</p>
							<div class="tags-wrap" style="margin:0;padding:0;">{{tags}}</div>
						</td>
					</tr>
					<tr>
						<td class="kf-rc-pad" style="padding:16px 28px 8px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
							<p style="margin:0 0 10px;font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;color:#334155;">{{notes_label}}</p>
							<div style="font-size:16px;line-height:1.6;color:#1e293b;">{{notes}}</div>
						</td>
					</tr>
					<tr>
						<td class="kf-rc-pad" style="padding:8px 28px 32px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
							<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-top:1px solid #e2e8f0;padding-top:24px;">
								<tr>
									<td align="center">
										<a href="{{portal_url}}" style="display:inline-block;padding:14px 28px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;background-color:#2563eb;border-radius:10px;">{{portal_cta}}</a>
									</td>
								</tr>
								<tr>
									<td align="center" style="padding-top:16px;">
										<p style="margin:0;font-size:12px;color:#94a3b8;">{{portal_hint}}</p>
									</td>
								</tr>
							</table>
						</td>
					</tr>
				</table>
			</td>
		</tr>
	</table>
</body>
</html>
HTML;
	}

	/**
	 * Build tag markup: each tag wrapped in &lt;span class="tag"&gt;…&lt;/span&gt;.
	 *
	 * @param string[] $tags Tag strings.
	 * @return string
	 */
	protected static function build_email_tags_spans( array $tags ) {
		$html = '';
		foreach ( $tags as $t ) {
			if ( '' === $t ) {
				continue;
			}
			$html .= '<span class="tag">' . esc_html( $t ) . '</span>';
		}
		return $html;
	}

	/**
	 * HTML email body from template + placeholder replacement.
	 *
	 * @param string   $pet_name   Pet display name.
	 * @param string   $image_url  Attachment URL.
	 * @param string[] $tags       Tags.
	 * @param string   $notes      Notes (plain).
	 * @param string   $portal_url Escaped portal URL.
	 * @return string
	 */
	protected static function build_email_html( $pet_name, $image_url, array $tags, $notes, $portal_url ) {
		/**
		 * Filters the raw HTML template before placeholders are replaced.
		 *
		 * Placeholders: {{pet_name}}, {{pet_name_attr}}, {{image_url}}, {{tags}}, {{notes}}, {{portal_url}},
		 * {{headline}}, {{subhead}}, {{report_label}}, {{tags_label}}, {{notes_label}}, {{portal_cta}}, {{portal_hint}}.
		 *
		 * @since 0.2.9
		 *
		 * @param string $template Default template.
		 */
		$template = apply_filters( 'ltkf_report_card_email_template_html', self::get_default_email_template() );
		if ( ! is_string( $template ) || '' === $template ) {
			$template = self::get_default_email_template();
		}

		/* translators: %s: pet display name */
		$headline = sprintf( __( 'Daily update for %s', 'kennelflow-core' ), $pet_name );

		$tags_html = self::build_email_tags_spans( $tags );

		$notes_html = '';
		if ( '' !== $notes ) {
			$notes_html = nl2br( esc_html( $notes ) );
		}

		$replacements = array(
			'{{pet_name}}'      => esc_html( $pet_name ),
			'{{pet_name_attr}}' => esc_attr( $pet_name ),
			'{{image_url}}'     => esc_url( $image_url ),
			'{{tags}}'          => $tags_html,
			'{{notes}}'         => $notes_html,
			'{{portal_url}}'    => esc_url( $portal_url ),
			'{{headline}}'      => esc_html( $headline ),
			'{{subhead}}'       => esc_html__( 'From your boarding team — here is how things are going today.', 'kennelflow-core' ),
			'{{report_label}}'  => esc_html__( 'Daily report', 'kennelflow-core' ),
			'{{tags_label}}'    => esc_html__( 'Status', 'kennelflow-core' ),
			'{{notes_label}}'   => esc_html__( 'Notes', 'kennelflow-core' ),
			'{{portal_cta}}'    => esc_html__( 'Open your portal', 'kennelflow-core' ),
			'{{portal_hint}}'   => esc_html__( 'Book visits, view updates, and manage your pet profile.', 'kennelflow-core' ),
		);

		return str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
	}
}
