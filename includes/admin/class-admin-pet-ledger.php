<?php
/**
 * Admin: kf_pet — Health & Compliance Ledger (compliance status + immutable medical record history).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class AdminPetLedger
 */
class AdminPetLedger {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_boxes' ), 9 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Styles for ledger tables (pet edit screen only).
	 *
	 * @param string $hook_suffix Current screen hook.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ltkf_get_pet_post_type() !== $screen->post_type ) {
			return;
		}

		wp_add_inline_style(
			'wp-admin',
			'.kf-ledger-section { margin: 0 0 1.25rem; }
			.kf-ledger-section h3 { margin: 0 0 0.5rem; font-size: 13px; }
			.kf-ledger-status { font-size: 1.25rem; line-height: 1; vertical-align: middle; }
			.kf-ledger-status--valid { color: #00a32a; }
			.kf-ledger-status--bad { color: #d63638; }
			.kf-ledger-muted { color: #646970; font-size: 12px; }
			.kf-ledger-history-wrap { overflow-x: auto; }
			table.kf-ledger-history { margin-top: 0.5rem; }
			table.kf-ledger-history th { white-space: nowrap; }'
		);
	}

	/**
	 * Register meta box (above typical EMR boxes at priority 11).
	 *
	 * @return void
	 */
	public static function register_meta_boxes() {
		/**
		 * Whether to show the Health & Compliance Ledger meta box on kf_pet.
		 *
		 * @since 0.2.0
		 *
		 * @param bool $show Default true.
		 */
		if ( ! apply_filters( 'ltkf_show_pet_health_ledger_meta_box', true ) ) {
			return;
		}

		add_meta_box(
			'ltkf_hub_health_compliance_ledger',
			__( 'Health & Compliance Ledger', 'kennelflow-core' ),
			array( __CLASS__, 'render_meta_box' ),
			ltkf_get_pet_post_type(),
			'normal',
			'high'
		);
	}

	/**
	 * Render compliance summary + immutable history (read-only).
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public static function render_meta_box( $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			echo '<p>' . esc_html__( 'You do not have permission to view this ledger.', 'kennelflow-core' ) . '</p>';
			return;
		}

		$pet_id = absint( $post->ID );
		if ( $pet_id < 1 ) {
			echo '<p class="kf-ledger-muted">' . esc_html__( 'Save the pet as a draft or publish to view compliance and medical history.', 'kennelflow-core' ) . '</p>';
			return;
		}

		echo '<div class="kf-ledger-wrap">';

		self::render_compliance_section( $pet_id );
		self::render_history_section( $pet_id );

		echo '</div>';
	}

	/**
	 * Current status table from ltkf_get_pet_compliance_status().
	 *
	 * @param int $pet_id Pet post ID.
	 * @return void
	 */
	protected static function render_compliance_section( $pet_id ) {
		echo '<div class="kf-ledger-section">';
		echo '<h3>' . esc_html__( 'Current status (required vaccines)', 'kennelflow-core' ) . '</h3>';

		$status = ltkf_get_pet_compliance_status( $pet_id );
		if ( is_wp_error( $status ) ) {
			printf(
				'<p class="notice notice-error inline"><span>%s</span></p>',
				esc_html( $status->get_error_message() )
			);
			echo '</div>';
			return;
		}

		$vaccines = isset( $status['vaccines'] ) && is_array( $status['vaccines'] ) ? $status['vaccines'] : array();
		if ( empty( $vaccines ) ) {
			$required = ltkf_get_required_vaccines();
			if ( empty( $required ) ) {
				echo '<p class="kf-ledger-muted">' . esc_html__( 'No required vaccines are configured. Set them under KennelFlow → Compliance Rules.', 'kennelflow-core' ) . '</p>';
			} else {
				echo '<p class="kf-ledger-muted">' . esc_html__( 'No compliance rows to display.', 'kennelflow-core' ) . '</p>';
			}
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Vaccine', 'kennelflow-core' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Current status', 'kennelflow-core' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Expiration (UTC)', 'kennelflow-core' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $vaccines as $label => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$st = isset( $row['status'] ) ? (string) $row['status'] : 'Missing';
			$ok = ( 'Valid' === $st );

			echo '<tr>';
			echo '<td>' . esc_html( (string) $label ) . '</td>';
			echo '<td>';
			if ( $ok ) {
				echo '<span class="kf-ledger-status kf-ledger-status--valid" role="img" aria-label="' . esc_attr__( 'Valid', 'kennelflow-core' ) . '">&#10003;</span> ';
				echo '<span class="screen-reader-text">' . esc_html__( 'Valid', 'kennelflow-core' ) . '</span>';
			} else {
				echo '<span class="kf-ledger-status kf-ledger-status--bad" role="img" aria-label="' . esc_attr__( 'Expired or missing', 'kennelflow-core' ) . '">&#10007;</span> ';
				echo '<span class="screen-reader-text">' . esc_html( $st ) . '</span>';
				echo ' <span class="kf-ledger-muted">(' . esc_html( $st ) . ')</span>';
			}
			echo '</td>';
			$exp = isset( $row['expiration_gmt'] ) && null !== $row['expiration_gmt'] ? (string) $row['expiration_gmt'] : '';
			echo '<td>' . ( '' !== $exp ? esc_html( $exp ) : '—' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		if ( isset( $status['checked_at_gmt'] ) ) {
			printf(
				'<p class="kf-ledger-muted">%s %s</p>',
				esc_html__( 'Evaluated at (UTC):', 'kennelflow-core' ),
				esc_html( (string) $status['checked_at_gmt'] )
			);
		}
		echo '</div>';
	}

	/**
	 * Read-only history from kf_medical_records (newest first).
	 *
	 * @param int $pet_id Pet post ID.
	 * @return void
	 */
	protected static function render_history_section( $pet_id ) {
		echo '<div class="kf-ledger-section">';
		echo '<h3>' . esc_html__( 'Immutable history', 'kennelflow-core' ) . '</h3>';
		echo '<p class="kf-ledger-muted">' . esc_html__( 'Read-only audit trail. Rows cannot be deleted from this view.', 'kennelflow-core' ) . '</p>';

		$rows = self::query_medical_history( $pet_id );
		if ( empty( $rows ) ) {
			$table = ltkf_medical_records_table_name();
			if ( ! ltkf_table_exists( $table ) ) {
				echo '<p class="kf-ledger-muted">' . esc_html__( 'Medical records table is not installed yet.', 'kennelflow-core' ) . '</p>';
			} else {
				echo '<p class="kf-ledger-muted">' . esc_html__( 'No medical record rows for this pet.', 'kennelflow-core' ) . '</p>';
			}
			echo '</div>';
			return;
		}

		echo '<div class="kf-ledger-history-wrap">';
		echo '<table class="widefat striped kf-ledger-history"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Date entered (UTC)', 'kennelflow-core' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Type / analyte', 'kennelflow-core' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Result / value', 'kennelflow-core' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Expiration (UTC)', 'kennelflow-core' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Entered by', 'kennelflow-core' ) . '</th>';
		if ( self::history_has_status_column() ) {
			echo '<th scope="col">' . esc_html__( 'Record status', 'kennelflow-core' ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $rows as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$created   = isset( $r['created_gmt'] ) ? (string) $r['created_gmt'] : '';
			$type      = isset( $r['hl7_message_type'] ) ? trim( (string) $r['hl7_message_type'] ) : '';
			$analyte   = isset( $r['analyte_name'] ) ? (string) $r['analyte_name'] : '';
			$type_disp = '' !== $type ? $type . ' — ' . $analyte : $analyte;
			$val       = isset( $r['value_text'] ) ? (string) $r['value_text'] : '';
			$exp       = isset( $r['expiration_gmt'] ) && null !== $r['expiration_gmt'] ? (string) $r['expiration_gmt'] : '';
			$uid       = isset( $r['created_by'] ) ? absint( $r['created_by'] ) : 0;
			$user      = $uid > 0 ? get_userdata( $uid ) : null;
			$who       = $user ? $user->display_name : __( '—', 'kennelflow-core' );

			echo '<tr>';
			echo '<td>' . esc_html( $created ) . '</td>';
			echo '<td>' . esc_html( $type_disp ) . '</td>';
			echo '<td>' . esc_html( '' !== $val ? $val : '—' ) . '</td>';
			echo '<td>' . esc_html( '' !== $exp ? $exp : '—' ) . '</td>';
			echo '<td>' . esc_html( $who ) . '</td>';
			if ( self::history_has_status_column() && isset( $r['status'] ) ) {
				echo '<td>' . esc_html( (string) $r['status'] ) . '</td>';
			}
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';

		$limit = (int) apply_filters( 'ltkf_pet_ledger_medical_records_limit', 500 );
		if ( count( $rows ) >= $limit ) {
			printf(
				'<p class="kf-ledger-muted">%s</p>',
				sprintf(
					/* translators: %d: row limit */
					esc_html__( 'Showing the %d most recent rows.', 'kennelflow-core' ),
					$limit
				)
			);
		}

		echo '</div>';
	}

	/**
	 * Whether status column is present on kf_medical_records.
	 *
	 * @return bool
	 */
	protected static function history_has_status_column() {
		static $has = null;
		if ( null !== $has ) {
			return $has;
		}
		$table = ltkf_medical_records_table_name();
		$has   = ltkf_table_exists( $table ) && ltkf_db_column_exists( $table, 'status' );
		return $has;
	}

	/**
	 * All rows for pet (including archived), newest first, capped.
	 *
	 * @param int $pet_id Pet post ID.
	 * @return array<int, array<string, mixed>>
	 */
	protected static function query_medical_history( $pet_id ) {
		$pet_id = absint( $pet_id );
		if ( $pet_id < 1 ) {
			return array();
		}

		$table = ltkf_medical_records_table_name();
		if ( ! ltkf_table_exists( $table ) ) {
			return array();
		}

		$limit = (int) apply_filters( 'ltkf_pet_ledger_medical_records_limit', 500 );
		$limit = max( 1, min( 2000, $limit ) );

		$cols = '`id`, `analyte_name`, `value_text`, `expiration_gmt`, `created_gmt`, `created_by`, `hl7_message_type`';
		if ( ltkf_db_column_exists( $table, 'status' ) ) {
			$cols .= ', `status`';
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Columns validated; table from helper.
		$sql = "SELECT {$cols} FROM `{$table}` WHERE `pet_post_id` = %d ORDER BY `created_gmt` DESC LIMIT %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin ledger; capped.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $pet_id, $limit ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}
}
