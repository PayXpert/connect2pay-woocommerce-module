<?php
/**
 * Wc Payment Gateway Payxpert Installment Blocks Support.
 *
 * @package Payxpert
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Payxpert\Models\Payxpert_Payment_Transaction;
use Payxpert\Utils\WC_Payxpert_Utils;

defined( 'ABSPATH' ) || exit;

/**
 * WC Payment Gateway Payxpert Installment Blocks Support.
 */
abstract class WC_Payment_Gateway_Payxpert_Installment_Blocks_Support extends AbstractPaymentMethodType {
	/**
	 * Gateway.
	 *
	 * @var mixed
	 */
	protected $gateway;
	/**
	 * Name.
	 *
	 * @var string
	 */
	protected $name = '';

	/**
	 * Initialize.
	 *
	 * @return mixed
	 */
	public function initialize() {
		$this->settings = get_option( "woocommerce_{$this->name}_settings", array() );
		$gateways       = WC()->payment_gateways->payment_gateways();
		$this->gateway  = $gateways[ $this->name ] ?? null;
	}

	/**
	 * Is active.
	 *
	 * @return mixed
	 */
	public function is_active() {
		return $this->gateway && $this->gateway->is_available();
	}

	/**
	 * Get payment method script handles.
	 *
	 * @return mixed
	 */
	public function get_payment_method_script_handles() {
		$script_name = $this->name;
		$plugin_dir  = dirname( __DIR__, 2 );

		$script_asset_path = $plugin_dir . '/assets/js/build/blocks/' . $script_name . '.asset.php';

		$script_asset = file_exists( $script_asset_path )
		? require $script_asset_path
		: array(
			'dependencies' => array(),
			'version'      => '1.0.0',
		);

		if ( $this->gateway && $this->gateway->seamless ) {
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
			'wc-' . $this->name . '-blocks-integration',
			$script_url,
			$script_asset['dependencies'],
			$script_asset['version'],
			true
		);

		return array( 'wc-' . $this->name . '-blocks-integration' );
	}

	/**
	 * Get payment method data.
	 *
	 * @return mixed
	 */
	public function get_payment_method_data() {
		$seamless_data = null;
		$description   = '';

		if ( $this->gateway->seamless ) {
			global $wp;
			$order = null;

			if ( isset( $wp->query_vars['order-pay'] ) ) {
				$order_id = absint( $wp->query_vars['order-pay'] );
				$order    = wc_get_order( $order_id );
			}

			$seamless_data = $this->gateway->get_seamless_data( $order );
			if ( is_null( $seamless_data ) ) {
				$description = '';
			} elseif ( isset( $seamless_data['error'] ) ) {
				$description = is_string( $seamless_data['error'] ) ? esc_html( $seamless_data['error'] ) : '';
			} else {
				$schedule_html = method_exists( $this->gateway, 'get_installment_schedule_html' )
				? $this->gateway->get_installment_schedule_html()
				: '';
				$extra         = 'style="width:100%"';
				$description   = $schedule_html . $this->gateway->get_seamless_payment_fields_html( $seamless_data, $extra );
			}
		} else {
			$description = $this->gateway->get_payment_fields();
		}

		$plugin_dir               = dirname( __DIR__, 2 );
		$payment_method_logo_path = plugin_dir_url( $plugin_dir . '/payxpert.php' ) . 'assets/img/payment_method_logo';
		$icons                    = WC_Payxpert_Utils::get_gateway_block_icons( $this->gateway->configuration );

		return array(
			'title'        => $this->get_setting( 'title', $this->gateway->title ),
			'description'  => $description,
			'seamless'     => $seamless_data,
			'supports'     => array_filter( $this->gateway->supports, array( $this->gateway, 'supports' ) ),
			'icons'        => $icons,
			'icon_path'    => $payment_method_logo_path,
			'icon_enabled' => 'yes' === $this->gateway->configuration['payxpert_installment_payment_logo_enabled'],
		);
	}
}
