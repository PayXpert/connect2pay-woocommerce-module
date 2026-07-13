<?php
/**
 * Wc Payxpert Transaction List.
 *
 * @package Payxpert
 */

use Payxpert\Models\Payxpert_Payment_Transaction;

/**
 * WC Payxpert Transaction List.
 */
class WC_Payxpert_Transaction_List {

	/**
	 * Singleton instance holder.
	 *
	 * @var self|null
	 */
	protected static $instance = null;

	/**
	 * Returns the singleton instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Construct.
	 */
	public function __construct() {
		// Add sub-menu in WooCommerce > Extensions.
		add_action( 'admin_menu', array( $this, 'add_submenu_page' ), 101 );

		// Loading assets.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Registers the PayXpert admin submenu under WooCommerce.
	 */
	public function add_submenu_page(): void {
		add_submenu_page(
			'woocommerce',
			__( 'PayXpert Transactions List', 'payxpert' ),
			__( 'PayXpert - Transaction', 'payxpert' ),
			'manage_woocommerce',
			'payxpert-transaction-list',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Enqueue assets.
	 *
	 * @param mixed $hook Hook.
	 */
	public function enqueue_assets( $hook ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check of the current admin page to scope asset enqueue.
		if ( isset( $_GET['page'] ) && 'payxpert-transaction-list' === sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) {
			WC_Payxpert_Admin_Assets::enqueue_admin_settings_assets();
		}
	}

	/**
	 * Render settings page.
	 *
	 * @return mixed
	 */
	public function render_settings_page() {
		$per_page = 20;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination parameter for the admin listing.
		$current_page = isset( $_GET['paged'] ) ? max( 1, intval( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$offset       = ( $current_page - 1 ) * $per_page;

		// Load paginated transactions.
		$transactions = Payxpert_Payment_Transaction::get_all_paginated( $per_page, $offset );
		$total_items  = Payxpert_Payment_Transaction::count_all();

		include WC_PAYXPERT_PLUGIN_FILE_PATH . 'templates/views/html-admin-transaction-list.php';
	}
}
