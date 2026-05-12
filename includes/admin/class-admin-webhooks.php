<?php
/**
 * Admin: Webhooks & API settings.
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class AdminWebhooks
 */
class AdminWebhooks {

	const PAGE_SLUG = 'kf-webhooks-api';

	const NONCE_ACTION = 'ltkf_webhooks_save';

	const AJAX_ACTION_TEST = 'ltkf_test_webhook';

	const NONCE_ACTION_TEST = 'ltkf_test_webhook';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 12 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_test_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION_TEST, array( __CLASS__, 'ajax_test_webhook' ) );
	}

	/**
	 * Capability.
	 *
	 * @return string
	 */
	public static function required_cap() {
		return apply_filters( 'ltkf_webhooks_capability', 'manage_options' );
	}

	/**
	 * Submenu under KennelFlow Hub.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_submenu_page(
			ltkf_get_hub_menu_slug(),
			__( 'Webhooks & API', 'kennelflow-core' ),
			__( 'Webhooks & API', 'kennelflow-core' ),
			self::required_cap(),
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Save POST.
	 *
	 * @return void
	 */
	public static function maybe_save() {
		if ( ! isset( $_POST['ltkf_webhooks_save'] ) ) {
			return;
		}

		if ( ! current_user_can( self::required_cap() ) ) {
			return;
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Rows sanitized in loop (URL and allowlisted event slugs).
		$raw = isset( $_POST['ltkf_webhook_rows'] ) ? wp_unslash( $_POST['ltkf_webhook_rows'] ) : array();
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$allowed_events = array_keys( WebhookEngine::get_event_labels() );
		$saved          = array();

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$url = isset( $row['url'] ) ? esc_url_raw( trim( (string) $row['url'] ) ) : '';
			if ( '' === $url ) {
				continue;
			}

			$ev_raw = isset( $row['events'] ) ? $row['events'] : array();
			if ( ! is_array( $ev_raw ) ) {
				$ev_raw = array();
			}

			$events = array();
			foreach ( $ev_raw as $e ) {
				$e = sanitize_text_field( (string) $e );
				if ( in_array( $e, $allowed_events, true ) ) {
					$events[] = $e;
				}
			}

			$events = array_values( array_unique( $events ) );
			if ( empty( $events ) ) {
				continue;
			}

			$saved[] = array(
				'url'    => $url,
				'events' => $events,
			);
		}

		update_option( WebhookEngine::OPTION_KEY, $saved, false );

		set_transient( 'ltkf_webhooks_saved_notice_' . get_current_user_id(), '1', 60 );

		wp_safe_redirect(
			admin_url( 'admin.php?page=' . self::PAGE_SLUG )
		);
		exit;
	}

	/**
	 * Scripts for test-ping buttons (Webhooks screen only).
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue_test_assets( $hook_suffix ) {
		unset( $hook_suffix );

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$want   = ltkf_get_hub_page_hook_suffix( self::PAGE_SLUG );
		if ( null === $screen || ! isset( $screen->id ) || $want !== $screen->id ) {
			return;
		}

		$webhook_rows = WebhookEngine::get_endpoints();
		if ( empty( $webhook_rows ) ) {
			$webhook_rows = array(
				array(
					'url'    => '',
					'events' => array(),
				),
			);
		}
		$event_labels = WebhookEngine::get_event_labels();

		wp_register_script(
			'kf-admin-webhooks',
			LTKF_PLUGIN_URL . 'assets/js/kf-admin-webhooks.js',
			array(),
			LTKF_CORE_VERSION,
			true
		);
		wp_enqueue_script( 'kf-admin-webhooks' );

		wp_localize_script(
			'kf-admin-webhooks',
			'kfWebhookTest',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION_TEST ),
				'action'  => self::AJAX_ACTION_TEST,
				'strings' => array(
					'sending'       => __( 'Sending…', 'kennelflow-core' ),
					'emptyUrl'      => __( 'Enter a webhook URL first.', 'kennelflow-core' ),
					'requestFailed' => __( 'Request failed.', 'kennelflow-core' ),
				),
			)
		);

		wp_localize_script(
			'kf-admin-webhooks',
			'kfWebhooksPage',
			array(
				'nextRowIndex' => count( $webhook_rows ),
				'eventLabels'  => $event_labels,
				'strings'      => array(
					'testPing'       => __( 'Send Test Ping', 'kennelflow-core' ),
					'placeholderUrl' => 'https://',
				),
			)
		);

		wp_enqueue_style( 'common' );
		wp_add_inline_style(
			'common',
			'.kf-webhook-test-wrap{margin-top:8px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;}'
			. '.kf-webhook-test-result{font-size:13px;min-height:1.2em;}'
			. '.kf-webhook-test-result--ok{color:#00a32a;}'
			. '.kf-webhook-test-result--err{color:#d63638;}'
		);
	}

	/**
	 * AJAX: POST a test JSON payload to one webhook URL.
	 *
	 * @return void
	 */
	public static function ajax_test_webhook() {
		if ( ! check_ajax_referer( self::NONCE_ACTION_TEST, 'nonce', false ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Invalid security token.', 'kennelflow-core' ) ),
				403
			);
		}

		if ( ! current_user_can( self::required_cap() ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to do this.', 'kennelflow-core' ) ),
				403
			);
		}

		if ( ! isset( $_POST['webhook_url'] ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Missing URL.', 'kennelflow-core' ) ),
				400
			);
		}

		$url = esc_url_raw( trim( (string) wp_unslash( $_POST['webhook_url'] ) ) );
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Invalid or unreachable URL format.', 'kennelflow-core' ) ),
				400
			);
		}

		$payload = array(
			'event'    => 'ping',
			'message'  => 'KennelFlow Webhook Test Successful',
			'site_url' => home_url( '/' ),
		);

		$body = wp_json_encode( $payload );
		if ( false === $body ) {
			wp_send_json_error(
				array( 'message' => __( 'Could not build payload.', 'kennelflow-core' ) ),
				500
			);
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type'       => 'application/json; charset=' . get_bloginfo( 'charset' ),
					'X-KennelFlow-Event' => 'ping',
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: error message from HTTP client. */
						__( 'Request failed: %s', 'kennelflow-core' ),
						$response->get_error_message()
					),
				),
				200
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %d: HTTP status code. */
						__( 'Endpoint returned HTTP %d (expected 2xx).', 'kennelflow-core' ),
						$code
					),
				),
				200
			);
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Success: test ping delivered (HTTP %d).', 'kennelflow-core' ),
					$code
				),
			)
		);
	}

	/**
	 * Render settings.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( self::required_cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'kennelflow-core' ) );
		}

		$rows   = WebhookEngine::get_endpoints();
		$labels = WebhookEngine::get_event_labels();

		if ( empty( $rows ) ) {
			$rows = array(
				array(
					'url'    => '',
					'events' => array(),
				),
			);
		}

		$saved_notice = ( '1' === get_transient( 'ltkf_webhooks_saved_notice_' . get_current_user_id() ) );
		if ( $saved_notice ) {
			delete_transient( 'ltkf_webhooks_saved_notice_' . get_current_user_id() );
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Webhooks & API', 'kennelflow-core' ); ?></h1>
			<p class="description">
				<?php echo esc_html__( 'Send JSON payloads to Zapier, Make, or your own HTTPS endpoints when bookings or pet profiles change. Deliveries run in the background via Action Scheduler when available.', 'kennelflow-core' ); ?>
			</p>

			<?php if ( $saved_notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Settings saved.', 'kennelflow-core' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! function_exists( 'as_enqueue_async_action' ) ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php echo esc_html__( 'Action Scheduler is not available. Webhooks will be sent during the same request (slower). WooCommerce includes Action Scheduler.', 'kennelflow-core' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="ltkf_webhooks_save" value="1" />

				<table class="widefat striped" style="max-width: 960px;">
					<thead>
						<tr>
							<th scope="col"><?php echo esc_html__( 'Webhook URL (HTTPS)', 'kennelflow-core' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Events', 'kennelflow-core' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $rows as $i => $row ) {
							$url    = isset( $row['url'] ) ? esc_url_raw( (string) $row['url'] ) : '';
							$active = isset( $row['events'] ) && is_array( $row['events'] ) ? $row['events'] : array();
							?>
							<tr>
								<td>
									<input type="url" class="large-text" name="kf_webhook_rows[<?php echo esc_attr( (string) $i ); ?>][url]" value="<?php echo esc_attr( $url ); ?>" placeholder="https://hooks.zapier.com/..." />
									<div class="kf-webhook-test-wrap">
										<button type="button" class="button kf-webhook-test-ping">
											<?php echo esc_html__( 'Send Test Ping', 'kennelflow-core' ); ?>
										</button>
										<span class="kf-webhook-test-result" aria-live="polite"></span>
									</div>
								</td>
								<td>
									<?php foreach ( $labels as $slug => $label ) : ?>
										<label style="display:block;margin:0.25rem 0;">
											<input type="checkbox" name="kf_webhook_rows[<?php echo esc_attr( (string) $i ); ?>][events][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $active, true ) ); ?> />
											<?php echo esc_html( $label ); ?>
										</label>
									<?php endforeach; ?>
								</td>
							</tr>
							<?php
						}
						?>
						<tr class="kf-webhook-add-row-wrap">
							<td colspan="2">
								<button type="button" class="button" id="kf-webhook-add-row"><?php echo esc_html__( 'Add another URL', 'kennelflow-core' ); ?></button>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php echo esc_html__( 'Save webhooks', 'kennelflow-core' ); ?></button>
				</p>
			</form>

			<h2><?php echo esc_html__( 'Payload shape', 'kennelflow-core' ); ?></h2>
			<p class="description"><?php echo esc_html__( 'Each request is a POST with Content-Type: application/json and header X-KennelFlow-Event set to the event slug.', 'kennelflow-core' ); ?></p>
			<pre style="max-width: 52rem; padding: 1rem; background: #f6f7f7; border: 1px solid #c3c4c7; overflow: auto;"><?php echo esc_html( '{"event":"booking_created","site_url":"...","occurred_at":"2026-01-01T12:00:00+00:00","data":{...}}' ); ?></pre>
		</div>
		<?php
	}
}
