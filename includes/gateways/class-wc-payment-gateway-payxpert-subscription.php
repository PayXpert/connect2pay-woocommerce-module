<?php

use PayXpert\Connect2Pay\containers\constant\PaymentMethod;
use PayXpert\Connect2Pay\containers\constant\PaymentMode;
use Payxpert\Utils\WC_Payxpert_Utils;
use Payxpert\Utils\WC_Payxpert_Webservice;
use Payxpert\WC_Payxpert;

defined('ABSPATH') || exit;

class WC_Payment_Gateway_Payxpert_Subscription extends WC_Payment_Gateway_Payxpert {
    const ID = 'payxpert_subscription';
    
    public $configuration;
    
    public $seamless = false;
    
    public function __construct() {
        $this->id                  = self::ID;
        $this->method_title        = __( 'Subscription per Credit Cards by PayXpert', 'payxpert' );
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
        $subscriptionLabel  = isset($this->configuration['payxpert_subscription_label'])
        ? trim((string) $this->configuration['payxpert_subscription_label'])
        : '';
        if ($subscriptionLabel !== '') {
            $subscriptionLabel = WC_Payxpert_Utils::translate_custom_text(
                $subscriptionLabel,
                'payxpert_subscription_label'
            );
        }
        $this->title        = $subscriptionLabel !== '' ? $subscriptionLabel : $this->method_title;
        $this->enabled      = $this->configuration['payxpert_subscription_enabled'];
        
        $this->seamless = ($this->configuration['payxpert_redirect_mode'] == WC_Payxpert::REDIRECT_MODE_SEAMLESS);
        $this->has_fields = true;
        $this->icon = plugins_url('assets/img/payment_method_logo/cc.jpg', WC_PAYXPERT_PLUGIN_NAME);
        
        // Settings save
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        
        // IPN + IFRAME
        add_action('woocommerce_api_' . strtolower(__CLASS__), array($this, 'callback'));
        
        add_action('wp_enqueue_scripts', array( $this, 'add_scripts' ));
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
        
        $subscription_in_cart = WC_Payxpert_Utils::has_subscription_in_cart(WC()->cart->get_cart(), true);
        
        if ($subscription_in_cart == false) {
            return null;
        }
        
        $preparedPayment = WC_Payxpert_Webservice::preparePayment(
            $this->configuration,
            strtolower(__CLASS__),
            PaymentMethod::CREDIT_CARD,
            PaymentMode::RECURRENT,
            $order,
            [],
            false,
            true
        );
        
        return $this->assemble_seamless_data( $preparedPayment, $order );
    }
    
    public function get_payment_fields() {
        $fields = '';
        
        $cart = WC()->cart;
        if (!$cart || $cart->is_empty()) {
            return parent::get_payment_fields();
        }
        
        foreach ($cart->get_cart() as $cart_item) {
            /** @var WC_Product $product */
            $product = $cart_item['data'];
            $product_id = $product->get_id();
            
            $frequency = get_post_meta($product_id, WC_Payxpert::SUBSCRIPTION_FREQUENCY, true);
            // Skip non-subscription products.
            if (empty($frequency)) {
                continue;
            }
            
            $price = (int) $cart_item['quantity'] * get_post_meta($product_id, WC_Payxpert::SUBSCRIPTION_PRICE, true);
            
            $shipping_amount = WC()->cart ? (WC()->cart->get_shipping_total() + WC()->cart->get_shipping_tax()) : 0;
            $shipping_text = $shipping_amount > 0 ? sprintf(__('+ %s shipping cost', 'payxpert'), wc_price($shipping_amount) . $product->get_price_suffix()) : '';
            $price_html = wc_price($price) . $product->get_price_suffix();
            $price_html .= $shipping_text;
            
            $subscription_data = [
                'frequency' => $frequency,
                'price_html' => $price_html,
                'first_payment' => WC_Payxpert_Utils::get_subscription_first_payment_text(
                    get_post_meta($product_id, WC_Payxpert::SUBSCRIPTION_FIRST_PAYMENT, true),
                    $frequency,
                    get_post_meta($product_id, WC_Payxpert::SUBSCRIPTION_DURATION_TYPE, true),
                    get_post_meta($product_id, WC_Payxpert::SUBSCRIPTION_MAX_CYCLES, true),
                ),
            ];
            
            $fields .= WC_Payxpert::render_subscription_box($subscription_data);
            
            // Single subscription product per cart.
            break;
        }
        return $fields . parent::get_payment_fields();
    }
    
    public function process_payment( $order_id ) {
        return $this->process_payment_cc(
            strtolower(__CLASS__),
            $order_id, 
            PaymentMethod::CREDIT_CARD, 
            PaymentMode::RECURRENT,
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
