<?php
/**
 * Admin: Public booking profile + location roster on the WordPress user edit screen (clinicians only).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class AdminClinicianProfiles
 */
class AdminClinicianProfiles {

	/**
	 * User meta: public bio (booking directory / marketing).
	 */
	const META_PUBLIC_BIO = 'kf_public_bio';

	/**
	 * User meta: comma- or phrase-style specialties text.
	 */
	const META_SPECIALTIES = 'kf_specialties';

	/**
	 * User meta: per-location weekly availability (clinician roster).
	 *
	 * Structure: [ location_post_id (int|string) => [ 'mon' => [ 'start' => 'HH:MM', 'end' => 'HH:MM' ], ... ] ]
	 *
	 * @var string
	 */
	const META_LOCATION_ROSTER = '_kf_location_roster';

	/**
	 * Weekday keys (lowercase, Mon-first).
	 *
	 * @return string[]
	 */
	public static function get_roster_weekday_keys() {
		return array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );
	}

	/**
	 * Localized weekday labels keyed by roster key.
	 *
	 * @return array<string, string>
	 */
	public static function get_roster_weekday_labels() {
		return array(
			'mon' => __( 'Monday', 'kennelflow-core' ),
			'tue' => __( 'Tuesday', 'kennelflow-core' ),
			'wed' => __( 'Wednesday', 'kennelflow-core' ),
			'thu' => __( 'Thursday', 'kennelflow-core' ),
			'fri' => __( 'Friday', 'kennelflow-core' ),
			'sat' => __( 'Saturday', 'kennelflow-core' ),
			'sun' => __( 'Sunday', 'kennelflow-core' ),
		);
	}

	/**
	 * Sanitize HH:MM (24h) time string for roster storage.
	 *
	 * @param string $raw Raw input.
	 * @return string Sanitized 'H:i' or empty string if invalid.
	 */
	public static function sanitize_roster_time( $raw ) {
		$s = sanitize_text_field( (string) $raw );
		if ( '' === $s ) {
			return '';
		}
		if ( ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $s ) ) {
			return '';
		}
		return $s;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'show_user_profile', array( __CLASS__, 'render_fields' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_fields' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_fields' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_fields' ) );
	}

	/**
	 * Role slugs treated as clinicians for showing / saving profile fields.
	 *
	 * @return string[]
	 */
	public static function get_clinician_role_slugs() {
		$roles = array( 'veterinarian', 'clinician', 'kennelflow_vet_provider' );

		/**
		 * Filters which WordPress role slugs qualify for the Public Booking Profile section.
		 *
		 * @since 0.2.6
		 *
		 * @param string[] $roles Role slugs (lowercase).
		 */
		return apply_filters( 'ltkf_clinician_profile_role_slugs', $roles );
	}

	/**
	 * Whether the user has at least one clinician role.
	 *
	 * @param WP_User $user User object.
	 * @return bool
	 */
	public static function user_is_clinician( $user ) {
		if ( ! $user instanceof \WP_User ) {
			return false;
		}

		$allowed = self::get_clinician_role_slugs();
		if ( empty( $allowed ) ) {
			return false;
		}

		foreach ( (array) $user->roles as $role ) {
			if ( in_array( (string) $role, $allowed, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Output Public Booking Profile + Location Roster fields.
	 *
	 * @param WP_User $user User being edited.
	 * @return void
	 */
	public static function render_fields( $user ) {
		if ( ! $user instanceof \WP_User || ! self::user_is_clinician( $user ) ) {
			return;
		}

		$bio = get_user_meta( $user->ID, self::META_PUBLIC_BIO, true );
		$bio = is_string( $bio ) ? $bio : '';

		$spec = get_user_meta( $user->ID, self::META_SPECIALTIES, true );
		$spec = is_string( $spec ) ? $spec : '';

		$roster_raw = get_user_meta( $user->ID, self::META_LOCATION_ROSTER, true );
		$roster     = is_array( $roster_raw ) ? $roster_raw : array();

		$loc_pt = function_exists( 'ltkf_get_location_post_type' ) ? ltkf_get_location_post_type() : 'kf_location';

		$locations = get_posts(
			array(
				'post_type'              => $loc_pt,
				'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page'         => -1,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
			)
		);

		$day_keys   = self::get_roster_weekday_keys();
		$day_labels = self::get_roster_weekday_labels();

		?>
		<h2 id="kf-public-booking-profile"><?php esc_html_e( 'Public Booking Profile', 'kennelflow-core' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Shown where your site lists clinicians for public booking (when enabled).', 'kennelflow-core' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="kf_public_bio"><?php esc_html_e( 'Public bio', 'kennelflow-core' ); ?></label>
				</th>
				<td>
					<textarea
						name="kf_public_bio"
						id="kf_public_bio"
						class="large-text"
						rows="4"
						cols="30"
					><?php echo esc_textarea( $bio ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'Short biography for clients (e.g. areas of focus, experience).', 'kennelflow-core' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="kf_specialties"><?php esc_html_e( 'Specialties', 'kennelflow-core' ); ?></label>
				</th>
				<td>
					<input
						type="text"
						name="kf_specialties"
						id="kf_specialties"
						class="regular-text"
						value="<?php echo esc_attr( $spec ); ?>"
					/>
					<p class="description">
						<?php esc_html_e( 'e.g. Surgery, Dentistry, Exotics', 'kennelflow-core' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<h2 id="kf-location-roster"><?php esc_html_e( 'Location Roster', 'kennelflow-core' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Set which days this clinician is available at each branch, with local times (24-hour).', 'kennelflow-core' ); ?>
		</p>
		<?php
		if ( empty( $locations ) ) {
			echo '<p class="description">' . esc_html__( 'No locations found. Add Hub locations first.', 'kennelflow-core' ) . '</p>';
		} else {
			foreach ( $locations as $loc ) {
				if ( ! $loc instanceof \WP_Post ) {
					continue;
				}
				$loc_id = (int) $loc->ID;
				if ( $loc_id < 1 ) {
					continue;
				}
				$loc_roster = array();
				if ( isset( $roster[ (string) $loc_id ] ) && is_array( $roster[ (string) $loc_id ] ) ) {
					$loc_roster = $roster[ (string) $loc_id ];
				} elseif ( isset( $roster[ $loc_id ] ) && is_array( $roster[ $loc_id ] ) ) {
					$loc_roster = $roster[ $loc_id ];
				}

				$fieldset_id = 'kf-roster-loc-' . $loc_id;
				?>
				<h3 class="kf-roster-location-title"><?php echo esc_html( get_the_title( $loc ) ); ?></h3>
				<table class="widefat striped kf-roster-table" id="<?php echo esc_attr( $fieldset_id ); ?>" role="grid">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Day', 'kennelflow-core' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Available', 'kennelflow-core' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Start', 'kennelflow-core' ); ?></th>
							<th scope="col"><?php esc_html_e( 'End', 'kennelflow-core' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $day_keys as $dkey ) {
							$day_row = isset( $loc_roster[ $dkey ] ) && is_array( $loc_roster[ $dkey ] ) ? $loc_roster[ $dkey ] : array();
							$st      = isset( $day_row['start'] ) && is_string( $day_row['start'] ) ? $day_row['start'] : '';
							$en      = isset( $day_row['end'] ) && is_string( $day_row['end'] ) ? $day_row['end'] : '';
							$active  = ( '' !== $st && '' !== $en );
							$label   = isset( $day_labels[ $dkey ] ) ? $day_labels[ $dkey ] : $dkey;
							$cb_id   = 'kf-roster-' . $loc_id . '-' . $dkey . '-active';
							$st_id   = 'kf-roster-' . $loc_id . '-' . $dkey . '-start';
							$en_id   = 'kf-roster-' . $loc_id . '-' . $dkey . '-end';
							?>
							<tr>
								<th scope="row"><?php echo esc_html( $label ); ?></th>
								<td>
									<input
										type="checkbox"
										name="kf_roster[<?php echo esc_attr( (string) $loc_id ); ?>][<?php echo esc_attr( $dkey ); ?>][active]"
										id="<?php echo esc_attr( $cb_id ); ?>"
										value="1"
										<?php checked( $active ); ?>
									/>
								</td>
								<td>
									<input
										type="time"
										name="kf_roster[<?php echo esc_attr( (string) $loc_id ); ?>][<?php echo esc_attr( $dkey ); ?>][start]"
										id="<?php echo esc_attr( $st_id ); ?>"
										class="kf-roster-time"
										value="<?php echo esc_attr( $st ); ?>"
										step="60"
									/>
								</td>
								<td>
									<input
										type="time"
										name="kf_roster[<?php echo esc_attr( (string) $loc_id ); ?>][<?php echo esc_attr( $dkey ); ?>][end]"
										id="<?php echo esc_attr( $en_id ); ?>"
										class="kf-roster-time"
										value="<?php echo esc_attr( $en ); ?>"
										step="60"
									/>
								</td>
							</tr>
							<?php
						}
						?>
					</tbody>
				</table>
				<?php
			}
		}
	}

	/**
	 * Persist meta when the profile form is saved.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function save_fields( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id < 1 ) {
			return;
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'update-user_' . $user_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user || ! self::user_is_clinician( $user ) ) {
			return;
		}

		$bio = '';
		if ( isset( $_POST['kf_public_bio'] ) ) {
			$bio = sanitize_textarea_field( wp_unslash( $_POST['kf_public_bio'] ) );
		}

		$spec = '';
		if ( isset( $_POST['kf_specialties'] ) ) {
			$spec = sanitize_text_field( wp_unslash( $_POST['kf_specialties'] ) );
		}

		update_user_meta( $user_id, self::META_PUBLIC_BIO, $bio );
		update_user_meta( $user_id, self::META_SPECIALTIES, $spec );

		$roster_raw = array();
		if ( isset( $_POST['kf_roster'] ) && is_array( $_POST['kf_roster'] ) ) {
			$roster_raw = map_deep( wp_unslash( $_POST['kf_roster'] ), 'sanitize_text_field' );
		}
		$roster_out = self::parse_roster_from_post( $roster_raw );
		update_user_meta( $user_id, self::META_LOCATION_ROSTER, $roster_out );
	}

	/**
	 * Build roster array from sanitized POST subtree (kf_roster) — checked days with valid start/end.
	 *
	 * @param array $roster_raw map_deep()-sanitized copy of POST `kf_roster`; must be populated only after nonce + capability checks in save_fields().
	 * @return array<int|string, array<string, array{start: string, end: string}>>
	 */
	protected static function parse_roster_from_post( array $roster_raw ) {
		$raw    = $roster_raw;
		$valid  = self::get_roster_weekday_keys();
		$validm = array_fill_keys( $valid, true );

		$out = array();

		foreach ( $raw as $loc_key => $days ) {
			$loc_id = absint( $loc_key );
			if ( $loc_id < 1 || ! is_array( $days ) ) {
				continue;
			}
			$loc_pt = function_exists( 'ltkf_get_location_post_type' ) ? ltkf_get_location_post_type() : 'kf_location';
			if ( get_post_type( $loc_id ) !== $loc_pt ) {
				continue;
			}

			$loc_block = array();
			foreach ( $days as $dkey => $row ) {
				$dkey = sanitize_key( (string) $dkey );
				if ( ! isset( $validm[ $dkey ] ) || ! is_array( $row ) ) {
					continue;
				}
				$active = ! empty( $row['active'] );
				if ( ! $active ) {
					continue;
				}
				$st = isset( $row['start'] ) ? self::sanitize_roster_time( $row['start'] ) : '';
				$en = isset( $row['end'] ) ? self::sanitize_roster_time( $row['end'] ) : '';
				if ( '' === $st || '' === $en ) {
					continue;
				}
				$loc_block[ $dkey ] = array(
					'start' => $st,
					'end'   => $en,
				);
			}

			if ( ! empty( $loc_block ) ) {
				$out[ $loc_id ] = $loc_block;
			}
		}

		return $out;
	}

	/**
	 * Whether the user has at least one roster day with valid start/end at a location.
	 *
	 * @param int $user_id     User ID.
	 * @param int $location_id Location post ID.
	 * @return bool
	 */
	public static function user_has_location_roster_day( $user_id, $location_id ) {
		$user_id     = absint( $user_id );
		$location_id = absint( $location_id );
		if ( $user_id < 1 || $location_id < 1 ) {
			return false;
		}

		$roster = get_user_meta( $user_id, self::META_LOCATION_ROSTER, true );
		if ( ! is_array( $roster ) ) {
			return false;
		}

		$days = null;
		if ( isset( $roster[ $location_id ] ) ) {
			$days = $roster[ $location_id ];
		} elseif ( isset( $roster[ (string) $location_id ] ) ) {
			$days = $roster[ (string) $location_id ];
		}

		if ( ! is_array( $days ) || empty( $days ) ) {
			return false;
		}

		$validm = array_fill_keys( self::get_roster_weekday_keys(), true );

		foreach ( $days as $dkey => $row ) {
			$dkey = sanitize_key( (string) $dkey );
			if ( ! isset( $validm[ $dkey ] ) || ! is_array( $row ) ) {
				continue;
			}
			$st = isset( $row['start'] ) ? self::sanitize_roster_time( $row['start'] ) : '';
			$en = isset( $row['end'] ) ? self::sanitize_roster_time( $row['end'] ) : '';
			if ( '' !== $st && '' !== $en ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Roster weekday rows for a Hub location (mon…sun → start/end), or null if none.
	 *
	 * @param int $user_id     User ID.
	 * @param int $location_id Hub kf_location post ID.
	 * @return array<string, array{start: string, end: string}>|null
	 */
	protected static function get_roster_days_for_location( $user_id, $location_id ) {
		$user_id     = absint( $user_id );
		$location_id = absint( $location_id );
		if ( $user_id < 1 || $location_id < 1 ) {
			return null;
		}

		$roster = get_user_meta( $user_id, self::META_LOCATION_ROSTER, true );
		if ( ! is_array( $roster ) ) {
			return null;
		}

		$days = null;
		if ( isset( $roster[ $location_id ] ) ) {
			$days = $roster[ $location_id ];
		} elseif ( isset( $roster[ (string) $location_id ] ) ) {
			$days = $roster[ (string) $location_id ];
		}

		if ( ! is_array( $days ) || empty( $days ) ) {
			return null;
		}

		return $days;
	}

	/**
	 * Whether [start_gmt, end_gmt) falls within roster hours for each local calendar day touched (Checks 1–2).
	 *
	 * @param int    $user_id     User ID.
	 * @param int    $location_id Hub kf_location post ID.
	 * @param string $start_gmt   Start UTC MySQL.
	 * @param string $end_gmt     End UTC MySQL (exclusive half-open with start).
	 * @return bool
	 */
	public static function is_clinician_interval_within_roster_at_location( $user_id, $location_id, $start_gmt, $end_gmt ) {
		$user_id     = absint( $user_id );
		$location_id = absint( $location_id );
		if ( $user_id < 1 || $location_id < 1 ) {
			return false;
		}

		$days = self::get_roster_days_for_location( $user_id, $location_id );
		if ( null === $days ) {
			return false;
		}

		$tz_string = function_exists( 'ltkf_get_hub_location_timezone_string' )
			? ltkf_get_hub_location_timezone_string( $location_id )
			: ( wp_timezone_string() ? wp_timezone_string() : 'UTC' );

		try {
			$tz = new \DateTimeZone( $tz_string );
		} catch ( \Exception $e ) {
			unset( $e );
			$tz = new \DateTimeZone( 'UTC' );
		}

		try {
			$start_utc = new \DateTimeImmutable( $start_gmt, new \DateTimeZone( 'UTC' ) );
			$end_utc   = new \DateTimeImmutable( $end_gmt, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			unset( $e );
			return false;
		}

		if ( $end_utc <= $start_utc ) {
			return false;
		}

		$start_local = $start_utc->setTimezone( $tz );
		$end_local   = $end_utc->setTimezone( $tz );

		$last_instant = $end_local->modify( '-1 second' );
		if ( $last_instant < $start_local ) {
			return false;
		}

		$map_n = array(
			1 => 'mon',
			2 => 'tue',
			3 => 'wed',
			4 => 'thu',
			5 => 'fri',
			6 => 'sat',
			7 => 'sun',
		);

		try {
			$last_day = new \DateTimeImmutable( $last_instant->format( 'Y-m-d' ), $tz );
			$cursor   = new \DateTimeImmutable( $start_local->format( 'Y-m-d' ), $tz );
		} catch ( \Exception $e ) {
			unset( $e );
			return false;
		}

		while ( $cursor <= $last_day ) {
			$dow = (int) $cursor->format( 'N' );
			$key = isset( $map_n[ $dow ] ) ? $map_n[ $dow ] : '';
			if ( '' === $key || ! isset( $days[ $key ] ) || ! is_array( $days[ $key ] ) ) {
				return false;
			}

			$row = $days[ $key ];
			$st  = isset( $row['start'] ) ? self::sanitize_roster_time( $row['start'] ) : '';
			$en  = isset( $row['end'] ) ? self::sanitize_roster_time( $row['end'] ) : '';
			if ( '' === $st || '' === $en ) {
				return false;
			}

			$day_end_boundary = $cursor->modify( '+1 day' );

			$seg_start = $start_local > $cursor ? $start_local : $cursor;
			$seg_end   = $end_local < $day_end_boundary ? $end_local : $day_end_boundary;

			if ( $seg_start >= $seg_end ) {
				$cursor = $cursor->modify( '+1 day' );
				continue;
			}

			$open_str  = $cursor->format( 'Y-m-d' ) . ' ' . $st;
			$close_str = $cursor->format( 'Y-m-d' ) . ' ' . $en;
			$open      = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $open_str, $tz );
			$close     = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $close_str, $tz );
			if ( ! $open instanceof \DateTimeImmutable || ! $close instanceof \DateTimeImmutable ) {
				return false;
			}
			if ( $close <= $open ) {
				return false;
			}

			if ( $seg_start < $open || $seg_end > $close ) {
				return false;
			}

			$cursor = $cursor->modify( '+1 day' );
		}

		return true;
	}
}
