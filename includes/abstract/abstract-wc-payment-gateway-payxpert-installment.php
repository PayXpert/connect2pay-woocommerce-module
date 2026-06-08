<?php

use PayXpert\Connect2Pay\containers\constant\PaymentMethod;
use PayXpert\Connect2Pay\containers\constant\PaymentMode;
use Payxpert\Utils\WC_Payxpert_Utils;
use Payxpert\Utils\WC_Payxpert_Webservice;
use Payxpert\WC_Payxpert;

defined('ABSPATH') || exit;

abstract class WC_Payment_Gateway_Payxpert_Installment extends WC_Payment_Gateway_Payxpert {
    protected $xTimes;
    protected $percentageKey;
    protected $labelKey;
    protected $enabledKey;
    
    public $seamless = false;
    public $configuration;
    
    public function __construct($gateway_id) {
        $this->id = $gateway_id;
        $this->method_description = __('Credit card gateway.', 'payxpert');
        
        $this->supports = [
            'products', 
            'pay_for_order',
            'refunds',
        ];
        
        $this->percentageKey = $gateway_id . '_percentage';
        $this->labelKey      = $gateway_id . '_label';
        $this->enabledKey    = $gateway_id . '_enabled';
        
        $this->init_form_fields();
        $this->init_settings();
        
        $this->configuration = WC_Payxpert_Utils::get_configuration();
        $this->seamless      = ($this->configuration['payxpert_redirect_mode'] == WC_Payxpert::REDIRECT_MODE_SEAMLESS);
        $this->icon          = WC_Payxpert_Utils::get_gateway_icon_name($this->configuration, $this->id);
        $this->title        = $this->configuration[$this->labelKey] ?? $this->method_title;
        $this->enabled      = $this->configuration[$this->enabledKey];
        $this->has_fields   = true;
        
        add_action('woocommerce_api_' . strtolower(__CLASS__), [$this, 'callback']);
        add_action('wp_enqueue_scripts', [$this, 'add_scripts']);
    }
    
    public function payment_fields() {
        echo $this->get_payment_fields();
    }
    
    /**
     * Schedule + template HTML only (no preparePayment).
     */
    public function get_installment_schedule_html(): string {
        global $wp;
        $amount = 0;
        
        if (isset($wp->query_vars['order-pay'])) {
            $order = wc_get_order(absint($wp->query_vars['order-pay']));
            $amount = $order->get_total();
        } elseif (isset(WC()->cart)) {
            $amount = WC()->cart->get_total('edit');
        }
        
        $schedule = WC_Payxpert_Utils::build_instalment_schedule(
            $amount,
            $this->configuration[$this->percentageKey],
            $this->xTimes
        );
        
        ob_start();
        include dirname(__FILE__, 3) . '/templates/views/html-admin-payment-installment.php';
        
        return (string) ob_get_clean();
    }
    
    public function get_payment_fields() {
        global $wp;
        $fields = $this->get_installment_schedule_html();
        
        if ($this->seamless) {
            $order = null;
            if (isset($wp->query_vars['order-pay'])) {
                $order = wc_get_order(absint($wp->query_vars['order-pay']));
            }
            
            $seamlessData = $this->get_seamless_data($order);
            if (isset($seamlessData['error'])) {
                return esc_html($seamlessData['error']);
            }
            
            $fields .= $this->get_seamless_payment_fields_html($seamlessData, 'style="width:100%"');
        }
        
        return $fields;
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
            PaymentMode::INSTALMENTS,
            $order,
            [
                'firstPercentage' => $this->configuration[$this->percentageKey],
                'xTimes' => $this->xTimes
            ],
            false
        );
        
        return $this->assemble_seamless_data( $preparedPayment, $order );
    }
    
    public function is_available() {
        global $wp;
        
        if (!is_user_logged_in()) {
            return false;
        }
        
        $amount = 0;
        
        if (isset($wp->query_vars['order-pay'])) {
            $order = wc_get_order(absint($wp->query_vars['order-pay']));
            $amount = $order->get_total();
        } elseif (isset(WC()->cart)) {
            $amount = WC()->cart->get_total('edit');
        }
        
        if ($amount < $this->configuration['payxpert_instalment_payment_min_amount']) {
            return false;
        }
        
        return parent::is_available();
    }
    
    public function process_payment($order_id) {
        return $this->process_payment_cc(
            strtolower(__CLASS__),
            $order_id,
            PaymentMethod::CREDIT_CARD,
            PaymentMode::INSTALMENTS,
            [
                'firstPercentage' => $this->configuration[$this->percentageKey],
                'xTimes' => $this->xTimes
            ]
        );
    }
        
    public function callback() {
        parent::callback();
    }
}
