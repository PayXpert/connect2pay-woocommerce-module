<?php
/**
 * Wc Payment Gateway Payxpert Installment.
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
 * WC Payment Gateway Payxpert Installment.
 */
abstract class WC_Payment_Gateway_Payxpert_Installment extends WC_Payment_Gateway_Payxpert {
	/**
	 * X times.
	 *
	 * @var mixed
	 */
	protected $x_times;
	/**
	 * Percentage key.
	 *
	 * @var mixed
	 */
	protected $percentage_key;
	/**
	 * Label key.
	 *
	 * @var mixed
	 */
	protected $label_key;
	/**
	 * Enabled key.
	 *
	 * @var mixed
	 */
	protected $enabled_key;

	/**
	 * Seamless.
	 *
	 * @var bool
	 */
	public $seamless = false;
	/**
	 * Configuration.
	 *
	 * @var mixed
	 */
	public $configuration;

	/**
	 * Construct.
	 *
	 * @param mixed $gateway_id Gateway id.
	 */
	public function __construct( $gateway_id ) {
		$this->id                 = $gateway_id;
		$this->method_description = __( 'Credit card gateway.', 'payxpert' );

		$this->supports = array(
			'products',
			'pay_for_order',
			'refunds',
		);

		$this->percentage_key = $gateway_id . '_percentage';
		$this->label_key      = $gateway_id . '_label';
		$this->enabled_key    = $gateway_id . '_enabled';

		$this->init_form_fields();
		$this->init_settings();

		$this->configuration = WC_Payxpert_Utils::get_configuration();
		$this->seamless      = ( WC_Payxpert::REDIRECT_MODE_SEAMLESS == $this->configuration['payxpert_redirect_mode'] );
		$this->icon          = WC_Payxpert_Utils::get_gateway_icon_name( $this->configuration, $this->id );
		$this->title         = $this->configuration[ $this->label_key ] ?? $this->method_title;
		$this->enabled       = $this->configuration[ $this->enabled_key ];
		$this->has_fields    = true;

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
	 * Schedule + template HTML only (no prepare_payment).
	 */
	public function get_installment_schedule_html(): string {
		global $wp;
		$amount = 0;

		if ( isset( $wp->query_vars['order-pay'] ) ) {
			$order  = wc_get_order( absint( $wp->query_vars['order-pay'] ) );
			$amount = $order->get_total();
		} elseif ( isset( WC()->cart ) ) {
			$amount = WC()->cart->get_total( 'edit' );
		}

		$schedule = WC_Payxpert_Utils::build_instalment_schedule(
			$amount,
			$this->configuration[ $this->percentage_key ],
			$this->x_times
		);

		ob_start();
		include dirname( __DIR__, 2 ) . '/templates/views/html-admin-payment-installment.php';

		return (string) ob_get_clean();
	}

	/**
	 * Get payment fields.
	 *
	 * @return mixed
	 */
	public function get_payment_fields() {
		global $wp;
		$fields = $this->get_installment_schedule_html();

		if ( $this->seamless ) {
			$order = null;
			if ( isset( $wp->query_vars['order-pay'] ) ) {
				$order = wc_get_order( absint( $wp->query_vars['order-pay'] ) );
			}

			$seamless_data = $this->get_seamless_data( $order );
			if ( isset( $seamless_data['error'] ) ) {
				return esc_html( $seamless_data['error'] );
			}

			$fields .= $this->get_seamless_payment_fields_html( $seamless_data, 'style="width:100%"' );
		}

		return $fields;
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
			PaymentMode::INSTALMENTS,
			$order,
			array(
				'firstPercentage' => $this->configuration[ $this->percentage_key ],
				'xTimes'          => $this->x_times,
			),
			false
		);

		return $this->assemble_seamless_data( $prepared_payment, $order );
	}

	/**
	 * Is available.
	 *
	 * @return mixed
	 */
	public function is_available() {
		global $wp;

		if ( ! is_user_logged_in() ) {
			return false;
		}

		$amount = 0;

		if ( isset( $wp->query_vars['order-pay'] ) ) {
			$order  = wc_get_order( absint( $wp->query_vars['order-pay'] ) );
			$amount = $order->get_total();
		} elseif ( isset( WC()->cart ) ) {
			$amount = WC()->cart->get_total( 'edit' );
		}

		if ( $amount < $this->configuration['payxpert_instalment_payment_min_amount'] ) {
			return false;
		}

		return parent::is_available();
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
			PaymentMode::INSTALMENTS,
			array(
				'firstPercentage' => $this->configuration[ $this->percentage_key ],
				'xTimes'          => $this->x_times,
			)
		);
	}

	/**
	 * Callback.
	 */
	public function callback(): void {
		parent::callback();
	}
}
