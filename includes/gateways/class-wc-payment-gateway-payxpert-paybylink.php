<?php

use Payxpert\Utils\WC_Payxpert_Utils;

defined('ABSPATH') || exit;

class WC_Payment_Gateway_Payxpert_Paybylink extends WC_Payment_Gateway_Payxpert {
    const ID = 'payxpert_paybylink';

    public $configuration;

    public function __construct() {
        $this->id                  = self::ID;
		$this->method_title        = __( 'PayByLink by PayXpert', 'payxpert' );
		$this->method_description  = __( 'Credit card gateway.', 'payxpert' );

        $this->supports = array();

        // Load the settings
        $this->init_form_fields();
        $this->init_settings();

        $this->configuration = WC_Payxpert_Utils::get_configuration();

        // Define user settings
        $this->title        = $this->method_title;
        $this->description  = '';
        $this->enabled      = $this->configuration['payxpert_paybylink_enabled'];
        $this->has_fields   = false;
        $this->icon         = plugins_url('assets/img/payment_method_logo/cc.jpg', WC_PAYXPERT_PLUGIN_NAME);
    }


    public function is_available() {
        if (is_admin() || defined('REST_REQUEST')) {
            return true;
        }
        return false;
    }

    public function payment_fields() {
        // Ne rien afficher
    }

    public function process_payment($order_id) {
        $order = wc_get_order($order_id);

        $link = '';

        // Ajoute une note avec le lien
        $order->add_order_note(__('Payment Link generated : ', 'payxpert') . $link);
        $order->update_meta_data('_paybylink_url', $link);
        $order->save();

        // Ne pas rediriger vers paiement mais vers une page d’admin
        return [
            'result' => 'success',
            'redirect' => admin_url('post.php?post=' . $order_id . '&action=edit'),
        ];
    }


}
