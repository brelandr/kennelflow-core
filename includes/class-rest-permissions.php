<?php
/**
 * REST: Staff permissions matrix (GET + PATCH KennelFlow-managed capabilities only).
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class RestPermissions
 */
class RestPermissions {

	const REST_NAMESPACE = 'kennelflow/v1';

	const ROUTE = '/permissions';

	/**
	 * KennelFlow-managed capability slugs (labels only — never exposes core WP caps like delete_plugins).
	 *
	 * @return array<string,string> Capability slug => admin label.
	 */
	public static function get_managed_capability_definitions() {
		$defs = array(
			'kennelflow_vet_edit_emr'     => __( 'Edit Medical Records (EMR)', 'kennelflow-core' ),
			'kennelpress_override_roster' => __( 'Override Roster', 'kennelflow-core' ),
			'groompress_view_commissions' => __( 'View Grooming Commissions', 'kennelflow-core' ),
		);

		/**
		 * Filters KennelFlow-managed capabilities shown in the matrix and accepted by PATCH.
		 *
		 * @since 0.2.0
		 *
		 * @param array<string,string> $defs Capability slug => label.
		 */
		return apply_filters( 'ltkf_permissions_managed_capabilities', $defs );
	}

	/**
	 * Allowed capability slugs for PATCH (keys of managed definitions).
	 *
	 * @return string[]
	 */
	public static function get_managed_capability_slugs() {
		return array_keys( self::get_managed_capability_definitions() );
	}

