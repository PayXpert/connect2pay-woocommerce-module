<?php

namespace Payxpert\Utils;

defined( 'ABSPATH' ) || exit();

class WC_Payxpert_Install {

    public static function install() {
		WC_Payxpert_Logger::debug('install');

        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $tables = [
            'payxpert_payment_transaction' => "CREATE TABLE {$wpdb->prefix}payxpert_payment_transaction (
                id_payxpert_payment_transaction INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                id_shop INT NOT NULL,
                transaction_id VARCHAR(128) NOT NULL,
                transaction_referal_id VARCHAR(128),
                order_id INT UNSIGNED NOT NULL,
                payment_id VARCHAR(50) NOT NULL,
                liability_shift BOOLEAN DEFAULT FALSE,
                payment_method VARCHAR(50) NOT NULL,
                operation VARCHAR(50) NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                currency VARCHAR(3) NOT NULL,
                result_code VARCHAR(10) NOT NULL,
                result_message TEXT NOT NULL,
                date_add DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                order_slip_id INT DEFAULT NULL,
                subscription_id INT DEFAULT NULL,
                UNIQUE KEY idx_transaction_id (transaction_id),
                INDEX idx_order_id (order_id),
                INDEX idx_payment_id (payment_id),
                INDEX idx_id_shop (id_shop),
                INDEX idx_subscription_id (subscription_id)
            ) $charset_collate;",

            'payxpert_payment_token' => "CREATE TABLE {$wpdb->prefix}payxpert_payment_token (
                id_payxpert_payment_token INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                merchant_token VARCHAR(50) NOT NULL,
                customer_token VARCHAR(50) NOT NULL,
                date_add DATETIME DEFAULT NOW(),
                user_id INT NOT NULL,
                order_id INT NOT NULL,
                is_paybylink BOOLEAN DEFAULT 0,
                INDEX idx_customer_token (customer_token),
                INDEX idx_merchant_token (merchant_token),
                INDEX idx_is_paybylink (is_paybylink)
            ) $charset_collate;",

            'payxpert_subscription' => "CREATE TABLE {$wpdb->prefix}payxpert_subscription (
                id_payxpert_subscription INT UNSIGNED NOT NULL AUTO_INCREMENT,
                customer_id INT NOT NULL,
                subscription_id INT NOT NULL,
                subscription_type VARCHAR(32) NOT NULL,
                offer_id INT UNSIGNED DEFAULT 0,
                transaction_id VARCHAR(64) NOT NULL,
                amount INT NOT NULL,
                period VARCHAR(32) DEFAULT NULL,
                trial_amount INT DEFAULT NULL,
                trial_period VARCHAR(32) DEFAULT NULL,
                state VARCHAR(32) NOT NULL,
                subscription_start INT UNSIGNED DEFAULT 0,
                period_start INT UNSIGNED DEFAULT 0,
                period_end INT UNSIGNED DEFAULT 0,
                cancel_date INT UNSIGNED DEFAULT 0,
                cancel_reason TEXT DEFAULT NULL,
                iterations INT DEFAULT 0,
                iterations_left INT DEFAULT 0,
                retries INT DEFAULT 0,
                date_add DATETIME NOT NULL DEFAULT NOW(),
                date_upd DATETIME NOT NULL,
                INDEX idx_customer_id (customer_id),
                PRIMARY KEY (id_payxpert_subscription),
                UNIQUE KEY uniq_subscription_id (subscription_id)
            ) $charset_collate;",

            'payxpert_cron_log' => "CREATE TABLE {$wpdb->prefix}payxpert_cron_log (
                id_payxpert_cron_log INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                cron_type TINYINT NOT NULL,
                date_add DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                duration FLOAT DEFAULT NULL,
                status TINYINT DEFAULT 0,
                context TEXT,
                has_error TINYINT DEFAULT 0,
                INDEX idx_date_add (date_add),
                INDEX idx_cron_type (cron_type)
            ) $charset_collate;",
        ];

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        foreach ($tables as $key => $sqlRequest) {
            dbDelta($sqlRequest);

            $last_error = $wpdb->last_error;

            if (!empty($last_error)) {
                WC_Payxpert_Logger::critical("Error while upserting table `{$key}` : {$last_error}");
                wp_die(
                    __('Error creating tables. Please check the logs.'),
                    __('Installation Error')
                );
            }
        }

        flush_rewrite_rules();
    }

    public static function deactivate() {
		WC_Payxpert_Logger::debug('deactivate');
    }
}
