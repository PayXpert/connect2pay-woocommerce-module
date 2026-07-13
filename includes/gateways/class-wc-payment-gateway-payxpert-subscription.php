<?php
/**
 * Wc Payment Gateway Payxpert Subscription.
 *
 * @package Payxpert
 */

use PayXpert\Connect2Pay\containers\constant\PaymentMethod;
use PayXpert\Connect2Pay\containers\constant\PaymentMode;
use Payxpert\Utils\WC_Payxpert_Utils;
use Payxpert\Utils\WC_Payxpert_Webservice;
use Payxpert\WC_Payxpert;

defined( 'ABSPATH' ) || exit;

/**
 * WC Payment Gateway Payxpert Subscription.
 */
class WC_Payment_Gateway_Payxpert_Subscription extends WC_Payment_Gateway_Payxpert {
	const ID = 'payxpert_subscription';

	/**
	 * Configuration.
	 *
	 * @var mixed
	 */
	public $configuration;

	/**
	 * Seamless.
	 *
	 * @var bool
	 */
	public $seamless = false;

	/**
	 * Construct.
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->method_title       = __( 'Subscription per Credit Cards by PayXpert', 'payxpert' );
		$this->method_description = __( 'Credit card gateway.', 'payxpert' );

		$this->supports = array(
			'products',
			'pay_for_order',
			'refunds',
		);

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		$this->configuration = WC_Payxpert_Utils::get_configuration();

		// Define user settings.
		$subscription_label = isset( $this->configuration['payxpert_subscription_label'] )
		? trim( (string) $this->configuration['payxpert_subscription_label'] )
		: '';
		if ( '' !== $subscription_label ) {
			$subscription_label = WC_Payxpert_Utils::translate_custom_text(
				$subscription_label,
				'payxpert_subscription_label'
			);
		}
		$this->title   = '' !== $subscription_label ? $subscription_label : $this->method_title;
		$this->enabled = $this->configuration['payxpert_subscription_enabled'];

		$this->seamless   = ( WC_Payxpert::REDIRECT_MODE_SEAMLESS == $this->configuration['payxpert_redirect_mode'] );
		$this->has_fields = true;
		$this->icon       = plugins_url( 'assets/img/payment_method_logo/cc.jpg', WC_PAYXPERT_PLUGIN_NAME );

		// Settings save.
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'save_admin_options' ) );

		// IPN + IFRAME.
		add_action( 'woocommerce_api_' . strtolower( __CLASS__ ), array( $this, 'callback' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'add_scripts' ) );
	}

	/**
	 * Build the seamless payment data for the given order.
	 *
	 * @param \WC_Order|null $order Order to build the seamless data for, or null for the cart.
	 * @return array<string, mixed>|null
	 */
	public function get_seamless_data( $order = null ) {
		// Avoid fatal error on backend editor pages (e.g., admin, block editor, or cart not loaded).
		if (
			! function_exists( 'WC' ) ||
			! WC()->cart ||
			( is_admin() && ! is_ajax() ) // Editing/Backoffice page, not an AJAX call.
		) {
			return null;
		}

		$subscription_in_cart = WC_Payxpert_Utils::has_subscription_in_cart( WC()->cart->get_cart(), true );

		if ( false == $subscription_in_cart ) {
			return null;
		}

		$prepared_payment = WC_Payxpert_Webservice::prepare_payment(
			$this->configuration,
			strtolower( __CLASS__ ),
			PaymentMethod::CREDIT_CARD,
			PaymentMode::RECURRENT,
			$order,
			array(),
			false,
			true
		);

		return $this->assemble_seamless_data( $prepared_payment, $order );
	}

	/**
	 * Get payment fields.
	 *
	 * @return mixed
	 */
	public function get_payment_fields() {
		$fields = '';

		$cart = WC()->cart;
		if ( ! $cart || $cart->is_empty() ) {
			return parent::get_payment_fields();
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- Inline type hint.
			/** @var WC_Product $product */
			$product    = $cart_item['data'];
			$product_id = $product->get_id();

			$frequency = get_post_meta( $product_id, WC_Payxpert::SUBSCRIPTION_FREQUENCY, true );
			// Skip non-subscription products.
			if ( empty( $frequency ) ) {
				continue;
			}

			$price = (int) $cart_item['quantity'] * get_post_meta( $product_id, WC_Payxpert::SUBSCRIPTION_PRICE, true );

			$shipping_amount = WC()->cart ? ( WC()->cart->get_shipping_total() + WC()->cart->get_shipping_tax() ) : 0;
			// translators: %s is the formatted shipping cost.
			$shipping_text = $shipping_amount > 0 ? sprintf( __( '+ %s shipping cost', 'payxpert' ), wc_price( $shipping_amount ) . $product->get_price_suffix() ) : '';
			$price_html    = wc_price( $price ) . $product->get_price_suffix();
			$price_html   .= $shipping_text;

			$subscription_data = array(
				'frequency'     => WC_Payxpert_Utils::get_humanize_subscription_frequency( $frequency ),
				'price_html'    => $price_html,
				'first_payment' => WC_Payxpert_Utils::get_subscription_first_payment_text(
					get_post_meta( $product_id, WC_Payxpert::SUBSCRIPTION_FIRST_PAYMENT, true ),
					$frequency,
					get_post_meta( $product_id, WC_Payxpert::SUBSCRIPTION_DURATION_TYPE, true ),
					get_post_meta( $product_id, WC_Payxpert::SUBSCRIPTION_MAX_CYCLES, true ),
				),
			);

			$fields .= WC_Payxpert::render_subscription_box( $subscription_data );

			// Single subscription product per cart.
			break;
		}
		return $fields . parent::get_payment_fields();
	}

	/**
	 * Process payment.
	 *
	 * @param mixed $order_id Order id.
	 * @return mixed
	 */
	public function process_payment( $order_id ) {
		return $this->process_payment_cc(
			strtolower( __CLASS__ ),
			$order_id,
			PaymentMethod::CREDIT_CARD,
			PaymentMode::RECURRENT,
			array()
		);
	}

	/**
	 * Callback URL
	 */
	public function callback(): void {
		parent::callback();
	}
}
