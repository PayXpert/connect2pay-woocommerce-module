<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Payxpert\Utils\WC_Payxpert_Utils;

defined('ABSPATH') || exit;

final class WC_Payment_Gateway_Payxpert_CC_Blocks_Support extends AbstractPaymentMethodType {
	private $gateway;
	
	protected $name = 'payxpert_cc';
	
	public function initialize() {
		$this->settings = get_option( "woocommerce_{$this->name}_settings", array() );
		$gateways = WC()->payment_gateways->payment_gateways();
		$this->gateway  = $gateways[ $this->name ];
	}
	
	public function is_active() {
		return $this->gateway->is_available();
	}
	
	public function get_payment_method_script_handles() {
		$script_name = $this->name;
		$plugin_dir  = dirname(__DIR__, 3);
		
		$script_asset_path = $plugin_dir . '/assets/js/build/blocks/' . $script_name . '.asset.php';
		
		if ( file_exists( $script_asset_path ) ) {
			$script_asset = require $script_asset_path;
		} else {
			$script_asset = [
				'dependencies' => [],
				'version'      => '1.0.0',
			];
		}

		if ( $this->gateway->seamless && ! in_array( 'payxpert-seamless', $script_asset['dependencies'], true ) ) {
			$script_asset['dependencies'][] = 'payxpert-seamless';
		}
		
		$script_url = plugin_dir_url( $plugin_dir . '/payxpert.php' ) . 'assets/js/build/blocks/' . $script_name . '.js';
		
		wp_register_script(
			'wc-payxpert-cc-blocks-integration',
			$script_url,
			$script_asset['dependencies'],
			$script_asset['version'],
			true
		);
		
		return [ 'wc-payxpert-cc-blocks-integration' ];
	}
	
	public function get_payment_method_data() {
		$seamlessData = null;
		$description = '';
		
		if ($this->gateway->seamless) {
			global $wp;
			$order = null;
			
			if ( isset( $wp->query_vars['order-pay'] ) ) {
				$order_id = absint( $wp->query_vars['order-pay'] );
				$order = wc_get_order( $order_id );
			}
			
			$seamlessData = $this->gateway->get_seamless_data($order);
			if (is_null($seamlessData)) {
				$description = '';
			} elseif (isset($seamlessData['error'])) {
				$description = is_string($seamlessData['error']) ? esc_html($seamlessData['error']) : '';
			} else {
				$description = $this->gateway->get_seamless_payment_fields_html($seamlessData);
			}
		} else {
			$description = $this->gateway->get_payment_fields();
		}
		
		// Icon conditions
		$plugin_dir = dirname(__DIR__, 3);
		$paymentMethodLogoPath = plugin_dir_url( $plugin_dir . '/payxpert.php' ) . 'assets/img/payment_method_logo';
		$icons = WC_Payxpert_Utils::get_gateway_block_icons($this->gateway->configuration);
		
		return array(
			'title' 		=> $this->get_setting( 'title' , $this->gateway->title),
			'description' 	=> $description,
			'seamless' 		=> $seamlessData ?? null,
			'supports'  	=> array_filter( $this->gateway->supports, [ $this->gateway, 'supports' ] ),
			'icons'         => $icons,
			'icon_path'     => $paymentMethodLogoPath,
		);
	}
}
