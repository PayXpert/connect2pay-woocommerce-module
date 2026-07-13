<?php
/**
 * Wc Payxpert Install.
 *
 * @package Payxpert
 */

namespace Payxpert\Utils;

defined( 'ABSPATH' ) || exit();

/**
 * WC Payxpert Install.
 */
class WC_Payxpert_Install {

	/**
	 * Install.
	 *
	 * @return mixed
	 */
	public static function install() {
		WC_Payxpert_Logger::debug( 'install' );

		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$tables = array(
			'payxpert_payment_transaction' => "CREATE TABLE {$wpdb->prefix}payxpert_payment_transaction (
                id_payxpert_payment_transaction INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                id_shop INT NOT NULL,
                transaction_id VARCHAR(128) NOT NULL,
                transaction_referal_id VARCHAR(128),
                order_id INT UNSIGNED NOT NULL,
                payment_id VARCHAR(50) DEFAULT NULL,
                liability_shift BOOLEAN DEFAULT FALSE,
                payment_method VARCHAR(50) NOT NULL,
                operation VARCHAR(50) NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                currency VARCHAR(3) NOT NULL,
                result_code VARCHAR(10) NOT NULL,
                result_message TEXT NOT NULL,
                card_number VARCHAR(32) DEFAULT NULL,
                card_brand VARCHAR(32) DEFAULT NULL,
                date_add DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                order_slip_id INT DEFAULT NULL,
                subscription_id INT DEFAULT NULL,
                UNIQUE KEY idx_transaction_id (transaction_id),
                INDEX idx_order_id (order_id),
                INDEX idx_payment_id (payment_id),
                INDEX idx_id_shop (id_shop),
                INDEX idx_subscription_id (subscription_id)
            ) $charset_collate;",

			'payxpert_payment_token'       => "CREATE TABLE {$wpdb->prefix}payxpert_payment_token (
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

			'payxpert_subscription'        => "CREATE TABLE {$wpdb->prefix}payxpert_subscription (
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

			'payxpert_cron_log'            => "CREATE TABLE {$wpdb->prefix}payxpert_cron_log (
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
		);

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( $tables as $key => $sql_request ) {
			dbDelta( $sql_request );

			$last_error = $wpdb->last_error;

			if ( ! empty( $last_error ) ) {
				WC_Payxpert_Logger::critical( "Error while upserting table `{$key}` : {$last_error}" );
				wp_die(
					esc_html__( 'Error creating tables. Please check the logs.', 'payxpert' ),
					esc_html__( 'Installation Error', 'payxpert' )
				);
			}
		}

		flush_rewrite_rules();
		WC_Payxpert_Utils::ensure_ipn_secret();
		update_option( 'wc_payxpert_plugin_version', WC_PAYXPERT_VERSION );
	}

	// This function is used only to set dynamic translation
	// No need to call it.
	/**
	 * Dynamic translations.
	 *
	 * @return mixed
	 */
	public static function dynamic_translations() {
		// Subscription status.
		__( 'finished', 'payxpert' );
		__( 'disabled', 'payxpert' );
		__( 'active', 'payxpert' );

		// Subscription type.
		__( 'normal', 'payxpert' );
		__( 'partpayment', 'payxpert' );
		__( 'lifetime', 'payxpert' );
		__( 'onetime', 'payxpert' );
		__( 'infinite', 'payxpert' );

		// Transaction status.
		__( 'success', 'payxpert' );
		__( 'failure', 'payxpert' );

		// Cancel reasons.
		__( 'Didn’t like the site', 'payxpert' );
		__( 'Disagree (‘did not do it’ or ‘do not recognize the transaction’)', 'payxpert' );
		__( 'I could not get in to the site', 'payxpert' );
		__( 'No problem, just moving on', 'payxpert' );
		__( 'Not enough updates', 'payxpert' );
		__( 'Problems with the movies/videos', 'payxpert' );
		__( 'Site was too slow', 'payxpert' );
		__( 'The site did not work', 'payxpert' );
		__( 'Too expensive', 'payxpert' );
		__( 'Un-authorized signup by family member', 'payxpert' );
		__( 'Webmaster requested to cancel', 'payxpert' );
		__( 'I haven’t received my item', 'payxpert' );
		__( 'The item was damaged or defective', 'payxpert' );
		__( 'The box was empty', 'payxpert' );
		__( 'The order was incomplete', 'payxpert' );
		__( 'Bank denial', 'payxpert' );
		__( 'Canceled due to refund', 'payxpert' );
		__( 'Canceled due to retrieval request', 'payxpert' );
		__( 'Cancellation letter sent by bank', 'payxpert' );
		__( 'Chargeback', 'payxpert' );
		__( 'Company account closed', 'payxpert' );
		__( 'Site account closed', 'payxpert' );
		__( 'Fraud from webmaster', 'payxpert' );
		__( 'Undetermined reasons', 'payxpert' );
		__( 'Unknown reason', 'payxpert' );

		// Subscription type.
		__( 'infinite', 'payxpert' );

		// Subscription status.
		__( 'actif', 'payxpert' );
		__( 'canceled', 'payxpert' );

		// Subscription frequency.
		__( 'daily', 'payxpert' );
		__( 'weekly', 'payxpert' );
		__( 'monthly', 'payxpert' );
		__( 'quarterly', 'payxpert' );
		__( 'yearly', 'payxpert' );
	}

	/**
	 * Deactivate.
	 *
	 * @return mixed
	 */
	public static function deactivate() {
		WC_Payxpert_Logger::debug( 'deactivate' );
	}

	/**
	 * Upgrade db.
	 *
	 * @return mixed
	 */
	public static function upgrade_db() {
		global $wpdb;
		$table = $wpdb->prefix . 'payxpert_payment_transaction';

		// payment_id DEFAULT NULL (due to no payment_id in refund transaction on otherApi).
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Schema maintenance on a safe internal table name; no user input.
		$column = $wpdb->get_row(
			$wpdb->prepare(
				"SHOW COLUMNS FROM `$table` LIKE %s",
				'payment_id'
			)
		);

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- "Null" is a column name returned verbatim by MySQL SHOW COLUMNS, not a plugin property.
		if ( $column && 'NO' === $column->Null ) {
			$wpdb->query(
				"
                ALTER TABLE `$table`
                MODIFY COLUMN `payment_id` VARCHAR(50) DEFAULT NULL
            "
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Cleanup legacy logs.
	 */
	public static function cleanup_legacy_logs(): void {
		$log_dir   = dirname( WC_Payxpert_Logger::get_log_file_path() );
		$pattern   = trailingslashit( $log_dir ) . '*.log';
		$log_files = glob( $pattern );

		if ( ! is_array( $log_files ) ) {
			return;
		}

		foreach ( $log_files as $log_file ) {
			if ( is_file( $log_file ) ) {
				wp_delete_file( $log_file );
			}
		}
	}
}
