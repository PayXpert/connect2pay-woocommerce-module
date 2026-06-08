<?php

namespace Payxpert;

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use DateTime;
use PayXpert\Connect2Pay\containers\constant\PaymentMethod;
use PayXpert\Connect2Pay\containers\constant\PaymentMode;
use PayXpert\Connect2Pay\containers\constant\SubscriptionType;
use Payxpert\Models\Payxpert_Payment_Transaction;
use Payxpert\Models\Payxpert_Subscription;
use Payxpert\Utils\WC_Payxpert_Cron;
use Payxpert\Utils\WC_Payxpert_Install;
use Payxpert\Utils\WC_Payxpert_Logger;
use Payxpert\Utils\WC_Payxpert_Utils;
use Payxpert\Utils\WC_Payxpert_Webservice;
use WC_Customer;

defined( 'ABSPATH' ) || exit();

class WC_Payxpert {
	const SUBSCRIPTION_FREQUENCY = '_payxpert_subscription_frequency';
	const SUBSCRIPTION_DURATION_TYPE = '_payxpert_subscription_duration_type';
	const SUBSCRIPTION_MAX_CYCLES = '_payxpert_subscription_max_cycles';
	const SUBSCRIPTION_FIRST_PAYMENT = '_payxpert_subscription_first_payment';
	const SUBSCRIPTION_PRICE = '_payxpert_subscription_price';
	const SUBSCRIPTION = '_payxpert_subscription';
	
	const REDIRECT_MODE_REDIRECT = 0;
	const REDIRECT_MODE_SEAMLESS = 1;
	
	const REDIRECT_MODES = [
		self::REDIRECT_MODE_REDIRECT,
		self::REDIRECT_MODE_SEAMLESS,
	];
	
	const CAPTURE_MODE_AUTOMATIC = 0;
	const CAPTURE_MODE_MANUAL = 1;
	
	const CAPTURE_MODES = [
		self::CAPTURE_MODE_AUTOMATIC,
		self::CAPTURE_MODE_MANUAL,
	];
	
	//! Do not exceed 17chars
	const ORDER_STATUS_INSTALLMENT_PENDING = 'pxp-istlm-pending';
	const ORDER_STATUS_CAPTURE_PENDING = 'pxp-captr-pending';
	
	/** Nonce issued in seamless iframe before the WC order exists (checkout / blocks). */
	const PAYMENT_RESULT_SEAMLESS_NONCE_ACTION = 'payxpert_seamless_payment_poll';
	
	/** @deprecated Legacy nonce action kept for backward compatibility. */
	const PAYMENT_RESULT_LEGACY_NONCE_ACTION = 'payxpert_payment_nonce';
	
	const PAYMENT_RESULT_POLL_ATTEMPTS = 40;
	const PAYMENT_RESULT_POLL_INTERVAL_US = 250000;
	
	/**
	 * Nonce action for payment-result polling.
	 * - order_id > 0: per-order (e.g. pay-for-order page).
	 * - order_id <= 0: checkout seamless iframe mounted before order creation.
	 */
	public static function payment_result_nonce_action( int $order_id ): string {
		if ( $order_id <= 0 ) {
			return self::PAYMENT_RESULT_SEAMLESS_NONCE_ACTION;
		}
		
		return 'payxpert_pay_' . $order_id;
	}
	
	public static $_instance;
	
	public static function instance() {
		if ( self::$_instance == null ) {
			self::$_instance = new self();
		}
		
		return self::$_instance;
	}
	
	private function __clone() {}
	
	public function __wakeup(): void {}
	
