<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Payxpert\Models\Payxpert_Payment_Transaction;

defined('ABSPATH') || exit;

abstract class WC_Payment_Gateway_Payxpert_Installment_Blocks_Support extends AbstractPaymentMethodType {
    protected $gateway;
    protected $name;

    public function initialize() {
        $this->settings = get_option("woocommerce_{$this->name}_settings", array());
        $gateways = WC()->payment_gateways->payment_gateways();
        $this->gateway = $gateways[$this->name] ?? null;
    }

    public function is_active() {
        return $this->gateway && $this->gateway->is_available();
    }

    public function get_payment_method_script_handles() {
        $script_name = $this->name;
        $plugin_dir = dirname(__DIR__, 2);

        $script_asset_path = $plugin_dir . '/assets/js/build/blocks/' . $script_name . '.asset.php';

        $script_asset = file_exists($script_asset_path)
            ? require $script_asset_path
            : ['dependencies' => [], 'version' => '1.0.0'];

        $script_url = plugin_dir_url($plugin_dir . '/payxpert.php') . 'assets/js/build/blocks/' . $script_name . '.js';

        wp_register_script(
            'wc-' . $this->name . '-blocks-integration',
            $script_url,
            $script_asset['dependencies'],
            $script_asset['version'],
            true
        );

        return ['wc-' . $this->name . '-blocks-integration'];
    }

    public function get_payment_method_data() {
        $description = $this->gateway->get_payment_fields();
        $seamlessData = null;

        if ($this->gateway->seamless) {
            global $wp;
            $order = null;

            if (isset($wp->query_vars['order-pay'])) {
                $order_id = absint($wp->query_vars['order-pay']);
                $order = wc_get_order($order_id);
            }

            $seamlessData = $this->gateway->get_seamless_data($order);
            if (isset($seamlessData['error'])) {
                $description = $seamlessData['error'];
            }
        }

        $plugin_dir = dirname(__DIR__, 2);
        $paymentMethodLogoPath = plugin_dir_url($plugin_dir . '/payxpert.php') . 'assets/img/payment_method_logo';
        $icons = [Payxpert_Payment_Transaction::VISA_ICON, Payxpert_Payment_Transaction::MASTERCARD_ICON];

        if ($this->gateway->configuration['payxpert_amex_enabled'] === 'yes') {
            $icons[] = Payxpert_Payment_Transaction::AMEX_ICON;
        }

        return array(
            'title'       => $this->get_setting('title', $this->gateway->title),
            'description' => $description,
            'seamless'    => $seamlessData,
            'supports'    => array_filter($this->gateway->supports, [$this->gateway, 'supports']),
            'icons'       => $icons,
            'icon_path'   => $paymentMethodLogoPath,
        );
    }
}
