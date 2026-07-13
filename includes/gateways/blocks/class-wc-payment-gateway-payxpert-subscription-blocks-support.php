<?php
/**
 * Wc Payment Gateway Payxpert Subscription Blocks Support.
 *
 * @package Payxpert
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Payxpert\Models\Payxpert_Payment_Transaction;
use Payxpert\Utils\WC_Payxpert_Utils;

defined( 'ABSPATH' ) || exit;

/**
 * WC Payment Gateway Payxpert Subscription Blocks Support.
 */
final class WC_Payment_Gateway_Payxpert_Subscription_Blocks_Support extends AbstractPaymentMethodType {
	/**
	 * Gateway.
	 *
	 * @var mixed
	 */
	private $gateway;

	/**
	 * Name.
	 *
	 * @var string
	 */
	protected $name = 'payxpert_subscription';

	/**
	 * Initialize.
	 *
	 * @return mixed
	 */
	public function initialize() {
		$this->settings = get_option( "woocommerce_{$this->name}_settings", array() );
		$gateways       = WC()->payment_gateways->payment_gateways();
		$this->gateway  = $gateways[ $this->name ];
	}

	/**
	 * Is active.
	 *
	 * @return mixed
	 */
	public function is_active() {
		return $this->gateway->is_available();
	}

	/**
	 * Get payment method script handles.
	 *
	 * @return mixed
	 */
	public function get_payment_method_script_handles() {
		$script_name = $this->name;
		$plugin_dir  = dirname( __DIR__, 3 );

		$script_asset_path = $plugin_dir . '/assets/js/build/blocks/' . $script_name . '.asset.php';

		if ( file_exists( $script_asset_path ) ) {
			$script_asset = require $script_asset_path;
		} else {
			$script_asset = array(
				'dependencies' => array(),
				'version'      => '1.0.0',
			);
		}

		if ( $this->gateway->seamless ) {
			global $wp;
			$checkout_order_id = 0;
			if ( is_checkout_pay_page() && isset( $wp->query_vars['order-pay'] ) ) {
				$checkout_order_id = absint( $wp->query_vars['order-pay'] );
			}
			$this->gateway->register_seamless_script_assets( $checkout_order_id );

			if ( ! in_array( 'payxpert-seamless', $script_asset['dependencies'], true ) ) {
				$script_asset['dependencies'][] = 'payxpert-seamless';
			}
		}

		$script_url = plugin_dir_url( $plugin_dir . '/payxpert.php' ) . 'assets/js/build/blocks/' . $script_name . '.js';

		wp_register_script(
			'wc-payxpert-subscription-blocks-integration',
			$script_url,
			$script_asset['dependencies'],
			$script_asset['version'],
			true
		);

		return array( 'wc-payxpert-subscription-blocks-integration' );
	}

	/**
	 * Get payment method data.
	 *
	 * @return mixed
	 */
	public function get_payment_method_data() {
		$description = $this->gateway->get_payment_fields();

		if ( $this->gateway->seamless ) {
			global $wp;
			$order = null;

			// Order-Pay special case.
			if ( isset( $wp->query_vars['order-pay'] ) ) {
				$order_id = absint( $wp->query_vars['order-pay'] );
				$order    = wc_get_order( $order_id );
			}

			$seamless_data = $this->gateway->get_seamless_data( $order );
			if ( isset( $seamless_data['error'] ) ) {
				$description = $seamless_data['error'];
			}
		}

		// Icon conditions.
		$plugin_dir               = dirname( __DIR__, 3 );
		$payment_method_logo_path = plugin_dir_url( $plugin_dir . '/payxpert.php' ) . 'assets/img/payment_method_logo';
		$icons                    = WC_Payxpert_Utils::get_gateway_block_icons( $this->gateway->configuration );

		return array(
			'title'       => $this->get_setting( 'title', $this->gateway->title ),
			'description' => $description,
			'seamless'    => $seamless_data ?? null,
			'supports'    => array_filter( $this->gateway->supports, array( $this->gateway, 'supports' ) ),
			'icons'       => $icons,
			'icon_path'   => $payment_method_logo_path,
		);
	}
}
