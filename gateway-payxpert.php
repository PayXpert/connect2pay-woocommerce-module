<?php
/*
 * Plugin Name: WooCommerce PayXpert Gateway
 * Plugin URI: http://www.payxpert.com
 * Description: WooCommerce PayXpert Gateway plugin
 * Version: 1.3.0
 * Author: PayXpert
 * Author URI: http://www.payxpert.com
 * Text Domain: payxpert
 * Domain Path: /languages
 */

/**
 * PayXpert Standard Payment Gateway Library
 *
 * Provides a PayXpert Standard Payment Gateway.
 */
include_once( 'vendor/autoload.php' );

/**
 * Check if WooCommerce is active
 **/
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {

}

/*
* Include Gateway Setting
*/
require_once "includes/class-wc-setting.php";

/*
* Include PayXpert main Class
*/
require_once "includes/class-wc-payxpert.php";

/**
 * The Main Class Of Plugin
 */
final class PayxpertMainClass {
	// Class construction
	private function __construct() {
		$this->define_function();

		add_action( 'plugins_loaded', [ $this, 'init_plugin' ] );
		add_filter( 'woocommerce_payment_gateways', [ $this, 'woocommerce_payxpert_gateway' ] );
		add_action( 'admin_head', [ $this, 'redirct_to_another_setting' ] );
		add_action( 'wp_footer', [ $this, 'payxpert_payment_script_footer' ] );
		add_action( 'plugins_loaded', [ $this, 'woocommerce_payxpert_init' ], 0 );

		// Activate plugin hook
		register_activation_hook( __FILE__, [ $this, 'activate_plugin' ] );

		// Uninstall actions
		//register_uninstall_hook( __FILE__, [ $this, 'uninstall_plugin' ] );

	}

	/*
		Single instance
	*/
	public static function init() {
		static $instance = false;

		if ( ! $instance ) {
			$instance = new self();
		}

		return $instance;
	}


	public function define_function() {
		define( "PX_FILE", __FILE__ );
		define( "PX_PATH", __DIR__ );
		define( "PX_URL", plugins_url( '', PX_FILE ) );
		define( "PX_ASSETS", PX_URL . '/assets' );
		define("PX_PLUGIN_VERSION", '1.3.0');
	}

	public function init_plugin() {
		new PayXpertOption();

		// Language support
		load_plugin_textdomain( 'payxpert', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	}

	public function woocommerce_payxpert_gateway( $methods ) {
		$methods[] = 'WC_Gateway_PayXpert_WeChat';
		$methods[] = 'WC_Gateway_PayXpert_Alipay';
		$methods[] = 'WC_PayXpert_Seamless_Gateway';

		return $methods;
	}

	public function redirct_to_another_setting() {
		if ( isset( $_GET['page'] ) && isset( $_GET['tab'] ) && isset( $_GET['section'] ) ) {
			$getoptionurl = get_admin_url( null, "/admin.php?page=wc-settings&tab=checkout&section=payxpert" );
			// PayXpert Seamless Option
			if ( $_GET['page'] == "wc-settings" && $_GET['tab'] == "checkout" && $_GET['section'] == "payxpert_seamless" ) {
				wp_safe_redirect( $getoptionurl );
			}

			// PayXpert WeChat Option
			if ( $_GET['page'] == "wc-settings" && $_GET['tab'] == "checkout" && $_GET['section'] == "payxpert_wechat" ) {
				wp_safe_redirect( $getoptionurl );
			}

			// PayXpert Alipay Option
			if ( $_GET['page'] == "wc-settings" && $_GET['tab'] == "checkout" && $_GET['section'] == "payxpert_alipay" ) {
				wp_safe_redirect( $getoptionurl );
			}

			// PayXpert Alipay Option
			if ( $_GET['page'] == "wc-settings" && $_GET['tab'] == "checkout" && $_GET['section'] == "payxpert" ) {
				$optionupdate = array(
					'enabled' => 'yes'
				);
			}
		}
	}

	public function payxpert_payment_script_footer() {
		
		if ( is_checkout() ) {
			$arrayavailableid          = array();
			$available_payment_methods = WC()->payment_gateways->get_available_payment_gateways();
			if ( ! empty( $available_payment_methods ) ) {
				foreach ( $available_payment_methods as $method ) {
					$arrayavailableid[] = $method->id;
				}
			}
			
			if ( 
				in_array( "payxpert_seamless", $arrayavailableid ) &&
				get_option('payxpert_credit_card_mode') == 'seamless'
				) {
				include( PX_PATH.'/views/seamless-footer-script.php' );
			}
		}
	}

	public function woocommerce_payxpert_init() {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}
		if ( get_option( 'payxpert_wechat_pay' ) == "yes" ) {
			require_once( plugin_basename( 'includes/class-wc-gateway-payxpert-wechat.php' ) );
		}
		if ( get_option( 'payxpert_seamless_mode' ) == "yes" ) {
			require_once( plugin_basename( 'includes/class-wc-gateway-payxpert-card.php' ) );
		}
		if ( get_option( 'payxpert_alipay' ) == "yes" ) {
			require_once( plugin_basename( 'includes/class-wc-gateway-payxpert-alipay.php' ) );
		}
	}

	public function activate_plugin() {

		// Set all options to default
		$this->set_default_options();

	}

	public function uninstall_plugin() {

		// Set all options to default
		$this->remove_all_options();

	}

	public function remove_all_options() {

		$options = [
			'payxpert_originator_id',
			'payxpert_password',
			'payxpert_wechat_pay',
			'payxpert_alipay',
			'payxpert_seamless_mode',
			'payxpert_credit_card_mode',
			'payxpert_transaction_operation',
			'payxpert_pay_button',
			'payxpert_seamless_version',
			'payxpert_seamless_hash',
			'payxpert_connect2_url',
			'payxpert_api_url',
			'payxpert_debug',
			'payxpert_merchant_notifications',
			'payxpert_merchant_notifications_to',
			'payxpert_merchant_notifications_lang'
		];

		// Loop through the options and delete them
		foreach ( $options as $option ) {
			delete_option( $option );
		}

	}

	public function set_default_options() {

		$options = [
			'payxpert_credit_card_mode' => 'redirect',
			'payxpert_transaction_operation' => 'default',
			'payxpert_pay_button' => 'Payment',
			'payxpert_seamless_version' => '1.5.0',
			'payxpert_seamless_hash' => 'sha384-0IS2bunsGTTsco/UMCa56QRukMlq2pEcjUPMejy6WspCmLpGmsD3z0CmF5LQHF5X',
			'payxpert_connect2_url' => 'https://connect2.payxpert.com',
			'payxpert_api_url' => 'https://connect2.payxpert.com',
			'payxpert_merchant_notifications' => 'default',
			'payxpert_merchant_notifications_lang' => 'en',
		];

		// Loop through the options and set the default values
		foreach ( $options as $key => $value ) {
			if ( ! get_option( $key ) ) {
				update_option( $key, $value );
			}
		}

	}

}

/*
Initialize the main plugin
*/
function Payxpert_init() {
	return PayxpertMainClass::init();
}

/*
Active Plugin
*/
Payxpert_init();




