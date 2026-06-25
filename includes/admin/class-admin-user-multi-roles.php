<?php
/**
 * Admin: assign multiple WordPress roles per user (primary from core dropdown + extras).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class AdminUserMultiRoles
 */
class AdminUserMultiRoles {

	/**
	 * POST field for additional role slugs (beyond the Role dropdown).
	 */
	const POST_FIELD = 'ltkf_extra_roles';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! apply_filters( 'ltkf_enable_user_multi_roles_ui', true ) ) {
			return;
		}

		add_action( 'show_user_profile', array( __CLASS__, 'render_section' ), 15 );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_section' ), 15 );

		add_action( 'personal_options_update', array( __CLASS__, 'save_extra_roles' ), 99 );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_extra_roles' ), 99 );
	}

	/**
	 * Primary role slug for display (first role WordPress exposes for the dropdown).
	 *
	 * @param WP_User $user User.
	 * @return string
	 */
	protected static function get_primary_role_slug( $user ) {
		if ( ! $user instanceof \WP_User ) {
			return '';
		}
		$roles = (array) $user->roles;
		return isset( $roles[0] ) ? (string) $roles[0] : '';
	}

	/**
	 * Output “Additional roles” checkboxes below the default profile fields.
	 *
	 * @param WP_User $user User being edited.
	 * @return void
	 */
	public static function render_section( $user ) {
		if ( ! $user instanceof \WP_User ) {
			return;
		}

		if ( ! current_user_can( 'promote_users' ) || ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$editable = get_editable_roles();
		if ( empty( $editable ) || ! is_array( $editable ) ) {
			return;
		}

		$primary  = self::get_primary_role_slug( $user );
		$assigned = array_values( array_map( 'strval', (array) $user->roles ) );

		?>
		<h2 id="kf-additional-roles"><?php esc_html_e( 'Additional roles (KennelFlow)', 'kennelflow-core' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'The Role dropdown above sets the primary role. Check any extra roles this account should have (for example Groomer plus KennelFlow Vet provider).', 'kennelflow-core' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Extra roles', 'kennelflow-core' ); ?></th>
				<td>
					<fieldset class="kf-extra-roles-fieldset">
						<legend class="screen-reader-text"><?php esc_html_e( 'Extra roles', 'kennelflow-core' ); ?></legend>
						<?php
						foreach ( $editable as $slug => $info ) {
							$slug = sanitize_key( $slug );
							if ( '' === $slug ) {
								continue;
							}
							if ( $slug === $primary ) {
								continue;
							}
							$label   = isset( $info['name'] ) ? $info['name'] : $slug;
							$label   = is_string( $label ) ? $label : $slug;
							$id      = 'kf-extra-role-' . $slug;
							$checked = in_array( $slug, $assigned, true );
							?>
							<label for="<?php echo esc_attr( $id ); ?>">
								<input
									type="checkbox"
									name="<?php echo esc_attr( self::POST_FIELD ); ?>[]"
									id="<?php echo esc_attr( $id ); ?>"
									value="<?php echo esc_attr( $slug ); ?>"
									<?php checked( $checked ); ?>
								/>
								<?php echo esc_html( translate_user_role( $label ) ); ?>
							</label><br />
							<?php
						}
						?>
					</fieldset>
					<p class="description">
						<?php
						if ( '' !== $primary ) {
							printf(
								/* translators: %s: current primary role slug */
								esc_html__( 'Primary role from the dropdown: %s', 'kennelflow-core' ),
								esc_html( $primary )
							);
						}
						?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Merge primary role (from core) with checked extra roles.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function save_extra_roles( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id < 1 ) {
			return;
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'update-user_' . $user_id ) ) {
			return;
		}

		if ( ! current_user_can( 'promote_users' ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		$editable = get_editable_roles();
		$allowed  = is_array( $editable ) ? array_keys( $editable ) : array();
		$allowed  = array_filter( array_map( 'sanitize_key', $allowed ) );

		$raw_extras = isset( $_POST[ self::POST_FIELD ] )
			? map_deep( wp_unslash( $_POST[ self::POST_FIELD ] ), 'sanitize_text_field' )
			: array();
		$extras     = array_map( 'sanitize_key', (array) $raw_extras );
		$extras     = array_intersect( $extras, $allowed );

		$user_after = new \WP_User( $user_id );
		$primary    = self::get_primary_role_slug( $user_after );

		if ( '' === $primary || ! in_array( $primary, $allowed, true ) ) {
			return;
		}

		$desired = array_unique( array_merge( array( $primary ), $extras ) );

		/**
		 * Filter the final role list before applying (editable roles only).
		 *
		 * @since 0.2.6
		 *
		 * @param string[] $desired Role slugs (includes primary).
		 * @param int      $user_id User ID.
		 */
		$desired = apply_filters( 'ltkf_user_multi_roles_desired', $desired, $user_id );
		$desired = array_values( array_intersect( (array) $desired, $allowed ) );

		if ( empty( $desired ) ) {
			return;
		}

		$user = new \WP_User( $user_id );
		$have = array_values( array_map( 'strval', (array) $user->roles ) );

		foreach ( $allowed as $slug ) {
			$want = in_array( $slug, $desired, true );
			$got  = in_array( $slug, $have, true );
			if ( $want && ! $got ) {
				$user->add_role( $slug );
			}
			if ( ! $want && $got ) {
				$user->remove_role( $slug );
			}
		}
	}
}
