<?php
/**
 * Admin: kf_pet — Boarding Care Defaults (allergies, behavioral tags, default diet).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class AdminPetCareDefaults
 */
class AdminPetCareDefaults {

	/**
	 * Nonce action / field for the care meta box.
	 */
	const NONCE_ACTION = 'ltkf_save_pet_care_defaults';

	const NONCE_FIELD = 'ltkf_pet_care_defaults_nonce';

	/**
	 * Meta box id.
	 */
	const META_BOX_ID = 'ltkf_boarding_care_defaults';

	/**
	 * Bootstrap hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_boxes' ), 10, 0 );
		add_action( 'save_post_' . ltkf_get_pet_post_type(), array( __CLASS__, 'save_care_meta' ), 18, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'render_care_warning_notice' ) );
	}

	/**
	 * Behavioral tag slug => default label (translatable).
	 *
	 * @return array<string, string>
	 */
	public static function get_behavioral_tag_options() {
		$options = array(
			'escape_artist'   => __( 'Escape Artist', 'kennelflow-core' ),
			'dog_aggressive'  => __( 'Dog Aggressive', 'kennelflow-core' ),
			'fear_of_thunder' => __( 'Fear of Thunder', 'kennelflow-core' ),
			'jumper'          => __( 'Jumper', 'kennelflow-core' ),
		);

		/**
		 * Filter boarding behavioral tag checkboxes on the pet profile.
		 *
		 * @since 0.2.0
		 *
		 * @param array<string, string> $options Slug => label.
		 */
		return (array) apply_filters( 'ltkf_pet_boarding_behavioral_tag_options', $options );
	}

	/**
	 * Register meta box.
	 *
	 * @return void
	 */
	public static function register_meta_boxes() {
		add_meta_box(
			self::META_BOX_ID,
			__( 'Boarding Care Defaults', 'kennelflow-core' ),
			array( __CLASS__, 'render_meta_box' ),
			ltkf_get_pet_post_type(),
			'normal',
			'default'
		);
	}

