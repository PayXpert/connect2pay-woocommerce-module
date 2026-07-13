<?php
/**
 * Wc Payxpert.
 *
 * @package Payxpert
 */

namespace Payxpert;

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use DateTime;
use PayXpert\Connect2Pay\containers\constant\PaymentMethod;
use PayXpert\Connect2Pay\containers\constant\PaymentMode;
use PayXpert\Connect2Pay\containers\constant\SubscriptionType;
use Payxpert\Models\Payxpert_Payment_Transaction;
use Payxpert\Models\Payxpert_Subscription;
use Payxpert\Utils\WC_Payxpert_Admin_Notices;
use Payxpert\Utils\WC_Payxpert_Cron;
use Payxpert\Utils\WC_Payxpert_Install;
use Payxpert\Utils\WC_Payxpert_Logger;
use Payxpert\Utils\WC_Payxpert_Utils;
use Payxpert\Utils\WC_Payxpert_Webservice;
use WC_Customer;

defined( 'ABSPATH' ) || exit();

/**
 * WC Payxpert.
 */
class WC_Payxpert {


	const SUBSCRIPTION_FREQUENCY     = '_payxpert_subscription_frequency';
	const SUBSCRIPTION_DURATION_TYPE = '_payxpert_subscription_duration_type';
	const SUBSCRIPTION_MAX_CYCLES    = '_payxpert_subscription_max_cycles';
	const SUBSCRIPTION_FIRST_PAYMENT = '_payxpert_subscription_first_payment';
	const SUBSCRIPTION_PRICE         = '_payxpert_subscription_price';
	const SUBSCRIPTION               = '_payxpert_subscription';

	const REDIRECT_MODE_REDIRECT = 0;
	const REDIRECT_MODE_SEAMLESS = 1;

	const REDIRECT_MODES = array(
		self::REDIRECT_MODE_REDIRECT,
		self::REDIRECT_MODE_SEAMLESS,
	);

	const CAPTURE_MODE_AUTOMATIC = 0;
	const CAPTURE_MODE_MANUAL    = 1;

	const CAPTURE_MODES = array(
		self::CAPTURE_MODE_AUTOMATIC,
		self::CAPTURE_MODE_MANUAL,
	);

	// ! Do not exceed 17chars
	const ORDER_STATUS_INSTALLMENT_PENDING = 'pxp-istlm-pending';
	const ORDER_STATUS_CAPTURE_PENDING     = 'pxp-captr-pending';

	/** Nonce issued in seamless iframe before the WC order exists (checkout / blocks). */
	const PAYMENT_RESULT_SEAMLESS_NONCE_ACTION = 'payxpert_seamless_payment_poll';

	/**
	 * Legacy nonce action kept for backward compatibility.
	 *
	 * @deprecated Legacy nonce action kept for backward compatibility.
	 */
	const PAYMENT_RESULT_LEGACY_NONCE_ACTION = 'payxpert_payment_nonce';

	const PAYMENT_RESULT_POLL_ATTEMPTS    = 40;
	const PAYMENT_RESULT_POLL_INTERVAL_US = 250000;

	/**
	 * Nonce action for payment-result polling.
	 * - order_id > 0: per-order (e.g. pay-for-order page).
	 * - order_id <= 0: checkout seamless iframe mounted before order creation.
	 *
	 * @param int $order_id Order ID, or <= 0 for the pre-order checkout iframe.
	 * @return string
	 */
	public static function payment_result_nonce_action( int $order_id ): string {
		if ( $order_id <= 0 ) {
			return self::PAYMENT_RESULT_SEAMLESS_NONCE_ACTION;
		}

		return 'payxpert_pay_' . $order_id;
	}

	/**
	 * Instance.
	 *
	 * @var mixed
	 */
	public static $instance;

	/**
	 * Instance.
	 *
	 * @return mixed
	 */
	public static function instance() {
		if ( null == self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Clone.
	 *
	 * @return mixed
	 */
	private function __clone() {}

	/**
	 * Wakeup.
	 */
	public function __wakeup(): void {}

	/**
	 * Construct.
	 */
	public function __construct() {
		add_action(
			'before_woocommerce_init',
			function () {
				if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
					\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WC_PAYXPERT_PLUGIN_FILE_PATH . 'payxpert.php' );
					\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WC_PAYXPERT_PLUGIN_FILE_PATH . 'payxpert.php' );
				}
			}
		);

		add_action( 'init', array( $this, 'init' ) );
		add_action( 'woocommerce_init', array( $this, 'woocommerce_dependencies' ) );
		add_action( 'woocommerce_blocks_loaded', array( $this, 'payxpert_blocks_loaded' ) );

		// Remove canonical redirection for FO pages with pagination.
		add_filter(
			'redirect_canonical',
			function ( $redirect_url ) {
				// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only inspection of the current request URL/pagination to disable canonical redirect on paginated account pages; no state change.
				$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
				$has_paged   = isset( $_GET['paged'] );
				// phpcs:enable WordPress.Security.NonceVerification.Recommended
				if (
					! is_admin()
					&& is_account_page()
					&& strpos( $request_uri, 'payxpert-subscription-details' ) !== false
					&& $has_paged
				) {
					return false;
				}
				return $redirect_url;
			}
		);

		add_filter( 'plugin_action_links_' . WC_PAYXPERT_PLUGIN_NAME, array( $this, 'action_links' ) );

		add_action(
			'plugins_loaded',
			function () {
				// Load translations.
				load_plugin_textdomain( 'payxpert', false, dirname( WC_PAYXPERT_PLUGIN_NAME ) . '/languages/' );

				if ( is_admin() ) {
					require_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/admin/class-wc-payxpert-admin-assets.php';
					require_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/admin/class-wc-payxpert-settings.php';
					\WC_Payxpert_Settings::instance();

					require_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/admin/class-wc-payxpert-transaction-list.php';
					\WC_Payxpert_Transaction_List::instance();

					require_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/admin/class-wc-payxpert-subscription-list.php';
					\WC_Payxpert_Subscription_List::instance();

					require_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/admin/class-wc-payxpert-subscription-detail.php';
					\WC_Payxpert_Subscription_Detail::instance();
				}

				// Add subscription checkbox in simple/variable product Type.
				add_filter(
					'product_type_options',
					function ( $options ) {
						$options['payxpert_subscription'] = array(
							'id'            => self::SUBSCRIPTION,
							'wrapper_class' => 'show_if_simple show_if_variable',
							'label'         => __( 'subscription PayXpert', 'payxpert' ),
							'description'   => __( 'Enable to set this product as a subscription', 'payxpert' ),
							'default'       => 'no',
						);

						return $options;
					}
				);

				// ---- CHECK DB VERSION ----
				global $wpdb;
				$installed_version = get_option( 'wc_payxpert_db_version', '1.0.0' ); // version en DB.
				$current_version   = WC_PAYXPERT_DB_VERSION; // version constante plugin.

				if ( version_compare( $installed_version, $current_version, '<' ) ) {
					WC_Payxpert_Install::install();

					if ( method_exists( '\Payxpert\Utils\WC_Payxpert_Install', 'upgrade_db' ) ) {
						WC_Payxpert_Install::upgrade_db();
					}

					update_option( 'wc_payxpert_db_version', $current_version );
				}

				$installed_plugin_version = get_option( 'wc_payxpert_plugin_version', '0.0.0' );
				if ( version_compare( $installed_plugin_version, '2.3.1', '<' ) ) {
					WC_Payxpert_Install::cleanup_legacy_logs();
				}
				update_option( 'wc_payxpert_plugin_version', WC_PAYXPERT_VERSION );

				// Add subscription checkbox in simple/variable product Type.
				add_filter(
					'product_type_options',
					function ( $options ) {
						$options['payxpert_subscription'] = array(
							'id'            => self::SUBSCRIPTION,
							'wrapper_class' => 'show_if_simple show_if_variable',
							'label'         => __( 'subscription PayXpert', 'payxpert' ),
							'description'   => __( 'Enable to set this product as a subscription', 'payxpert' ),
							'default'       => 'no',
						);

						return $options;
					}
				);
			}
		);

		// IFRAME ajax call.
		add_action( 'wp_ajax_payxpert_handle_payment_result', array( $this, 'handle_payxpert_payment_result' ) );
		add_action( 'wp_ajax_nopriv_payxpert_handle_payment_result', array( $this, 'handle_payxpert_payment_result' ) );
		add_action( 'template_redirect', array( $this, 'maybe_add_failed_payment_notice' ) );

		// Transaction section in order page (BO).
		add_action( 'add_meta_boxes', array( $this, 'payxpert_add_transaction_metabox' ) );

