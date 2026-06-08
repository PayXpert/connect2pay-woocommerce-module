<?php

use Payxpert\Models\Payxpert_Subscription;
use Payxpert\Utils\WC_Payxpert_Utils;

class WC_Payxpert_Subscription_Detail {
    
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
    }
    
    /**
     * Registers the PayXpert admin submenu under WooCommerce.
     */
    public function add_submenu_page() {
        add_submenu_page(
            null,
            __('PayXpert Subscription Detail', 'payxpert'),
            __('PayXpert - Subscription Detail', 'payxpert'),
            'manage_woocommerce',
            'payxpert-subscription-detail',
            [$this, 'render_settings_page']
        );
    }
    
    public function enqueue_assets($hook) {
        if (isset($_GET['page']) && $_GET['page'] === 'payxpert-subscription-detail') {
            wp_enqueue_style('payxpert-admin-settings-style', WC_PAYXPERT_ASSETS . 'css/admin-settings.css', [], WC_PAYXPERT_VERSION);
            wp_enqueue_script('payxpert-admin-settings-script', WC_PAYXPERT_ASSETS . 'js/src/admin-settings.js', [], WC_PAYXPERT_VERSION);
        }
    }
    
    public function render_settings_page() {
        if ( empty($_GET['id']) ) {
            echo '<p>' . __('No subscription ID provided.', 'payxpert') . '</p>';
            return;
        }
        
        $subscription_id = absint($_GET['id']);
        $sub = Payxpert_Subscription::get_subscription_details($subscription_id);
        
        if ( ! $sub ) {
            echo '<p>' . __('Subscription not found.', 'payxpert') . '</p>';
            return;
        }
        
        foreach ($sub->transactions as &$trans) {
            $timestamp = strtotime($trans->date_add); // string to timestamp
            $trans->date_add_formatted = date_i18n(get_option('date_format'), $timestamp);
        }
        
        include WC_PAYXPERT_PLUGIN_FILE_PATH . 'templates/views/html-admin-subscription-detail.php';
    }
}
