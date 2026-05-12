<?php
/**
 * Restricted admin UI: archived medical records (Data Vault).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class ArchiveVaultAdmin
 */
class ArchiveVaultAdmin {

	const PAGE_SLUG = 'kf-data-vault';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_kf_restore_record', array( __CLASS__, 'handle_restore_post' ) );
	}

	/**
	 * Submenu under KennelFlow (Pets).
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_submenu_page(
			ltkf_get_hub_menu_slug(),
			__( 'Data Vault', 'kennelflow-core' ),
			__( 'Data Vault', 'kennelflow-core' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Handle POST from Data Vault: restore an archived medical record (admin-post.php).
	 *
	 * @return void
	 */
	public static function handle_restore_post() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission Denied', 'kennelflow-core' ), esc_html__( 'Access denied', 'kennelflow-core' ), array( 'response' => 403 ) );
		}

		if ( ! isset( $_POST['record_id'] ) || ! isset( $_POST['_wpnonce'] ) ) {
			wp_die( esc_html__( 'Invalid request.', 'kennelflow-core' ), esc_html__( 'Error', 'kennelflow-core' ), array( 'response' => 400 ) );
		}

		$record_id = absint( wp_unslash( $_POST['record_id'] ) );
		if ( $record_id < 1 ) {
			wp_die( esc_html__( 'Invalid request.', 'kennelflow-core' ), esc_html__( 'Error', 'kennelflow-core' ), array( 'response' => 400 ) );
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'ltkf_vault_restore_' . $record_id ) ) {
			wp_die( esc_html__( 'Invalid security token.', 'kennelflow-core' ), esc_html__( 'Error', 'kennelflow-core' ), array( 'response' => 403 ) );
		}

		$redirect_error = add_query_arg(
			array(
				'page'  => self::PAGE_SLUG,
				'error' => '1',
			),
			admin_url( 'edit.php?post_type=' . ltkf_get_pet_post_type() )
		);

		$redirect_success = add_query_arg(
			array(
				'page'     => self::PAGE_SLUG,
				'restored' => '1',
			),
			admin_url( 'edit.php?post_type=' . ltkf_get_pet_post_type() )
		);

		$table = ltkf_medical_records_table_name();
		if ( ! ltkf_table_exists( $table ) ) {
			wp_safe_redirect( $redirect_error );
			exit;
		}

		ComplianceRetention::maybe_upgrade_medical_records_schema();

		global $wpdb;

		$has_archived_gmt = self::db_column_exists( $table, 'archived_gmt' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Single-row restore.
		$updated = $wpdb->update(
			$table,
			array( 'status' => ComplianceRetention::RECORD_STATUS_ACTIVE ),
			array(
				'id'     => $record_id,
				'status' => ComplianceRetention::RECORD_STATUS_ARCHIVED,
			),
			array( '%s' ),
			array( '%d', '%s' )
		);

		if ( false === $updated || (int) $wpdb->rows_affected < 1 ) {
			wp_safe_redirect( $redirect_error );
			exit;
		}

		if ( $has_archived_gmt ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Clear archive timestamp; wpdb->update does not set SQL NULL reliably here.
			$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET `archived_gmt` = NULL WHERE `id` = %d", $record_id ) );
			// phpcs:enable
		}

		self::maybe_log_kennelflow_vet_audit_restore( $record_id );

		wp_safe_redirect( $redirect_success );
		exit;
	}

	/**
	 * Append a KennelFlow Vet audit row when the audit table exists (compliance trail).
	 *
	 * @param int $record_id kf_medical_records primary key.
	 * @return void
	 */
	protected static function maybe_log_kennelflow_vet_audit_restore( $record_id ) {
		global $wpdb;

		$record_id = absint( $record_id );
		if ( $record_id < 1 ) {
			return;
		}

		$audit_table = $wpdb->prefix . 'kennelflow_vet_audit_log';
		if ( ! ltkf_table_exists( $audit_table ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return;
		}

		$old_json = wp_json_encode(
			array(
				'status' => ComplianceRetention::RECORD_STATUS_ARCHIVED,
			)
		);
		$new_json = wp_json_encode(
			array(
				'status' => ComplianceRetention::RECORD_STATUS_ACTIVE,
			)
		);
		if ( false === $old_json ) {
			$old_json = '';
		}
		if ( false === $new_json ) {
			$new_json = '';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Compliance audit append.
		$wpdb->insert(
			$audit_table,
			array(
				'entity_type' => 'medical_record',
				'entity_id'   => $record_id,
				'user_id'     => $user_id,
				'action'      => 'Restored from Archive',
				'old_value'   => $old_json,
				'new_value'   => $new_json,
				'created_gmt' => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Whether a column exists (same pattern as retention; kept local to avoid widening retention API).
	 *
	 * @param string $table  Full table name.
	 * @param string $column Column name.
	 * @return bool
	 */
	protected static function db_column_exists( $table, $column ) {
		global $wpdb;

		$table  = (string) $table;
		$column = (string) $column;
		if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) || ! preg_match( '/^[a-zA-Z0-9_]+$/', $column ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- One-off schema probe.
		$n = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				DB_NAME,
				$table,
				$column
			)
		);

		return $n > 0;
	}

	/**
	 * Render vault screen.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission Denied', 'kennelflow-core' ), esc_html__( 'Access denied', 'kennelflow-core' ), array( 'response' => 403 ) );
		}

		ComplianceRetention::maybe_upgrade_medical_records_schema();

		$table = ltkf_medical_records_table_name();
		if ( ! ltkf_table_exists( $table ) ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Data Vault', 'kennelflow-core' ) . '</h1>';
			echo '<p>' . esc_html__( 'The medical records table is not installed. Activate KennelFlow Vet or the integration that creates it.', 'kennelflow-core' ) . '</p></div>';
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flags from redirect after restore.
		if ( isset( $_GET['restored'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['restored'] ) ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Record restored.', 'kennelflow-core' ) . '</p></div>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flags from redirect after failed restore.
		if ( isset( $_GET['error'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['error'] ) ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Could not restore the record.', 'kennelflow-core' ) . '</p></div>';
		}

		$list_table = new ArchivedRecordsTable();
		$list_table->prepare_items();

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Data Vault', 'kennelflow-core' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Archived medical records (soft-deleted by retention policy). Only administrators can view this list.', 'kennelflow-core' ) . '</p>';
		echo '<form method="get">';
		echo '<input type="hidden" name="post_type" value="' . esc_attr( ltkf_get_pet_post_type() ) . '" />';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';
		$list_table->display();
		echo '</form>';
		echo '</div>';
	}
}
