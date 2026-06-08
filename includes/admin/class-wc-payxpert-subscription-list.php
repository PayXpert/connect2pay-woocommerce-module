<?php

use Payxpert\Models\Payxpert_Subscription;
use Payxpert\Utils\WC_Payxpert_Logger;
use Payxpert\Utils\WC_Payxpert_Utils;
use Payxpert\WC_Payxpert;

class WC_Payxpert_Subscription_List {
    
    /**
     * Singleton instance holder.
     */
    protected static $instance = null;
    
    /**
     * Returns the singleton instance.
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
        
        add_action('admin_post_payxpert_cancel_subscription', [$this, 'action_cancel_subscription']); 
    }
    
    /**
     * Registers the PayXpert admin submenu under WooCommerce.
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
        $per_page = 10;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        
        $filters = array(
            'status'    => isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '',
            'date_from' => isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '',
            'date_to'   => isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '',
            'customer'  => isset($_GET['customer']) ? sanitize_text_field($_GET['customer']) : '',
        );
        
        $results = Payxpert_Subscription::get_all_with_transaction_counts(
            $per_page,
            ($current_page - 1) * $per_page,
            $filters
        );
        $this->formatResults($results['items']);
        
        // Used in template
        include WC_PAYXPERT_PLUGIN_FILE_PATH . 'templates/views/html-admin-subscription-list.php';
    }
    
    public function action_cancel_subscription() {
        if ( !current_user_can('manage_woocommerce') ) {
            wp_die('No permission');
        }
        
        $subscription_id = sanitize_text_field($_POST['subscription_id']);
        $reason_id       = sanitize_text_field($_POST['payxpert_cancel_reason']);
        $nonce           = $_POST['payxpert_cancel_nonce'] ?? '';
        
        if ( ! wp_verify_nonce($nonce, 'payxpert_cancel_subscription') ) {
            set_transient('payxpert_admin_error', __('Security check failed.', 'payxpert'), 30);
            wp_safe_redirect( admin_url('admin.php?page=payxpert-subscription-list') );
            exit;
        }
        
        $result = WC_Payxpert::cancel_subscription($subscription_id, get_current_user_id(), $reason_id, true);
        
        if ($result['success']) {
            WC_Payxpert_Logger::info('Admin cancelled subscription ID ' . $subscription_id);
            set_transient('payxpert_admin_success', $result['message'], 30);
        } else {
            WC_Payxpert_Logger::error('Admin failed to cancel subscription ID ' . $subscription_id . ' : ' . $result['message']);
            set_transient('payxpert_admin_error', $result['message'], 30);
        }
        
        wp_safe_redirect(admin_url('admin.php?page=payxpert-subscription-list'));
        exit;
    }
    
    
    protected function formatResults(&$results)
    {
        if (empty($results) || !is_array($results)) {
            return;
        }
        
        foreach ($results as &$sub) {
            $sub->subscription_type_label = ($sub->subscription_type === 'partpayment' ? __( 'Installment', 'payxpert' ) : __( 'Subscription', 'payxpert' ));     
            $sub->state = (string) __($sub->state, 'payxpert');
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
            $sub->iterations_left = ($sub->iterations_left == 0 ? '∞' : (string)$sub->iterations_left);
            $sub->retries = (string)$sub->retries;
        }
    }
}
