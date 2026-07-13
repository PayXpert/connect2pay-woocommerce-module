<?php
/**
 * Uninstall.
 *
 * @package Payxpert
 */

/**
 * Fired when the plugin is uninstalled (deleted).
 *
 * @package Payxpert
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit();

global $wpdb;

$payxpert_tables = array(
	'payxpert_payment_transaction',
	'payxpert_payment_token',
	'payxpert_subscription',
	'payxpert_cron_log',
);

foreach ( $payxpert_tables as $payxpert_table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is hardcoded.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$payxpert_table}" );
}

// Options created by this plugin (prefix payxpert_).
$payxpert_like = $wpdb->esc_like( 'payxpert_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $payxpert_like ) );

wp_clear_scheduled_hook( 'payxpert_sync_subscriptions_cron' );
wp_clear_scheduled_hook( 'payxpert_cleanup_payment_tokens_cron' );
