<?php

use PayXpert\Connect2Pay\containers\constant\PaymentMethod;
use PayXpert\Connect2Pay\containers\constant\PaymentMode;
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
        $this->has_fields = $this->seamless;
        $this->icon = WC_Payxpert_Utils::get_gateway_icon_name($this->configuration, $this->id);
        
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
        return parent::get_payment_fields();
    }
    
    /**
     * @param \WC_Order|null $order
     * @return array<string, mixed>|null
     */
    public function get_seamless_data( $order = null ) {
        if (!is_checkout()) {
            // Avoid fatal error on checkout editor page
            return null;
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
        
        return $this->assemble_seamless_data( $preparedPayment, $order );
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
