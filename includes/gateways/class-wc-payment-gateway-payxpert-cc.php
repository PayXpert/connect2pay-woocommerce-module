<?php

use PayXpert\Connect2Pay\containers\constant\PaymentMethod;
use PayXpert\Connect2Pay\containers\constant\PaymentMode;
use Payxpert\Utils\WC_Payxpert_Logger;
use Payxpert\Utils\WC_Payxpert_Utils;
use Payxpert\Utils\WC_Payxpert_Webservice;
use Payxpert\WC_Payxpert;

defined('ABSPATH') || exit;

class WC_Payment_Gateway_Payxpert_CC extends WC_Payment_Gateway_Payxpert {
    const ID = 'payxpert_cc';

    public $configuration;

	public $seamless = false;

    public function __construct() {
        $this->id                  = self::ID;
		$this->method_title        = __( 'Credit Cards by PayXpert', 'payxpert' );
		$this->method_description  = __( 'Credit card gateway.', 'payxpert' );

        $this->supports = array(
            'products',
            'pay_for_order',
            'refunds',
        );

        // Load the settings
        $this->init_form_fields();
        $this->init_settings();

        $this->configuration = WC_Payxpert_Utils::get_configuration();

        // Define user settings
        $this->title        = $this->configuration['payxpert_cc_label'] ?? $this->method_title;
        $this->enabled      = $this->configuration['payxpert_cc_enabled'];

        $this->seamless = ($this->configuration['payxpert_redirect_mode'] == WC_Payxpert::REDIRECT_MODE_SEAMLESS);
        $this->has_fields = true;
        $this->icon = plugins_url('assets/img/payment_method_logo/cc.jpg', WC_PAYXPERT_PLUGIN_NAME);

        // Settings save
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        
        // IPN + IFRAME
        add_action('woocommerce_api_' . strtolower(__CLASS__), array($this, 'callback'));

        add_action('wp_enqueue_scripts', array( $this, 'add_scripts' ));
    }

    public function payment_fields() {
        echo $this->get_payment_fields();
    }

    public function get_payment_fields() {
        if (!$this->seamless) {
            return null;
        }

        global $wp;
        $order = null;

        if ( isset( $wp->query_vars['order-pay'] ) ) {
            $order_id = absint( $wp->query_vars['order-pay'] );
            $order = wc_get_order( $order_id );
        }

        $seamlessData = $this->get_seamless_data($order);

        if (!$seamlessData) {
            return;
        }

        if (isset($seamlessData['error'])) {
            return esc_html($seamlessData['error']);
        }

        ob_start(); ?>
        <div class="payxpert-seamless-container" 
            data-method="<?php echo esc_attr($this->id); ?>"
            data-customer-token="<?php echo esc_attr($seamlessData['customerToken']); ?>" 
            data-language="<?php echo esc_attr($seamlessData['language']); ?>"
            data-seamless-submit="<?php echo esc_attr($seamlessData['seamlessSubmit']); ?>"
        >
            <div class="payxpert-placeholder"></div>
            <input type="hidden" class="payxpert_nonce" value="<?php echo esc_attr($seamlessData['nonce']); ?>">
            <input type="hidden" class="merchantToken" value="<?php echo esc_attr($seamlessData['merchantToken']); ?>" name="<?php echo esc_attr($this->id); ?>_merchant_token"/>
            <input type="hidden" class="customerToken" value="<?php echo esc_attr($seamlessData['customerToken']); ?>" name="<?php echo esc_attr($this->id); ?>_customer_token"/>
            <button type="button" class="payxpert_seamless_hidden_submit" id="<?php echo esc_attr($seamlessData['seamlessSubmit']); ?>" style="display:none" >Payer </button>
        </div>
        <?php
        $fields = ob_get_clean();
        return $fields;
    }

    public function get_seamless_data($order)
    {
        if (!is_checkout()) {
            // Avoid fatal error on checkout editor page
            return;
        }

        $preparedPayment = WC_Payxpert_Webservice::preparePayment(
            $this->configuration,
            strtolower(__CLASS__),
            PaymentMethod::CREDIT_CARD,
            PaymentMode::SINGLE,
            $order,
            [],
            false
        );

        return parent::get_seamless_data($preparedPayment);
    }

    public function process_payment( $order_id ) {
        return $this->process_payment_cc(
            strtolower(__CLASS__),
            $order_id, 
            PaymentMethod::CREDIT_CARD, 
            PaymentMode::SINGLE,
            []
        );
    }

    /**
     * Callback URL
     */
    public function callback() 
    {
        parent::callback();
    }
}