		// Payment gateways.
		add_filter( 'woocommerce_payment_gateways', array( $this, 'add_payment_gateway' ) );
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'payxpert_available_payment_gateways' ) );

		// Order status management.
		add_filter( 'wc_order_statuses', array( $this, 'payxpert_order_statuses' ) );
		add_filter( 'woocommerce_register_shop_order_post_statuses', array( $this, 'register_payxpert_shop_order_statuses' ) );
		add_filter(
			'woocommerce_order_is_paid_statuses',
			function ( $statuses ) {
				$statuses[] = self::ORDER_STATUS_INSTALLMENT_PENDING;
				return $statuses;
			}
		);

		// Front GLOBAL assets.
		add_action(
			'wp_enqueue_scripts',
			function () {
				if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
					return;
				}

				wp_enqueue_style(
					'payxpert-checkout-loader',
					plugin_dir_url( __FILE__ ) . '../assets/css/iframe-redirect-loader.css',
					array(),
					'1.0.0'
				);
			}
		);

		// Admin GLOBAL CSS.
		add_action(
			'admin_enqueue_scripts',
			function () {
				wp_enqueue_style(
					'payxpert-admin-global',
					WC_PAYXPERT_ASSETS . 'css/admin-global.css'
				);
			}
		);

		// Support mail.
		add_filter(
			'woocommerce_email_classes',
			function ( $emails ) {
				require_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/class-wc-email-payxpert-support.php';
				require_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/class-wc-email-payxpert-paybylink.php';

				$emails['WC_Email_Payxpert_Support']   = new \WC_Email_Payxpert_Support();
				$emails['WC_Email_Payxpert_Paybylink'] = new \WC_Email_Payxpert_Paybylink();
				return $emails;
			}
		);

		add_action( 'payxpert_sync_subscriptions_cron', array( new WC_Payxpert_Cron(), 'sync_subscriptions_cron' ) );
		add_action( 'payxpert_cleanup_payment_tokens_cron', array( new WC_Payxpert_Cron(), 'cleanup_payment_tokens_cron' ) );

		// Admin Order - Actions.
		add_filter( 'woocommerce_order_actions', array( $this, 'payxpert_order_actions' ) );
		add_action( 'woocommerce_order_action_payxpert_send_paybylink', array( $this, 'payxpert_paybylink' ) );
		add_action( 'woocommerce_order_action_payxpert_send_paybylink_x2', array( $this, 'payxpert_paybylink_x2' ) );
		add_action( 'woocommerce_order_action_payxpert_send_paybylink_x3', array( $this, 'payxpert_paybylink_x3' ) );
		add_action( 'woocommerce_order_action_payxpert_send_paybylink_x4', array( $this, 'payxpert_paybylink_x4' ) );

		// Admin Order - Custom button.
		add_action( 'admin_post_payxpert_capture_transaction', array( $this, 'handle_capture_transaction' ) );

		// ! Real gateway IDs. Check gateways/class::ID
		$gateway_ids = array(
			'payxpert_cc',
			'payxpert_installment_x2',
			'payxpert_installment_x3',
			'payxpert_installment_x4',
			'payxpert_paybylink',
			'payxpert_subscription',
		);

		// Override toggle enable/disable in wooc payment settings.
		foreach ( $gateway_ids as $gateway_id ) {
			$enabled     = get_option( $gateway_id . '_enabled', 'no' ); // ta valeur custom.
			$wc_settings = get_option( "woocommerce_{$gateway_id}_settings", array() );

			// Initialize when not configured yet.
			if ( ! isset( $wc_settings['enabled'] ) ) {
				$wc_settings['enabled'] = $enabled; // oui/non.
				update_option( "woocommerce_{$gateway_id}_settings", $wc_settings );
			}

			add_filter(
				"pre_update_option_woocommerce_{$gateway_id}_settings",
				function ( $new_value, $old_value ) use ( $gateway_id ) {
					update_option( $gateway_id . '_enabled', $new_value['enabled'] ?? 'no' );
					return $new_value;
				},
				10,
				2
			);
		}

		add_action(
			'admin_notices',
			function () {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check of the current admin page to decide whether to show a flash notice.
				$is_payxpert_settings_page = isset( $_GET['page'] ) && sanitize_key( wp_unslash( $_GET['page'] ) ) === 'payxpert-settings';

				$error = WC_Payxpert_Admin_Notices::get_and_delete( 'admin_error' );
				if ( ! $is_payxpert_settings_page && $error ) {
					echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $error ) . '</p></div>';
				}

				$success = WC_Payxpert_Admin_Notices::get_and_delete( 'admin_success' );
				if ( $success ) {
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $success ) . '</p></div>';
				}

				$info = WC_Payxpert_Admin_Notices::get_and_delete( 'admin_info' );
				if ( $info ) {
					echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $info ) . '</p></div>';
				}

				$errors = WC_Payxpert_Admin_Notices::get_and_delete( 'subscription_errors' );
				if ( $errors ) {
					echo '<div class="notice notice-error"><p>';
					echo implode( '<br>', array_map( 'esc_html', (array) $errors ) );
					echo '</p></div>';
				}
			}
		);

		// Menu in My-Account section.
		add_action(
			'woocommerce_account_payxpert-subscription-list_endpoint',
			function () {
				wc_get_template( 'views/myaccount/html-subscription-list.php', array(), '', WC_PAYXPERT_PLUGIN_FILE_PATH . 'templates/' );
			}
		);
		add_action(
			'woocommerce_account_payxpert-subscription-details_endpoint',
			function () {
				wc_get_template( 'views/myaccount/html-subscription-details.php', array(), '', WC_PAYXPERT_PLUGIN_FILE_PATH . 'templates/' );
			}
		);

		add_filter(
			'query_vars',
			function ( $vars ) {
				$vars[] = 'payxpert-subscription-list';
				$vars[] = 'payxpert-subscription-details';
				return $vars;
			}
		);

		add_filter(
			'woocommerce_account_menu_items',
			function ( $items ) {
				$new_items = array();

				$user_has_subscriptions = Payxpert_Subscription::count_user_subscriptions( get_current_user_id() ) > 0;

				foreach ( $items as $key => $label ) {
					$new_items[ $key ] = $label;

					if ( 'orders' === $key ) {
						if ( $user_has_subscriptions ) {
							$new_items['payxpert-subscription-list'] = __( 'Subscriptions', 'payxpert' );
						}
					}
				}

				return $new_items;
			}
		);

		add_action( 'wp_footer', array( $this, 'payxpert_modal_cancel_assets' ) );
		add_action( 'admin_footer', array( $this, 'admin_footer' ) );

		// When product is saved.
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'admin_process_product_object' ) );
		// When product variations are saved.
		add_action( 'woocommerce_save_product_variation', array( $this, 'payxpert_save_variation_subscription_fields' ), 10, 2 );

		// Add Subscription Detail Tab in product_data.
		add_filter(
			'woocommerce_product_data_tabs',
			function ( $options ) {
				$options['payxpert_subscription'] = array(
					'label'    => __( 'Subscription plan details', 'payxpert' ),
					'target'   => 'payxpert_subscription_product_data',
					'class'    => array( 'hide_if_grouped', 'hide_if_external', 'hide_if_variable' ),
					'priority' => 90,
				);

				return $options;
			}
		);

		// Add Panel Data for subscription detail.
		add_action( 'woocommerce_product_data_panels', array( $this, 'display_product_subscription_detail_panel' ) );

		// Add Panel Data for product variation.
		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'display_variation_subscription_panel' ), 10, 3 );

		// Display subscription info for single product.
		add_action( 'woocommerce_single_product_summary', array( $this, 'display_subscription_info_single_product' ), 25 );
		add_filter( 'woocommerce_available_variation', array( $this, 'add_available_variation_data' ), 25, 3 );
		add_action( 'woocommerce_single_variation', array( $this, 'display_subscription_info_variable_product' ) );

		// Add Validator to avoid cart with subscription item and normal item.
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'restrict_mixed_subscription_cart' ), 10, 2 );
	}

	/**
	 * Action links.
	 *
	 * @param mixed $links Links.
	 * @return mixed
	 */
	public function action_links( $links ) {
		$url           = admin_url( 'admin.php?page=payxpert-settings' );
		$settings_link = '<a href="' . esc_url( $url ) . '">' . __( 'Settings', 'payxpert' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Payxpert available payment gateways.
	 *
	 * @param mixed $gateways Gateways.
	 * @return mixed
	 */
	public function payxpert_available_payment_gateways( $gateways ) {
		if ( is_null( WC()->cart ) ) {
			return array();
		}

		$configuration = WC_Payxpert_Utils::get_configuration();

		// Disable gateways if payxpert account not linked.
		if ( false == $configuration['payxpert_conn_status'] ) {
			foreach ( $gateways as $id => $gateway ) {
				if ( strpos( $id, 'payxpert' ) !== false ) {
					unset( $gateways[ $id ] );
				}
			}
		}

		// Whether the cart has a product flagged as PayXpert subscription.
		$subscription_in_cart = WC_Payxpert_Utils::has_subscription_in_cart( WC()->cart->get_cart() );

		// Subscription product in cart: restrict gateways.
		if ( $subscription_in_cart ) {
			// Allowed payment methods for subscription products.
			$allowed_gateways = array( 'payxpert_subscription' );

			foreach ( $gateways as $gateway_id => $gateway ) {
				if ( ! in_array( $gateway_id, $allowed_gateways ) ) {
					unset( $gateways[ $gateway_id ] );
				}
			}
		} elseif ( isset( $gateways['payxpert_subscription'] ) ) {
			// No subscription in cart: remove subscription gateway only.
			unset( $gateways['payxpert_subscription'] );
		}

		return $gateways;
	}

	/**
	 * Woocommerce dependencies.
	 */
	public function woocommerce_dependencies(): void {
		// Load abstracts.
		include_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/abstract/class-wc-payment-gateway-payxpert.php';
		include_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/abstract/class-wc-payment-gateway-payxpert-installment.php';

		// Load gateways.
		include_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/gateways/class-wc-payment-gateway-payxpert-cc.php';
		include_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/gateways/class-wc-payment-gateway-payxpert-installment-x2.php';
		include_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/gateways/class-wc-payment-gateway-payxpert-installment-x3.php';
		include_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/gateways/class-wc-payment-gateway-payxpert-installment-x4.php';
		include_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/gateways/class-wc-payment-gateway-payxpert-paybylink.php';
		include_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/gateways/class-wc-payment-gateway-payxpert-subscription.php';
	}

	/**
	 * Add payment gateway.
	 *
	 * @param mixed $gateways Gateways.
	 * @return mixed
	 */
	public function add_payment_gateway( $gateways ) {
		$gateways[] = 'WC_Payment_Gateway_Payxpert_CC';
		$gateways[] = 'WC_Payment_Gateway_Payxpert_Installment_X2';
		$gateways[] = 'WC_Payment_Gateway_Payxpert_Installment_X3';
		$gateways[] = 'WC_Payment_Gateway_Payxpert_Installment_X4';
		$gateways[] = 'WC_Payment_Gateway_Payxpert_Paybylink';
		$gateways[] = 'WC_Payment_Gateway_payxpert_subscription';

		return $gateways;
	}

	/**
	 * Payxpert add transaction metabox.
	 */
	public function payxpert_add_transaction_metabox(): void {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		if ( strpos( $screen->id, 'shop_order' ) === false && 'woocommerce_page_wc-orders' !== $screen->id ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check of the current order screen to decide whether to add a metabox.
		$order_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : ( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 );

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
			array( $this, 'output_transaction_metabox' ),
			$screen,
			'normal',
			'core'
		);
	}

	/**
	 * Polls order status after seamless payment. Order state is only updated by the IPN.
	 */
	public function handle_payxpert_payment_result(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Authenticated by a custom HMAC token ($security) verified through verify_payment_result_nonce() immediately below.
		$order_id       = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$transaction_id = isset( $_POST['transactionID'] ) ? sanitize_text_field( wp_unslash( $_POST['transactionID'] ) ) : '';
		$payment_id     = isset( $_POST['paymentID'] ) ? sanitize_text_field( wp_unslash( $_POST['paymentID'] ) ) : '';
		$security       = isset( $_POST['security'] ) ? sanitize_text_field( wp_unslash( $_POST['security'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $order_id <= 0 || '' === $transaction_id || '' === $payment_id || '' === $security ) {
			wp_send_json_error( array( 'message' => __( 'Missing parameters.', 'payxpert' ) ) );
		}

		if ( ! $this->verify_payment_result_nonce( $order_id, $security ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'payxpert' ) ), 403 );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'payxpert' ) ), 404 );
		}

		if ( ! $this->assert_ajax_order_access( $order ) ) {
			return;
		}

		if ( strpos( (string) $order->get_payment_method(), 'payxpert' ) !== 0 ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized order.', 'payxpert' ) ), 403 );
		}

		$transaction = $this->poll_payment_transaction( $transaction_id, $payment_id );

		if ( ! $transaction ) {
			wp_send_json_success( array( 'pending' => true ) );
		}

		if ( (int) ( $transaction['order_id'] ?? 0 ) !== $order_id ) {
			wp_send_json_error( array( 'message' => __( 'Transaction does not match this order.', 'payxpert' ) ), 403 );
		}

		$current_status = $order->get_status();
		$paid_statuses  = array(
			'processing',
			'completed',
			self::ORDER_STATUS_INSTALLMENT_PENDING,
			self::ORDER_STATUS_CAPTURE_PENDING,
		);

		if ( in_array( $current_status, $paid_statuses, true ) ) {
			wp_send_json_success(
				array(
					'urlRedirect' => $order->get_checkout_order_received_url(),
				)
			);
		}

		if ( is_user_logged_in() && (int) get_current_user_id() === (int) $order->get_user_id() ) {
			$retry_url = $order->get_view_order_url();
		} else {
			$retry_url = add_query_arg(
				array( 'payxpert_payment' => 'failed' ),
				$order->get_checkout_payment_url()
			);
		}

		wp_send_json_success( array( 'urlRedirect' => $retry_url ) );
	}

	/**
	 * Poll for a payment transaction until it is recorded or attempts run out.
	 *
	 * @param string $transaction_id Transaction identifier.
	 * @param string $payment_id     Payment identifier.
	 * @return array<string, mixed>|null
	 */
	private function poll_payment_transaction( string $transaction_id, string $payment_id ): ?array {
		for ( $attempt = 1; $attempt <= self::PAYMENT_RESULT_POLL_ATTEMPTS; $attempt++ ) {
			$transaction = Payxpert_Payment_Transaction::find_by_transaction_id_and_payment_id( $transaction_id, $payment_id );
			if ( $transaction ) {
				return $transaction;
			}
			if ( $attempt < self::PAYMENT_RESULT_POLL_ATTEMPTS ) {
				usleep( self::PAYMENT_RESULT_POLL_INTERVAL_US );
			}
		}
		return null;
	}

	/**
	 * Verify payment result nonce.
	 *
	 * @param int    $order_id Order id.
	 * @param string $security Security.
	 * @return bool
	 */
	private function verify_payment_result_nonce( int $order_id, string $security ): bool {
		if ( $order_id <= 0 ) {
			return false;
		}

		// Pay-for-order: nonce and order_id were both issued for this order.
		if ( false !== wp_verify_nonce( $security, self::payment_result_nonce_action( $order_id ) ) ) {
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
	 *
	 * @param int    $order_id Order ID being polled.
	 * @param string $security Nonce value submitted by the client.
	 * @return bool
	 */
	private function verify_checkout_seamless_poll_nonce( int $order_id, string $security ): bool {
		$checkout_actions = array(
			self::PAYMENT_RESULT_SEAMLESS_NONCE_ACTION,
			'payxpert_pay_0', // Previous releases used payment_result_nonce_action( 0 ).
			self::PAYMENT_RESULT_LEGACY_NONCE_ACTION,
		);

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
	 * @param \WC_Order $order            Order to authorize access to.
	 * @param string    $posted_order_key Order key submitted by a guest, if any.
	 * @param bool      $send_json_error  When true, sends a JSON error and exits on failure.
	 * @return bool When $send_json_error is true, sends JSON error and exits on failure.
	 */
	public function assert_ajax_order_access( \WC_Order $order, string $posted_order_key = '', bool $send_json_error = true ): bool {
		$uid       = get_current_user_id();
		$order_uid = (int) $order->get_customer_id();

		if ( $order_uid > 0 ) {
			if ( $uid !== $order_uid ) {
				if ( $send_json_error ) {
					wp_send_json_error( array( 'message' => __( 'Unauthorized order.', 'payxpert' ) ), 403 );
				}
				return false;
			}
			return true;
		}

		$wc_session    = WC()->session;
		$session_order = $wc_session ? absint( $wc_session->get( 'order_awaiting_payment' ) ) : 0;

		$guest_ok = ( $session_order === (int) $order->get_id() )
			|| ( '' !== $posted_order_key && hash_equals( (string) $order->get_order_key(), $posted_order_key ) );

		if ( ! $guest_ok ) {
			if ( $send_json_error ) {
				wp_send_json_error( array( 'message' => __( 'Unauthorized order.', 'payxpert' ) ), 403 );
			}
			return false;
		}

		return true;
	}

	/**
	 * Maybe add failed payment notice.
	 */
	public function maybe_add_failed_payment_notice(): void {
		if ( ! function_exists( 'is_checkout_pay_page' ) || ! is_checkout_pay_page() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag used to display a checkout failure notice; no state change.
		$payment_state = isset( $_GET['payxpert_payment'] ) ? sanitize_text_field( wp_unslash( $_GET['payxpert_payment'] ) ) : '';
		if ( 'failed' !== $payment_state ) {
			return;
		}

		wc_add_notice( __( 'Payment failed. Please try again.', 'payxpert' ), 'error' );
	}

	/**
	 * Payxpert blocks loaded.
	 */
	public function payxpert_blocks_loaded(): void {
		if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}

		require_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/abstract/class-wc-payment-gateway-payxpert-installment-blocks-support.php';

		require_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/gateways/blocks/class-wc-payment-gateway-payxpert-cc-blocks-support.php';
		require_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/gateways/blocks/class-wc-payment-gateway-payxpert-installment-x2-blocks-support.php';
		require_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/gateways/blocks/class-wc-payment-gateway-payxpert-installment-x3-blocks-support.php';
		require_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/gateways/blocks/class-wc-payment-gateway-payxpert-installment-x4-blocks-support.php';
		require_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/gateways/blocks/class-wc-payment-gateway-payxpert-subscription-blocks-support.php';

		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function ( PaymentMethodRegistry $payment_method_registry ) {
				$payment_method_registry->register( new \WC_Payment_Gateway_Payxpert_CC_Blocks_Support() );
				$payment_method_registry->register( new \WC_Payment_Gateway_Payxpert_Installment_X2_Blocks_Support() );
				$payment_method_registry->register( new \WC_Payment_Gateway_Payxpert_Installment_X3_Blocks_Support() );
				$payment_method_registry->register( new \WC_Payment_Gateway_Payxpert_Installment_X4_Blocks_Support() );
				$payment_method_registry->register( new \WC_Payment_Gateway_Payxpert_Subscription_Blocks_Support() );
			}
		);
	}

	/**
	 * Init.
	 */
	public function init(): void {
		// Add endpoint subscriptions in my-account.
		add_rewrite_endpoint( 'payxpert-subscription-list', EP_ROOT | EP_PAGES );
		add_rewrite_endpoint( 'payxpert-subscription-details', EP_ROOT | EP_PAGES );

		// New status installment payment pending.
		register_post_status(
			'wc-' . self::ORDER_STATUS_INSTALLMENT_PENDING,
			array(
				'label'                     => _x( 'Instalment Payment Pending (PayXpert)', 'Order status', 'payxpert' ),
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				// translators: %s is the number of items with this status.
				'label_count'               => _n_noop(
					'Waiting for instalment payments <span class="count">(%s)</span>',
					'Waiting for instalment payments <span class="count">(%s)</span>',
					'payxpert'
				),
			)
		);

		// New status capture payment pending.
		register_post_status(
			'wc-' . self::ORDER_STATUS_CAPTURE_PENDING,
			array(
				'label'                     => _x( 'Capture Payment Pending (PayXpert)', 'Order status', 'payxpert' ),
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
			)
		);

		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';

		if ( ! is_admin() && is_user_logged_in() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only detects the cancel form submission; the nonce is verified immediately below before any processing.
			if ( 'POST' === $request_method && ! empty( $_POST['payxpert_cancel_subscription'] ) ) {

				$current_user_id = get_current_user_id();
				$nonce           = isset( $_POST['payxpert_cancel_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['payxpert_cancel_nonce'] ) ) : '';

				if ( ! wp_verify_nonce( $nonce, 'payxpert_cancel_subscription' ) ) {
					\wc_add_notice( __( 'Security check failed.', 'payxpert' ), 'error' );
					wp_safe_redirect( wc_get_account_endpoint_url( 'payxpert-subscription-list' ) );
					exit;
				}

				$subscription_id = isset( $_POST['subscription_id'] ) ? sanitize_text_field( wp_unslash( $_POST['subscription_id'] ) ) : '';
				$reason_id       = isset( $_POST['payxpert_cancel_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['payxpert_cancel_reason'] ) ) : '';

				$result = self::cancel_subscription( $subscription_id, $current_user_id, $reason_id );

				if ( $result['success'] ) {
					\wc_add_notice( $result['message'], 'success' );
				} else {
					\wc_add_notice( $result['message'], 'error' );
				}

				wp_safe_redirect( wc_get_account_endpoint_url( 'payxpert-subscription-list' ) );
				exit;
			}
		}
	}

	/**
	 * Register payxpert shop order statuses.
	 *
	 * @param mixed $order_statuses Order statuses.
	 * @return mixed
	 */
	public function register_payxpert_shop_order_statuses( $order_statuses ) {
		// Installment payment in progress.
		$order_statuses[ 'wc-' . self::ORDER_STATUS_INSTALLMENT_PENDING ] = array(
			'label'                     => _x( 'Instalment Payment Pending (PayXpert)', 'Order status', 'payxpert' ),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			// translators: %s is the number of items with this status.
			'label_count'               => _n_noop(
				'Waiting for instalment payments <span class="count">(%s)</span>',
				'Waiting for instalment payments <span class="count">(%s)</span>',
				'payxpert'
			),
		);

		// Waiting for payment capture.
		$order_statuses[ 'wc-' . self::ORDER_STATUS_CAPTURE_PENDING ] = array(
			'label'                     => _x( 'Capture Payment Pending (PayXpert)', 'Order status', 'payxpert' ),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
		);

		return $order_statuses;
	}

	/**
	 * Payxpert order statuses.
	 *
	 * @param mixed $order_statuses Order statuses.
	 * @return mixed
	 */
	public function payxpert_order_statuses( $order_statuses ) {
		$order_statuses[ 'wc-' . self::ORDER_STATUS_INSTALLMENT_PENDING ] = _x( 'Instalment Payment Pending (PayXpert)', 'Order status', 'payxpert' );
		$order_statuses[ 'wc-' . self::ORDER_STATUS_CAPTURE_PENDING ]     = _x( 'Capture Payment Pending (PayXpert)', 'Order status', 'payxpert' );

		return $order_statuses;
	}

	/**
	 * Output transaction metabox.
	 *
	 * @param mixed $post Post.
	 * @return mixed
	 */
	public function output_transaction_metabox( $post ) {
		$order = $post instanceof \WC_Order ? $post : wc_get_order( isset( $post->ID ) ? (int) $post->ID : 0 );
		if ( ! $order ) {
			echo '<p style="text-align:center;">' . esc_html__( 'Order not found.', 'payxpert' ) . '</p>';
			return;
		}
		$transactions = Payxpert_Payment_Transaction::find_all_by_order_id( $order->get_id() );

		if ( empty( $transactions ) || ! is_array( $transactions ) ) {
			echo '<p style="text-align:center;">' . esc_html__( 'No transaction found.', 'payxpert' ) . '</p>';
			return;
		}

		// ! Used in template
		$order_transactions_formatted = WC_Payxpert_Utils::get_order_transactions_formatted( $transactions );

		$template_path = WC_PAYXPERT_PLUGIN_FILE_PATH . 'templates/views/html-admin-order-transactions.php';

		if ( file_exists( $template_path ) ) {
			include $template_path;
		} else {
			echo '<p>' . esc_html__( 'Transaction template not found.', 'payxpert' ) . '</p>';
		}
	}

	/**
	 * Handle capture transaction.
	 */
	public function handle_capture_transaction(): void {
		if (
			! current_user_can( 'edit_shop_orders' ) ||
			! isset( $_POST['payxpert_capture_action_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['payxpert_capture_action_nonce'] ) ), 'payxpert_capture_action' )
		) {
			wp_die( esc_html__( 'Non authorized action.', 'payxpert' ) );
		}

		$order_id       = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$transaction_id = isset( $_POST['capture_transaction_id'] ) ? sanitize_text_field( wp_unslash( $_POST['capture_transaction_id'] ) ) : '';

		if ( ! $order_id || ! $transaction_id ) {
			wp_die( esc_html__( 'Missing parameters.', 'payxpert' ) );
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'payxpert' ) );
		}

		$transaction = Payxpert_Payment_Transaction::find_one_by(
			array(
				'transaction_id' => $transaction_id,
				'order_id'       => $order_id,
			)
		);

		if ( ! $transaction ) {
			wp_die( esc_html__( 'Transaction not found.', 'payxpert' ) );
		}

		if ( Payxpert_Payment_Transaction::OPERATION_AUTHORIZE !== $transaction['operation'] ) {
			wp_die( esc_html__( 'Transaction operation must be `authorize`', 'payxpert' ) );
		}

		if ( ! empty( Payxpert_Payment_Transaction::find_one_by( array( 'transaction_referal_id' => $transaction_id ) ) ) ) {
			wp_die( esc_html__( 'A capture transaction already exist for this transaction ID.', 'payxpert' ) );
		}

		$configuration = WC_Payxpert_Utils::get_configuration();
		$capture_info  = WC_Payxpert_Webservice::capture_transaction(
			$configuration,
			$transaction_id,
			intval( floatval( $transaction['amount'] ) * 100 )
		);

		if ( isset( $capture_info['error'] ) ) {
			WC_Payxpert_Logger::critical( $capture_info['error'] );
			wp_die( esc_html( __( 'An error occured during the capture process : ', 'payxpert' ) . $capture_info['error'] ) );
		}

		if ( Payxpert_Payment_Transaction::RESULT_CODE_SUCCESS !== $capture_info['code'] ) {
			WC_Payxpert_Logger::critical( $capture_info['message'] );
			wp_die( esc_html( __( 'An error occured during the capture process : ', 'payxpert' ) . $capture_info['message'] ) );
		}

		$transaction_info = WC_Payxpert_Webservice::get_transaction_info( $configuration, $capture_info['transaction_id'] );

		$payxpert_payment_transaction = new Payxpert_Payment_Transaction();
		$new_info                     = array_merge(
			$transaction_info,
			array(
				'order_id'               => $order_id,
				'transaction_referal_id' => $transaction_id,
			)
		);
		$payxpert_payment_transaction->set( $new_info );
		$payxpert_payment_transaction->save();

		$order->add_order_note(
			sprintf(
				// translators: %s is the transaction ID.
				__( 'Transaction %s captured successfully via PayXpert.', 'payxpert' ),
				$capture_info['transaction_id']
			)
		);

		// Payment complete.
		$order->payment_complete( $capture_info['transaction_id'] );

		if ( in_array( $order->get_status(), array( 'pxp-captr-pending', 'wc-pxp-captr-pending' ), true ) ) {
			$new_status = $order->needs_processing() ? 'processing' : 'completed';
			$order->update_status( $new_status, __( 'Status updated after payment captured via PayXpert.', 'payxpert' ) );
		}

		// Redirect back to the order.
		$redirect_url = WC_Payxpert_Utils::is_hpos_enabled()
			? admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id )
			: admin_url( 'post.php?post=' . $order_id . '&action=edit' );

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Payxpert order actions.
	 *
	 * @param mixed $actions Actions.
	 * @return mixed
	 */
	public function payxpert_order_actions( $actions ) {
		global $theorder;
		$configuration = WC_Payxpert_Utils::get_configuration();

		if (
			$theorder instanceof \WC_Order
			&& ( $theorder->has_status( 'pending' ) || $theorder->has_status( 'failed' ) )
			&& $theorder->get_customer_id() > 0 // Order has a customer.
			&& 'yes' === $configuration['payxpert_paybylink_enabled']
		) {
			$actions['payxpert_send_paybylink'] = __( '(PayXpert) Send PayByLink', 'payxpert' );

			$order_total = $theorder->get_total();

			if ( WC_Payxpert_Utils::is_installment_payment_available( $configuration, $order_total ) ) {
				if ( 'yes' === $configuration['payxpert_installment_x2_enabled'] ) {
					$actions['payxpert_send_paybylink_x2'] = __( '(PayXpert) Send PayByLink x2', 'payxpert' );
				}

				if ( 'yes' === $configuration['payxpert_installment_x3_enabled'] ) {
					$actions['payxpert_send_paybylink_x3'] = __( '(PayXpert) Send PayByLink x3', 'payxpert' );
				}

				if ( 'yes' === $configuration['payxpert_installment_x4_enabled'] ) {
					$actions['payxpert_send_paybylink_x4'] = __( '(PayXpert) Send PayByLink x4', 'payxpert' );
				}
			}
		}

		return $actions;
	}

	/**
	 * Payxpert paybylink.
	 *
	 * @param mixed $order Order.
	 */
	public function payxpert_paybylink( $order ): void {
		$this->payxpert_paybylink_action( $order );
	}

	/**
	 * Payxpert paybylink x2.
	 *
	 * @param mixed $order Order.
	 */
	public function payxpert_paybylink_x2( $order ): void {
		$this->payxpert_paybylink_action( $order, 2 );
	}

	/**
	 * Payxpert paybylink x3.
	 *
	 * @param mixed $order Order.
	 */
	public function payxpert_paybylink_x3( $order ): void {
		$this->payxpert_paybylink_action( $order, 3 );
	}

	/**
	 * Payxpert paybylink x4.
	 *
	 * @param mixed $order Order.
	 */
	public function payxpert_paybylink_x4( $order ): void {
		$this->payxpert_paybylink_action( $order, 4 );
	}

	/**
	 * Payxpert paybylink action.
	 *
	 * @param mixed $order Order.
	 * @param mixed $x_times X times.
	 * @return mixed
	 * @throws \Exception If the PayByLink email cannot be prepared or sent.
	 */
	private function payxpert_paybylink_action( $order, $x_times = 1 ) {
		try {
			$configuration = WC_Payxpert_Utils::get_configuration();

			if ( ! $order->has_status( 'pending' ) && ! $order->has_status( 'failed' ) ) {
				throw new \Exception( __( 'The order status must be `Waiting for payment` or `Failed`', 'payxpert' ) );
			}

			if ( $order->get_customer_id() == 0 ) {
				throw new \Exception( __( 'A customer must be assigned to the order before sending PayByLink (PayXpert)', 'payxpert' ) );
			}

			if ( count( $order->get_items() ) === 0 ) {
				throw new \Exception( __( 'Your order must contain at least one product (PayXpert)', 'payxpert' ) );
			}

			if ( $order->get_total() == 0 ) {
				throw new \Exception( __( 'Your order amount must be greater than 0 (PayXpert)', 'payxpert' ) );
			}

			$payment_mode          = PaymentMode::SINGLE;
			$gateway               = 'payxpert_cc';
			$instalment_parameters = array();

			if ( $x_times > 1 ) {
				$gateway               = 'payxpert_installment';
				$payment_mode          = PaymentMode::INSTALMENTS;
				$instalment_parameters = array(
					'firstPercentage' => $configuration[ 'payxpert_installment_x' . $x_times . '_percentage' ],
					'xTimes'          => $x_times,
				);
			}

			$prepared_payment = WC_Payxpert_Webservice::prepare_payment(
				$configuration,
				'wc_payment_gateway_' . $gateway,
				PaymentMethod::CREDIT_CARD,
				$payment_mode,
				$order,
				$instalment_parameters,
				true
			);

			if ( isset( $prepared_payment['error'] ) ) {
				throw new \Exception( $prepared_payment['error'] );
			}

			$customer = new WC_Customer( $order->get_customer_id() );
			$deadline = ( new DateTime( $order->get_date_created() ) )->modify( '+30 days' );

			// Format products list.
			$order_products = '';
			foreach ( $order->get_items() as $item ) {
				$order_products .= '<p>- ' . esc_html( $item->get_name() ) . ' x ' . intval( $item->get_quantity() ) . '</p>';
			}

			// Send the email using WooCommerce mailer.
			$mailer = WC()->mailer();
			$emails = $mailer->get_emails();
			if ( ! isset( $emails['WC_Email_Payxpert_Paybylink'] ) ) {
				throw new \Exception( __( 'WC_Email_Payxpert_Paybylink not found', 'payxpert' ) );
			}

			// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- Inline type hint.
			/** @var \WC_Email_Payxpert_Paybylink $paybylink_email */
			$paybylink_email = $emails['WC_Email_Payxpert_Paybylink'];

			$sent = $paybylink_email->trigger(
				array(
					'recipient'        => $order->get_billing_email(),
					'order'            => $order,
					'payment_link'     => esc_url( $prepared_payment['redirectUrl'] ),
					'firstname'        => $customer->get_first_name(),
					'lastname'         => $customer->get_last_name(),
					'order_reference'  => $order->get_order_number(),
					'order_date'       => $order->get_date_created()->date( 'Y-m-d H:i:s' ),
					'order_products'   => $order_products,
					'order_subtotal'   => wc_price( $order->get_subtotal() ),
					'order_shipping'   => wc_price( $order->get_shipping_total() ),
					'order_total'      => wc_price( $order->get_total() ),
					'payment_deadline' => $deadline->format( 'Y-m-d H:i:s' ),
					'shop_name'        => get_bloginfo( 'name' ),
					'shop_url'         => home_url(),
					'email'            => $paybylink_email,
				)
			);

			if ( $sent ) {
				$order->add_order_note( __( 'PayByLink email sent to the customer (PayXpert)', 'payxpert' ) );
				update_post_meta( $order->get_id(), '_payxpert_paybylink_last_sent', current_time( 'mysql' ) );

				if ( is_admin() ) {
					WC_Payxpert_Admin_Notices::set( 'admin_success', __( 'The PayByLink email has been sent successfully.', 'payxpert' ) );
				}
			} else {
				WC_Payxpert_Admin_Notices::set( 'admin_error', __( 'An error occured while sending the PayByLink email.', 'payxpert' ) );
			}
		} catch ( \Exception $e ) {
			if ( is_admin() ) {
				WC_Payxpert_Logger::error( $e->getMessage() );
				WC_Payxpert_Admin_Notices::set( 'admin_error', $e->getMessage() );
			}
		}
	}

	/**
	 * Admin footer.
	 */
	public function admin_footer(): void {
		global $theorder;

		?>
		<script>
			jQuery(document).ready(function($) {
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
				$('#_payxpert_subscription').on('change', function() {
					toggleSubscriptionBlocks($(this).is(':checked'));
				});
			});
		</script>
		<?php

		$this->payxpert_modal_cancel_assets();

		if ( ! ( $theorder instanceof \WC_Order ) ) {
			return;
		}

		$order_id = $theorder->get_id();

		// Last PayByLink email sent (if any).
		$last_sent_date = get_post_meta( $order_id, '_payxpert_paybylink_last_sent', true );
		if ( $last_sent_date ) {
			// Human-readable date for the notice.
			$last_sent_date = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $last_sent_date ) );

			// Translatable prompt strings.
			$message_base = __( 'A payment link has already been sent', 'payxpert' );
			// translators: %s is the date the link was last sent.
			$last_sent_info   = $last_sent_date ? sprintf( __( ' on %s', 'payxpert' ), $last_sent_date ) : '';
			$message_continue = __( '. Do you want to continue?', 'payxpert' );
			$full_message     = $message_base . $last_sent_info . $message_continue;
			?>
			<script>
				jQuery(document).ready(function($) {
					var message = <?php echo wp_json_encode( $full_message ); ?>;

					// Intercept order actions form submit.
					$('#actions').closest('form').on('submit', function(e) {
						var selected = $(this).find('select[name="wc_order_action"]').val();

						// Confirm before PayXpert bulk actions.
						if (selected && selected.startsWith('payxpert_send_paybylink')) {
							if (!confirm(message)) {
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

	// When product is saved.
	/**
	 * Admin process product object.
	 *
	 * @param mixed $product Product.
	 */
	public function admin_process_product_object( $product ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Runs on the WooCommerce product-save hook, after WooCommerce has verified its own nonce; each field is sanitized in save_subscription_fields().
		$this->save_subscription_fields( wp_unslash( $_POST ), $product->get_id(), '_payxpert_', $product->is_type( 'variable' ) );
	}

	/**
	 * Payxpert save variation subscription fields.
	 *
	 * @param mixed $variation_id Variation id.
	 * @param mixed $i I.
	 */
	public function payxpert_save_variation_subscription_fields( $variation_id, $i ): void {
		$prefix = "_payxpert_sub_{$variation_id}_payxpert_";
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Runs on the WooCommerce variation-save hook, after WooCommerce has verified its own nonce; each field is sanitized in save_subscription_fields().
		$this->save_subscription_fields( wp_unslash( $_POST ), $variation_id, $prefix );
	}

	/**
	 * Display product subscription detail panel.
	 */
	public function display_product_subscription_detail_panel(): void {
		global $post;
		$product = wc_get_product( $post->ID );
		if ( ! $product || $product->is_type( 'variable' ) ) {
			return;
		}

		echo '<div id="payxpert_subscription_product_data" class="panel woocommerce_options_panel hidden">';
		$this->render_subscription_fields( $product, '_payxpert_' );
		echo '</div>';

		// Panel-specific script for subscription fields.
		?>
		<script type="text/javascript">
			jQuery(function($) {
				var $panel = $('#payxpert_subscription_product_data');

				function toggleMaxCycles() {
					if ($panel.find('select[name="<?php echo esc_js( self::SUBSCRIPTION_DURATION_TYPE ); ?>"]').val() === "<?php echo esc_js( SubscriptionType::NORMAL ); ?>") {
						$panel.find('.subscription-max-cycles').show();
					} else {
						$panel.find('.subscription-max-cycles').hide();
					}
				}
				toggleMaxCycles();
				$panel.find('select[name="<?php echo esc_js( self::SUBSCRIPTION_DURATION_TYPE ); ?>"]').on('change', toggleMaxCycles);
			});
		</script>
		<?php
	}

	/**
	 * Display variation subscription panel.
	 *
	 * @param mixed $loop Loop.
	 * @param mixed $variation_data Variation data.
	 * @param mixed $variation Variation.
	 */
	public function display_variation_subscription_panel( $loop, $variation_data, $variation ): void {
		$variation_id = $variation->ID;
		$prefix       = "_payxpert_sub_{$variation_id}";

		if ( ! $variation instanceof \WC_Product ) {
			if ( isset( $variation_id ) ) {
				$variation = wc_get_product( $variation_id );
			} else {
				return; // safety.
			}
		}
		// Parent product ID.
		$parent_id = $variation->get_parent_id();

		// Subscription flag on parent product.
		$is_subscription = get_post_meta( $parent_id, self::SUBSCRIPTION, true ) === 'yes' ? true : false;

		?>
		<div class="payxpert-subscription-variation" data-variation-id="<?php echo esc_attr( $variation_id ); ?>" style="
			border:1px solid #ddd;
			padding:10px;
			margin-top:10px;
			background:#f9f9f9;
			border-radius:5px;
			<?php echo esc_attr( $is_subscription ? '' : 'display:none;' ); ?>
		">
			<h4 style="margin:0 0 10px 0; color:#21759b;"><?php esc_html_e( 'PayXpert Subscription Settings', 'payxpert' ); ?></h4>

			<?php $this->render_subscription_fields( $variation, $prefix . '_payxpert_' ); ?>
		</div>

		<script type="text/javascript">
			jQuery(function($) {
				var $container = $('.payxpert-subscription-variation[data-variation-id="<?php echo esc_js( (string) $variation_id ); ?>"]');

				function toggleMaxCycles() {
					if ($container.find('select[name="<?php echo esc_js( $prefix . self::SUBSCRIPTION_DURATION_TYPE ); ?>"]').val() === "<?php echo esc_js( SubscriptionType::NORMAL ); ?>") {
						$container.find('.subscription-max-cycles').show();
					} else {
						$container.find('.subscription-max-cycles').hide();
					}
				}
				toggleMaxCycles();
				$container.find('select[name="<?php echo esc_js( $prefix . self::SUBSCRIPTION_DURATION_TYPE ); ?>"]').on('change', toggleMaxCycles);
			});
		</script>
		<?php
	}

	/**
	 * Display subscription info single product.
	 */
	public function display_subscription_info_single_product(): void {
		global $product;

		if ( ! $product instanceof \WC_Product || $product->is_type( 'variable' ) ) {
			return;
		}

		$product_id      = $product->get_id();
		$is_subscription = get_post_meta( $product_id, self::SUBSCRIPTION, true );
		if ( empty( $is_subscription ) ) {
			return;
		}

		$frequency         = get_post_meta( $product_id, self::SUBSCRIPTION_FREQUENCY, true );
		$subscription_data = array(
			'frequency'     => WC_Payxpert_Utils::get_humanize_subscription_frequency( $frequency ),
			'price_html'    => wc_price( get_post_meta( $product_id, self::SUBSCRIPTION_PRICE, true ) ) . $product->get_price_suffix(),
			'first_payment' => WC_Payxpert_Utils::get_subscription_first_payment_text(
				get_post_meta( $product_id, self::SUBSCRIPTION_FIRST_PAYMENT, true ),
				$frequency,
				get_post_meta( $product_id, self::SUBSCRIPTION_DURATION_TYPE, true ),
				get_post_meta( $product_id, self::SUBSCRIPTION_MAX_CYCLES, true ),
			),
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-rendered trusted template output; values are escaped inside the template.
		echo self::render_subscription_box( $subscription_data );
	}

	/**
	 * Add available variation data.
	 *
	 * @param mixed $variation_data Variation data.
	 * @param mixed $product Product.
	 * @param mixed $variation Variation.
	 * @return mixed
	 */
	public function add_available_variation_data( $variation_data, $product, $variation ) {
		$variation_id    = $variation->get_id();
		$is_subscription = get_post_meta( $variation_id, self::SUBSCRIPTION, true );

		if ( $is_subscription ) {
			$frequency = get_post_meta( $variation_id, '_payxpert_subscription_frequency', true );
			$price     = get_post_meta( $variation_id, self::SUBSCRIPTION_PRICE, true );

			$variation_data['payxpert_subscription'] = array(
				'frequency'     => WC_Payxpert_Utils::get_humanize_subscription_frequency( $frequency ),
				'price_html'    => wc_price( $price ) . $variation->get_price_suffix(),
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

	/**
	 * Display subscription info variable product.
	 */
	public function display_subscription_info_variable_product(): void {
		global $product;
		// Default values before variation-specific data loads.
		$subscription_data = array(
			'frequency'     => '',
			'price_html'    => '',
			'first_payment' => '',
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-rendered trusted template output; values are escaped inside the template.
		echo self::render_subscription_box( $subscription_data, 'payxpert-subscription-info', true );
	}

	/**
	 * Restrict mixed subscription cart.
	 *
	 * @param mixed $passed Passed.
	 * @param mixed $product_id Product id.
	 * @return mixed
	 */
	public function restrict_mixed_subscription_cart( $passed, $product_id ) {
		// Detect PayXpert subscription product in cart validation.
		$is_subscription = (bool) get_post_meta( $product_id, self::SUBSCRIPTION, true );

		// Parcours du panier.
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$cart_product_id      = $cart_item['product_id'];
			$cart_is_subscription = (bool) get_post_meta( $cart_product_id, self::SUBSCRIPTION, true );

			// Block mixing subscription and non-subscription products.
			if ( ( $cart_is_subscription && ! $is_subscription ) || ( ! $cart_is_subscription && $is_subscription ) ) {
				wc_add_notice( __( 'You cannot mix subscription products and standard products in the same cart.', 'payxpert' ), 'error' );
				return false;
			}

			// Block multiple distinct subscription products.
			if ( $cart_is_subscription && (int) $cart_product_id !== (int) $product_id ) {
				wc_add_notice(
					__( 'You cannot add more than one different subscription product to the cart.', 'payxpert' ),
					'error'
				);
				return false;
			}
		}

		return $passed;
	}

	/**
	 * Render subscription fields.
	 *
	 * @param mixed $product_or_variation Product or variation.
	 * @param mixed $prefix Prefix.
	 * @return mixed
	 */
	public function render_subscription_fields( $product_or_variation, $prefix = '' ) {
		?>
		<div class="payxpert-subscription-fields" data-prefix="<?php echo esc_attr( $prefix ); ?>">

			<?php
			// Frequency.
			woocommerce_wp_select(
				array(
					'id'          => $prefix . 'subscription_frequency',
					'label'       => __( 'Frequency', 'payxpert' ),
					'description' => __( 'Billing interval', 'payxpert' ),
					'desc_tip'    => true,
					'options'     => array(
						'daily'     => __( 'Daily', 'payxpert' ),
						'weekly'    => __( 'Weekly', 'payxpert' ),
						'monthly'   => __( 'Monthly', 'payxpert' ),
						'quarterly' => __( 'Quarterly', 'payxpert' ),
						'yearly'    => __( 'Annually', 'payxpert' ),
					),
					'value'       => $product_or_variation->get_meta( '_payxpert_subscription_frequency', true ),
				)
			);

			// Duration type.
			woocommerce_wp_select(
				array(
					'id'          => $prefix . 'subscription_duration_type',
					'label'       => __( 'Duration type', 'payxpert' ),
					'description' => __( 'Select the duration type for this subscription', 'payxpert' ),
					'desc_tip'    => true,
					'options'     => array(
						SubscriptionType::NORMAL   => __( 'Fixed duration', 'payxpert' ),
						SubscriptionType::INFINITE => __( 'Unlimited', 'payxpert' ),
					),
					'value'       => $product_or_variation->get_meta( self::SUBSCRIPTION_DURATION_TYPE, true ) ? $product_or_variation->get_meta( self::SUBSCRIPTION_DURATION_TYPE, true ) : SubscriptionType::INFINITE,
				)
			);

			// Max cycles.
			woocommerce_wp_text_input(
				array(
					'id'                => $prefix . 'subscription_max_cycles',
					'label'             => __( 'Number of occurrences', 'payxpert' ),
					'description'       => __( 'Number of payments before the end of the subscription', 'payxpert' ),
					'type'              => 'number',
					'custom_attributes' => array( 'min' => 1 ),
					'value'             => $product_or_variation->get_meta( self::SUBSCRIPTION_MAX_CYCLES, true ) ? $product_or_variation->get_meta( self::SUBSCRIPTION_MAX_CYCLES, true ) : 1,
					'wrapper_class'     => 'subscription-max-cycles',
				)
			);

			// First due.
			woocommerce_wp_select(
				array(
					'id'          => $prefix . 'subscription_first_payment',
					'label'       => __( 'First due', 'payxpert' ),
					'description' => __( 'Select the date of the first due date', 'payxpert' ),
					'desc_tip'    => true,
					'options'     => array(
						'immediate' => __( 'Immediate (upon subscription)', 'payxpert' ),
					),
					'value'       => $product_or_variation->get_meta( self::SUBSCRIPTION_FIRST_PAYMENT, true ) ? $product_or_variation->get_meta( self::SUBSCRIPTION_FIRST_PAYMENT, true ) : 'immediate',
				)
			);

			// Recurring amount.
			$recurring_amount = $product_or_variation->get_meta( self::SUBSCRIPTION_PRICE, true );
			$recurring_amount = $recurring_amount ? $recurring_amount : $product_or_variation->get_price();
			woocommerce_wp_text_input(
				array(
					'id'          => $prefix . 'subscription_price',
					'label'       => __( 'Recurring amount', 'payxpert' ),
					'description' => __( 'Recurring amount for the subscription', 'payxpert' ),
					'type'        => 'text',
					'value'       => $recurring_amount,
				)
			);
			?>
		</div>
		<?php
	}

	/**
	 * Sanitize subscription meta value.
	 *
	 * @param mixed $field Field.
	 * @param mixed $raw_value Raw value.
	 * @return mixed
	 */
	private function sanitize_subscription_meta_value( $field, $raw_value ) {
		switch ( $field ) {
			case 'subscription':
				return ( 'on' === $raw_value || 'yes' === $raw_value ) ? 'yes' : 'no';
			case 'subscription_frequency':
				$allowed = array( 'daily', 'weekly', 'monthly', 'quarterly', 'yearly' );
				$value   = sanitize_key( $raw_value );
				return in_array( $value, $allowed, true ) ? $value : 'monthly';
			case 'subscription_duration_type':
				$value = sanitize_key( $raw_value );
				return in_array( $value, array( SubscriptionType::NORMAL, SubscriptionType::INFINITE ), true )
					? $value
					: SubscriptionType::INFINITE;
			case 'subscription_max_cycles':
				return max( 0, absint( $raw_value ) );
			case 'subscription_first_payment':
				$value = sanitize_key( $raw_value );
				return 'immediate' === $value ? 'immediate' : 'immediate';
			case 'subscription_price':
				return wc_format_decimal( $raw_value );
			default:
				return sanitize_text_field( $raw_value );
		}
	}

	/**
	 * Is subscription enabled for save.
	 *
	 * @param mixed $post_data Post data.
	 * @param mixed $object_id Object id.
	 * @param mixed $prefix Prefix.
	 * @param mixed $is_variable Is variable.
	 * @return mixed
	 */
	private function is_subscription_enabled_for_save( $post_data, $object_id, $prefix, $is_variable ) {
		if ( $is_variable ) {
			return false;
		}

		if ( isset( $post_data[ $prefix . 'subscription' ] ) ) {
			return 'on' === $post_data[ $prefix . 'subscription' ];
		}

		$product = wc_get_product( $object_id );
		if ( $product && $product->is_type( 'variation' ) ) {
			return get_post_meta( $product->get_parent_id(), self::SUBSCRIPTION, true ) === 'yes';
		}

		return false;
	}

	/**
	 * Save subscription fields.
	 *
	 * @param mixed $post_data Post data.
	 * @param mixed $object_id Object id.
	 * @param mixed $prefix Prefix.
	 * @param mixed $is_variable Is variable.
	 * @return mixed
	 */
	public function save_subscription_fields( $post_data, $object_id, $prefix = '', $is_variable = false ) {

		$fields = array(
			'subscription',
			'subscription_frequency',
			'subscription_duration_type',
			'subscription_max_cycles',
			'subscription_first_payment',
			'subscription_price',
		);

		$errors               = array();
		$subscription_enabled = $this->is_subscription_enabled_for_save( $post_data, $object_id, $prefix, $is_variable );

		// --- Validate when subscription is enabled (simple products and variations). ---
		if ( ! $is_variable && $subscription_enabled ) {
			$recurring_amount = isset( $post_data[ $prefix . 'subscription_price' ] )
				? floatval( $this->sanitize_subscription_meta_value( 'subscription_price', $post_data[ $prefix . 'subscription_price' ] ) )
				: 0;
			$duration_type    = isset( $post_data[ $prefix . 'subscription_duration_type' ] )
				? $this->sanitize_subscription_meta_value( 'subscription_duration_type', $post_data[ $prefix . 'subscription_duration_type' ] )
				: SubscriptionType::INFINITE;
			$max_cycles       = isset( $post_data[ $prefix . 'subscription_max_cycles' ] )
				? $this->sanitize_subscription_meta_value( 'subscription_max_cycles', $post_data[ $prefix . 'subscription_max_cycles' ] )
				: 0;

			if ( $recurring_amount <= 0 ) {
				$errors[] = __( 'Recurring amount must be greater than 0.', 'payxpert' );
			}

			if ( SubscriptionType::NORMAL === $duration_type && $max_cycles <= 0 ) {
				$errors[] = __( 'For a NORMAL subscription, the number of occurrences must be greater than 0.', 'payxpert' );
			}

			if ( ! empty( $errors ) ) {
				WC_Payxpert_Admin_Notices::set( 'subscription_errors', $errors );
				return false;
			}
		}

		// --- Save meta on parent product. ---
		foreach ( $fields as $field ) {
			$key      = '_payxpert_' . $field;
			$post_key = $prefix . $field;

			if ( isset( $post_data[ $post_key ] ) ) {
				$value = $this->sanitize_subscription_meta_value( $field, $post_data[ $post_key ] );
				update_post_meta( $object_id, $key, $value );
			} elseif ( 'subscription' === $field ) {
				// Always persist checkbox state (including unchecked).
				update_post_meta( $object_id, $key, 'no' );
			}
		}

		// --- Variable product: sync variation meta from parent. ---
		if ( $is_variable ) {
			$product = wc_get_product( $object_id );
			if ( $product && $product->is_type( 'variable' ) ) {
				$meta_subscription = get_post_meta( $object_id, self::SUBSCRIPTION, true );

				foreach ( $product->get_children() as $variation_id ) {
					// Subscription checkbox value for this variation.
					update_post_meta( $variation_id, self::SUBSCRIPTION, 'yes' === $meta_subscription ? 'yes' : 'no' );

					// When parent is subscription, seed default meta on variations if missing.
					if ( 'yes' === $meta_subscription ) {
						$defaults = array(
							'subscription_frequency'     => 'monthly',
							'subscription_duration_type' => SubscriptionType::INFINITE,
							'subscription_max_cycles'    => 1,
							'subscription_first_payment' => 'immediate',
							'subscription_price'         => get_post_meta( $variation_id, '_price', true ) ? get_post_meta( $variation_id, '_price', true ) : 0,
						);

						foreach ( $defaults as $field => $default_value ) {
							$meta_key = '_payxpert_' . $field;
							$existing = get_post_meta( $variation_id, $meta_key, true );
							if ( '' === $existing ) {
								update_post_meta( $variation_id, $meta_key, $default_value );
							}
						}
					} else {
						update_post_meta( $variation_id, self::SUBSCRIPTION, 'no' );
					}
				}
			}
		}

		return true;
	}

	/**
	 * Renders the PayXpert subscription block on the product page.
	 *
	 * @param array<string, mixed> $subscription_data Subscription payload: frequency, price_html, first_payment, etc.
	 * @param string               $container_id      ID unique du conteneur.
	 * @param bool                 $is_variable       Whether this is a variation context.
	 */
	public static function render_subscription_box(
		array $subscription_data,
		string $container_id = 'payxpert-subscription-info',
		bool $is_variable = false
	): string {
		if ( empty( $subscription_data ) ) {
			return '';
		}

		$args = array(
			'container_id'       => $container_id,
			'is_variable'        => $is_variable,
			'frequency_text'     => $subscription_data['frequency'] ?? '',
			'price_html'         => $subscription_data['price_html'] ?? '',
			'first_payment_text' => $subscription_data['first_payment'] ?? '',
		);

		ob_start();

		wc_get_template(
			'html-product-subscription-detail.php',
			$args,
			'',
			WC_PAYXPERT_PLUGIN_FILE_PATH . 'templates/views/product/'
		);

		return ob_get_clean();
	}

	/**
	 * Payxpert modal cancel assets.
	 */
	public function payxpert_modal_cancel_assets(): void {
		?>
		<script>
			jQuery(function($) {

				$('.cancel-subscription').on('click', function(e) {
					e.preventDefault();
					$('#modal_subscription_id').val(
						$(this).data('subscription-id')
					);
					$('#cancel-subscription-modal').fadeIn();
				});

				$('#cancel-modal-close, .payxpert-modal-overlay').on('click', function() {
					$('#cancel-subscription-modal').fadeOut();
				});

			});
		</script>

		<style>
			.payxpert-modal {
				display: none;
				position: fixed;
				top: 0;
				left: 0;
				width: 100%;
				height: 100%;
				z-index: 9998;
			}

			.payxpert-modal-overlay {
				background: rgba(0, 0, 0, 0.5);
				position: absolute;
				width: 100%;
				height: 100%;
			}

			.payxpert-modal-content {
				position: relative;
				background: #fff;
				max-width: 420px;
				margin: 80px auto;
				padding: 20px;
				border-radius: 6px;
				z-index: 9999;
			}
		</style>
		<?php
	}

	/**
	 * Cancel subscription.
	 *
	 * @param mixed $subscription_id Subscription id.
	 * @param mixed $user_id User id.
	 * @param mixed $reason_id Reason id.
	 * @param mixed $is_admin Is admin.
	 * @return mixed
	 */
	public static function cancel_subscription( $subscription_id, $user_id, $reason_id, $is_admin = false ) {
		$find_one_by = array( 'subscription_id' => $subscription_id );
		if ( ! $is_admin ) {
			$find_one_by['customer_id'] = $user_id;
		}

		$subscription = Payxpert_Subscription::find_one_by( $find_one_by );

		if ( ! $subscription ) {
			return array(
				'success' => false,
				'message' => __( 'Subscription not found.', 'payxpert' ),
			);
		}

		if ( 'active' !== $subscription['state'] ) {
			return array(
				'success' => false,
				'message' => __( 'This subscription cannot be cancelled.', 'payxpert' ),
			);
		}

		if ( ! isset( Payxpert_Subscription::CUSTOMER_CANCEL_REASONS[ $reason_id ] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Reason unknown.', 'payxpert' ),
			);
		}

		$configuration = WC_Payxpert_Utils::get_configuration();
		$result        = WC_Payxpert_Webservice::cancel_subscription( $configuration, $subscription_id, $reason_id );

		if ( isset( $result['error'] ) ) {
			return array(
				'success' => false,
				'message' => $result['error'],
			);
		}

		// MAJ locale.
		$subscription_info = WC_Payxpert_Webservice::get_status_subscription( $configuration, $subscription_id );
		if ( isset( $subscription_info['error'] ) ) {
			WC_Payxpert_Logger::critical( 'Error retrieving subscription info for subscriptionID : ' . (int) $subscription_id );
			return array(
				'success' => true,
				'message' => __( 'Subscription seems to have been cancelled but info not updated.', 'payxpert' ),
			);
		}

		$subscription_info     = WC_Payxpert_Utils::to_snake_case_keys( $subscription_info['subscription'] );
		$payxpert_subscription = Payxpert_Subscription::get_instance_from_array( $subscription );
		$payxpert_subscription->set( $subscription_info );
		$payxpert_subscription->save();

		return array(
			'success' => true,
			'message' => __( 'Your subscription has been cancelled.', 'payxpert' ),
		);
	}
}
