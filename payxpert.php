<?php
/*
 * Plugin Name: PayXpert payment gateway
 * Plugin URI: https://www.payxpert.com/
 * Description: Accept card payments and instalments via PayXpert (Connect2Pay) in WooCommerce.
 * Author: Payxpert
 * Author URI: https://www.payxpert.com/
 * Version: 2.3.1
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 7.6.0
 * License: MIT License
 * License URI: https://opensource.org/license/mitl
 * Text Domain: payxpert
 * Domain Path: /languages
 */
defined( 'ABSPATH' ) || exit ();

define( 'WC_PAYXPERT_PLUGIN_FILE_PATH', plugin_dir_path( __FILE__ ) );
define( 'WC_PAYXPERT_ASSETS', plugin_dir_url( __FILE__ ) . 'assets/' );
define( 'WC_PAYXPERT_PLUGIN_NAME', plugin_basename( __FILE__ ) );
define( 'WC_PAYXPERT_VERSION', '2.3.1' );
define( 'WC_PAYXPERT_DB_VERSION', '1.1.0' );

require_once( WC_PAYXPERT_PLUGIN_FILE_PATH . 'vendor/autoload.php' );

use Payxpert\Utils\WC_Payxpert_Cron;
use Payxpert\Utils\WC_Payxpert_Install;

// Install
register_activation_hook(__FILE__, [WC_Payxpert_Install::class, 'install']);
register_deactivation_hook(__FILE__, [WC_Payxpert_Install::class, 'deactivate']);

// CRON
register_activation_hook(__FILE__, [WC_Payxpert_Cron::class, 'install']);
register_deactivation_hook(__FILE__, [WC_Payxpert_Cron::class, 'deactivate']);
add_action(
    'plugins_loaded',
    static function (): void {
        WC_Payxpert_Cron::install();
    },
    20
);

// Fix 404 not found on endpoint when module is deactivated - reactivated
register_activation_hook(__FILE__, function() {
    // Register subscription endpoints.
    add_rewrite_endpoint('payxpert-subscription-list', EP_ROOT | EP_PAGES);
    add_rewrite_endpoint('payxpert-subscription-details', EP_ROOT | EP_PAGES);
    
    // Flush rewrite rules.
    flush_rewrite_rules();
});

require_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/class-wc-payxpert.php';
