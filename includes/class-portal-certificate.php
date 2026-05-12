<?php
/**
 * Printable certificate download (HTML attachment; users print/save as PDF in browser).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class PortalCertificate
 */
class PortalCertificate {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_certificate' ), 1 );
	}

	/**
	 * Serve certificate HTML when query args are valid and record is owned.
	 *
	 * @return void
	 */
	public static function maybe_serve_certificate() {
		if ( ! isset( $_GET['ltkf_cert'], $_GET['record_id'], $_GET['_wpnonce'] ) ) {
			return;
		}

		if ( '1' !== sanitize_text_field( wp_unslash( $_GET['ltkf_cert'] ) ) ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( home_url( '/' ) ) );
			exit;
		}

		$record_id = absint( wp_unslash( $_GET['record_id'] ) );
		if ( $record_id < 1 ) {
			wp_die( esc_html__( 'Invalid record.', 'kennelflow-core' ), '', array( 'response' => 400 ) );
		}

		$nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'ltkf_cert_' . $record_id ) ) {
			wp_die( esc_html__( 'Invalid security token.', 'kennelflow-core' ), '', array( 'response' => 403 ) );
		}

		$user_id = get_current_user_id();
		$pet_ids = PortalData::get_owned_pet_ids_for_user( $user_id );

		$row = PortalData::get_medical_record_if_owned( $record_id, $pet_ids );
		if ( null === $row ) {
			wp_die( esc_html__( 'You do not have access to this record.', 'kennelflow-core' ), '', array( 'response' => 403 ) );
		}

		$pet_title = get_the_title( (int) $row->pet_post_id );
		if ( '' === $pet_title ) {
			$pet_title = __( 'Pet', 'kennelflow-core' );
		}

		$when = '';
		if ( ! empty( $row->reported_gmt ) ) {
			$when = (string) $row->reported_gmt;
		} elseif ( ! empty( $row->collected_gmt ) ) {
			$when = (string) $row->collected_gmt;
		} else {
			$when = (string) $row->created_gmt;
		}

		$title_line = isset( $row->analyte_name ) ? sanitize_text_field( (string) $row->analyte_name ) : __( 'Medical record', 'kennelflow-core' );

		$html = self::build_certificate_html(
			array(
				'site_name'  => sanitize_text_field( get_bloginfo( 'name' ) ),
				'pet_name'   => $pet_title,
				'title_line' => $title_line,
				'value'      => isset( $row->value_text ) ? sanitize_text_field( (string) $row->value_text ) : '',
				'unit'       => isset( $row->unit ) ? sanitize_text_field( (string) $row->unit ) : '',
				'ref'        => isset( $row->reference_text ) ? sanitize_text_field( (string) $row->reference_text ) : '',
				'when_gmt'   => $when,
				'flag'       => isset( $row->flag ) ? sanitize_key( (string) $row->flag ) : '',
			),
			$row
		);

		/**
		 * Filters certificate HTML before output.
		 *
		 * @since 0.2.0
		 *
		 * @param string $html Full document HTML.
		 * @param object $row    Medical record row.
		 */
		$html = apply_filters( 'ltkf_portal_certificate_html', $html, $row );

		$filename = 'certificate-' . (int) $row->pet_post_id . '-' . $record_id . '.html';

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( $filename ) . '"' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Intentional HTML document.
		echo $html;
		exit;
	}

	/**
	 * Build minimal printable certificate markup.
	 *
	 * @param array  $parts Display parts.
	 * @param object $row   DB row.
	 * @return string
	 */
	protected static function build_certificate_html( array $parts, $row ) {
		unset( $row );

		$when_display = '';
		if ( '' !== $parts['when_gmt'] ) {
			$ts = strtotime( $parts['when_gmt'] . ' UTC' );
			if ( false !== $ts ) {
				$when_display = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts, wp_timezone() );
			}
		}

		ob_start();
		// phpcs:disable WordPress.Files.FileLineEndings,Generic.WhiteSpace.ScopeIndent -- Printable HTML document template.
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php echo esc_html( $parts['site_name'] . ' — ' . $parts['title_line'] ); ?></title>
	<style>
		body { font-family: Georgia, "Times New Roman", serif; margin: 2rem; color: #111; }
		.brand { font-size: 1.5rem; margin-bottom: 2rem; border-bottom: 2px solid #333; padding-bottom: 0.5rem; }
		.label { font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.06em; color: #555; }
		.value { font-size: 1.15rem; margin: 0.25rem 0 1.25rem; }
		.footer { margin-top: 3rem; font-size: 0.9rem; color: #666; }
		@media print { body { margin: 0; } a { display: none; } }
	</style>
</head>
<body>
	<div class="brand"><?php echo esc_html( $parts['site_name'] ); ?></div>
	<p class="label"><?php esc_html_e( 'Patient', 'kennelflow-core' ); ?></p>
	<p class="value"><?php echo esc_html( $parts['pet_name'] ); ?></p>
	<p class="label"><?php esc_html_e( 'Record', 'kennelflow-core' ); ?></p>
	<p class="value"><?php echo esc_html( $parts['title_line'] ); ?></p>
	<?php if ( '' !== $parts['value'] ) : ?>
	<p class="label"><?php esc_html_e( 'Result', 'kennelflow-core' ); ?></p>
	<p class="value"><?php echo esc_html( trim( $parts['value'] . ( $parts['unit'] ? ' ' . $parts['unit'] : '' ) ) ); ?></p>
	<?php endif; ?>
	<?php if ( '' !== $parts['ref'] ) : ?>
	<p class="label"><?php esc_html_e( 'Reference', 'kennelflow-core' ); ?></p>
	<p class="value"><?php echo esc_html( $parts['ref'] ); ?></p>
	<?php endif; ?>
	<?php if ( '' !== $parts['flag'] ) : ?>
	<p class="label"><?php esc_html_e( 'Flag', 'kennelflow-core' ); ?></p>
	<p class="value"><?php echo esc_html( $parts['flag'] ); ?></p>
	<?php endif; ?>
	<?php if ( '' !== $when_display ) : ?>
	<p class="label"><?php esc_html_e( 'Date / time', 'kennelflow-core' ); ?></p>
	<p class="value"><?php echo esc_html( $when_display ); ?></p>
	<?php endif; ?>
	<p class="footer"><?php esc_html_e( 'Open this file in a browser and use Print → Save as PDF to store a PDF copy.', 'kennelflow-core' ); ?></p>
</body>
</html>
		<?php
		// phpcs:enable WordPress.Files.FileLineEndings,Generic.WhiteSpace.ScopeIndent
		return (string) ob_get_clean();
	}
}
