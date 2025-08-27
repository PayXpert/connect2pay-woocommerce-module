<?php

namespace Payxpert;

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use DateTime;
use PayXpert\Connect2Pay\containers\constant\PaymentMethod;
use PayXpert\Connect2Pay\containers\constant\PaymentMode;
use Payxpert\Models\Payxpert_Payment_Transaction;
use Payxpert\Utils\WC_Payxpert_Cron;
use Payxpert\Utils\WC_Payxpert_Logger;
use Payxpert\Utils\WC_Payxpert_Utils;
use Payxpert\Utils\WC_Payxpert_Webservice;
use WC_Customer;

defined( 'ABSPATH' ) || exit();

class WC_Payxpert {
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

	public static $_instance;

	public static function instance() {
		if ( self::$_instance == null ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

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
			}
		});

		// IFRAME ajax call
        add_action( 'wp_ajax_payxpert_handle_payment_result', array($this, 'handle_payxpert_payment_result') );
        add_action( 'wp_ajax_nopriv_payxpert_handle_payment_result', array($this, 'handle_payxpert_payment_result') );

		add_action('wp_ajax_payxpert_refresh_tokens', array($this, 'payxpert_refresh_tokens') );
		add_action('wp_ajax_nopriv_payxpert_refresh_tokens', array($this, 'payxpert_refresh_tokens') );

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


		add_action( 'payxpert_sync_installment_transactions_cron', [new WC_Payxpert_Cron(), 'sync_installment_transactions_cron'] );
	
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
        ];

		// Override toggle enable/disable in wooc payment settings
        foreach ( $gateway_ids as $gateway_id ) {
            add_filter( "pre_update_option_woocommerce_{$gateway_id}_settings", function( $new_value ) use ( $gateway_id ) {
                update_option($gateway_id . '_enabled', $new_value['enabled']);
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
		} );

		// Menu in My-Account section
		add_action( 'woocommerce_account_payxpert-subscriptions_endpoint', function() {
			wc_get_template('views/myaccount/subscriptions.php', [], '', WC_PAYXPERT_PLUGIN_FILE_PATH . 'templates/');
		});
		add_filter( 'query_vars', function ($vars) {
			$vars[] = 'payxpert-subscriptions';
			return $vars;
		} );
		add_filter( 'woocommerce_account_menu_items', function ($items) {
			$new_items = [];

			foreach ($items as $key => $label) {
				$new_items[$key] = $label;

				if ($key === 'orders') {
					$new_items['payxpert-subscriptions'] = __('Subscriptions', 'payxpert');
				}
			}

			return $new_items;
		} );
	}

	public function action_links($links)
	{
		$url = admin_url('admin.php?page=payxpert-settings');
		$settings_link = '<a href="' . esc_url($url) . '">' . __('Settings', 'payxpert') . '</a>';
		array_unshift($links, $settings_link);
		return $links;
	}

	public function payxpert_available_payment_gateways($gateways) {
		$configuration = WC_Payxpert_Utils::get_configuration();

		// Disable gateways if payxpert account not linked
		if ($configuration['payxpert_conn_status'] == false) {
			foreach ($gateways as $id => $gateway) {
				if (strpos($id, 'payxpert') !== false) {
					unset($gateways[$id]);
				}
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
	}

	public function add_payment_gateway($gateways)
	{
		$gateways[] = 'WC_Payment_Gateway_Payxpert_CC';
		$gateways[] = 'WC_Payment_Gateway_Payxpert_Installment_X2';
		$gateways[] = 'WC_Payment_Gateway_Payxpert_Installment_X3';
		$gateways[] = 'WC_Payment_Gateway_Payxpert_Installment_X4';
		$gateways[] = 'WC_Payment_Gateway_Payxpert_Paybylink';
		
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

	public function handle_payxpert_payment_result() {
        check_ajax_referer('payxpert_payment_nonce', 'security');

        $transactionID = sanitize_text_field($_POST['transactionID'] ?? '');
        $paymentID     = sanitize_text_field($_POST['paymentID'] ?? '');

        if (!$transactionID || !$paymentID) {
            wp_send_json_error(['message' => 'Missing parameters']);
        }

		$transaction = null;
		$maxAttempts = 3;

		for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
			$transaction = Payxpert_Payment_Transaction::findByTransactionIdAndPaymentId((string) $transactionID, (string) $paymentID);

			if ($transaction) {
				break;
			}

			if ($attempt < $maxAttempts) {
				sleep(1);
			}
		}

        if (!$transaction) {
            wp_send_json_error([
				'message' => 'Transaction not found'
			]);
        }

        $order = wc_get_order($transaction['order_id']);

        if (!$order || $order->get_user_id() !== get_current_user_id()) {
            wp_send_json_error(['message' => 'Unauthorized order']);
        }

        $current_status = $order->get_status();
        if (
			in_array(
				$current_status, 
				[
					'processing', 
					'completed', 
					self::ORDER_STATUS_INSTALLMENT_PENDING,
					self::ORDER_STATUS_CAPTURE_PENDING,
				]
			)
		) {
			wp_send_json_success([
				'urlRedirect' => $order->get_checkout_order_received_url(),
			]);
        } 

		wp_send_json_success([
			'urlRedirect' => $order->get_view_order_url()
		]);
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
		
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function (PaymentMethodRegistry $payment_method_registry) {
				$payment_method_registry->register(new \WC_Payment_Gateway_Payxpert_CC_Blocks_Support());
				$payment_method_registry->register(new \WC_Payment_Gateway_Payxpert_Installment_X2_Blocks_Support());
				$payment_method_registry->register(new \WC_Payment_Gateway_Payxpert_Installment_X3_Blocks_Support());
				$payment_method_registry->register(new \WC_Payment_Gateway_Payxpert_Installment_X4_Blocks_Support());
			}
		);

	}

	public function init()
	{
		// Add endpoint subscriptions in my-account
		add_rewrite_endpoint('payxpert-subscriptions', EP_ROOT | EP_PAGES);

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
		$order = wc_get_order($post->ID);
        $transactions = Payxpert_Payment_Transaction::findAllByOrderId($order->get_id());

        if (empty($transactions) || !is_array($transactions)) {
            echo '<p style="text-align:center;">' . esc_html__('No transaction found.', 'payxpert') . '</p>';
            return;
        }

		//! Used in template
		$orderTransactionsFormatted = WC_Payxpert_Utils::get_order_transactions_formatted($transactions);

       	$template_path = WC_PAYXPERT_PLUGIN_FILE_PATH . 'templates/views/html-order-transactions.php';

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
			&& $theorder->has_status('pending')
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

			if (!$order->has_status('pending')) {
				throw new \Exception(__('The order status must be `Waiting for paybylink payment (PayXpert)`', 'payxpert'));
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
			$paybylink_email->trigger([
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

			$order->add_order_note(__('PayByLink email sent to the customer (PayXpert)', 'payxpert'));

			if (is_admin()) {
				set_transient('payxpert_admin_success', __('The PayByLink email has been sent successfully.', 'payxpert'), 60);
			}
		} catch (\Exception $e) {
			if (is_admin()) {
				WC_Payxpert_Logger::error($e->getMessage());
				set_transient('payxpert_admin_error', $e->getMessage(), 60);
			}
		}
	}

	public function payxpert_refresh_tokens() {
		check_ajax_referer('payxpert_payment', 'nonce');

		if ( ! isset($_POST['payment_method']) ) {
			wp_send_json_error(['message' => 'Missing payment method.'], 400);
		}
		$payment_method = sanitize_text_field($_POST['payment_method']);

		if ( ! isset($_POST['order_id']) ) {
			wp_send_json_error(['message' => 'Missing order ID.'], 400);
		}

		$order_id = sanitize_text_field($_POST['order_id']);
		$order = null;
		if ($order_id > 0) {
			$order = wc_get_order($order_id);
		}

		// Exemple : 'payxpert_installment_x2' => donc on cherche une instance du gateway
		$gateways = WC()->payment_gateways()->payment_gateways();

		if ( ! isset($gateways[ $payment_method ]) ) {
			wp_send_json_error(['message' => 'Unknown payment method.'], 400);
		}

		$gateway = $gateways[ $payment_method ];

		if ( ! method_exists($gateway, 'get_seamless_data') ) {
			wp_send_json_error(['message' => 'Payment method does not support seamless refresh.'], 400);
		}

		// On appelle la méthode comme dans les blocks pour obtenir toutes les données
		$data = $gateway->get_seamless_data($order);

		wp_send_json_success($data);
	}
}

function payxpert() {
	return WC_Payxpert::instance();
}

WC_Payxpert::instance();
