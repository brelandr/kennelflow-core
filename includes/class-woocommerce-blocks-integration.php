<?php
/**
 * WooCommerce Blocks / Store API: emergency contact (checkout block + cart/extensions).
 *
 * Store API cart callbacks receive only request $data (no order). Values are stored in session
 * and copied to order meta on {@see woocommerce_store_api_checkout_order_processed}.
 *
 * @package KennelFlow
 */

namespace Landtech\KennelFlow\Core;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
	return;
}

if ( ! interface_exists( '\Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface' ) ) {
	return;
}

if ( ! class_exists( 'WooCommerce' ) ) {
	return;
}

/**
 * Class WoocommerceBlocksIntegration
 */
class WoocommerceBlocksIntegration implements \Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface {

	/**
	 * Session key for emergency phone between extensionCartUpdate and order placement.
	 */
	const SESSION_KEY_EMERGENCY = 'ltkf_emergency_contact_blocks';

	/**
	 * Namespace for woocommerce_store_api_register_update_callback (must match JS extensionCartUpdate).
	 */
	const STORE_API_NAMESPACE = 'kf-emergency-contact';

	/**
	 * WordPress script handle for the compiled checkout block bundle (build/checkout-blocks.js).
	 */
	const SCRIPT_HANDLE = 'kf-blocks-checkout';

	/**
	 * Relative path to the webpack output (same folder as checkout-blocks.asset.php from npm run build).
	 */
	const BUILD_SCRIPT_PATH = 'build/checkout-blocks.js';

	/**
	 * Relative path to wp-scripts generated dependency manifest.
	 */
	const BUILD_ASSET_PATH = 'build/checkout-blocks.asset.php';

	/**
	 * Register hooks (call from load_woocommerce_integration).
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'register_store_api_callbacks' ), 10 );
		add_action( 'woocommerce_blocks_checkout_block_registration', array( __CLASS__, 'register_checkout_integration' ), 10, 1 );
		if ( function_exists( 'wc_get_order' ) ) {
			add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'persist_emergency_from_session' ), 10, 1 );
		}
	}

	/**
	 * Register cart/extensions callback for extensionCartUpdate.
	 *
	 * @return void
	 */
	public static function register_store_api_callbacks() {
		if ( ! function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			return;
		}

		woocommerce_store_api_register_update_callback(
			array(
				'namespace' => self::STORE_API_NAMESPACE,
				'callback'  => array( __CLASS__, 'store_api_emergency_callback' ),
			)
		);
	}

	/**
	 * Persist emergency phone to session so it can be attached to the order after checkout.
	 *
	 * @param array $data Data from cart/extensions (extensionCartUpdate).
	 * @return void
	 */
	public static function store_api_emergency_callback( $data ) {
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		if ( ! isset( $data['ltkf_emergency_contact'] ) ) {
			return;
		}

		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		$value = sanitize_text_field( wp_unslash( (string) $data['ltkf_emergency_contact'] ) );
		WC()->session->set( self::SESSION_KEY_EMERGENCY, $value );
	}

	/**
	 * After Store API checkout creates the order, copy session value to order meta (same as classic checkout).
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	public static function persist_emergency_from_session( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( ! class_exists( 'WoocommerceCheckout' ) ) {
			return;
		}

		if ( ! WoocommerceCheckout::order_has_kf_booking( $order ) ) {
			return;
		}

		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		$raw = WC()->session->get( self::SESSION_KEY_EMERGENCY );
		if ( null === $raw ) {
			return;
		}

		$value = sanitize_text_field( (string) $raw );

		if ( '' === $value ) {
			$order->delete_meta_data( WoocommerceCheckout::ORDER_META_EMERGENCY_CONTACT );
		} else {
			$order->update_meta_data( WoocommerceCheckout::ORDER_META_EMERGENCY_CONTACT, $value );
		}

		$order->save();

		WC()->session->set( self::SESSION_KEY_EMERGENCY, null );
	}

	/**
	 * Register this integration with the checkout block registry.
	 *
	 * @param object $integration_registry Integration registry (Blocks).
	 * @return void
	 */
	public static function register_checkout_integration( $integration_registry ) {
		if ( ! is_object( $integration_registry ) || ! method_exists( $integration_registry, 'register' ) ) {
			return;
		}

		$integration_registry->register( new self() );
	}

	/**
	 * Unique integration id for Blocks script data (getSetting suffix _data).
	 *
	 * @return string
	 */
	public function get_name() {
		return 'kennelflow-kf-emergency';
	}

	/**
	 * Register frontend script for checkout block (IntegrationInterface).
	 *
	 * @return void
	 */
	public function initialize() {
		$this->register_checkout_block_script();
	}

	/**
	 * Register `build/checkout-blocks.js` and merge dependencies/version from `build/checkout-blocks.asset.php`.
	 *
	 * @return void
	 */
	private function register_checkout_block_script() {
		$script_url = LTKF_PLUGIN_URL . self::BUILD_SCRIPT_PATH;
		$asset_file = LTKF_PLUGIN_DIR . self::BUILD_ASSET_PATH;

		$asset = array(
			'dependencies' => array(),
			'version'      => LTKF_CORE_VERSION,
		);

		if ( file_exists( $asset_file ) ) {
			$loaded = require $asset_file;
			if ( is_array( $loaded ) ) {
				$asset = array_merge( $asset, $loaded );
			}
		}

		$dependencies = ( isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) )
			? $asset['dependencies']
			: array();
		$version      = isset( $asset['version'] ) ? $asset['version'] : LTKF_CORE_VERSION;

		wp_register_script(
			self::SCRIPT_HANDLE,
			$script_url,
			$dependencies,
			$version,
			true
		);

		wp_set_script_translations( self::SCRIPT_HANDLE, 'kennelflow-core', LTKF_PLUGIN_DIR . 'languages' );
	}

	/**
	 * Script handles to enqueue on checkout (frontend).
	 *
	 * @return string[]
	 */
	public function get_script_handles() {
		return array( self::SCRIPT_HANDLE );
	}

	/**
	 * Script handles for block editor (none).
	 *
	 * @return string[]
	 */
	public function get_editor_script_handles() {
		return array();
	}

	/**
	 * Data exposed to JS via wcSettings.getSetting( '{name}_data' ), e.g. kennelflow-kf-emergency_data.
	 *
	 * @return array<string, mixed>
	 */
	public function get_script_data() {
		$show = false;
		if ( class_exists( 'WoocommerceCheckout' ) ) {
			$show = WoocommerceCheckout::cart_has_kf_booking();
		}

		return array(
			'showEmergencyField' => $show,
			'fieldLabel'         => __( 'Emergency Contact Phone (Optional)', 'kennelflow-core' ),
			'fieldPlaceholder'   => __( 'Phone number', 'kennelflow-core' ),
		);
	}
}
