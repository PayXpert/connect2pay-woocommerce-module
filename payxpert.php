<?php
/*
 * Plugin Name: PayXpert payment gateway
 * Description:
 * Author: We+
 * Author URI: https://alan-allman.com/en/cabinet/we/
 * Version: 2.0.0
 * Requires Plugins: woocommerce
 * WC requires at least: 7.6.0
 */
defined( 'ABSPATH' ) || exit ();

define( 'WC_PAYXPERT_PLUGIN_FILE_PATH', plugin_dir_path( __FILE__ ) );
define( 'WC_PAYXPERT_ASSETS', plugin_dir_url( __FILE__ ) . 'assets/' );
define( 'WC_PAYXPERT_PLUGIN_NAME', plugin_basename( __FILE__ ) );
define( 'WC_PAYXPERT_VERSION', '2.0.0' );

require_once( WC_PAYXPERT_PLUGIN_FILE_PATH . 'vendor/autoload.php' );

use Payxpert\Utils\WC_Payxpert_Cron;
use Payxpert\Utils\WC_Payxpert_Install;

// Install
register_activation_hook(__FILE__, [WC_Payxpert_Install::class, 'install']);
register_deactivation_hook(__FILE__, [WC_Payxpert_Install::class, 'deactivate']);

// CRON
register_activation_hook(__FILE__, [WC_Payxpert_Cron::class, 'install']);
register_deactivation_hook(__FILE__, [WC_Payxpert_Cron::class, 'deactivate']);

require_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/class-wc-payxpert.php';