	/**
	 * Render meta box fields.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public static function render_meta_box( $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, true );

		$allergies = get_post_meta( $post->ID, ltkf_get_pet_meta_key_allergies(), true );
		$allergies = is_string( $allergies ) ? $allergies : '';

		$tags_raw = get_post_meta( $post->ID, ltkf_get_pet_meta_key_behavioral_tags(), true );
		$tags     = array();
		if ( is_array( $tags_raw ) ) {
			$tags = array_map( 'sanitize_key', $tags_raw );
		}

		$diet = get_post_meta( $post->ID, ltkf_get_pet_meta_key_default_diet(), true );
		$diet = is_string( $diet ) ? $diet : '';

		$options = self::get_behavioral_tag_options();
		?>
		<p class="description" style="margin-top:0;">
			<?php esc_html_e( 'Baseline care and feeding for this pet. Booking-specific overrides can be set on each boarding stay.', 'kennelflow-core' ); ?>
		</p>

		<p>
			<label for="kf_allergies"><strong><?php esc_html_e( 'Allergies', 'kennelflow-core' ); ?></strong></label>
		</p>
		<input
			type="text"
			class="widefat"
			id="kf_allergies"
			name="kf_allergies"
			value="<?php echo esc_attr( $allergies ); ?>"
			placeholder="<?php esc_attr_e( 'e.g. Chicken, Penicillin', 'kennelflow-core' ); ?>"
			autocomplete="off"
		/>

		<fieldset class="kf-care-defaults-behavioral" style="margin:1em 0;">
			<legend><strong><?php esc_html_e( 'Behavioral tags', 'kennelflow-core' ); ?></strong></legend>
			<?php foreach ( $options as $slug => $label ) : ?>
				<?php
				$slug = sanitize_key( $slug );
				if ( '' === $slug ) {
					continue;
				}
				$id = 'ltkf_behavioral_' . $slug;
				?>
				<label for="<?php echo esc_attr( $id ); ?>" style="display:block;margin:0.35em 0;">
					<input
						type="checkbox"
						id="<?php echo esc_attr( $id ); ?>"
						name="kf_behavioral_tags[]"
						value="<?php echo esc_attr( $slug ); ?>"
						<?php checked( in_array( $slug, $tags, true ) ); ?>
					/>
					<?php echo esc_html( $label ); ?>
				</label>
			<?php endforeach; ?>
		</fieldset>

		<p>
			<label for="kf_default_diet"><strong><?php esc_html_e( 'Default diet', 'kennelflow-core' ); ?></strong></label>
		</p>
		<textarea
			class="widefat"
			rows="4"
			id="kf_default_diet"
			name="kf_default_diet"
			placeholder="<?php esc_attr_e( 'e.g. 1 cup Purina Pro Plan AM/PM', 'kennelflow-core' ); ?>"
		><?php echo esc_textarea( $diet ); ?></textarea>
		<?php

		/**
		 * After Boarding Care Defaults fields (extend with add-ons).
		 *
		 * @since 0.2.0
		 *
		 * @param WP_Post $post Pet post.
		 */
		do_action( 'ltkf_pet_after_care_defaults_fields', $post );
	}

	/**
	 * Save care post meta.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @param bool    $update  Whether update.
	 * @return void
	 */
	public static function save_care_meta( $post_id, $post, $update ) {
		unset( $update );

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		if ( ltkf_get_pet_post_type() !== $post->post_type ) {
			return;
		}

		if ( ! self::verify_meta_box_nonce( self::NONCE_ACTION, self::NONCE_FIELD ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$allergies = isset( $_POST['kf_allergies'] ) ? sanitize_text_field( wp_unslash( $_POST['kf_allergies'] ) ) : '';
		$diet      = isset( $_POST['kf_default_diet'] ) ? sanitize_textarea_field( wp_unslash( $_POST['kf_default_diet'] ) ) : '';

		if ( '' === $allergies ) {
			delete_post_meta( $post_id, ltkf_get_pet_meta_key_allergies() );
		} else {
			update_post_meta( $post_id, ltkf_get_pet_meta_key_allergies(), $allergies );
		}

		if ( '' === $diet ) {
			delete_post_meta( $post_id, ltkf_get_pet_meta_key_default_diet() );
		} else {
			update_post_meta( $post_id, ltkf_get_pet_meta_key_default_diet(), $diet );
		}

		$allowed_slugs = array_keys( self::get_behavioral_tag_options() );
		$allowed_slugs = array_map( 'sanitize_key', $allowed_slugs );
		$allowed_slugs = array_filter( array_unique( $allowed_slugs ) );

		$posted = array();
		if ( isset( $_POST['kf_behavioral_tags'] ) && is_array( $_POST['kf_behavioral_tags'] ) ) {
			$posted = map_deep( wp_unslash( $_POST['kf_behavioral_tags'] ), 'sanitize_key' );
		}
		$posted = array_values( array_intersect( $allowed_slugs, $posted ) );

		if ( empty( $posted ) ) {
			delete_post_meta( $post_id, ltkf_get_pet_meta_key_behavioral_tags() );
		} else {
			update_post_meta( $post_id, ltkf_get_pet_meta_key_behavioral_tags(), $posted );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Bright warning when allergies or Dog Aggressive is set.
	 *
	 * @return void
	 */
	public static function render_care_warning_notice() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base ) {
			return;
		}
		if ( ltkf_get_pet_post_type() !== $screen->post_type ) {
			return;
		}

		global $post;
		if ( ! $post instanceof \WP_Post || ltkf_get_pet_post_type() !== $post->post_type ) {
			return;
		}

		$post_id = (int) $post->ID;
		if ( $post_id < 1 ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! function_exists( 'ltkf_pet_care_warning_should_show' ) || ! ltkf_pet_care_warning_should_show( $post_id ) ) {
			return;
		}

		$allergies = ltkf_get_pet_care_defaults_allergies( $post_id );
		$tags      = ltkf_get_pet_care_defaults_behavioral_tags( $post_id );
		$reasons   = array();

		if ( '' !== trim( $allergies ) ) {
			$reasons[] = __( 'allergies on file', 'kennelflow-core' );
		}
		if ( in_array( 'dog_aggressive', $tags, true ) ) {
			$reasons[] = __( 'Dog Aggressive tag', 'kennelflow-core' );
		}

		$reason_text = '';
		if ( ! empty( $reasons ) ) {
			$reason_text = implode( ' · ', $reasons );
		}

		?>
		<div
			class="notice notice-error kf-pet-care-warning-badge"
			style="border-left:6px solid #d63638;background:#fcf0f1;padding:10px 12px;margin:12px 0 18px;"
		>
			<p style="margin:0;font-size:14px;">
				<strong style="color:#b32d2e;"><?php esc_html_e( 'Care warning', 'kennelflow-core' ); ?></strong>
				<?php if ( '' !== $reason_text ) : ?>
					<span style="color:#1d2327;">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: reasons (e.g. allergies, behavioral tag). */
								__( 'Review before check-in: %s.', 'kennelflow-core' ),
								$reason_text
							)
						);
						?>
					</span>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Verify meta box nonce (save_post-safe: invalid/missing nonce returns false; never wp_die()).
	 *
	 * wp_die() on failure would abort the entire post save for autosave/REST requests that omit this nonce.
	 *
	 * @param string $action Nonce action string.
	 * @param string $field  POST field.
	 * @return bool
	 */
	protected static function verify_meta_box_nonce( $action, $field ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce read for wp_verify_nonce().
		if ( ! isset( $_POST[ $field ] ) ) {
			return false;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			return false;
		}

		return true;
	}
}
