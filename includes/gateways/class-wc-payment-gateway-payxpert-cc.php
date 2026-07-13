<?php
/**
 * Wc Payment Gateway Payxpert Cc.
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
 * WC Payment Gateway Payxpert CC.
 */
class WC_Payment_Gateway_Payxpert_CC extends WC_Payment_Gateway_Payxpert {
	const ID = 'payxpert_cc';

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
		$this->method_title       = __( 'Credit Cards by PayXpert', 'payxpert' );
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
		$this->title   = $this->configuration['payxpert_cc_label'] ?? $this->method_title;
		$this->enabled = $this->configuration['payxpert_cc_enabled'];

		$this->seamless   = ( WC_Payxpert::REDIRECT_MODE_SEAMLESS == $this->configuration['payxpert_redirect_mode'] );
		$this->has_fields = $this->seamless;
		$this->icon       = WC_Payxpert_Utils::get_gateway_icon_name( $this->configuration, $this->id );

		// Settings save.
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'save_admin_options' ) );

		// IPN + IFRAME.
		add_action( 'woocommerce_api_' . strtolower( __CLASS__ ), array( $this, 'callback' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'add_scripts' ) );
	}

	/**
	 * Payment fields.
	 */
	public function payment_fields(): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted gateway markup built internally (seamless/iframe payment fields); dynamic error paths are escaped in get_payment_fields().
		echo $this->get_payment_fields();
	}

	/**
	 * Get payment fields.
	 *
	 * @return mixed
	 */
	public function get_payment_fields() {
		return parent::get_payment_fields();
	}

	/**
	 * Build the seamless payment data for the given order.
	 *
	 * @param \WC_Order|null $order Order to build the seamless data for, or null for the cart.
	 * @return array<string, mixed>|null
	 */
	public function get_seamless_data( $order = null ) {
		if ( ! is_checkout() ) {
			// Avoid fatal error on checkout editor page.
			return null;
		}

		$prepared_payment = WC_Payxpert_Webservice::prepare_payment(
			$this->configuration,
			strtolower( __CLASS__ ),
			PaymentMethod::CREDIT_CARD,
			PaymentMode::SINGLE,
			$order,
			array(),
			false
		);

		return $this->assemble_seamless_data( $prepared_payment, $order );
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
			PaymentMode::SINGLE,
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
