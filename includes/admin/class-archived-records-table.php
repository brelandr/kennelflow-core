<?php
/**
 * Admin list table: archived kf_medical_records rows.
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class ArchivedRecordsTable
 */
class ArchivedRecordsTable extends \WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'ltkf_archived_record',
				'plural'   => 'ltkf_archived_records',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Column definitions.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'archived_date' => __( 'Archived Date', 'kennelflow-core' ),
			'pet'           => __( 'Pet ID / Name', 'kennelflow-core' ),
			'record_type'   => __( 'Record Type', 'kennelflow-core' ),
			'original_date' => __( 'Original Date', 'kennelflow-core' ),
		);
	}

	/**
	 * Message when no rows.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No archived records found.', 'kennelflow-core' );
	}

	/**
	 * Load rows for current page.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$table = ltkf_medical_records_table_name();
		if ( ! ltkf_table_exists( $table ) ) {
			$this->items = array();
			$this->set_pagination_args(
				array(
					'total_items' => 0,
					'per_page'    => 20,
					'total_pages' => 0,
				)
			);
			return;
		}

		global $wpdb;

		$per_page = 20;
		$paged    = max( 1, (int) $this->get_pagenum() );
		$offset   = ( $paged - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Admin vault; table name from helper.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE `status` = %s",
				ComplianceRetention::RECORD_STATUS_ARCHIVED
			)
		);

		$sql = $wpdb->prepare(
			"SELECT * FROM `{$table}` WHERE `status` = %s ORDER BY COALESCE(`archived_gmt`, `created_gmt`) DESC, `id` DESC LIMIT %d OFFSET %d",
			ComplianceRetention::RECORD_STATUS_ARCHIVED,
			$per_page,
			$offset
		);

		$rows = $wpdb->get_results( $sql );
		// phpcs:enable

		$this->items = is_array( $rows ) ? $rows : array();

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
			)
		);
	}

	/**
	 * Archived Date column + row actions.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_archived_date( $item ) {
		$record_id = isset( $item->id ) ? absint( $item->id ) : 0;
		$archived  = isset( $item->archived_gmt ) ? (string) $item->archived_gmt : '';

		if ( '' === $archived || '0000-00-00 00:00:00' === $archived ) {
			$display = '—';
		} else {
			$display = self::format_datetime_site( $archived );
		}

		$actions = array();
		if ( $record_id > 0 ) {
			$form  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;margin:0;padding:0;">';
			$form .= wp_nonce_field( 'ltkf_vault_restore_' . $record_id, '_wpnonce', true, false );
			$form .= '<input type="hidden" name="action" value="ltkf_restore_record" />';
			$form .= '<input type="hidden" name="record_id" value="' . esc_attr( (string) $record_id ) . '" />';
			$form .= '<button type="submit" class="button-link">' . esc_html__( 'Restore Record', 'kennelflow-core' ) . '</button>';
			$form .= '</form>';

			$actions['restore'] = $form;
		}

		return esc_html( $display ) . $this->row_actions( $actions );
	}

	/**
	 * Pet ID / Name column.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_pet( $item ) {
		$pet_id = isset( $item->pet_post_id ) ? absint( $item->pet_post_id ) : 0;
		if ( $pet_id < 1 ) {
			return esc_html__( '—', 'kennelflow-core' );
		}

		$post = get_post( $pet_id );
		if ( $post && ltkf_get_pet_post_type() === $post->post_type ) {
			$title = get_the_title( $post );
			if ( '' === $title ) {
				$title = sprintf(
					/* translators: %d: pet post ID */
					__( 'Pet #%d', 'kennelflow-core' ),
					$pet_id
				);
			}
			return esc_html( sprintf( '%d — %s', $pet_id, $title ) );
		}

		return esc_html(
			sprintf(
				/* translators: 1: pet post ID */
				__( '%1$d (missing)', 'kennelflow-core' ),
				$pet_id
			)
		);
	}

	/**
	 * Record Type column (HL7 / lab context).
	 *
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_record_type( $item ) {
		$type = isset( $item->hl7_message_type ) ? trim( (string) $item->hl7_message_type ) : '';
		if ( '' !== $type ) {
			return esc_html( $type );
		}

		$name = isset( $item->analyte_name ) ? trim( (string) $item->analyte_name ) : '';
		if ( '' !== $name ) {
			return esc_html( $name );
		}

		return esc_html__( 'Lab / medical', 'kennelflow-core' );
	}

	/**
	 * Original clinical / observation date.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_original_date( $item ) {
		$raw = self::get_original_date_gmt_string( $item );
		if ( null === $raw || '' === $raw || '0000-00-00 00:00:00' === $raw ) {
			return esc_html__( '—', 'kennelflow-core' );
		}

		return esc_html( self::format_datetime_site( $raw ) );
	}

	/**
	 * Primary column for responsive / row semantics.
	 *
	 * @return string
	 */
	protected function get_primary_column_name() {
		return 'archived_date';
	}

	/**
	 * GMT datetime string for “original” display (matches retention COALESCE logic).
	 *
	 * @param object $item Row.
	 * @return string|null
	 */
	protected static function get_original_date_gmt_string( $item ) {
		if ( ! is_object( $item ) ) {
			return null;
		}

		if ( isset( $item->last_visit_date ) ) {
			$lv = (string) $item->last_visit_date;
			if ( '' !== $lv && '0000-00-00 00:00:00' !== $lv ) {
				return $lv;
			}
		}

		foreach ( array( 'collected_gmt', 'reported_gmt', 'created_gmt' ) as $col ) {
			if ( isset( $item->{$col} ) ) {
				$v = (string) $item->{$col};
				if ( '' !== $v && '0000-00-00 00:00:00' !== $v ) {
					return $v;
				}
			}
		}

		return null;
	}

	/**
	 * Format MySQL datetime (stored GMT) for site timezone display.
	 *
	 * @param string $mysql_gmt Datetime string.
	 * @return string
	 */
	protected static function format_datetime_site( $mysql_gmt ) {
		try {
			$d = new DateTimeImmutable( $mysql_gmt, new DateTimeZone( 'UTC' ) );
		} catch ( Exception $e ) {
			unset( $e );
			return $mysql_gmt;
		}

		return wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			$d->getTimestamp(),
			wp_timezone()
		);
	}
}