	public function __construct() {
		add_action( 'before_woocommerce_init', function () {
			if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WC_PAYXPERT_PLUGIN_FILE_PATH . 'payxpert.php' );
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WC_PAYXPERT_PLUGIN_FILE_PATH . 'payxpert.php' );
			}
		});
		
		add_action( 'init', array($this, 'init') );
		add_action( 'woocommerce_init', array( $this, 'woocommerce_dependencies' ) );
		add_action( 'woocommerce_blocks_loaded', array( $this, 'payxpert_blocks_loaded' ) );
		
		// Remove canonical redirection for FO pages with pagination
		add_filter('redirect_canonical', function($redirect_url){
			if (!is_admin()
			&& is_account_page()
			&& strpos($_SERVER['REQUEST_URI'], 'payxpert-subscription-details') !== false
			&& isset($_GET['paged'])) {
				return false;
			}
			return $redirect_url;
		});
		
		add_filter( 'plugin_action_links_' . WC_PAYXPERT_PLUGIN_NAME, array( $this, 'action_links') );
		
		add_action( 'plugins_loaded', function() {
			// Load translations
			load_plugin_textdomain( 'payxpert', false, dirname( WC_PAYXPERT_PLUGIN_NAME ) . '/languages/' );
			
			if (is_admin()) {
				require_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/admin/class-wc-payxpert-settings.php';
				\WC_Payxpert_Settings::instance();
				
				require_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/admin/class-wc-payxpert-transaction-list.php';
				\WC_Payxpert_Transaction_List::instance();
				
				require_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/admin/class-wc-payxpert-subscription-list.php';
				\WC_Payxpert_Subscription_List::instance();
				
				require_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/admin/class-wc-payxpert-subscription-detail.php';
				\WC_Payxpert_Subscription_Detail::instance();
			}
			
			// Add subscription checkbox in simple/variable product Type
			add_filter( 'product_type_options', function ( $options ) {
				$options['payxpert_subscription'] = array(
					'id'            => self::SUBSCRIPTION,
					'wrapper_class' => 'show_if_simple show_if_variable',
					'label'         => __( 'subscription PayXpert', 'payxpert' ),
					'description'   => __( 'Enable to set this product as a subscription', 'payxpert' ),
					'default'       => 'no',
				);
				
				return $options;
			});
			
			// ---- CHECK DB VERSION ----
			global $wpdb;
			$installed_version = get_option('wc_payxpert_db_version', '1.0.0'); // version en DB
			$current_version   = WC_PAYXPERT_DB_VERSION; // version constante plugin
			
			if (version_compare($installed_version, $current_version, '<')) {
				WC_Payxpert_Install::install();
				
				if (method_exists('\Payxpert\Utils\WC_Payxpert_Install', 'upgrade_db')) {
					WC_Payxpert_Install::upgrade_db();
				}
				
				update_option('wc_payxpert_db_version', $current_version);
			}
			
			$installed_plugin_version = get_option('wc_payxpert_plugin_version', '0.0.0');
			if (version_compare($installed_plugin_version, '2.3.1', '<')) {
				WC_Payxpert_Install::cleanup_legacy_logs();
			}
			update_option('wc_payxpert_plugin_version', WC_PAYXPERT_VERSION);
			
			// Add subscription checkbox in simple/variable product Type
			add_filter( 'product_type_options', function ( $options ) {
				$options['payxpert_subscription'] = array(
					'id'            => self::SUBSCRIPTION,
					'wrapper_class' => 'show_if_simple show_if_variable',
					'label'         => __( 'subscription PayXpert', 'payxpert' ),
					'description'   => __( 'Enable to set this product as a subscription', 'payxpert' ),
					'default'       => 'no',
				);
				
				return $options;
			});
		});
		
		// IFRAME ajax call
		add_action( 'wp_ajax_payxpert_handle_payment_result', array($this, 'handle_payxpert_payment_result') );
		add_action( 'wp_ajax_nopriv_payxpert_handle_payment_result', array($this, 'handle_payxpert_payment_result') );
		add_action('template_redirect', array($this, 'maybe_add_failed_payment_notice'));
		
		// Transaction section in order page (BO)
		add_action( 'add_meta_boxes', array($this, 'payxpert_add_transaction_metabox') );
		
		// Payment gateways
		add_filter( 'woocommerce_payment_gateways', array( $this, 'add_payment_gateway' ) );
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'payxpert_available_payment_gateways' ) );
		
		// Order status management
		add_filter( 'wc_order_statuses', array($this, 'payxpert_order_statuses') );
		add_filter( 'woocommerce_register_shop_order_post_statuses', array($this, 'register_payxpert_shop_order_statuses') );
		add_filter( 'woocommerce_order_is_paid_statuses', function ($statuses) {
			$statuses[] = self::ORDER_STATUS_INSTALLMENT_PENDING;
			return $statuses;
		} );
		
		// Front GLOBAL assets
		add_action('wp_enqueue_scripts', function () {
			if ( ! function_exists('is_checkout') || ! is_checkout() || is_order_received_page() ) {
				return;
			}
			
			wp_enqueue_style(
				'payxpert-checkout-loader',
				plugin_dir_url(__FILE__) . '../assets/css/iframe-redirect-loader.css',
				[],
				'1.0.0'
			);
		});
		
		// Admin GLOBAL CSS
		add_action( 'admin_enqueue_scripts', function() {
			wp_enqueue_style(
				'payxpert-admin-global',
				WC_PAYXPERT_ASSETS . 'css/admin-global.css'
			);
		} );
		
		// Support mail
		add_filter( 'woocommerce_email_classes', function( $emails ) {
			require_once WC_PAYXPERT_PLUGIN_FILE_PATH. 'includes/class-wc-email-payxpert-support.php';
			require_once WC_PAYXPERT_PLUGIN_FILE_PATH. 'includes/class-wc-email-payxpert-paybylink.php';
			
			$emails['WC_Email_Payxpert_Support'] = new \WC_Email_Payxpert_Support();
			$emails['WC_Email_Payxpert_Paybylink'] = new \WC_Email_Payxpert_Paybylink();
			return $emails;
		} );
		
		
		add_action( 'payxpert_sync_subscriptions_cron', [new WC_Payxpert_Cron(), 'sync_subscriptions_cron'] );
		add_action( 'payxpert_cleanup_payment_tokens_cron', [new WC_Payxpert_Cron(), 'cleanup_payment_tokens_cron'] );
		
		// Admin Order - Actions
		add_filter( 'woocommerce_order_actions', [ $this , 'payxpert_order_actions' ] ); 
		add_action( 'woocommerce_order_action_payxpert_send_paybylink', [ $this , 'payxpert_paybylink' ] );
		add_action( 'woocommerce_order_action_payxpert_send_paybylink_x2', [ $this , 'payxpert_paybylink_x2' ] );
		add_action( 'woocommerce_order_action_payxpert_send_paybylink_x3', [ $this , 'payxpert_paybylink_x3' ] );
		add_action( 'woocommerce_order_action_payxpert_send_paybylink_x4', [ $this , 'payxpert_paybylink_x4' ] );
		
		// Admin Order - Custom button
		add_action( 'admin_post_payxpert_capture_transaction', [ $this, 'handle_capture_transaction' ] );
		
		//! Real gateway IDs. Check gateways/class::ID
		$gateway_ids = [ 
			'payxpert_cc',
			'payxpert_installment_x2',
			'payxpert_installment_x3',
			'payxpert_installment_x4',
			'payxpert_paybylink',
			'payxpert_subscription',
		];
		
		// Override toggle enable/disable in wooc payment settings
		foreach ( $gateway_ids as $gateway_id ) {
			$enabled = get_option($gateway_id.'_enabled', 'no'); // ta valeur custom
			$wc_settings = get_option("woocommerce_{$gateway_id}_settings", []);
			
			// Initialize when not configured yet.
			if ( ! isset($wc_settings['enabled']) ) {
				$wc_settings['enabled'] = $enabled; // oui/non
				update_option("woocommerce_{$gateway_id}_settings", $wc_settings);
			}
			
			add_filter( "pre_update_option_woocommerce_{$gateway_id}_settings", function( $new_value ) use ( $gateway_id ) {
				update_option($gateway_id . '_enabled', $new_value['enabled'] ?? 'no');
				return $new_value;
			}, 10, 2 );
		}
		
		add_action( 'admin_notices', function() {
			if ($error = get_transient('payxpert_admin_error')) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($error) . '</p></div>';
				delete_transient('payxpert_admin_error');
			}
			
			if ($success = get_transient('payxpert_admin_success')) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($success) . '</p></div>';
				delete_transient('payxpert_admin_success');
			}
			
			if ($info = get_transient('payxpert_admin_info')) {
				echo '<div class="notice notice-info is-dismissible"><p>' . esc_html($info) . '</p></div>';
				delete_transient('payxpert_admin_info');
			}
			
			$errors = get_transient('payxpert_subscription_errors_' . get_current_user_id());
			if ($errors) {
				echo '<div class="notice notice-error"><p>';
				echo implode('<br>', $errors);
				echo '</p></div>';
				delete_transient('payxpert_subscription_errors_' . get_current_user_id());
			}
		} );
		
		// Menu in My-Account section
		add_action( 'woocommerce_account_payxpert-subscription-list_endpoint', function() {
			wc_get_template('views/myaccount/html-subscription-list.php', [], '', WC_PAYXPERT_PLUGIN_FILE_PATH . 'templates/');
		});
		add_action( 'woocommerce_account_payxpert-subscription-details_endpoint', function() {
			wc_get_template('views/myaccount/html-subscription-details.php', [], '', WC_PAYXPERT_PLUGIN_FILE_PATH . 'templates/');
		});
		
		add_filter( 'query_vars', function ($vars) {
			$vars[] = 'payxpert-subscription-list';
			$vars[] = 'payxpert-subscription-details';
			return $vars;
		} );
		
		add_filter( 'woocommerce_account_menu_items', function ($items) {
			$new_items = [];
			
			$user_has_subscriptions = Payxpert_Subscription::count_user_subscriptions(get_current_user_id()) > 0;
			
			foreach ($items as $key => $label) {
				$new_items[$key] = $label;
				
				if ($key === 'orders') {
					if ($user_has_subscriptions) {
						$new_items['payxpert-subscription-list'] = __('Subscriptions', 'payxpert');
					} 
				}
			}
			
			return $new_items;
		} );
		
		add_action( 'wp_footer', array( $this, 'payxpert_modal_cancel_assets' ));
		add_action( 'admin_footer' , array( $this, 'admin_footer' ));
		
		// When product is saved
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'admin_process_product_object' ));
		// When product variations are saved
		add_action( 'woocommerce_save_product_variation', array( $this, 'payxpert_save_variation_subscription_fields' ), 10, 2);
		
		// Add Subscription Detail Tab in product_data
		add_filter( 'woocommerce_product_data_tabs', function ( $options ) {
			$options['payxpert_subscription'] = array(
				'label'    => __( 'Subscription plan details', 'payxpert' ),
				'target'   => 'payxpert_subscription_product_data',
				'class'    => array( 'hide_if_grouped', 'hide_if_external', 'hide_if_variable'),
				'priority' => 90,
			);
			
			return $options;
		});
		
		// Add Panel Data for subscription detail
		add_action( 'woocommerce_product_data_panels', array( $this, 'display_product_subscription_detail_panel'));
		
		// Add Panel Data for product variation
		add_action( 'woocommerce_product_after_variable_attributes', array($this, 'display_variation_subscription_panel'), 10, 3 );
		
		// Display subscription info for single product
		add_action( 'woocommerce_single_product_summary', array( $this, 'display_subscription_info_single_product'), 25 );
		add_action( 'woocommerce_available_variation', array( $this, 'add_available_variation_data'), 25, 3 );
		add_action( 'woocommerce_single_variation', array( $this, 'display_subscription_info_variable_product') );
		
		// Add Validator to avoid cart with subscription item and normal item
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'restrict_mixed_subscription_cart' ), 10, 2 );
	}
	
	public function action_links($links)
	{
		$url = admin_url('admin.php?page=payxpert-settings');
		$settings_link = '<a href="' . esc_url($url) . '">' . __('Settings', 'payxpert') . '</a>';
		array_unshift($links, $settings_link);
		return $links;
	}
	
	public function payxpert_available_payment_gateways($gateways) {
		if (is_null(WC()->cart)) {
			return [];
		}
		
		$configuration = WC_Payxpert_Utils::get_configuration();
		
		// Disable gateways if payxpert account not linked
		if ($configuration['payxpert_conn_status'] == false) {
			foreach ($gateways as $id => $gateway) {
				if (strpos($id, 'payxpert') !== false) {
					unset($gateways[$id]);
				}
			}
		}
		
		// Whether the cart has a product flagged as PayXpert subscription.
		$subscription_in_cart = WC_Payxpert_Utils::has_subscription_in_cart(WC()->cart->get_cart());
		
		// Subscription product in cart: restrict gateways.
		if ($subscription_in_cart) {
			// Allowed payment methods for subscription products.
			$allowed_gateways = ['payxpert_subscription'];
			
			foreach ($gateways as $gateway_id => $gateway) {
				if (!in_array($gateway_id, $allowed_gateways)) {
					unset($gateways[$gateway_id]);
				}
			}
		} else {
			// No subscription in cart: remove subscription gateway only.
			if ( isset( $gateways['payxpert_subscription'] ) ) {
				unset( $gateways['payxpert_subscription'] );
			}
		}
		
		return $gateways;
	}
	
	public function woocommerce_dependencies() {
		// Load abstracts
		include_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/abstract/abstract-wc-payment-gateway-payxpert.php';
		include_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/abstract/abstract-wc-payment-gateway-payxpert-installment.php';
		
		// Load gateways
		include_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/gateways/class-wc-payment-gateway-payxpert-cc.php';
		include_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/gateways/class-wc-payment-gateway-payxpert-installment-x2.php';
		include_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/gateways/class-wc-payment-gateway-payxpert-installment-x3.php';
		include_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/gateways/class-wc-payment-gateway-payxpert-installment-x4.php';
		include_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/gateways/class-wc-payment-gateway-payxpert-paybylink.php';
		include_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/gateways/class-wc-payment-gateway-payxpert-subscription.php';
	}
	
	public function add_payment_gateway($gateways)
	{
		$gateways[] = 'WC_Payment_Gateway_Payxpert_CC';
		$gateways[] = 'WC_Payment_Gateway_Payxpert_Installment_X2';
		$gateways[] = 'WC_Payment_Gateway_Payxpert_Installment_X3';
		$gateways[] = 'WC_Payment_Gateway_Payxpert_Installment_X4';
		$gateways[] = 'WC_Payment_Gateway_Payxpert_Paybylink';
		$gateways[] = 'WC_Payment_Gateway_payxpert_subscription';
		
		return $gateways;
	}
	
	public function payxpert_add_transaction_metabox() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}
		
		if ( strpos( $screen->id, 'shop_order' ) === false && $screen->id !== 'woocommerce_page_wc-orders') {
			return;
		}
		
		$order_id = isset($_GET['post']) ? absint($_GET['post']) : (isset($_GET['id']) ? absint($_GET['id']) : 0);
		
		if ( ! $order_id ) {
			return;
		}
		
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		
		$payment_method = $order->get_payment_method();
		if ( strpos( $payment_method, 'payxpert' ) !== 0 ) {
			return;
		}
		
		add_meta_box(
			'payxpert_transaction_meta_box',
			'PayXpert',
			[ $this, 'output_transaction_metabox' ],
			$screen,
			'normal',
			'core'
		);
	}
	
	/**
	 * Polls order status after seamless payment. Order state is only updated by the IPN.
	 */
	public function handle_payxpert_payment_result() {
		$order_id       = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$transaction_id = isset( $_POST['transactionID'] ) ? sanitize_text_field( wp_unslash( $_POST['transactionID'] ) ) : '';
		$payment_id     = isset( $_POST['paymentID'] ) ? sanitize_text_field( wp_unslash( $_POST['paymentID'] ) ) : '';
		$security       = isset( $_POST['security'] ) ? sanitize_text_field( wp_unslash( $_POST['security'] ) ) : '';
		
		if ( $order_id <= 0 || $transaction_id === '' || $payment_id === '' || $security === '' ) {
			wp_send_json_error( [ 'message' => __( 'Missing parameters.', 'payxpert' ) ] );
		}
		
		if ( ! $this->verify_payment_result_nonce( $order_id, $security ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid security token.', 'payxpert' ) ], 403 );
		}
		
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( [ 'message' => __( 'Order not found.', 'payxpert' ) ], 404 );
		}
		
		if ( ! $this->assert_ajax_order_access( $order ) ) {
			return;
		}
		
		if ( strpos( (string) $order->get_payment_method(), 'payxpert' ) !== 0 ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized order.', 'payxpert' ) ], 403 );
		}
		
		$transaction = $this->poll_payment_transaction( $transaction_id, $payment_id );
		
		if ( ! $transaction ) {
			wp_send_json_success( [ 'pending' => true ] );
		}
		
		if ( (int) ( $transaction['order_id'] ?? 0 ) !== $order_id ) {
			wp_send_json_error( [ 'message' => __( 'Transaction does not match this order.', 'payxpert' ) ], 403 );
		}
		
		$current_status = $order->get_status();
		$paid_statuses  = [
			'processing',
			'completed',
			self::ORDER_STATUS_INSTALLMENT_PENDING,
			self::ORDER_STATUS_CAPTURE_PENDING,
		];
		
		if ( in_array( $current_status, $paid_statuses, true ) ) {
			wp_send_json_success( [
				'urlRedirect' => $order->get_checkout_order_received_url(),
			] );
		}
			
		if ( is_user_logged_in() && (int) get_current_user_id() === (int) $order->get_user_id() ) {
			$retry_url = $order->get_view_order_url();
		} else {
			$retry_url = add_query_arg(
				[ 'payxpert_payment' => 'failed' ],
				$order->get_checkout_payment_url()
			);
		}
		
		wp_send_json_success( [ 'urlRedirect' => $retry_url ] );
	}
		
	/**
	 * @return array<string, mixed>|null
	 */
	private function poll_payment_transaction( string $transaction_id, string $payment_id ): ?array {
		for ( $attempt = 1; $attempt <= self::PAYMENT_RESULT_POLL_ATTEMPTS; $attempt++ ) {
			$transaction = Payxpert_Payment_Transaction::findByTransactionIdAndPaymentId( $transaction_id, $payment_id );
			if ( $transaction ) {
				return $transaction;
			}
			if ( $attempt < self::PAYMENT_RESULT_POLL_ATTEMPTS ) {
				usleep( self::PAYMENT_RESULT_POLL_INTERVAL_US );
			}
		}
		return null;
	}
	
	private function verify_payment_result_nonce( int $order_id, string $security ): bool {
		if ( $order_id <= 0 ) {
			return false;
		}
		
		// Pay-for-order: nonce and order_id were both issued for this order.
		if ( wp_verify_nonce( $security, self::payment_result_nonce_action( $order_id ) ) ) {
			return true;
		}
		
		// Checkout seamless: nonce from iframe prep (order did not exist yet).
		if ( $this->verify_checkout_seamless_poll_nonce( $order_id, $security ) ) {
			return true;
		}
		
		return false;
	}
	
	/**
	 * Accepts the pre-checkout seamless nonce when polling with the real order_id.
	 */
	private function verify_checkout_seamless_poll_nonce( int $order_id, string $security ): bool {
		$checkout_actions = [
			self::PAYMENT_RESULT_SEAMLESS_NONCE_ACTION,
			'payxpert_pay_0', // Previous releases used payment_result_nonce_action( 0 ).
			self::PAYMENT_RESULT_LEGACY_NONCE_ACTION,
		];
		
		foreach ( $checkout_actions as $action ) {
			if ( ! wp_verify_nonce( $security, $action ) ) {
				continue;
			}
			
			$order = wc_get_order( $order_id );
			return $order && $this->assert_ajax_order_access( $order, '', false );
		}
		
		return false;
	}
	
	/**
	 * Ensures the current visitor may act on this order (logged-in owner or guest session/key).
	 *
	 * @return bool When $send_json_error is true, sends JSON error and exits on failure.
	 */
	public function assert_ajax_order_access( \WC_Order $order, string $posted_order_key = '', bool $send_json_error = true ): bool {
		$uid       = get_current_user_id();
		$order_uid = (int) $order->get_customer_id();
		
		if ( $order_uid > 0 ) {
			if ( $uid !== $order_uid ) {
				if ( $send_json_error ) {
					wp_send_json_error( [ 'message' => __( 'Unauthorized order.', 'payxpert' ) ], 403 );
				}
				return false;
			}
			return true;
		}
		
		$session_order = ( WC()->session && is_callable( [ WC()->session, 'get' ] ) )
		? absint( WC()->session->get( 'order_awaiting_payment' ) )
		: 0;
		
		$guest_ok = ( $session_order === (int) $order->get_id() )
		|| ( $posted_order_key !== '' && hash_equals( (string) $order->get_order_key(), $posted_order_key ) );
		
		if ( ! $guest_ok ) {
			if ( $send_json_error ) {
				wp_send_json_error( [ 'message' => __( 'Unauthorized order.', 'payxpert' ) ], 403 );
			}
			return false;
		}
		
		return true;
	}
	
	public function maybe_add_failed_payment_notice() {
		if ( ! function_exists( 'is_checkout_pay_page' ) || ! is_checkout_pay_page() ) {
			return;
		}
		
		$payment_state = isset( $_GET['payxpert_payment'] ) ? sanitize_text_field( wp_unslash( $_GET['payxpert_payment'] ) ) : '';
		if ( 'failed' !== $payment_state ) {
			return;
		}
		
		wc_add_notice( __( 'Payment failed. Please try again.', 'woocommerce' ), 'error' );
	}
	
	public function payxpert_blocks_loaded()
	{
		if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}
		
		require_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/abstract/abstract-wc-payment-gateway-payxpert-installment-blocks-support.php';
		
		require_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/gateways/blocks/class-wc-payment-gateway-payxpert-cc-blocks-support.php';
		require_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/gateways/blocks/class-wc-payment-gateway-payxpert-installment-x2-blocks-support.php';
		require_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/gateways/blocks/class-wc-payment-gateway-payxpert-installment-x3-blocks-support.php';
		require_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/gateways/blocks/class-wc-payment-gateway-payxpert-installment-x4-blocks-support.php';
		require_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/gateways/blocks/class-wc-payment-gateway-payxpert-subscription-blocks-support.php';
		
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function (PaymentMethodRegistry $payment_method_registry) {
				$payment_method_registry->register(new \WC_Payment_Gateway_Payxpert_CC_Blocks_Support());
				$payment_method_registry->register(new \WC_Payment_Gateway_Payxpert_Installment_X2_Blocks_Support());
				$payment_method_registry->register(new \WC_Payment_Gateway_Payxpert_Installment_X3_Blocks_Support());
				$payment_method_registry->register(new \WC_Payment_Gateway_Payxpert_Installment_X4_Blocks_Support());
				$payment_method_registry->register(new \WC_Payment_Gateway_Payxpert_Subscription_Blocks_Support());
			}
		);
		
	}
	
	public function init()
	{
		// Add endpoint subscriptions in my-account
		add_rewrite_endpoint('payxpert-subscription-list', EP_ROOT | EP_PAGES);
		add_rewrite_endpoint('payxpert-subscription-details', EP_ROOT | EP_PAGES);
		
		// New status installment payment pending
		register_post_status('wc-' . self::ORDER_STATUS_INSTALLMENT_PENDING, array(
			'label'                     => _x('Instalment Payment Pending (PayXpert)', 'Order status', 'payxpert'),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop(
				'Waiting for instalment payments <span class="count">(%s)</span>',
				'Waiting for instalment payments <span class="count">(%s)</span>',
				'payxpert'
			),
		));
		
		// New status capture payment pending
		register_post_status('wc-' . self::ORDER_STATUS_CAPTURE_PENDING, array(
			'label'                     => _x('Capture Payment Pending (PayXpert)', 'Order status', 'payxpert'),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
		));
		
		if (!is_admin() && is_user_logged_in()) {
			if ( $_SERVER['REQUEST_METHOD'] === 'POST' && ! empty($_POST['payxpert_cancel_subscription']) ) {
				
				$current_user_id = get_current_user_id();
				$subscription_id = sanitize_text_field($_POST['subscription_id']);
				$reason_id       = sanitize_text_field($_POST['payxpert_cancel_reason']);
				$nonce           = $_POST['payxpert_cancel_nonce'] ?? '';
				
				if ( ! wp_verify_nonce($nonce, 'payxpert_cancel_subscription') ) {
					\wc_add_notice(__('Security check failed.', 'payxpert'), 'error');
					wp_safe_redirect( wc_get_account_endpoint_url('payxpert-subscription-list') );
					exit;
				}
				
				$result = self::cancel_subscription($subscription_id, $current_user_id, $reason_id);
				
				if ( $result['success'] ) {
					\wc_add_notice($result['message'], 'success');
				} else {
					\wc_add_notice($result['message'], 'error');
				}
				
				wp_safe_redirect( wc_get_account_endpoint_url('payxpert-subscription-list') );
				exit;
			}
			
		}
	}
	
	public function register_payxpert_shop_order_statuses($order_statuses)
	{
		// Installment payment in progress
		$order_statuses['wc-' . self::ORDER_STATUS_INSTALLMENT_PENDING] = array(
			'label'                     => _x('Instalment Payment Pending (PayXpert)', 'Order status', 'payxpert'),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop(
				'Waiting for instalment payments <span class="count">(%s)</span>',
				'Waiting for instalment payments <span class="count">(%s)</span>',
				'payxpert'
			),
		);
		
		// Waiting for payment capture
		$order_statuses['wc-' . self::ORDER_STATUS_CAPTURE_PENDING] = array(
			'label'                     => _x('Capture Payment Pending (PayXpert)', 'Order status', 'payxpert'),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
		);
		
		return $order_statuses;
	}
	
	public function payxpert_order_statuses($order_statuses)
	{
		$order_statuses['wc-' . self::ORDER_STATUS_INSTALLMENT_PENDING] = _x('Instalment Payment Pending (PayXpert)', 'Order status', 'payxpert');
		$order_statuses['wc-' . self::ORDER_STATUS_CAPTURE_PENDING] = _x('Capture Payment Pending (PayXpert)', 'Order status', 'payxpert');
		
		return $order_statuses;
	}
	
	public function output_transaction_metabox($post)
	{
		$order = $post instanceof \WC_Order ? $post : wc_get_order(isset($post->ID) ? (int) $post->ID : 0);
		if (!$order) {
			echo '<p style="text-align:center;">' . esc_html__('Order not found.', 'payxpert') . '</p>';
			return;
		}
		$transactions = Payxpert_Payment_Transaction::findAllByOrderId($order->get_id());
		
		if (empty($transactions) || !is_array($transactions)) {
			echo '<p style="text-align:center;">' . esc_html__('No transaction found.', 'payxpert') . '</p>';
			return;
		}
		
		//! Used in template
		$orderTransactionsFormatted = WC_Payxpert_Utils::get_order_transactions_formatted($transactions);
		
		$template_path = WC_PAYXPERT_PLUGIN_FILE_PATH . 'templates/views/html-admin-order-transactions.php';
		
		if (file_exists($template_path)) {
			include $template_path;
		} else {
			echo '<p>' . esc_html__('Transaction template not found.', 'payxpert') . '</p>';
		}
	}
	
	public function handle_capture_transaction()
	{
		if (
			!current_user_can('edit_shop_orders') ||
			!isset($_POST['payxpert_capture_action_nonce']) ||
			!wp_verify_nonce($_POST['payxpert_capture_action_nonce'], 'payxpert_capture_action')
		) {
			wp_die(__('Non authorized action.', 'payxpert'));
		}
			
		$order_id = absint($_POST['order_id'] ?? 0);
		$transaction_id = sanitize_text_field($_POST['capture_transaction_id'] ?? '');
		
		if (!$order_id || !$transaction_id) {
			wp_die(__('Missing parameters.', 'payxpert'));
		}
		
		$order = wc_get_order($order_id);
		
		if ( !$order ) {
			wp_die(__('Order not found.', 'payxpert'));
		}
		
		$transaction = Payxpert_Payment_Transaction::findOneBy([
			'transaction_id' => $transaction_id,
			'order_id' => $order_id
		]);
		
		if ( !$transaction ) {
			wp_die(__('Transaction not found.', 'payxpert'));
		}
		
		if ( Payxpert_Payment_Transaction::OPERATION_AUTHORIZE != $transaction['operation'] ) {
			wp_die(__('Transaction operation must be `authorize`', 'payxpert'));
		}
		
		if ( !empty(Payxpert_Payment_Transaction::findOneBy(['transaction_referal_id' => $transaction_id])) ) {
			wp_die(__('A capture transaction already exist for this transaction ID.', 'payxpert'));
		}
		
		$configuration = WC_Payxpert_Utils::get_configuration();
		$captureInfo = WC_Payxpert_Webservice::capture_transaction(
			$configuration, 
			$transaction_id, 
			intval(floatval($transaction['amount']) * 100)
		);
		
		if ( isset($captureInfo['error']) ) {
			WC_Payxpert_Logger::critical($captureInfo['error']);
			wp_die(__('An error occured during the capture process : ', 'payxpert') . $captureInfo['error']);
		}
		
		if (Payxpert_Payment_Transaction::RESULT_CODE_SUCCESS !== $captureInfo['code']) {
			WC_Payxpert_Logger::critical($captureInfo['message']);
			wp_die(__('An error occured during the capture process : ', 'payxpert') . $captureInfo['message']);
		}
		
		$transactionInfo = WC_Payxpert_Webservice::get_transaction_info($configuration, $captureInfo['transaction_id']);
		
		$payxpertPaymentTransaction = new Payxpert_Payment_Transaction();
		$newInfo = array_merge($transactionInfo, [
			'order_id' => $order_id,
			'transaction_referal_id' => $transaction_id,
		]);
		$payxpertPaymentTransaction->set($newInfo);
		$payxpertPaymentTransaction->save();
		
		$order->add_order_note(sprintf(
			__('Transaction %s captured successfully via PayXpert.', 'payxpert'),
			$captureInfo['transaction_id']
		));
		
		// Payment complete
		$order->payment_complete($captureInfo['transaction_id']);
		
		if (in_array($order->get_status(), ['pxp-captr-pending', 'wc-pxp-captr-pending'], true)) {
			$new_status = $order->needs_processing() ? 'processing' : 'completed';
			$order->update_status($new_status, __('Status updated after payment captured via PayXpert.', 'payxpert'));
		}
		
		// Redirect back to the order
		$redirect_url = WC_Payxpert_Utils::is_hpos_enabled()
		? admin_url('admin.php?page=wc-orders&action=edit&id=' . $order_id)
		: admin_url('post.php?post=' . $order_id . '&action=edit');
		
		wp_redirect($redirect_url);
		exit;
	}
		
	public function payxpert_order_actions($actions)
	{
		global $theorder;
		$configuration = WC_Payxpert_Utils::get_configuration();
		
		if (
			$theorder instanceof \WC_Order
			&& ($theorder->has_status('pending') || $theorder->has_status('failed'))
			&& $theorder->get_customer_id() > 0 // Order has a customer
			&& $configuration['payxpert_paybylink_enabled'] === 'yes'
		) {
			$actions['payxpert_send_paybylink'] = __('(PayXpert) Send PayByLink', 'payxpert');
			
			$order_total = $theorder->get_total();
			
			if (WC_Payxpert_Utils::is_installment_payment_available($configuration, $order_total)) {
				if ($configuration['payxpert_installment_x2_enabled'] === 'yes') {
					$actions['payxpert_send_paybylink_x2'] = __('(PayXpert) Send PayByLink x2', 'payxpert');
				}
				
				if ($configuration['payxpert_installment_x3_enabled'] === 'yes') {
					$actions['payxpert_send_paybylink_x3'] = __('(PayXpert) Send PayByLink x3', 'payxpert');
				}
				
				if ($configuration['payxpert_installment_x4_enabled'] === 'yes') {
					$actions['payxpert_send_paybylink_x4'] = __('(PayXpert) Send PayByLink x4', 'payxpert');
				}
			}
		}
			
		return $actions;
	}
			
	public function payxpert_paybylink($order)
	{
		$this->payxpert_paybylink_action($order);
	}
			
	public function payxpert_paybylink_x2($order)
	{
		$this->payxpert_paybylink_action($order, 2);
	}
	
	public function payxpert_paybylink_x3($order)
	{
		$this->payxpert_paybylink_action($order, 3);
	}
	
	public function payxpert_paybylink_x4($order)
	{
		$this->payxpert_paybylink_action($order, 4);
	}
			
	private function payxpert_paybylink_action($order, $xTimes = 1)
	{
		try {
			$configuration = WC_Payxpert_Utils::get_configuration();
			
			if (!$order->has_status('pending') && !$order->has_status('failed')) {
				throw new \Exception(__('The order status must be `Waiting for payment` or `Failed`', 'payxpert'));
			}
			
			if ($order->get_customer_id() == 0) {
				throw new \Exception(__('A customer must be assigned to the order before sending PayByLink (PayXpert)', 'payxpert'));
			}
			
			if (count($order->get_items()) === 0) {
				throw new \Exception(__('Your order must contain at least one product (PayXpert)', 'payxpert'));
			}
			
			if ($order->get_total() == 0) {
				throw new \Exception(__('Your order amount must be greater than 0 (PayXpert)', 'payxpert'));
			}
			
			$paymentMode = PaymentMode::SINGLE;
			$gateway = 'payxpert_cc';
			$instalmentParameters = [];
			
			if ($xTimes > 1) {
				$gateway = 'payxpert_installment';
				$paymentMode = PaymentMode::INSTALMENTS;
				$instalmentParameters = [
					'firstPercentage' => $configuration['payxpert_installment_x' . $xTimes . '_percentage'],
					'xTimes' => $xTimes,
				];
			}
			
			$preparedPayment = WC_Payxpert_Webservice::preparePayment(
				$configuration,
				'wc_payment_gateway_' . $gateway,
				PaymentMethod::CREDIT_CARD,
				$paymentMode,
				$order,
				$instalmentParameters,
				true
			);
			
			if (isset($preparedPayment['error'])) {
				throw new \Exception($preparedPayment['error']);
			}
			
			$customer = new WC_Customer($order->get_customer_id());
			$deadline = (new DateTime($order->get_date_created()))->modify('+30 days');
			
			// Format products list
			$order_products = '';
			foreach ($order->get_items() as $item) {
				$order_products .= '<p>- ' . esc_html($item->get_name()) . ' x ' . intval($item->get_quantity()) . '</p>';
			}
			
			// Send the email using WooCommerce mailer
			$mailer = WC()->mailer();
			$emails = $mailer->get_emails();
			if (!isset($emails['WC_Email_Payxpert_Paybylink'])) {
				throw new \Exception(__('WC_Email_Payxpert_Paybylink not found', 'payxpert'));
			}
			
			/** @var WC_Email_Payxpert_Paybylink $paybylink_email */
			$paybylink_email = $emails['WC_Email_Payxpert_Paybylink'];
			
			$sent = $paybylink_email->trigger([
				'recipient'        => $order->get_billing_email(),
				'order'            => $order,
				'payment_link'     => esc_url($preparedPayment['redirectUrl']),
				'firstname'        => $customer->get_first_name(),
				'lastname'         => $customer->get_last_name(),
				'order_reference'  => $order->get_order_number(),
				'order_date'       => $order->get_date_created()->date('Y-m-d H:i:s'),
				'order_products'   => $order_products,
				'order_subtotal'   => wc_price($order->get_subtotal()),
				'order_shipping'   => wc_price($order->get_shipping_total()),
				'order_total'      => wc_price($order->get_total()),
				'payment_deadline' => $deadline->format('Y-m-d H:i:s'),
				'shop_name'        => get_bloginfo('name'),
				'shop_url'         => home_url(),
				'email' 		   => $paybylink_email
			]);
			
			if ($sent) {
				$order->add_order_note(__('PayByLink email sent to the customer (PayXpert)', 'payxpert'));
				update_post_meta($order->get_id(), '_payxpert_paybylink_last_sent', current_time('mysql'));
				
				if (is_admin()) {
					set_transient('payxpert_admin_success', __('The PayByLink email has been sent successfully.', 'payxpert'), 60);
				}
			} else {
				set_transient('payxpert_admin_error',  __('An error occured while sending the PayByLink email.', 'payxpert'), 60);
			}
			
		} catch (\Exception $e) {
			if (is_admin()) {
				WC_Payxpert_Logger::error($e->getMessage());
				set_transient('payxpert_admin_error', $e->getMessage(), 60);
			}
		}
	}
	
	public function admin_footer() { 
		global $theorder; 
		
		?>
		<script>
		jQuery(document).ready(function($){
			// Subscription block in product
			function toggleSubscriptionBlocks($checked) {
				if ($checked) {
					$('.payxpert-subscription-variation').show();
				} else {
					$('.payxpert-subscription-variation').hide();
				}
			}
			
			toggleSubscriptionBlocks();
			
			// When WooCommerce injects new variations dynamically.
			$('#_payxpert_subscription').on('change', function(){
				toggleSubscriptionBlocks($(this).is(':checked'));
			});
		});
		</script>
		<?php
		
		$this->payxpert_modal_cancel_assets();
		
		if (!($theorder instanceof \WC_Order)) return; 
		
		$order_id = $theorder->get_id();
		
		// Last PayByLink email sent (if any).
		$last_sent_date = get_post_meta($order_id, '_payxpert_paybylink_last_sent', true);
		if ($last_sent_date) {
			// Human-readable date for the notice.
			$last_sent_date = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_sent_date));
			
			// Translatable prompt strings.
			$message_base     = __('A payment link has already been sent', 'payxpert');
			$last_sent_info   = $last_sent_date ? sprintf(__(' on %s', 'payxpert'), $last_sent_date) : '';
			$message_continue = __('. Do you want to continue?', 'payxpert');
			$full_message     = $message_base . $last_sent_info . $message_continue;
			?>
			<script>
			jQuery(document).ready(function($){
				var message = <?php echo json_encode($full_message); ?>;
				
				// Intercept order actions form submit.
				$('#actions').closest('form').on('submit', function(e){
					var selected = $(this).find('select[name="wc_order_action"]').val();
					
					// Confirm before PayXpert bulk actions.
					if(selected && selected.startsWith('payxpert_send_paybylink')){
						if(!confirm(message)){
							e.preventDefault(); // User cancelled confirmation.
							return false;
						}
					}
				});
			});
			</script>
			<?php
		}
	}
			
	// When product is saved
	public function admin_process_product_object( $product ) {
		$this->save_subscription_fields($_POST, $product->get_id(), '_payxpert_', $product->is_type( 'variable' ));
	}
	
	public function payxpert_save_variation_subscription_fields($variation_id, $i) {
		$prefix = "_payxpert_sub_{$variation_id}_payxpert_";
		$this->save_subscription_fields($_POST, $variation_id, $prefix);
	}
			
	public function display_product_subscription_detail_panel() {
		global $post;
		$product = wc_get_product( $post->ID );
		if (!$product || $product->is_type('variable')) {
			return;
		}
		
		echo '<div id="payxpert_subscription_product_data" class="panel woocommerce_options_panel hidden">';
		$this->render_subscription_fields($product, '_payxpert_');
		echo '</div>';
		
		// Panel-specific script for subscription fields.
		?>
		<script type="text/javascript">
		jQuery(function($){
			var $panel = $('#payxpert_subscription_product_data');
			function toggleMaxCycles() {
				if ($panel.find('select[name="<?php echo self::SUBSCRIPTION_DURATION_TYPE ?>"]').val() === "<?php echo SubscriptionType::NORMAL ?>") {
					$panel.find('.subscription-max-cycles').show();
				} else {
					$panel.find('.subscription-max-cycles').hide();
				}
			}
			toggleMaxCycles();
			$panel.find('select[name="<?php echo self::SUBSCRIPTION_DURATION_TYPE ?>"]').on('change', toggleMaxCycles);
		});
		</script>
		<?php
	} 
			
	public function display_variation_subscription_panel($loop, $variation_data, $variation)
	{		
		$variationID = $variation->ID;
		$prefix = "_payxpert_sub_{$variationID}";
		
		if ( ! $variation instanceof \WC_Product ) {
			if ( isset($variationID) ) {
				$variation = wc_get_product( $variationID );
			} else {
				return; // safety
			}
		}
		// Parent product ID.
		$parent_id = $variation->get_parent_id();
		
		// Subscription flag on parent product.
		$is_subscription = get_post_meta( $parent_id, self::SUBSCRIPTION, true ) === 'yes' ? true : false;
		
		?>
		<div class="payxpert-subscription-variation" data-variation-id="<?php echo esc_attr($variationID); ?>" style="
			border:1px solid #ddd;
			padding:10px;
			margin-top:10px;
			background:#f9f9f9;
			border-radius:5px;
			<?php echo $is_subscription ? '' : 'display:none;'; ?>
		">
		<h4 style="margin:0 0 10px 0; color:#21759b;"><?php _e('PayXpert Subscription Settings', 'payxpert'); ?></h4>
		
		<?php $this->render_subscription_fields($variation, $prefix . '_payxpert_'); ?>
		</div>
		
		<script type="text/javascript">
		jQuery(function($){
			var $container = $('.payxpert-subscription-variation[data-variation-id="<?php echo $variationID; ?>"]');
			function toggleMaxCycles() {
				if ($container.find('select[name="<?php echo $prefix . self::SUBSCRIPTION_DURATION_TYPE ?>"]').val() === "<?php echo SubscriptionType::NORMAL ?>") {
					$container.find('.subscription-max-cycles').show();
				} else {
					$container.find('.subscription-max-cycles').hide();
				}
			}
			toggleMaxCycles();
			$container.find('select[name="<?php echo $prefix . self::SUBSCRIPTION_DURATION_TYPE ?>"]').on('change', toggleMaxCycles);
		});
		</script>
		<?php
	}
	
	public function display_subscription_info_single_product(){
		global $product;
		
		if (!$product instanceof \WC_Product || $product->is_type('variable')) {
			return;
		}
		
		$product_id = $product->get_id();
		$is_subscription = get_post_meta($product_id, self::SUBSCRIPTION, true);
		if (empty($is_subscription)) return;
		
		$frequency = get_post_meta($product_id, self::SUBSCRIPTION_FREQUENCY, true);
		$subscription_data = [
			'frequency'     => $frequency,
			'price_html'    => wc_price(get_post_meta($product_id, self::SUBSCRIPTION_PRICE, true)) . $product->get_price_suffix(),
			'first_payment' => WC_Payxpert_Utils::get_subscription_first_payment_text(
				get_post_meta($product_id, self::SUBSCRIPTION_FIRST_PAYMENT, true), 
				$frequency,
				get_post_meta($product_id, self::SUBSCRIPTION_DURATION_TYPE, true),
				get_post_meta($product_id, self::SUBSCRIPTION_MAX_CYCLES, true),
			)
		];
			
		echo self::render_subscription_box($subscription_data);
	}
				
	public function add_available_variation_data($variation_data, $product, $variation)
	{
		$variation_id = $variation->get_id();
		$is_subscription = get_post_meta( $variation_id, self::SUBSCRIPTION, true );
		
		if ( $is_subscription ) {
			$frequency = get_post_meta( $variation_id, '_payxpert_subscription_frequency', true );
			$price = get_post_meta( $variation_id, self::SUBSCRIPTION_PRICE, true );
			
			$variation_data['payxpert_subscription'] = array(
				'frequency' => __( $frequency, 'payxpert' ),
				'price_html' => wc_price( $price ) . $variation->get_price_suffix(),
				'first_payment' => WC_Payxpert_Utils::get_subscription_first_payment_text( 
					get_post_meta( $variation_id, self::SUBSCRIPTION_FIRST_PAYMENT, true ),
					$frequency, 
					get_post_meta( $variation_id, self::SUBSCRIPTION_DURATION_TYPE, true ),
					get_post_meta( $variation_id, self::SUBSCRIPTION_MAX_CYCLES, true )
				),
			);
		} else {
			$variation_data['payxpert_subscription'] = false;
		}
		
		return $variation_data;
	}
	
	public function display_subscription_info_variable_product()
	{
		global $product;
		// Default values before variation-specific data loads.
		$subscription_data = [
			'frequency'     => '',
			'price_html'    => '',
			'first_payment' => ''
		];
		
		echo self::render_subscription_box($subscription_data, 'payxpert-subscription-info', true);
	}
	
	public function restrict_mixed_subscription_cart($passed, $product_id)
	{
		// Detect PayXpert subscription product in cart validation.
		$is_subscription = get_post_meta( $product_id, self::SUBSCRIPTION, true );
		
		// Parcours du panier
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$cart_product_id = $cart_item['product_id'];
			$cart_is_subscription = get_post_meta( $cart_product_id, self::SUBSCRIPTION, true );
			
			// Block mixing subscription and non-subscription products.
			if ( $cart_is_subscription && !$is_subscription || !$cart_is_subscription && $is_subscription) {
				wc_add_notice( __( "You cannot mix subscription products and standard products in the same cart.", 'payxpert' ), 'error' );
				return false;
			}
			
			// Block multiple distinct subscription products.
			if ($cart_is_subscription && $is_subscription && $cart_product_id != $product_id) {
				wc_add_notice(
					__("You cannot add more than one different subscription product to the cart.", 'payxpert'),
					'error'
				);
				return false;
			}
		}
		
		return $passed;
	}
	
	public function render_subscription_fields( $product_or_variation, $prefix = '' ) {
		?>
		<div class="payxpert-subscription-fields" data-prefix="<?php echo esc_attr($prefix); ?>">
		
		<?php
		// Frequency
		woocommerce_wp_select(array(
			'id'          => $prefix . 'subscription_frequency',
			'label'       => __('Frequency', 'payxpert'),
			'description' => __('Billing interval', 'payxpert'),
			'desc_tip'    => true,
			'options'     => array(
				'daily'     => __('Daily', 'payxpert'),
				'weekly'    => __('Weekly', 'payxpert'),
				'monthly'   => __('Monthly', 'payxpert'),
				'quarterly' => __('Quarterly', 'payxpert'),
				'yearly'    => __('Annually', 'payxpert'),
			),
			'value'       => $product_or_variation->get_meta('_payxpert_subscription_frequency', true),
		));
		
		// Duration type
		woocommerce_wp_select(array(
			'id'          => $prefix . 'subscription_duration_type',
			'label'       => __('Duration type', 'payxpert'),
			'description' => __('Select the duration type for this subscription', 'payxpert'),
			'desc_tip'    => true,
			'options'     => array(
				SubscriptionType::NORMAL   => __('Fixed duration', 'payxpert'),
				SubscriptionType::INFINITE => __('Unlimited', 'payxpert'),
			),
			'value'       => $product_or_variation->get_meta(self::SUBSCRIPTION_DURATION_TYPE, true) ?: SubscriptionType::INFINITE,
		));
		
		// Max cycles
		woocommerce_wp_text_input(array(
			'id'                => $prefix . 'subscription_max_cycles',
			'label'             => __('Number of occurrences', 'payxpert'),
			'description'       => __('Number of payments before the end of the subscription', 'payxpert'),
			'type'              => 'number',
			'custom_attributes' => array('min' => 1),
			'value'             => $product_or_variation->get_meta(self::SUBSCRIPTION_MAX_CYCLES, true) ?: 1,
			'wrapper_class'     => 'subscription-max-cycles',
		));
		
		// First due
		woocommerce_wp_select(array(
			'id'          => $prefix . 'subscription_first_payment',
			'label'       => __('First due', 'payxpert'),
			'description' => __('Select the date of the first due date', 'payxpert'),
			'desc_tip'    => true,
			'options'     => array(
				'immediate' => __('Immediate (upon subscription)', 'payxpert'),
			),
			'value'       => $product_or_variation->get_meta(self::SUBSCRIPTION_FIRST_PAYMENT, true) ?: 'immediate',
		));
		
		// Recurring amount
		$recurring_amount = $product_or_variation->get_meta(self::SUBSCRIPTION_PRICE, true) ?: $product_or_variation->get_price();
		woocommerce_wp_text_input(array(
			'id'          => $prefix . 'subscription_price',
			'label'       => __('Recurring amount', 'payxpert'),
			'description' => __('Recurring amount for the subscription', 'payxpert'),
			'type'        => 'text',
			'value'       => $recurring_amount,
		));
		?>
		</div>
		<?php
	}
	
	public function save_subscription_fields($post_data, $object_id, $prefix = '', $is_variable = false) {
		
		$fields = [
			'subscription',
			'subscription_frequency',
			'subscription_duration_type',
			'subscription_max_cycles',
			'subscription_first_payment',
			'subscription_price'
		];
		
		$errors = [];
		
		// --- Validate simple product only (not variations). ---
		if (!$is_variable) {
			$recurring_amount = isset($post_data[$prefix . 'subscription_price']) ? floatval($post_data[$prefix . 'subscription_price']) : 0;
			$duration_type    = isset($post_data[$prefix . 'subscription_duration_type']) ? $post_data[$prefix . 'subscription_duration_type'] : '';
			$max_cycles       = isset($post_data[$prefix . 'subscription_max_cycles']) ? intval($post_data[$prefix . 'subscription_max_cycles']) : 0;
			
			if ($recurring_amount <= 0) {
				$errors[] = __('Recurring amount must be greater than 0.', 'payxpert');
			}
			
			if ($duration_type === SubscriptionType::NORMAL && $max_cycles <= 0) {
				$errors[] = __('For a NORMAL subscription, the number of occurrences must be greater than 0.', 'payxpert');
			}
			
			if (!empty($errors)) {
				set_transient('payxpert_subscription_errors_' . get_current_user_id(), $errors, 30);
				return false;
			}
		}
		
		// --- Save meta on parent product. ---
		foreach ($fields as $field) {
			$key = '_payxpert_' . $field;
			$post_key = $prefix . $field;
			
			if (isset($post_data[$post_key])) {
				$value = $post_data[$post_key];
				
				if ($field === 'subscription') {
					$value = $value === 'on' ? 'yes' : 'no';
				}
				
				update_post_meta($object_id, $key, $value);
				
			} elseif ($field === 'subscription') {
				// Always persist checkbox state (including unchecked).
				update_post_meta($object_id, $key, 'no');
			}
		}
		
		// --- Variable product: sync variation meta from parent. ---
		if ($is_variable) {
			$product = wc_get_product($object_id);
			if ($product && $product->is_type('variable')) {
				$meta_subscription = get_post_meta($object_id, self::SUBSCRIPTION, true);
				
				foreach ($product->get_children() as $variation_id) {
					// Subscription checkbox value for this variation.
					update_post_meta($variation_id, self::SUBSCRIPTION, $meta_subscription === 'yes' ? 'yes' : 'no');
					
					// When parent is subscription, seed default meta on variations if missing.
					if ($meta_subscription === 'yes') {
						$defaults = [
							'subscription_frequency' => 'monthly',
							'subscription_duration_type' => SubscriptionType::INFINITE,
							'subscription_max_cycles' => 1,
							'subscription_first_payment' => 'immediate',
							'subscription_price' => get_post_meta($variation_id, '_price', true) ?: 0,
						];
						
						foreach ($defaults as $field => $default_value) {
							$meta_key = '_payxpert_' . $field;
							$existing = get_post_meta($variation_id, $meta_key, true);
							if ($existing === '') {
								update_post_meta($variation_id, $meta_key, $default_value);
							}
						}
					} else {
						update_post_meta($variation_id, self::SUBSCRIPTION, 'no');
					}
				}
			}
		}
		
		return true;
	}
				
	/**
	 * Renders the PayXpert subscription block on the product page.
	 *
	 * @param array  $subscription_data Subscription payload: frequency, price_html, first_payment, etc.
	 * @param string $container_id      ID unique du conteneur
	 * @param bool   $is_variable       Whether this is a variation context.
	 */
	public static function render_subscription_box(
		$subscription_data,
		$container_id = 'payxpert-subscription-info',
		$is_variable = false
	) {
		if ( empty($subscription_data) ) {
			return;
		}
		
		$args = [
			'container_id'        => $container_id,
			'is_variable'         => $is_variable,
			'frequency_text'      => $subscription_data['frequency'] ?? '',
			'price_html'          => $subscription_data['price_html'] ?? '',
			'first_payment_text'  => $subscription_data['first_payment'] ?? '',
		];
		
		ob_start();
		
		wc_get_template(
			'html-product-subscription-detail.php',
			$args,
			'',
			WC_PAYXPERT_PLUGIN_FILE_PATH . 'templates/views/product/'
		);
		
		return ob_get_clean();
	}
		
	public function payxpert_modal_cancel_assets()
	{
		?>
		<script>
		jQuery(function($){
			
			$('.cancel-subscription').on('click', function(e){
				e.preventDefault();
				$('#modal_subscription_id').val(
					$(this).data('subscription-id')
				);
				$('#cancel-subscription-modal').fadeIn();
			});
			
			$('#cancel-modal-close, .payxpert-modal-overlay').on('click', function(){
				$('#cancel-subscription-modal').fadeOut();
			});
			
		});
		</script>
		
		<style>
		.payxpert-modal {
			display:none;
			position:fixed;
			top:0;left:0;
			width:100%;height:100%;
			z-index:9998;
		}
		.payxpert-modal-overlay {
			background:rgba(0,0,0,0.5);
			position:absolute;
			width:100%;height:100%;
		}
		.payxpert-modal-content {
			position:relative;
			background:#fff;
			max-width:420px;
			margin:80px auto;
			padding:20px;
			border-radius:6px;
			z-index:9999;
		}
		</style>
		<?php
	}
		
	public static function cancel_subscription( $subscription_id, $user_id, $reason_id, $is_admin=false ) {
		$findOneBy = ['subscription_id' => $subscription_id];
		if (!$is_admin) {
			$findOneBy['customer_id'] = $user_id;
		}
		
		$subscription = Payxpert_Subscription::findOneBy($findOneBy);
		
		if ( ! $subscription ) {
			return [
				'success' => false,
				'message' => __('Subscription not found.', 'payxpert'),
			];
		}
		
		if ( $subscription['state'] !== 'active' ) {
			return [
				'success' => false,
				'message' => __('This subscription cannot be cancelled.', 'payxpert'),
			];
		}
		
		if ( ! isset(Payxpert_Subscription::CUSTOMER_CANCEL_REASONS[$reason_id]) ) {
			return [
				'success' => false,
				'message' => __('Reason unknown.', 'payxpert'),
			];
		}
		
		$configuration = WC_Payxpert_Utils::get_configuration();
		$result = WC_Payxpert_Webservice::cancelSubscription($configuration, $subscription_id, $reason_id);
		
		if ( isset($result['error']) ) {
			return [
				'success' => false,
				'message' => $result['error'],
			];
		}
		
		// MAJ locale
		$subscriptionInfo = WC_Payxpert_Webservice::get_status_subscription($configuration, $subscription_id);
		if ( isset($subscriptionInfo['error']) ) {
			WC_Payxpert_Logger::critical('Error retrieving subscription info for subscriptionID : ' . (int) $subscription_id);
			return [
				'success' => true,
				'message' => __('Subscription seems to have been cancelled but info not updated.', 'payxpert'),
			];
		}
		
		$subscriptionInfo = WC_Payxpert_Utils::to_snake_case_keys($subscriptionInfo['subscription']);
		$payxpertSubscription = Payxpert_Subscription::get_instance_from_array($subscription);
		$payxpertSubscription->set($subscriptionInfo);
		$payxpertSubscription->save();
		
		return [
			'success' => true,
			'message' => __('Your subscription has been cancelled.', 'payxpert'),
		];
	}
		
		
}

function payxpert() {
	return WC_Payxpert::instance();
}

WC_Payxpert::instance();
