<?php

use Payxpert\Models\Payxpert_Subscription;
use Payxpert\Utils\WC_Payxpert_Utils;

class WC_Payxpert_Subscription_List {

    /**
     * Instance singleton
     */
    protected static $instance = null;

    /**
     * Retourne l'instance
     */
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Add sub-menu in WooCommerce > Extensions
        add_action('admin_menu', [$this, 'add_submenu_page'], 102);

        // Loading assets
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Ajoute le sous-menu PayXpert dans WooCommerce > Extensions
     */
    public function add_submenu_page() {
        add_submenu_page(
            'woocommerce',
            __('PayXpert Subscriptions List', 'payxpert'),
            __('PayXpert - Subscription', 'payxpert'),
            'manage_woocommerce',
            'payxpert-subscription-list',
            [$this, 'render_settings_page']
        );
    }

    public function enqueue_assets($hook) {
        if (isset($_GET['page']) && $_GET['page'] === 'payxpert-subscription-list') {
            wp_enqueue_style('payxpert-admin-settings-style', WC_PAYXPERT_ASSETS . 'css/admin-settings.css', [], WC_PAYXPERT_VERSION);
            wp_enqueue_script('payxpert-admin-settings-script', WC_PAYXPERT_ASSETS . 'js/src/admin-settings.js', [], WC_PAYXPERT_VERSION);
        }
    }

    public function render_settings_page() {
        global $wpdb;

        $per_page = 20;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;

        $results = Payxpert_Subscription::get_all_with_transaction_counts($per_page, $offset);
        $total_items = (int)$wpdb->get_var("SELECT FOUND_ROWS()");
        $this->formatResults($results);

        include WC_PAYXPERT_PLUGIN_FILE_PATH . 'templates/views/html-subscription-list.php';
    }

    protected function formatResults(&$results)
    {
        if (empty($results) || !is_array($results)) {
            return;
        }

        foreach ($results as &$sub) {
            $sub->subscription_type_label = $sub->subscription_type === 'partpayment' ? 'Installment' : 'Subscription';
            $sub->state = (string)$sub->state;
            $sub->trial_amount_formatted = wc_price($sub->trial_amount / 100);
            $sub->amount_formatted = wc_price($sub->amount / 100);
            $sub->period = WC_Payxpert_Utils::render_human_period($sub->period);
            $sub->period_start_formatted = !empty($sub->period_start)
                ? date_i18n(get_option('date_format'), $sub->period_start)
                : ($sub->state == 'finished' 
                ? date_i18n(get_option('date_format'), $sub->cancel_date)
                : '-'
            );
            $sub->period_end_formatted = !empty($sub->period_end)
                ? date_i18n(get_option('date_format'), $sub->period_end)
                : '-';
            $sub->sale_transactions_count = (string)$sub->sale_transactions_count;
            $sub->iterations_left = (string)$sub->iterations_left;
            $sub->retries = (string)$sub->retries;
        }
    }
}
