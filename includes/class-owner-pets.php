<?php
/**
 * Owner (wp_users) ↔ Pet (kf_pet) mapping via user meta and post meta.
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class OwnerPets
 */
class OwnerPets {

	/**
	 * Cache of previous owner user ID per pet during save (priority 5 → 99).
	 *
	 * @var array<int,int>
	 */
	protected static $old_owner_by_pet = array();

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		register_post_meta(
			ltkf_get_pet_post_type(),
			ltkf_get_pet_owner_user_meta_key(),
			array(
				'type'              => 'integer',
				'single'            => true,
				'sanitize_callback' => 'absint',
				'show_in_rest'      => true,
				'auth_callback'     => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		add_action( 'save_post_' . ltkf_get_pet_post_type(), array( __CLASS__, 'capture_old_owner' ), 5, 3 );
		add_action( 'save_post_' . ltkf_get_pet_post_type(), array( __CLASS__, 'sync_after_save' ), 99, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'before_delete_pet' ), 10, 1 );

		/**
		 * Fires after KennelFlow Core registers owner↔pet sync hooks.
		 *
		 * @since 0.1.0
		 */
		do_action( 'ltkf_owner_pets_init' );
	}

	/**
	 * Snapshot previous owner before meta writes in this request.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @param bool    $update  Update.
	 * @return void
	 */
	public static function capture_old_owner( $post_id, $post, $update ) {
		unset( $post, $update );
		$post_id = absint( $post_id );
		if ( $post_id < 1 ) {
			return;
		}
		self::$old_owner_by_pet[ $post_id ] = absint( get_post_meta( $post_id, ltkf_get_pet_owner_user_meta_key(), true ) );
	}

	/**
	 * Rebuild `kf_owner_pet_ids` for affected users after save.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @param bool    $update  Update.
	 * @return void
	 */
	public static function sync_after_save( $post_id, $post, $update ) {
		unset( $post, $update );
		$post_id = absint( $post_id );
		if ( $post_id < 1 ) {
			return;
		}

		$old = isset( self::$old_owner_by_pet[ $post_id ] ) ? absint( self::$old_owner_by_pet[ $post_id ] ) : 0;
		$new = absint( get_post_meta( $post_id, ltkf_get_pet_owner_user_meta_key(), true ) );

		unset( self::$old_owner_by_pet[ $post_id ] );

		if ( $old > 0 && $old !== $new ) {
			self::rebuild_user_pet_ids( $old );
		}
		if ( $new > 0 ) {
			self::rebuild_user_pet_ids( $new );
		}
	}

	/**
	 * Remove pet from owner list before the post row is removed (meta still readable).
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function before_delete_pet( $post_id ) {
		$post_id = absint( $post_id );
		if ( $post_id < 1 ) {
			return;
		}
		if ( ltkf_get_pet_post_type() !== get_post_type( $post_id ) ) {
			return;
		}
		$owner = absint( get_post_meta( $post_id, ltkf_get_pet_owner_user_meta_key(), true ) );
		if ( $owner > 0 ) {
			self::rebuild_user_pet_ids( $owner, $post_id );
		}
	}

	/**
	 * Recompute user meta list of pet IDs from post meta (source of truth on posts).
	 *
	 * @param int $user_id          User ID.
	 * @param int $exclude_post_id  Pet post ID to omit (e.g. being deleted).
	 * @return void
	 */
	public static function rebuild_user_pet_ids( $user_id, $exclude_post_id = 0 ) {
		$user_id = absint( $user_id );
		if ( $user_id < 1 ) {
			return;
		}

		$exclude_post_id = absint( $exclude_post_id );

		$args = array(
			'post_type'      => ltkf_get_pet_post_type(),
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array(
					'key'   => ltkf_get_pet_owner_user_meta_key(),
					'value' => $user_id,
				),
			),
		);

		if ( $exclude_post_id > 0 ) {
			$args['post__not_in'] = array( $exclude_post_id );
		}

		// phpcs:disable WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Sync index.
		$q = new \WP_Query( $args );
		// phpcs:enable WordPress.WP.PostsPerPage.posts_per_page_posts_per_page

		$ids = array_map( 'absint', is_array( $q->posts ) ? $q->posts : array() );
		$ids = array_values( array_filter( array_unique( $ids ) ) );

		update_user_meta( $user_id, ltkf_get_owner_pet_ids_meta_key(), $ids );

		/**
		 * Fires after KennelFlow rebuilds a user's pet ID list.
		 *
		 * @since 0.1.0
		 *
		 * @param int   $user_id User ID.
		 * @param int[] $ids     Pet post IDs.
		 */
		do_action( 'ltkf_owner_pet_ids_rebuilt', $user_id, $ids );
	}

	/**
	 * Pet IDs for a user (from user meta cache).
	 *
	 * @param int $user_id User ID.
	 * @return int[]
	 */
	public static function get_pet_ids_for_user( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id < 1 ) {
			return array();
		}
		$raw = get_user_meta( $user_id, ltkf_get_owner_pet_ids_meta_key(), true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'absint', $raw ) ) );
	}
}
