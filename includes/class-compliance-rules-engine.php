<?php
/**
 * Compliance rules: required vaccines vs kf_medical_records (expiration in UTC).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class ComplianceRulesEngine
 */
class ComplianceRulesEngine {

	/**
	 * Evaluate required vaccines for a pet using the latest matching kf_medical_records row per analyte.
	 *
	 * @param int $pet_id kf_pet post ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_pet_status( $pet_id ) {
		$pet_id = absint( $pet_id );
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

		$required = get_option( 'ltkf_required_vaccines', array() );
		if ( ! is_array( $required ) ) {
			$required = array();
		}

		$required = array_map(
			static function ( $v ) {
				return sanitize_text_field( (string) $v );
			},
			$required
		);
		$required = array_filter(
			$required,
			static function ( $v ) {
				return '' !== $v;
			}
		);
		$required = array_values( array_unique( $required ) );

		$checked_at = gmdate( 'Y-m-d H:i:s' );
		$vaccines   = array();

		if ( empty( $required ) ) {
			return array(
				'pet_id'         => $pet_id,
				'checked_at_gmt' => $checked_at,
				'vaccines'       => array(),
			);
		}

		$table = ltkf_medical_records_table_name();
		if ( ! ltkf_table_exists( $table ) ) {
			foreach ( $required as $label ) {
				$vaccines[ $label ] = array(
					'status'         => 'Missing',
					'expiration_gmt' => null,
					'record_id'      => 0,
				);
			}
			return array(
				'pet_id'         => $pet_id,
				'checked_at_gmt' => $checked_at,
				'vaccines'       => $vaccines,
			);
		}

		global $wpdb;

		$exclude = ltkf_medical_records_where_not_archived_for_prepare();
		$sql     = "SELECT `id`, `analyte_name`, `expiration_gmt`, `created_gmt` FROM `{$table}` WHERE `pet_post_id` = %d";
		$sql    .= $exclude['sql'];
		$sql    .= ' ORDER BY `created_gmt` DESC';

		if ( '' !== $exclude['sql'] ) {
			$prep_args = array_merge( array( $sql, $pet_id ), (array) $exclude['value'] );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Hub medical table; per request; fragment from ltkf_medical_records_where_not_archived_for_prepare().
			$rows = $wpdb->get_results( call_user_func_array( array( $wpdb, 'prepare' ), $prep_args ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Hub medical table; status column may be absent.
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $pet_id ), ARRAY_A );
		}

		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$latest_by_norm = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$norm = self::normalize_analyte( isset( $row['analyte_name'] ) ? (string) $row['analyte_name'] : '' );
			if ( '' === $norm ) {
				continue;
			}
			if ( ! isset( $latest_by_norm[ $norm ] ) ) {
				$latest_by_norm[ $norm ] = $row;
			}
		}

		foreach ( $required as $label ) {
			$norm = self::normalize_analyte( $label );
			if ( '' === $norm ) {
				continue;
			}

			if ( ! isset( $latest_by_norm[ $norm ] ) ) {
				$vaccines[ $label ] = array(
					'status'         => 'Missing',
					'expiration_gmt' => null,
					'record_id'      => 0,
				);
				continue;
			}

			$row        = $latest_by_norm[ $norm ];
			$record_id  = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
			$expiration = isset( $row['expiration_gmt'] ) ? $row['expiration_gmt'] : null;

			if ( null === $expiration || '' === (string) $expiration ) {
				$vaccines[ $label ] = array(
					'status'         => 'Missing',
					'expiration_gmt' => null,
					'record_id'      => $record_id,
				);
				continue;
			}

			$exp_str = self::normalize_mysql_gmt( (string) $expiration );
			if ( '' === $exp_str ) {
				$vaccines[ $label ] = array(
					'status'         => 'Missing',
					'expiration_gmt' => null,
					'record_id'      => $record_id,
				);
				continue;
			}

			if ( $exp_str < $checked_at ) {
				$status = 'Expired';
			} else {
				$status = 'Valid';
			}

			$vaccines[ $label ] = array(
				'status'         => $status,
				'expiration_gmt' => $exp_str,
				'record_id'      => $record_id,
			);
		}

		/**
		 * Filters the compliance status array for a pet.
		 *
		 * @since 0.2.0
		 *
		 * @param array<string, array<string, mixed>> $vaccines  Per-label rows.
		 * @param int                                 $pet_id    Pet post ID.
		 * @param array<int, string>                  $required Required vaccine labels.
		 */
		$vaccines = apply_filters( 'ltkf_pet_compliance_vaccines', $vaccines, $pet_id, $required );

		return array(
			'pet_id'         => $pet_id,
			'checked_at_gmt' => $checked_at,
			'vaccines'       => $vaccines,
		);
	}

	/**
	 * Normalize analyte for comparison (trim + lowercase).
	 *
	 * @param string $name Raw analyte name.
	 * @return string
	 */
	public static function normalize_analyte( $name ) {
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return '';
		}
		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( $name, 'UTF-8' );
		}
		return strtolower( $name );
	}

	/**
	 * Normalize MySQL datetime string to Y-m-d H:i:s for comparison.
	 *
	 * @param string $gmt Raw value from DB.
	 * @return string Empty if invalid.
	 */
	protected static function normalize_mysql_gmt( $gmt ) {
		$gmt = trim( (string) $gmt );
		if ( '' === $gmt ) {
			return '';
		}
		$ts = strtotime( $gmt . ' UTC' );
		if ( false === $ts ) {
			$ts = strtotime( $gmt );
		}
		if ( false === $ts ) {
			return '';
		}
		return gmdate( 'Y-m-d H:i:s', $ts );
	}
}
