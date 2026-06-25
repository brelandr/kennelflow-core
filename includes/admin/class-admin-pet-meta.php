<?php
/**
 * Admin: Pet (kf_pet) meta boxes — owner assignment and Hub field visibility.
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class AdminPetMeta
 */
class AdminPetMeta {

	/**
	 * Shared with KennelPress / KennelFlow Vet: WordPress role slug for assignable owners.
	 */
	const ROLE_PET_OWNER = 'pet_owner';

	/**
	 * KennelFlow Vet post meta mirror (Hub writes canonical owner key plus this for spoke compatibility).
	 */
	const LEGACY_KENNELFLOW_VET_OWNER_KEY = '_kennelflow_vet_owner_user_id';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'kennelflow_vet_register_pet_owner_meta_box', '__return_false' );

		add_action( 'admin_init', array( __CLASS__, 'maybe_register_pet_owner_role' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_boxes' ), 10, 0 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_expand_meta_script' ) );
		add_action( 'save_post_' . ltkf_get_pet_post_type(), array( __CLASS__, 'save_owner_meta' ), 15, 3 );
	}

	/**
	 * Open meta boxes by default on the pet screen (block editor loads them asynchronously).
	 *
	 * Disable with: add_filter( 'ltkf_pet_auto_expand_meta_boxes', '__return_false' );
	 *
	 * @param string $hook_suffix Current admin screen hook.
	 * @return void
	 */
	public static function enqueue_expand_meta_script( $hook_suffix ) {
		if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
			return;
		}

		/**
		 * Whether to auto-expand meta boxes on the kf_pet edit screen.
		 *
		 * @since 0.2.0
		 *
		 * @param bool $expand Default true.
		 */
		if ( ! apply_filters( 'ltkf_pet_auto_expand_meta_boxes', true ) ) {
			return;
		}

		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post_type = '';
		if ( $screen && isset( $screen->post_type ) ) {
			$post_type = (string) $screen->post_type;
		} elseif ( 'post-new.php' === $hook_suffix ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
			$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
		} elseif ( 'post.php' === $hook_suffix ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
			$post_id   = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
			$post_type = $post_id > 0 ? (string) get_post_type( $post_id ) : '';
		}

		if ( ltkf_get_pet_post_type() !== $post_type ) {
			return;
		}

		wp_enqueue_script(
			'kf-admin-pet-expand-meta',
			LTKF_PLUGIN_URL . 'assets/js/admin-pet-expand-meta.js',
			array(),
			LTKF_CORE_VERSION,
			true
		);
	}

	/**
	 * Ensure the Pet Owner role exists so dropdowns and REST can assign owners without KennelFlow Boarding/KennelFlow Vet.
	 *
	 * @return void
	 */
	public static function maybe_register_pet_owner_role() {
		if ( get_role( self::ROLE_PET_OWNER ) ) {
			return;
		}

		add_role(
			self::ROLE_PET_OWNER,
			__( 'Pet Owner', 'kennelflow-core' ),
			array(
				'read' => true,
			)
		);
	}

	/**
	 * Register meta boxes for the pet CPT.
	 *
	 * @return void
	 */
	public static function register_meta_boxes() {
		add_meta_box(
			'ltkf_hub_pet_owner',
			__( 'Owner', 'kennelflow-core' ),
			array( __CLASS__, 'render_owner_box' ),
			ltkf_get_pet_post_type(),
			'side',
			'default'
		);
	}

	/**
	 * Pet owner dropdown (classic editor).
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public static function render_owner_box( $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		wp_nonce_field( 'ltkf_save_pet_owner_meta', 'ltkf_pet_owner_nonce', true );

		$uid  = (int) ltkf_get_pet_owner_user_id( $post->ID );
		$user = $uid ? get_userdata( $uid ) : null;

		$users = self::get_pet_owner_users_for_dropdown( $uid );
		?>
		<p>
			<label for="ltkf_hub_owner_user_id"><?php esc_html_e( 'Owner', 'kennelflow-core' ); ?></label>
		</p>
		<select name="ltkf_hub_owner_user_id" id="ltkf_hub_owner_user_id" class="widefat">
			<option value="0"><?php esc_html_e( '— No owner —', 'kennelflow-core' ); ?></option>
			<?php foreach ( $users as $u ) : ?>
				<option value="<?php echo esc_attr( (string) $u->ID ); ?>" <?php selected( $uid, $u->ID ); ?>>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: display name, 2: login */
							__( '%1$s (%2$s)', 'kennelflow-core' ),
							$u->display_name,
							$u->user_login
						)
					);
					?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'Only users with the Pet Owner role can be assigned. Add users under Users → Add New and assign them the Pet Owner role.', 'kennelflow-core' ); ?>
		</p>
		<?php if ( $user && ! self::user_is_pet_owner( $uid ) ) : ?>
			<div class="notice notice-warning inline" style="margin-top:8px;padding:8px;">
				<p><?php esc_html_e( 'The saved owner does not have the Pet Owner role. Assign that role to this user or pick another owner.', 'kennelflow-core' ); ?></p>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Users with the pet owner role for dropdowns (plus optional include ID).
	 *
	 * @param int $include_user_id Always include this user if set (e.g. legacy assignment).
	 * @return WP_User[]
	 */
	public static function get_pet_owner_users_for_dropdown( $include_user_id = 0 ) {
		$include_user_id = absint( $include_user_id );
		$users           = get_users(
			array(
				'role'    => self::ROLE_PET_OWNER,
				'orderby' => 'display_name',
				'order'   => 'ASC',
			)
		);

		if ( ! is_array( $users ) ) {
			$users = array();
		}

		if ( $include_user_id > 0 ) {
			$ids = wp_list_pluck( $users, 'ID' );
			if ( ! in_array( $include_user_id, $ids, true ) ) {
				$extra = get_userdata( $include_user_id );
				if ( $extra ) {
					array_unshift( $users, $extra );
				}
			}
		}

		return $users;
	}

	/**
	 * Whether a user has the pet owner role.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function user_is_pet_owner( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id < 1 ) {
			return false;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		return in_array( self::ROLE_PET_OWNER, (array) $user->roles, true );
	}

	/**
	 * Save owner post meta from the classic meta box.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @param bool    $update  Whether this is an update.
	 * @return void
	 */
	public static function save_owner_meta( $post_id, $post, $update ) {
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

		if ( ! self::verify_meta_box_nonce( 'ltkf_save_pet_owner_meta', 'ltkf_pet_owner_nonce' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified via verify_meta_box_nonce().
		$uid = isset( $_POST['ltkf_hub_owner_user_id'] ) ? absint( wp_unslash( $_POST['ltkf_hub_owner_user_id'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $uid > 0 && ! self::user_is_pet_owner( $uid ) ) {
			return;
		}

		self::persist_owner_user_id( $post_id, $uid );
	}

	/**
	 * Write canonical Hub meta and legacy KennelFlow Vet key for compatibility.
	 *
	 * @param int $post_id Post ID.
	 * @param int $uid     Owner user ID (0 = none).
	 * @return void
	 */
	public static function persist_owner_user_id( $post_id, $uid ) {
		$post_id = absint( $post_id );
		$uid     = absint( $uid );
		if ( $post_id < 1 ) {
			return;
		}

		$key = ltkf_get_pet_owner_user_meta_key();

		if ( $uid > 0 ) {
			update_post_meta( $post_id, $key, $uid );
			update_post_meta( $post_id, self::LEGACY_KENNELFLOW_VET_OWNER_KEY, $uid );
		} else {
			delete_post_meta( $post_id, $key );
			delete_post_meta( $post_id, self::LEGACY_KENNELFLOW_VET_OWNER_KEY );
		}
	}

	/**
	 * Verify meta box nonce (save_post-safe: returns false when invalid; never wp_die() — avoids breaking autosave/REST saves).
	 *
	 * @param string $action Nonce action string.
	 * @param string $field  $_POST key holding the nonce value.
	 * @return bool True when the nonce is present and valid; false otherwise.
	 */
	protected static function verify_meta_box_nonce( $action, $field ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce value read for wp_verify_nonce() (same check check_admin_referer() uses internally).
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
