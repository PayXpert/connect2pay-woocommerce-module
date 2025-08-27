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
        $this->icon          = plugins_url('assets/img/payment_method_logo/cc.jpg', WC_PAYXPERT_PLUGIN_NAME);

        $this->title        = $this->configuration[$this->labelKey] ?? $this->method_title;
        $this->enabled      = $this->configuration[$this->enabledKey];
        $this->has_fields   = true;

        add_action('woocommerce_api_' . strtolower(__CLASS__), [$this, 'callback']);
        add_action('wp_enqueue_scripts', [$this, 'add_scripts']);
    }

    public function payment_fields() {
        echo $this->get_payment_fields();
    }

    public function get_payment_fields() {
        global $wp;
        $fields = null;
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
        include dirname(__FILE__, 3) . '/templates/views/html-payment-installment.php';
        $fields = ob_get_clean();

        if ($this->seamless) {
            $order = null;
            if (isset($wp->query_vars['order-pay'])) {
                $order = wc_get_order(absint($wp->query_vars['order-pay']));
            }

            $seamlessData = $this->get_seamless_data($order);
            if (isset($seamlessData['error'])) {
                return esc_html($seamlessData['error']);
            }

            ob_start(); ?>
            <div class="payxpert-seamless-container"
                data-method="<?php echo esc_attr($this->id); ?>"
                data-customer-token="<?php echo esc_attr($seamlessData['customerToken']); ?>"
                data-language="<?php echo esc_attr($seamlessData['language']); ?>"
                data-seamless-submit="<?php echo esc_attr($seamlessData['seamlessSubmit']); ?>"
                style="width:100%"
            >
                <div class="payxpert-placeholder"></div>
                <input type="hidden" class="payxpert_nonce" value="<?php echo esc_attr($seamlessData['nonce']); ?>">
                <input type="hidden" class="merchantToken" value="<?php echo esc_attr($seamlessData['merchantToken']); ?>" name="<?php echo esc_attr($this->id); ?>_merchant_token"/>
                <input type="hidden" class="customerToken" value="<?php echo esc_attr($seamlessData['customerToken']); ?>" name="<?php echo esc_attr($this->id); ?>_customer_token"/>
                <button type="button" class="payxpert_seamless_hidden_submit" id="<?php echo esc_attr($seamlessData['seamlessSubmit']); ?>" style="display:none" >Payer </button>
            </div>
            <?php
            $fields .= ob_get_clean();
        }

        return $fields;
    }

    public function get_seamless_data($order) {
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

        return parent::get_seamless_data($preparedPayment);
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