	/**
	 * Role slugs excluded from the staff matrix (front-of-house / store customer roles).
	 *
	 * @return string[]
	 */
	public static function get_excluded_role_slugs() {
		$excluded = array( 'subscriber', 'customer' );

		/**
		 * Filters role slugs omitted from GET/PATCH /kennelflow/v1/permissions.
		 *
		 * @since 0.2.0
		 *
		 * @param string[] $excluded Slugs (lowercase).
		 */
		return apply_filters( 'ltkf_permissions_matrix_excluded_roles', $excluded );
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_permissions' ),
					'permission_callback' => array( __CLASS__, 'permission_manage' ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( __CLASS__, 'patch_permission' ),
					'permission_callback' => array( __CLASS__, 'permission_manage' ),
					'args'                => array(
						'role'       => array(
							'description' => __( 'WordPress role slug.', 'kennelflow-core' ),
							'type'        => 'string',
							'required'    => true,
						),
						'capability' => array(
							'description' => __( 'KennelFlow-managed capability slug.', 'kennelflow-core' ),
							'type'        => 'string',
							'required'    => true,
						),
						'grant'      => array(
							'description' => __( 'Whether to grant the capability.', 'kennelflow-core' ),
							'type'        => 'boolean',
							'required'    => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Only administrators may read or change the matrix.
	 *
	 * @return bool
	 */
	public static function permission_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET /kennelflow/v1/permissions
	 *
	 * @return WP_REST_Response
	 */
	public static function get_permissions() {
		return new \WP_REST_Response( self::build_permissions_payload(), 200 );
	}

	/**
	 * Parse grant flag from JSON/body (boolean or 0/1).
	 *
	 * @param mixed $value Raw.
	 * @return bool|null Null when invalid.
	 */
	protected static function parse_grant_boolean( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (bool) (int) $value;
		}
		if ( is_string( $value ) ) {
			$v = strtolower( trim( $value ) );
			if ( 'true' === $v || '1' === $v ) {
				return true;
			}
			if ( 'false' === $v || '0' === $v ) {
				return false;
			}
		}
		return null;
	}

	/**
	 * PATCH /kennelflow/v1/permissions
	 *
	 * Body: role, capability, grant (boolean).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function patch_permission( $request ) {
		$role_slug = isset( $request['role'] ) ? sanitize_key( (string) $request['role'] ) : '';
		$cap       = isset( $request['capability'] ) ? sanitize_key( (string) $request['capability'] ) : '';
		$grant     = isset( $request['grant'] ) ? self::parse_grant_boolean( $request['grant'] ) : null;

		if ( '' === $role_slug ) {
			return new \WP_Error(
				'ltkf_permissions_invalid_role',
				__( 'Invalid or missing role.', 'kennelflow-core' ),
				array( 'status' => 400 )
			);
		}

		$excluded_roles = array_map( 'strtolower', self::get_excluded_role_slugs() );
		if ( in_array( strtolower( $role_slug ), $excluded_roles, true ) ) {
			return new \WP_Error(
				'ltkf_permissions_role_not_allowed',
				__( 'This role cannot be modified via the matrix.', 'kennelflow-core' ),
				array( 'status' => 400 )
			);
		}

		$allowed_caps = self::get_managed_capability_slugs();
		if ( '' === $cap || ! in_array( $cap, $allowed_caps, true ) ) {
			return new \WP_Error(
				'ltkf_permissions_invalid_capability',
				__( 'Invalid or unknown KennelFlow capability.', 'kennelflow-core' ),
				array( 'status' => 400 )
			);
		}

		if ( null === $grant ) {
			return new \WP_Error(
				'ltkf_permissions_invalid_grant',
				__( 'Missing or invalid grant value.', 'kennelflow-core' ),
				array( 'status' => 400 )
			);
		}

		$role_obj = get_role( $role_slug );
		if ( ! $role_obj instanceof \WP_Role ) {
			return new \WP_Error(
				'ltkf_permissions_role_missing',
				__( 'Role does not exist.', 'kennelflow-core' ),
				array( 'status' => 404 )
			);
		}

		if ( true === $grant ) {
			$role_obj->add_cap( $cap );
		} else {
			$role_obj->remove_cap( $cap );
		}

		$payload = self::build_permissions_payload();

		$updated_role = null;
		foreach ( $payload['roles'] as $r ) {
			if ( isset( $r['slug'] ) && $role_slug === $r['slug'] ) {
				$updated_role = array(
					'slug'         => $role_slug,
					'name'         => isset( $r['name'] ) ? $r['name'] : $role_slug,
					'grants'       => isset( $payload['grants'][ $role_slug ] ) ? $payload['grants'][ $role_slug ] : array(),
					'capabilities' => isset( $payload['roles_to_capabilities'][ $role_slug ] )
						? $payload['roles_to_capabilities'][ $role_slug ]
						: array(),
				);
				break;
			}
		}

		$payload['updated_role'] = $updated_role;

		return new \WP_REST_Response( $payload, 200 );
	}

	/**
	 * Build response body for GET and PATCH (KennelFlow-managed caps only).
	 *
	 * @return array<string,mixed>
	 */
	protected static function build_permissions_payload() {
		$wp_roles = wp_roles();
		if ( ! $wp_roles || ! is_object( $wp_roles ) ) {
			return array(
				'roles'                 => array(),
				'capabilities'          => array(),
				'grants'                => array(),
				'roles_to_capabilities' => array(),
			);
		}

		$excluded = array_map( 'strtolower', self::get_excluded_role_slugs() );
		$defs     = self::get_managed_capability_definitions();
		$cap_keys = array_keys( $defs );

		$roles_out = array();
		$grants    = array();
		$map       = array();

		foreach ( $wp_roles->roles as $slug => $details ) {
			$slug = sanitize_key( (string) $slug );
			if ( '' === $slug || in_array( $slug, $excluded, true ) ) {
				continue;
			}

			$caps = isset( $details['capabilities'] ) && is_array( $details['capabilities'] )
				? $details['capabilities']
				: array();

			$name = isset( $details['name'] ) ? (string) $details['name'] : $slug;
			$name = translate_user_role( $name );

			$roles_out[] = array(
				'slug' => $slug,
				'name' => $name,
			);

			$grants[ $slug ] = array();
			$managed_granted = array();
			foreach ( $cap_keys as $cap ) {
				$has                     = ! empty( $caps[ $cap ] );
				$grants[ $slug ][ $cap ] = $has;
				if ( $has ) {
					$managed_granted[] = $cap;
				}
			}

			$map[ $slug ] = $managed_granted;
		}

		$capabilities = array();
		foreach ( $defs as $slug => $label ) {
			$capabilities[] = array(
				'slug'  => $slug,
				'label' => $label,
			);
		}

		return array(
			'roles'                 => $roles_out,
			'capabilities'          => $capabilities,
			'grants'                => $grants,
			'roles_to_capabilities' => $map,
		);
	}
}
