<?php

use Payxpert\Models\Payxpert_Payment_Transaction;

class WC_Payxpert_Transaction_List {

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
        add_action('admin_menu', [$this, 'add_submenu_page'], 101);

        // Loading assets
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Ajoute le sous-menu PayXpert dans WooCommerce > Extensions
     */
    public function add_submenu_page() {
        add_submenu_page(
            'woocommerce',
            __('PayXpert Transactions List', 'payxpert'),
            __('PayXpert - Transaction', 'payxpert'),
            'manage_woocommerce',
            'payxpert-transaction-list',
            [$this, 'render_settings_page']
        );
    }

    public function enqueue_assets($hook) {
        if (isset($_GET['page']) && $_GET['page'] === 'payxpert-transaction-list') {
            wp_enqueue_style('payxpert-admin-settings-style', WC_PAYXPERT_ASSETS . 'css/admin-settings.css', [], WC_PAYXPERT_VERSION);
            wp_enqueue_script('payxpert-admin-settings-script', WC_PAYXPERT_ASSETS . 'js/src/admin-settings.js', [], WC_PAYXPERT_VERSION);
        }
    }

    public function render_settings_page() {
        global $wpdb;

        $per_page = 20;

        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;

        // Récupérer transactions paginées
        $transactions = Payxpert_Payment_Transaction::get_all_paginated($per_page, $offset);
        $total_items = (int)$wpdb->get_var("SELECT FOUND_ROWS()");

        include WC_PAYXPERT_PLUGIN_FILE_PATH . 'templates/views/html-transaction-list.php';
    }

}
