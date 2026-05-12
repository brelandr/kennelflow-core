<?php
/**
 * Public registration and email verification REST API.
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class UserRegistration
 */
class UserRegistration {

	const REST_NAMESPACE = 'kennelflow/v1';

	const META_VERIFY_TOKEN = '_kf_verify_token';

	const META_EMAIL_VERIFIED = '_kf_email_verified';

	/**
	 * Minimum password length for public registration.
	 */
	const MIN_PASSWORD_LENGTH = 8;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'wp_authenticate_user', array( __CLASS__, 'guard_authenticated_user' ), 10, 2 );
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'rest_guard_unverified' ), 99 );
	}

	/**
	 * Block password authentication when email is not yet verified (new registrations only).
	 *
	 * Users without `_kf_email_verified` meta are allowed (pre–email-verification installs).
	 *
	 * @param WP_User|WP_Error $user     Authenticated user, or error from earlier checks.
	 * @param string           $password Password (unused; validation already ran).
	 * @return WP_User|WP_Error
	 */
	public static function guard_authenticated_user( $user, $password ) {
		unset( $password );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		if ( ! $user instanceof \WP_User ) {
			return $user;
		}
		if ( ! self::user_must_verify_email( (int) $user->ID ) ) {
			return $user;
		}
		return new \WP_Error(
			'unverified_email',
			__( 'Please check your email and click the verification link before logging in.', 'kennelflow-core' )
		);
	}

	/**
	 * Block REST API usage for logged-in users who have not verified email.
	 *
	 * Complements {@see guard_authenticated_user} for cookie/session REST auth.
	 *
	 * @param WP_Error|null|mixed $errors Previous authentication error, if any.
	 * @return WP_Error|null|mixed
	 */
	public static function rest_guard_unverified( $errors ) {
		if ( is_wp_error( $errors ) ) {
			return $errors;
		}
		if ( ! is_user_logged_in() ) {
			return $errors;
		}
		$uid = get_current_user_id();
		if ( $uid < 1 ) {
			return $errors;
		}
		if ( ! self::user_must_verify_email( $uid ) ) {
			return $errors;
		}
		return new \WP_Error(
			'unverified_email',
			__( 'Please check your email and click the verification link before logging in.', 'kennelflow-core' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Whether this user must verify email before using the site (meta present and stored as 0).
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	protected static function user_must_verify_email( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id < 1 ) {
			return false;
		}
		if ( ! metadata_exists( 'user', $user_id, self::META_EMAIL_VERIFIED ) ) {
			return false;
		}
		$v = get_user_meta( $user_id, self::META_EMAIL_VERIFIED, true );
		// Strictly unregistered: only integer 0 or string '0' (as saved by registration).
		return 0 === $v || '0' === $v;
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/register',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_register' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'first_name' => array(
						'type'     => 'string',
						'required' => true,
					),
					'last_name'  => array(
						'type'     => 'string',
						'required' => true,
					),
					'email'      => array(
						'type'     => 'string',
						'required' => true,
					),
					'password'   => array(
						'type'     => 'string',
						'required' => true,
					),
					'ltkf_trap'    => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/verify-email',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_verify_email' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'user_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
					'token'   => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Redirect target after email verification (boarding / booking page).
	 *
	 * @return string Absolute URL.
	 */
	public static function get_verify_redirect_url() {
		$url = home_url( '/' );

		/**
		 * Filters the URL users are sent to after verifying email via REST.
		 *
		 * Point this at your boarding booking page; `verified=1` is appended on success.
		 *
		 * @since 0.2.0
		 *
		 * @param string $url Default home URL.
		 */
		return apply_filters( 'ltkf_registration_verify_redirect_url', $url );
	}

	/**
	 * POST /kennelflow/v1/register
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_register( $request ) {
		$trap = $request->get_param( 'ltkf_trap' );
		if ( null !== $trap && '' !== trim( (string) $trap ) ) {
			return self::honeypot_fake_success();
		}

		$first = sanitize_text_field( wp_unslash( (string) $request->get_param( 'first_name' ) ) );
		$last  = sanitize_text_field( wp_unslash( (string) $request->get_param( 'last_name' ) ) );
		$email = sanitize_email( wp_unslash( (string) $request->get_param( 'email' ) ) );
		$pass  = (string) $request->get_param( 'password' );

		if ( '' === $first || '' === $last ) {
			return new \WP_Error(
				'ltkf_register_name',
				__( 'Please provide your first and last name.', 'kennelflow-core' ),
				array( 'status' => 400 )
			);
		}

		if ( ! is_email( $email ) ) {
			return new \WP_Error(
				'ltkf_register_email',
				__( 'Please provide a valid email address.', 'kennelflow-core' ),
				array( 'status' => 400 )
			);
		}

		if ( email_exists( $email ) ) {
			return new \WP_Error(
				'ltkf_register_email_exists',
				__( 'An account with this email already exists.', 'kennelflow-core' ),
				array( 'status' => 400 )
			);
		}

		if ( strlen( $pass ) < self::MIN_PASSWORD_LENGTH ) {
			return new \WP_Error(
				'ltkf_register_password',
				sprintf(
					/* translators: %d: minimum password length */
					__( 'Password must be at least %d characters.', 'kennelflow-core' ),
					(int) self::MIN_PASSWORD_LENGTH
				),
				array( 'status' => 400 )
			);
		}

		$user_login = self::generate_unique_username_from_email( $email );

		$user_id = wp_insert_user(
			array(
				'user_login'   => $user_login,
				'user_email'   => $email,
				'user_pass'    => $pass,
				'first_name'   => $first,
				'last_name'    => $last,
				'display_name' => trim( $first . ' ' . $last ),
				'role'         => 'subscriber',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return new \WP_Error(
				'ltkf_register_create',
				$user_id->get_error_message(),
				array( 'status' => 400 )
			);
		}

		$user_id = absint( $user_id );
		$token   = wp_generate_password( 24, false, false );

		update_user_meta( $user_id, self::META_VERIFY_TOKEN, $token );
		update_user_meta( $user_id, self::META_EMAIL_VERIFIED, 0 );

		self::send_verification_email( $user_id, $email, $token );

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Check your email to verify your account.', 'kennelflow-core' ),
				'user_id' => $user_id,
			)
		);
	}

	/**
	 * Honeypot filled: pretend success so bots do not retry differently.
	 *
	 * @return WP_REST_Response
	 */
	protected static function honeypot_fake_success() {
		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Check your email to verify your account.', 'kennelflow-core' ),
			)
		);
	}

	/**
	 * Unique login derived from email local part.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	protected static function generate_unique_username_from_email( $email ) {
		$local = '';
		if ( is_email( $email ) ) {
			$parts = explode( '@', $email, 2 );
			$local = isset( $parts[0] ) ? $parts[0] : '';
		}
		$base = sanitize_user( $local, true );
		if ( '' === $base ) {
			$base = 'user';
		}
		$candidate = $base;
		$n         = 0;
		while ( username_exists( $candidate ) ) {
			++$n;
			$candidate = $base . $n;
		}
		return $candidate;
	}

	/**
	 * Send HTML verification message.
	 *
	 * @param int    $user_id User ID.
	 * @param string $email   Email.
	 * @param string $token   Token.
	 * @return void
	 */
	protected static function send_verification_email( $user_id, $email, $token ) {
		$user_id = absint( $user_id );
		$verify  = add_query_arg(
			array(
				'user_id' => $user_id,
				'token'   => $token,
			),
			rest_url( self::REST_NAMESPACE . '/verify-email' )
		);

		$subject = __( 'Verify your email', 'kennelflow-core' );

		$body  = '<p>' . esc_html__( 'Thanks for registering. Click the link below to verify your email address:', 'kennelflow-core' ) . '</p>';
		$body .= '<p><a href="' . esc_url( $verify ) . '">' . esc_html__( 'Verify email address', 'kennelflow-core' ) . '</a></p>';
		$body .= '<p style="font-size:12px;color:#666;">' . esc_html( $verify ) . '</p>';

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		wp_mail( $email, $subject, $body, $headers );
	}

	/**
	 * GET /kennelflow/v1/verify-email
	 *
	 * @param WP_REST_Request $request Request.
	 * @return void
	 */
	public static function handle_verify_email( $request ) {
		$user_id = absint( $request->get_param( 'user_id' ) );
		$token   = sanitize_text_field( wp_unslash( (string) $request->get_param( 'token' ) ) );

		$redirect_base = self::get_verify_redirect_url();
		$redirect_ok   = add_query_arg( 'verified', '1', $redirect_base );
		$redirect_fail = add_query_arg( 'verified', '0', $redirect_base );

		$redirect_ok   = wp_validate_redirect( $redirect_ok, home_url( '/' ) );
		$redirect_fail = wp_validate_redirect( $redirect_fail, home_url( '/' ) );

		if ( $user_id < 1 || '' === $token ) {
			wp_safe_redirect( $redirect_fail );
			exit;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			wp_safe_redirect( $redirect_fail );
			exit;
		}

		$stored = (string) get_user_meta( $user_id, self::META_VERIFY_TOKEN, true );
		if ( '' === $stored || ! hash_equals( $stored, $token ) ) {
			wp_safe_redirect( $redirect_fail );
			exit;
		}

		update_user_meta( $user_id, self::META_EMAIL_VERIFIED, 1 );
		delete_user_meta( $user_id, self::META_VERIFY_TOKEN );

		wp_safe_redirect( $redirect_ok );
		exit;
	}
}
