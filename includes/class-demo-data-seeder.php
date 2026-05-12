<?php
/**
 * Demo data generator: Action Scheduler batches (owners → pets → medical → KennelFlow Boarding bookings).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class DemoDataSeeder
 */
class DemoDataSeeder {

	/**
	 * User and post meta flag for demo-tagged entities.
	 */
	const META_DEMO = '_kf_is_demo_data';

	/**
	 * Action Scheduler group name.
	 */
	const AS_GROUP = 'kf-demo-data';

	/**
	 * Hook name for each batch worker.
	 */
	const HOOK_BATCH = 'ltkf_generate_demo_batch';

	/**
	 * Owners created per async batch.
	 */
	const OWNERS_PER_BATCH = 10;

	/**
	 * Option: current seed run state (target, counters, run id).
	 */
	const OPTION_STATE = 'ltkf_demo_seed_state';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::HOOK_BATCH, array( __CLASS__, 'run_batch' ), 10, 1 );
	}

	/**
	 * Queue Action Scheduler jobs for the chosen scale.
	 *
	 * @param string $scale {@see AdminDemoData::SCALE_SMALL} or SCALE_ENTERPRISE.
	 * @return true|WP_Error
	 */
	public static function queue_for_scale( $scale ) {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return new \WP_Error(
				'ltkf_demo_no_as',
				__( 'Action Scheduler is not available. Install WooCommerce or the Action Scheduler plugin.', 'kennelflow-core' )
			);
		}

		if ( 'small' !== $scale && 'enterprise' !== $scale ) {
			return new \WP_Error( 'ltkf_demo_bad_scale', __( 'Invalid scale.', 'kennelflow-core' ) );
		}

		$target_pets = ( 'enterprise' === $scale ) ? 1000 : 100;
		$batches     = ( 'enterprise' === $scale ) ? 100 : 10;

		$run_id = wp_generate_password( 12, false, false );

		$state = array(
			'run_id'       => $run_id,
			'scale'        => $scale,
			'target_pets'  => $target_pets,
			'created_pets' => 0,
			'started_gmt'  => current_time( 'mysql', true ),
		);

		update_option( self::OPTION_STATE, $state, false );

		for ( $i = 0; $i < $batches; $i++ ) {
			as_enqueue_async_action( self::HOOK_BATCH, array( $i ), self::AS_GROUP );
		}

		return true;
	}

	/**
	 * Remove all demo-tagged entities (only rows/posts/users with _kf_is_demo_data).
	 *
	 * Order: kf_commissions (demo meta_json) → bookings → medical index rows (SQL) → pets → users.
	 *
	 * Demo staff (veterinarians, groomers, managers from Step 1) carry `_kf_is_demo_data` user meta and are
	 * removed in the final `get_users` + `wp_delete_user` pass alongside demo subscriber owners.
	 *
	 * @return int[] Keys: bookings, medical, commissions, pets, users.
	 */
	public static function nuke_demo_data() {
		$counts = array(
			'bookings'    => 0,
			'medical'     => 0,
			'commissions' => 0,
			'pets'        => 0,
			'users'       => 0,
		);

		$meta_key = self::META_DEMO;

		global $wpdb;

		$com_table = $wpdb->prefix . 'ltkf_commissions';
		if ( function_exists( 'ltkf_table_exists' ) && ltkf_table_exists( $com_table )
			&& function_exists( 'ltkf_db_column_exists' ) && ltkf_db_column_exists( $com_table, 'meta_json' ) ) {
			$like = '%' . $wpdb->esc_like( '_kf_is_demo_data' ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Demo nuke; prefix + literal table name; LIKE escaped.
			$delc = $wpdb->query( $wpdb->prepare( "DELETE FROM `{$com_table}` WHERE meta_json LIKE %s", $like ) );
			if ( is_numeric( $delc ) ) {
				$counts['commissions'] = (int) $delc;
			}
		}

		if ( post_type_exists( 'kennelpress_booking' ) ) {
			$booking_ids = get_posts(
				array(
					'post_type'              => 'kennelpress_booking',
					'post_status'            => 'any',
					'posts_per_page'         => -1,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'meta_query'             => array(
						array(
							'key'   => $meta_key,
							'value' => '1',
						),
					),
				)
			);
			foreach ( $booking_ids as $bid ) {
				$bid = absint( $bid );
				if ( $bid < 1 ) {
					continue;
				}
				$del = wp_delete_post( $bid, true );
				if ( false !== $del && null !== $del ) {
					++$counts['bookings'];
				}
			}
		}

		if ( function_exists( 'ltkf_medical_records_table_name' ) && function_exists( 'ltkf_table_exists' ) && function_exists( 'ltkf_db_column_exists' ) ) {
			$table = ltkf_medical_records_table_name();
			if ( ltkf_table_exists( $table ) && ltkf_db_column_exists( $table, 'meta_json' ) ) {
				$like = '%' . $wpdb->esc_like( '_kf_is_demo_data' ) . '%';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Demo nuke; table from helper; LIKE escaped.
				$deleted_med = $wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE meta_json LIKE %s", $like ) );
				if ( is_numeric( $deleted_med ) ) {
					$counts['medical'] = (int) $deleted_med;
				}
			}
		}

		$pet_type = function_exists( 'ltkf_get_pet_post_type' ) ? ltkf_get_pet_post_type() : '';
		if ( '' !== $pet_type && post_type_exists( $pet_type ) ) {
			$pet_ids = get_posts(
				array(
					'post_type'              => $pet_type,
					'post_status'            => 'any',
					'posts_per_page'         => -1,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'meta_query'             => array(
						array(
							'key'   => $meta_key,
							'value' => '1',
						),
					),
				)
			);
			foreach ( $pet_ids as $pid ) {
				$pid = absint( $pid );
				if ( $pid < 1 ) {
					continue;
				}
				$del = wp_delete_post( $pid, true );
				if ( false !== $del && null !== $del ) {
					++$counts['pets'];
				}
			}
		}

		// Demo subscribers, veterinarians, groomers, and manager accounts (all tagged with META_DEMO).
		$user_ids = get_users(
			array(
				'fields'     => 'ID',
				'meta_key'   => $meta_key,
				'meta_value' => '1',
				'number'     => 999999,
			)
		);
		foreach ( $user_ids as $uid ) {
			$uid = absint( $uid );
			if ( $uid < 2 ) {
				continue;
			}
			if ( wp_delete_user( $uid ) ) {
				++$counts['users'];
			}
		}

		delete_option( self::OPTION_STATE );

		return $counts;
	}

	/**
	 * Action Scheduler callback: one batch of demo owners (and their pets, medical, bookings).
	 *
	 * @param int|string $batch_index Batch number (0-based).
	 * @return void
	 */
	public static function run_batch( $batch_index ) {
		$batch_index = absint( $batch_index );

		$state = get_option( self::OPTION_STATE, null );
		if ( ! is_array( $state ) || empty( $state['run_id'] ) ) {
			return;
		}

		if ( (int) $state['created_pets'] >= (int) $state['target_pets'] ) {
			return;
		}

		if ( 0 === $batch_index && empty( $state['demo_staff_seeded'] ) ) {
			self::generate_demo_staff( (string) $state['run_id'] );
			$state = get_option( self::OPTION_STATE, array() );
			if ( is_array( $state ) ) {
				$state['demo_staff_seeded'] = 1;
				update_option( self::OPTION_STATE, $state, false );
			}
		}

		for ( $o = 0; $o < self::OWNERS_PER_BATCH; $o++ ) {
			$state = get_option( self::OPTION_STATE, array() );
			if ( ! is_array( $state ) || (int) $state['created_pets'] >= (int) $state['target_pets'] ) {
				break;
			}

			$user_id = self::create_demo_owner( (string) $state['run_id'], $batch_index, $o );
			if ( $user_id < 1 ) {
				continue;
			}

			$pets_for_owner = wp_rand( 1, 3 );
			for ( $p = 0; $p < $pets_for_owner; $p++ ) {
				$state = get_option( self::OPTION_STATE, array() );
				if ( ! is_array( $state ) || (int) $state['created_pets'] >= (int) $state['target_pets'] ) {
					break 2;
				}

				$pet_id = self::create_demo_pet( $user_id );
				if ( $pet_id < 1 ) {
					continue;
				}

				self::insert_rabies_record( $pet_id, $user_id );
				self::insert_demo_kennelflow_vet_kf_medical_records( $pet_id, $user_id );
				self::create_demo_bookings_for_pet( $pet_id );
				self::create_demo_omni_bookings_for_pet( $pet_id );

				$state['created_pets'] = (int) $state['created_pets'] + 1;
				update_option( self::OPTION_STATE, $state, false );
			}
		}
	}

	/**
	 * Ensure vet/groomer roles exist for demo staff (minimal caps; add-ons may enrich).
	 *
	 * @return void
	 */
	protected static function ensure_veterinarian_and_groomer_roles() {
		if ( ! get_role( 'veterinarian' ) ) {
			add_role(
				'veterinarian',
				__( 'Veterinarian', 'kennelflow-core' ),
				array(
					'read'       => true,
					'edit_posts' => true,
				)
			);
		}

		if ( ! get_role( 'groomer' ) ) {
			add_role(
				'groomer',
				__( 'Groomer', 'kennelflow-core' ),
				array(
					'read'       => true,
					'edit_posts' => true,
				)
			);
		}
	}

	/**
	 * Build `_kf_location_roster` payload: Mon–Fri 08:00–17:00 for each location ID.
	 *
	 * @param int[] $location_ids kf_location post IDs.
	 * @return array<string, array<string, array{start:string,end:string}>>
	 */
	protected static function build_demo_location_roster( array $location_ids ) {
		$day_block = array(
			'mon' => array(
				'start' => '08:00',
				'end'   => '17:00',
			),
			'tue' => array(
				'start' => '08:00',
				'end'   => '17:00',
			),
			'wed' => array(
				'start' => '08:00',
				'end'   => '17:00',
			),
			'thu' => array(
				'start' => '08:00',
				'end'   => '17:00',
			),
			'fri' => array(
				'start' => '08:00',
				'end'   => '17:00',
			),
		);

		$roster = array();
		foreach ( $location_ids as $lid ) {
			$lid = absint( $lid );
			if ( $lid < 1 ) {
				continue;
			}
			$roster[ $lid ] = $day_block;
		}

		return $roster;
	}

	/**
	 * Create demo veterinarians, groomers, and manager admins with full location rosters.
	 *
	 * @param string $run_id Seed run id (unique emails/logins).
	 * @return void
	 */
	protected static function generate_demo_staff( $run_id ) {
		$run_id = sanitize_key( (string) $run_id );
		if ( '' === $run_id ) {
			return;
		}

		self::ensure_veterinarian_and_groomer_roles();

		$loc_pt = function_exists( 'ltkf_get_location_post_type' ) ? ltkf_get_location_post_type() : 'kf_location';
		if ( '' === $loc_pt || ! post_type_exists( $loc_pt ) ) {
			$location_ids = array();
		} else {
			$location_ids = get_posts(
				array(
					'post_type'              => $loc_pt,
					'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
					'posts_per_page'         => -1,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
				)
			);
			$location_ids = is_array( $location_ids ) ? $location_ids : array();
		}

		$roster          = self::build_demo_location_roster( $location_ids );
		$meta_key_roster = class_exists( 'AdminClinicianProfiles' ) ? AdminClinicianProfiles::META_LOCATION_ROSTER : '_kf_location_roster';

		$uniq = wp_generate_password( 4, false, false );

		$vets = array(
			array(
				'display' => __( 'Dr. Demo Vet 1', 'kennelflow-core' ),
				'login'   => 'demo_dr_vet_1_' . $run_id,
				'email'   => sprintf( 'demo.dr.vet1.%s.%s@example.com', $run_id, strtolower( $uniq ) ),
			),
			array(
				'display' => __( 'Dr. Demo Vet 2', 'kennelflow-core' ),
				'login'   => 'demo_dr_vet_2_' . $run_id,
				'email'   => sprintf( 'demo.dr.vet2.%s.%s@example.com', $run_id, strtolower( $uniq ) ),
			),
			array(
				'display' => __( 'Dr. Demo Vet 3', 'kennelflow-core' ),
				'login'   => 'demo_dr_vet_3_' . $run_id,
				'email'   => sprintf( 'demo.dr.vet3.%s.%s@example.com', $run_id, strtolower( $uniq ) ),
			),
		);

		foreach ( $vets as $row ) {
			self::insert_demo_staff_user( $row['login'], $row['email'], $row['display'], 'veterinarian', $roster, $meta_key_roster );
		}

		$groomers = array(
			array(
				'display' => __( 'Demo Groomer 1', 'kennelflow-core' ),
				'login'   => 'demo_groomer_1_' . $run_id,
				'email'   => sprintf( 'demo.groomer1.%s.%s@example.com', $run_id, strtolower( $uniq ) ),
			),
			array(
				'display' => __( 'Demo Groomer 2', 'kennelflow-core' ),
				'login'   => 'demo_groomer_2_' . $run_id,
				'email'   => sprintf( 'demo.groomer2.%s.%s@example.com', $run_id, strtolower( $uniq ) ),
			),
			array(
				'display' => __( 'Demo Groomer 3', 'kennelflow-core' ),
				'login'   => 'demo_groomer_3_' . $run_id,
				'email'   => sprintf( 'demo.groomer3.%s.%s@example.com', $run_id, strtolower( $uniq ) ),
			),
		);

		foreach ( $groomers as $row ) {
			self::insert_demo_staff_user( $row['login'], $row['email'], $row['display'], 'groomer', $roster, $meta_key_roster );
		}

		$managers = array(
			array(
				'display' => __( 'Demo Manager 1', 'kennelflow-core' ),
				'login'   => 'demo_manager_1_' . $run_id,
				'email'   => sprintf( 'demo.manager1.%s.%s@example.com', $run_id, strtolower( $uniq ) ),
			),
			array(
				'display' => __( 'Demo Manager 2', 'kennelflow-core' ),
				'login'   => 'demo_manager_2_' . $run_id,
				'email'   => sprintf( 'demo.manager2.%s.%s@example.com', $run_id, strtolower( $uniq ) ),
			),
		);

		foreach ( $managers as $row ) {
			self::insert_demo_staff_user( $row['login'], $row['email'], $row['display'], 'administrator', $roster, $meta_key_roster );
		}
	}

	/**
	 * Create one demo staff user, tag demo meta, save roster.
	 *
	 * @param string $user_login   Login (unique).
	 * @param string $email        Email.
	 * @param string $display_name Display name.
	 * @param string $role         Role slug.
	 * @param array  $roster       Roster for `_kf_location_roster`.
	 * @param string $meta_key_roster User meta key for roster.
	 * @return void
	 */
	protected static function insert_demo_staff_user( $user_login, $email, $display_name, $role, array $roster, $meta_key_roster ) {
		$user_login = sanitize_user( (string) $user_login, true );
		$email      = sanitize_email( (string) $email );
		$role       = sanitize_key( (string) $role );
		if ( '' === $user_login || '' === $email || '' === $role ) {
			return;
		}

		if ( ! in_array( $role, array( 'veterinarian', 'groomer', 'administrator' ), true ) ) {
			return;
		}

		if ( get_user_by( 'login', $user_login ) ) {
			return;
		}

		$pass = wp_generate_password( 32, true, true );
		$uid  = wp_create_user( $user_login, $pass, $email );
		if ( is_wp_error( $uid ) || $uid < 1 ) {
			return;
		}

		$user = new \WP_User( $uid );
		$user->set_role( $role );

		wp_update_user(
			array(
				'ID'           => (int) $uid,
				'display_name' => $display_name,
			)
		);

		update_user_meta( (int) $uid, self::META_DEMO, 1 );

		if ( ! empty( $roster ) && '' !== $meta_key_roster ) {
			update_user_meta( (int) $uid, $meta_key_roster, $roster );
		}
	}

	/**
	 * Create a subscriber with demo user meta.
	 *
	 * @param string $run_id    Run id.
	 * @param int    $batch_idx Batch index.
	 * @param int    $owner_idx Owner index in batch.
	 * @return int User ID or 0.
	 */
	protected static function create_demo_owner( $run_id, $batch_idx, $owner_idx ) {
		$uniq    = wp_generate_password( 8, false, false );
		$email   = sprintf( 'demo.%s.b%d-o%d-%s@example.com', $run_id, $batch_idx, $owner_idx, strtolower( $uniq ) );
		$pass    = wp_generate_password( 24, true, true );
		$first   = self::random_from( self::first_names() );
		$last    = self::random_from( self::last_names() );
		$user_id = wp_create_user( $email, $pass, $email );

		if ( is_wp_error( $user_id ) ) {
			return 0;
		}

		$user = new \WP_User( $user_id );
		$user->set_role( 'subscriber' );

		wp_update_user(
			array(
				'ID'           => $user_id,
				'display_name' => $first . ' ' . $last,
				'first_name'   => $first,
				'last_name'    => $last,
			)
		);

		update_user_meta( $user_id, self::META_DEMO, 1 );

		return (int) $user_id;
	}

	/**
	 * Create kf_pet with owner link and demo meta.
	 *
	 * @param int $owner_user_id Owner user ID.
	 * @return int Pet post ID or 0.
	 */
	protected static function create_demo_pet( $owner_user_id ) {
		$name  = self::random_from( self::dog_names() );
		$breed = self::random_from( self::dog_breeds() );
		$title = sprintf(
			/* translators: 1: pet name, 2: breed */
			__( '%1$s (%2$s)', 'kennelflow-core' ),
			$name,
			$breed
		);

		$post_id = wp_insert_post(
			array(
				'post_type'   => ltkf_get_pet_post_type(),
				'post_status' => 'publish',
				'post_title'  => $title,
			),
			true
		);

		if ( is_wp_error( $post_id ) || $post_id < 1 ) {
			return 0;
		}

		update_post_meta( $post_id, ltkf_get_pet_owner_user_meta_key(), $owner_user_id );
		update_post_meta( $post_id, self::META_DEMO, 1 );

		if ( class_exists( 'OwnerPets' ) ) {
			OwnerPets::rebuild_user_pet_ids( $owner_user_id );
		}

		return (int) $post_id;
	}

	/**
	 * Insert a valid Rabies row into kf_medical_records.
	 *
	 * @param int $pet_id Pet post ID.
	 * @param int $uid    Acting user for created_by.
	 * @return void
	 */
	protected static function insert_rabies_record( $pet_id, $uid ) {
		$table = ltkf_medical_records_table_name();
		if ( ! function_exists( 'ltkf_table_exists' ) || ! ltkf_table_exists( $table ) ) {
			return;
		}

		if ( class_exists( 'ComplianceRetention' ) ) {
			ComplianceRetention::maybe_upgrade_medical_records_schema();
		}

		if ( ! function_exists( 'ltkf_db_column_exists' ) || ! ltkf_db_column_exists( $table, 'expiration_gmt' ) ) {
			return;
		}

		$utc = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		try {
			$expiration_gmt = $utc->modify( '+' . wp_rand( 180, 730 ) . ' days' )->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			unset( $e );
			return;
		}

		$meta      = array(
			'demo'             => true,
			'vaccine'          => 'Rabies',
			'_kf_is_demo_data' => true,
		);
		$meta_json = wp_json_encode( $meta );
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
			'obx_sequence'     => 1,
			'analyte_code'     => 'DEMO_RABIES',
			'analyte_name'     => 'Rabies',
			'value_text'       => 'Valid',
			'unit'             => '',
			'reference_text'   => '',
			'flag'             => '',
			'collected_gmt'    => null,
			'reported_gmt'     => null,
			'expiration_gmt'   => $expiration_gmt,
			'meta_json'        => $meta_json,
			'created_gmt'      => $now_gmt,
			'created_by'       => $uid,
		);

		$formats = array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' );

		if ( ltkf_db_column_exists( $table, 'status' ) ) {
			$data['status'] = class_exists( 'ComplianceRetention' ) ? ComplianceRetention::RECORD_STATUS_ACTIVE : 'active';
			$formats[]      = '%s';
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Demo seed insert; table from helper.
		$wpdb->insert( $table, $data, $formats );
	}

	/**
	 * Insert KennelFlow Vet-oriented demo rows into kf_medical_records (labs, DICOM stub, SOAP-style note).
	 *
	 * The hub table uses HL7-shaped columns; logical `record_type` is stored in `hl7_message_type` and meta_json
	 * (`notes` are stored in `reference_text`; there is no `notes` column).
	 *
	 * @param int $pet_id Pet post ID.
	 * @param int $uid    Created-by user ID.
	 * @return void
	 */
	protected static function insert_demo_kennelflow_vet_kf_medical_records( $pet_id, $uid ) {
		$table = ltkf_medical_records_table_name();
		if ( ! function_exists( 'ltkf_table_exists' ) || ! ltkf_table_exists( $table ) ) {
			return;
		}
		if ( ! function_exists( 'ltkf_db_column_exists' ) || ! ltkf_db_column_exists( $table, 'meta_json' ) ) {
			return;
		}

		if ( class_exists( 'ComplianceRetention' ) ) {
			ComplianceRetention::maybe_upgrade_medical_records_schema();
		}

		$pet_id = absint( $pet_id );
		$uid    = absint( $uid );
		if ( $pet_id < 1 || $uid < 1 ) {
			return;
		}

		$days_ago = wp_rand( 14, 180 );
		$base_ts  = time() - ( $days_ago * DAY_IN_SECONDS );
		$coll     = gmdate( 'Y-m-d H:i:s', $base_ts + ( wp_rand( 0, 3600 ) ) );
		$rep      = gmdate( 'Y-m-d H:i:s', $base_ts + ( wp_rand( 3600, 86400 ) ) );

		self::insert_demo_kf_medical_emr_row(
			$pet_id,
			$uid,
			'lab_result',
			'DEMO_CBC',
			'CBC',
			'WNL',
			'Complete Blood Count (CBC) - All levels within normal limits.',
			array(),
			$coll,
			$rep
		);

		self::insert_demo_kf_medical_emr_row(
			$pet_id,
			$uid,
			'dicom_image',
			'DEMO_DICOM_CHEST',
			'Thoracic radiograph (DICOM)',
			'',
			'Lateral thorax radiograph. Clear lungs.',
			array(
				'file' => 'demo_chest_xray.dcm',
			),
			$coll,
			$rep
		);

		self::insert_demo_kf_medical_emr_row(
			$pet_id,
			$uid,
			'soap_note',
			'DEMO_SOAP',
			'SOAP — wellness',
			'',
			'S: Patient is active. O: Temp 101.2F, HR 120. A: Healthy adult. P: Continue current diet.',
			array(),
			$coll,
			$rep
		);
	}

	/**
	 * One kf_medical_records row for demo EMR content (non-vaccine).
	 *
	 * @param int    $pet_id         Pet post ID.
	 * @param int    $uid            Created-by user ID.
	 * @param string $record_type    Stored in hl7_message_type and meta_json.record_type.
	 * @param string $analyte_code   Short code.
	 * @param string $analyte_name   Title / label.
	 * @param string $value_text     Optional value column.
	 * @param string $notes_text     Narrative (reference_text).
	 * @param array  $meta_json      Merged into meta_json (must include _kf_is_demo_data for nuke cleanup).
	 * @param string $collected_gmt  UTC datetime.
	 * @param string $reported_gmt   UTC datetime.
	 * @return void
	 */
	protected static function insert_demo_kf_medical_emr_row(
		$pet_id,
		$uid,
		$record_type,
		$analyte_code,
		$analyte_name,
		$value_text,
		$notes_text,
		array $meta_json,
		$collected_gmt,
		$reported_gmt
	) {
		$table = ltkf_medical_records_table_name();
		if ( ! function_exists( 'ltkf_table_exists' ) || ! ltkf_table_exists( $table ) ) {
			return;
		}

		$pet_id      = absint( $pet_id );
		$uid         = absint( $uid );
		$record_type = sanitize_key( (string) $record_type );
		if ( $pet_id < 1 || $uid < 1 || '' === $record_type ) {
			return;
		}

		$meta_json['record_type']      = $record_type;
		$meta_json['_kf_is_demo_data'] = true;

		$payload = wp_json_encode( $meta_json );
		if ( false === $payload ) {
			$payload = '{}';
		}

		$notes_text = (string) $notes_text;
		if ( function_exists( 'mb_substr' ) ) {
			$notes_text = mb_substr( $notes_text, 0, 255, 'UTF-8' );
		} else {
			$notes_text = substr( $notes_text, 0, 255 );
		}

		$now_gmt = current_time( 'mysql', true );

		$data = array(
			'pet_post_id'      => $pet_id,
			'pid_primary_id'   => '',
			'hl7_message_type' => $record_type,
			'hl7_control_id'   => '',
			'obx_set_id'       => '',
			'obx_sequence'     => 1,
			'analyte_code'     => sanitize_text_field( (string) $analyte_code ),
			'analyte_name'     => sanitize_text_field( (string) $analyte_name ),
			'value_text'       => sanitize_text_field( (string) $value_text ),
			'unit'             => '',
			'reference_text'   => sanitize_text_field( $notes_text ),
			'flag'             => '',
			'collected_gmt'    => $collected_gmt,
			'reported_gmt'     => $reported_gmt,
			'expiration_gmt'   => null,
			'meta_json'        => $payload,
			'created_gmt'      => $now_gmt,
			'created_by'       => $uid,
		);

		$formats = array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' );

		if ( function_exists( 'ltkf_db_column_exists' ) && ltkf_db_column_exists( $table, 'status' ) ) {
			$data['status'] = class_exists( 'ComplianceRetention' ) ? ComplianceRetention::RECORD_STATUS_ACTIVE : 'active';
			$formats[]      = '%s';
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Demo seed insert; table from helper.
		$wpdb->insert( $table, $data, $formats );
	}

	/**
	 * Create KennelFlow Boarding (`kennelpress_booking`) rows for a pet (historical + future) when the boarding add-on is active.
	 *
	 * @param int $pet_id Pet post ID.
	 * @return void
	 */
	protected static function create_demo_bookings_for_pet( $pet_id ) {
		if ( ! self::kennelpress_ready() ) {
			return;
		}

		$kennel_ids = self::get_boarding_kennel_ids();
		if ( empty( $kennel_ids ) ) {
			return;
		}

		$n_past   = wp_rand( 2, 5 );
		$n_future = wp_rand( 1, 2 );

		$windows = self::build_non_overlapping_windows( $n_past, $n_future );

		foreach ( $windows as $w ) {
			$pick      = self::random_from( $kennel_ids );
			$kennel_id = is_numeric( $pick ) ? (int) $pick : 0;
			if ( $kennel_id < 1 ) {
				continue;
			}
			self::create_boarding_booking_post( $pet_id, $kennel_id, $w['start'], $w['end'], $w['past'] );
		}
	}

	/**
	 * Demo sentinel order_id for kf_commissions rows created without WooCommerce.
	 *
	 * @var int
	 */
	const DEMO_COMMISSION_ORDER_ID = 990000000;

	/**
	 * Past clinic + grooming Omni-Bookings using demo veterinarian / groomer users; GroomPress commissions for grooming.
	 *
	 * @param int $pet_id Pet post ID.
	 * @return void
	 */
	protected static function create_demo_omni_bookings_for_pet( $pet_id ) {
		if ( ! self::kennelpress_ready() ) {
			return;
		}

		$staff = self::get_demo_veterinarian_and_groomer_ids();
		if ( empty( $staff['vets'] ) || empty( $staff['groomers'] ) ) {
			return;
		}

		$pet_id = absint( $pet_id );
		if ( $pet_id < 1 ) {
			return;
		}

		$n_clinic = wp_rand( 1, 2 );
		$n_groom  = wp_rand( 1, 2 );

		for ( $i = 0; $i < $n_clinic; $i++ ) {
			$vet_id = self::random_from( $staff['vets'] );
			$vet_id = is_numeric( $vet_id ) ? absint( $vet_id ) : 0;
			if ( $vet_id < 1 ) {
				continue;
			}
			$loc = self::get_demo_location_id_for_staff_user( $vet_id );
			if ( $loc < 1 ) {
				continue;
			}
			$w = self::build_past_clinic_grooming_window();
			self::create_omni_booking_post( $pet_id, 'clinic', $vet_id, $loc, $w['start'], $w['end'] );
		}

		for ( $j = 0; $j < $n_groom; $j++ ) {
			$groom_id = self::random_from( $staff['groomers'] );
			$groom_id = is_numeric( $groom_id ) ? absint( $groom_id ) : 0;
			if ( $groom_id < 1 ) {
				continue;
			}
			$loc = self::get_demo_location_id_for_staff_user( $groom_id );
			if ( $loc < 1 ) {
				continue;
			}
			$w   = self::build_past_clinic_grooming_window();
			$bid = self::create_omni_booking_post( $pet_id, 'grooming', $groom_id, $loc, $w['start'], $w['end'] );
			if ( $bid > 0 ) {
				self::maybe_insert_demo_grooming_commission( $bid, $groom_id );
			}
		}
	}

	/**
	 * Users tagged demo with veterinarian or groomer roles.
	 *
	 * @return array{vets: int[], groomers: int[]}
	 */
	protected static function get_demo_veterinarian_and_groomer_ids() {
		$out = array(
			'vets'     => array(),
			'groomers' => array(),
		);

		$users = get_users(
			array(
				'meta_key'     => self::META_DEMO,
				'meta_value'   => '1',
				'meta_compare' => '=',
				'number'       => 500,
				'fields'       => 'all',
			)
		);

		if ( ! is_array( $users ) ) {
			return $out;
		}

		foreach ( $users as $u ) {
			if ( ! $u instanceof \WP_User ) {
				continue;
			}
			$roles = (array) $u->roles;
			if ( in_array( 'veterinarian', $roles, true ) ) {
				$out['vets'][] = (int) $u->ID;
			}
			if ( in_array( 'groomer', $roles, true ) ) {
				$out['groomers'][] = (int) $u->ID;
			}
		}

		return $out;
	}

	/**
	 * Pick a kf_location for calendar meta from roster or first published location.
	 *
	 * @param int $user_id Staff user ID.
	 * @return int Location post ID or 0.
	 */
	protected static function get_demo_location_id_for_staff_user( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id < 1 ) {
			return 0;
		}

		$meta_key = class_exists( 'AdminClinicianProfiles' )
			? AdminClinicianProfiles::META_LOCATION_ROSTER
			: '_kf_location_roster';
		$roster   = get_user_meta( $user_id, $meta_key, true );
		if ( is_array( $roster ) ) {
			foreach ( array_keys( $roster ) as $k ) {
				$lid = absint( $k );
				if ( $lid > 0 ) {
					return $lid;
				}
			}
		}

		return self::get_first_kf_location_id();
	}

	/**
	 * First published kf_location post ID.
	 *
	 * @return int
	 */
	protected static function get_first_kf_location_id() {
		$pt = function_exists( 'ltkf_get_location_post_type' ) ? ltkf_get_location_post_type() : 'kf_location';
		if ( '' === $pt || ! post_type_exists( $pt ) ) {
			return 0;
		}

		$ids = get_posts(
			array(
				'post_type'              => $pt,
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
			)
		);

		return ( ! empty( $ids ) && isset( $ids[0] ) ) ? absint( $ids[0] ) : 0;
	}

	/**
	 * Random past interval for short clinic/grooming appointments (GMT).
	 *
	 * @return array{start:string,end:string}
	 */
	protected static function build_past_clinic_grooming_window() {
		$now      = time();
		$days_ago = wp_rand( 15, 280 );
		$start_ts = $now - ( $days_ago * DAY_IN_SECONDS ) - wp_rand( 0, DAY_IN_SECONDS );
		$dur_secs = wp_rand( 25, 150 ) * MINUTE_IN_SECONDS;

		return array(
			'start' => gmdate( 'Y-m-d H:i:s', $start_ts ),
			'end'   => gmdate( 'Y-m-d H:i:s', $start_ts + $dur_secs ),
		);
	}

	/**
	 * Create a completed kennelpress_booking for clinic or grooming; resource is a WordPress user ID (veterinarian / groomer).
	 *
	 * @param int    $pet_id           Pet post ID.
	 * @param string $kind             clinic|grooming.
	 * @param int    $resource_user_id Vet or groomer user ID.
	 * @param int    $location_id      kf_location post ID.
	 * @param string $start_gmt        Start UTC mysql.
	 * @param string $end_gmt          End UTC mysql.
	 * @return int Booking post ID or 0.
	 */
	protected static function create_omni_booking_post( $pet_id, $kind, $resource_user_id, $location_id, $start_gmt, $end_gmt ) {
		if ( ! class_exists( 'KennelFlow_Boarding_Post_Meta' ) ) {
			return 0;
		}

		$pet_id = absint( $pet_id );
		$kind   = sanitize_key( (string) $kind );
		if ( $pet_id < 1 || ! in_array( $kind, array( 'clinic', 'grooming' ), true ) ) {
			return 0;
		}

		$resource_user_id = absint( $resource_user_id );
		$location_id      = absint( $location_id );
		if ( $resource_user_id < 1 || $location_id < 1 ) {
			return 0;
		}

		$label = 'clinic' === $kind
			? __( 'Demo clinic visit', 'kennelflow-core' )
			: __( 'Demo grooming', 'kennelflow-core' );

		$title = sprintf(
			/* translators: 1: label, 2: pet post ID */
			__( '%1$s — pet %2$d', 'kennelflow-core' ),
			$label,
			$pet_id
		);

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'kennelpress_booking',
				'post_status' => 'publish',
				'post_title'  => $title,
			),
			true
		);

		if ( is_wp_error( $post_id ) || $post_id < 1 ) {
			return 0;
		}

		update_post_meta( $post_id, KennelFlow_Boarding_Post_Meta::BOOKING_PET_ID, $pet_id );
		update_post_meta( $post_id, KennelFlow_Boarding_Post_Meta::BOOKING_KENNEL_ID, $resource_user_id );
		update_post_meta( $post_id, KennelFlow_Boarding_Post_Meta::BOOKING_LOCATION_ID, $location_id );
		update_post_meta( $post_id, KennelFlow_Boarding_Post_Meta::BOOKING_START_GMT, $start_gmt );
		update_post_meta( $post_id, KennelFlow_Boarding_Post_Meta::BOOKING_END_GMT, $end_gmt );
		update_post_meta( $post_id, KennelFlow_Boarding_Post_Meta::BOOKING_STATUS, 'completed' );
		update_post_meta( $post_id, KennelFlow_Boarding_Post_Meta::BOOKING_KIND, $kind );
		update_post_meta( $post_id, self::META_DEMO, 1 );

		if ( class_exists( 'KennelFlow_Boarding_Booking_Index' ) ) {
			KennelFlow_Boarding_Booking_Index::sync_from_post( (int) $post_id );
		}

		return (int) $post_id;
	}

	/**
	 * Insert a GroomPress commission row for a completed demo grooming booking (no WooCommerce order).
	 *
	 * @param int $booking_post_id Boarding booking post ID (`kennelpress_booking`).
	 * @param int $groomer_id      Groomer WordPress user ID (resource).
	 * @return void
	 */
	protected static function maybe_insert_demo_grooming_commission( $booking_post_id, $groomer_id ) {
		if ( ! class_exists( 'GroomPress_Install' ) || ! GroomPress_Install::commissions_table_exists() ) {
			return;
		}

		GroomPress_Install::ensure_commissions_meta_json_column();

		$booking_post_id = absint( $booking_post_id );
		$groomer_id      = absint( $groomer_id );
		if ( $booking_post_id < 1 || $groomer_id < 1 ) {
			return;
		}

		if ( ! class_exists( 'KennelFlow_Boarding_Booking_Index' ) ) {
			return;
		}

		$row = KennelFlow_Boarding_Booking_Index::get_index_row_for_post( $booking_post_id );
		if ( ! is_object( $row ) || empty( $row->id ) ) {
			return;
		}

		$booking_row_id = absint( $row->id );
		if ( $booking_row_id < 1 ) {
			return;
		}

		$table = GroomPress_Install::commissions_table_name();
		if ( ! function_exists( 'ltkf_db_column_exists' ) || ! ltkf_db_column_exists( $table, 'meta_json' ) ) {
			return;
		}

		$cents_commission = wp_rand( 2500, 8500 );
		$commission_amt   = round( $cents_commission / 100.0, 2 );
		$gross_amt        = round( $commission_amt * 1.8, 2 );

		$meta_payload = array( '_kf_is_demo_data' => true );
		$meta_json    = wp_json_encode( $meta_payload );
		if ( false === $meta_json ) {
			$meta_json = '{}';
		}

		$now_gmt = gmdate( 'Y-m-d H:i:s' );

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Demo seed; GroomPress table; values escaped via wpdb->insert.
		$wpdb->insert(
			$table,
			array(
				'staff_user_id'     => $groomer_id,
				'order_id'          => self::DEMO_COMMISSION_ORDER_ID,
				'booking_id'        => $booking_row_id,
				'gross_amount'      => $gross_amt,
				'commission_amount' => $commission_amt,
				'status'            => 'pending',
				'meta_json'         => $meta_json,
				'created_gmt'       => $now_gmt,
			),
			array( '%d', '%d', '%d', '%f', '%f', '%s', '%s', '%s' )
		);
	}

	/**
	 * Whether boarding booking + kennel post types exist.
	 *
	 * @return bool
	 */
	protected static function kennelpress_ready() {
		return class_exists( 'KennelFlow_Boarding_Post_Meta' )
			&& post_type_exists( 'kennelpress_kennel' )
			&& post_type_exists( 'kennelpress_booking' );
	}

	/**
	 * Kennel post IDs that are boarding-capable (default boarding when unset).
	 *
	 * @return int[]
	 */
	protected static function get_boarding_kennel_ids() {
		$q = new \WP_Query(
			array(
				'post_type'              => 'kennelpress_kennel',
				'post_status'            => 'publish',
				'posts_per_page'         => 100,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$ids = array();
		if ( ! class_exists( 'KennelFlow_Boarding_Post_Meta' ) ) {
			return $ids;
		}

		foreach ( $q->posts as $kid ) {
			$kid = absint( $kid );
			if ( $kid < 1 ) {
				continue;
			}
			$rtype = (string) get_post_meta( $kid, KennelFlow_Boarding_Post_Meta::KENNEL_RESOURCE_TYPE, true );
			if ( '' === $rtype || 'boarding' === $rtype ) {
				$loc = absint( get_post_meta( $kid, KennelFlow_Boarding_Post_Meta::KENNEL_LOCATION_ID, true ) );
				if ( $loc > 0 ) {
					$ids[] = $kid;
				}
			}
		}

		return $ids;
	}

	/**
	 * Build non-overlapping GMT windows (past stays then future stays).
	 *
	 * @param int $n_past   Past booking count.
	 * @param int $n_future Future booking count.
	 * @return array<int, array{start:string,end:string,past:bool}>
	 */
	protected static function build_non_overlapping_windows( $n_past, $n_future ) {
		$out    = array();
		$now    = time();
		$cursor = $now - ( DAY_IN_SECONDS * wp_rand( 30, 400 ) );

		for ( $i = 0; $i < $n_past; $i++ ) {
			$len_hours = wp_rand( 24, 168 );
			$start     = $cursor - ( $len_hours * HOUR_IN_SECONDS );
			$end       = $cursor;
			$out[]     = array(
				'start' => gmdate( 'Y-m-d H:i:s', $start ),
				'end'   => gmdate( 'Y-m-d H:i:s', $end ),
				'past'  => true,
			);
			$cursor    = $start - ( HOUR_IN_SECONDS * wp_rand( 12, 72 ) );
		}

		$cursor = $now + ( HOUR_IN_SECONDS * wp_rand( 24, 72 ) );
		for ( $j = 0; $j < $n_future; $j++ ) {
			$len_hours = wp_rand( 48, 120 );
			$start     = $cursor;
			$end       = $start + ( $len_hours * HOUR_IN_SECONDS );
			$out[]     = array(
				'start' => gmdate( 'Y-m-d H:i:s', $start ),
				'end'   => gmdate( 'Y-m-d H:i:s', $end ),
				'past'  => false,
			);
			$cursor    = $end + ( HOUR_IN_SECONDS * wp_rand( 24, 96 ) );
		}

		return $out;
	}

	/**
	 * Insert kennelpress_booking post and meta; KennelFlow_Boarding_Booking_Index syncs kf_bookings.
	 *
	 * @param int    $pet_id    Pet ID.
	 * @param int    $kennel_id Kennel ID.
	 * @param string $start_gmt Start GMT mysql.
	 * @param string $end_gmt   End GMT mysql.
	 * @param bool   $is_past   Whether stay is in the past.
	 * @return void
	 */
	protected static function create_boarding_booking_post( $pet_id, $kennel_id, $start_gmt, $end_gmt, $is_past ) {
		if ( ! class_exists( 'KennelFlow_Boarding_Post_Meta' ) ) {
			return;
		}

		$title = sprintf(
			/* translators: %d: pet post ID */
			__( 'Demo boarding — pet %d', 'kennelflow-core' ),
			$pet_id
		);

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'kennelpress_booking',
				'post_status' => 'publish',
				'post_title'  => $title,
			),
			true
		);

		if ( is_wp_error( $post_id ) || $post_id < 1 ) {
			return;
		}

		$status = $is_past ? 'completed' : 'confirmed';

		update_post_meta( $post_id, KennelFlow_Boarding_Post_Meta::BOOKING_PET_ID, $pet_id );
		update_post_meta( $post_id, KennelFlow_Boarding_Post_Meta::BOOKING_KENNEL_ID, $kennel_id );
		update_post_meta( $post_id, KennelFlow_Boarding_Post_Meta::BOOKING_START_GMT, $start_gmt );
		update_post_meta( $post_id, KennelFlow_Boarding_Post_Meta::BOOKING_END_GMT, $end_gmt );
		update_post_meta( $post_id, KennelFlow_Boarding_Post_Meta::BOOKING_STATUS, $status );
		update_post_meta( $post_id, KennelFlow_Boarding_Post_Meta::BOOKING_KIND, 'boarding' );

		$loc = absint( get_post_meta( $kennel_id, KennelFlow_Boarding_Post_Meta::KENNEL_LOCATION_ID, true ) );
		if ( $loc > 0 ) {
			update_post_meta( $post_id, KennelFlow_Boarding_Post_Meta::BOOKING_LOCATION_ID, $loc );
		}

		update_post_meta( $post_id, self::META_DEMO, 1 );
	}

	/**
	 * Pick a random element from an indexed list.
	 *
	 * @param array<int|string, mixed> $arr List.
	 * @return mixed
	 */
	protected static function random_from( array $arr ) {
		$n = count( $arr );
		if ( $n < 1 ) {
			return null;
		}
		return $arr[ wp_rand( 0, $n - 1 ) ];
	}

	/**
	 * Demo owner first names.
	 *
	 * @return string[]
	 */
	protected static function first_names() {
		return array( 'Alex', 'Jordan', 'Taylor', 'Casey', 'Riley', 'Morgan', 'Quinn', 'Avery', 'Parker', 'Reese' );
	}

	/**
	 * Demo owner last names.
	 *
	 * @return string[]
	 */
	protected static function last_names() {
		return array( 'Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Miller', 'Davis', 'Wilson', 'Moore', 'Taylor' );
	}

	/**
	 * Demo dog names.
	 *
	 * @return string[]
	 */
	protected static function dog_names() {
		return array( 'Buddy', 'Bella', 'Charlie', 'Luna', 'Cooper', 'Lucy', 'Max', 'Daisy', 'Bailey', 'Sadie', 'Rocky', 'Molly', 'Duke', 'Stella', 'Bear', 'Zoey', 'Tucker', 'Penny', 'Murphy', 'Ruby' );
	}

	/**
	 * Demo dog breeds.
	 *
	 * @return string[]
	 */
	protected static function dog_breeds() {
		return array( 'Labrador Retriever', 'Golden Retriever', 'German Shepherd', 'French Bulldog', 'Bulldog', 'Poodle', 'Beagle', 'Rottweiler', 'Dachshund', 'Yorkshire Terrier', 'Siberian Husky', 'Corgi', 'Australian Shepherd', 'Boxer', 'Border Collie' );
	}
}
