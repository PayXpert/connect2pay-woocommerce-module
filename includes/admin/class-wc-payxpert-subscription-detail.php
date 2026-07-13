<?php
/**
 * Wc Payxpert Subscription Detail.
 *
 * @package Payxpert
 */

use Payxpert\Models\Payxpert_Subscription;
use Payxpert\Utils\WC_Payxpert_Utils;

/**
 * WC Payxpert Subscription Detail.
 */
class WC_Payxpert_Subscription_Detail {

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
		add_action( 'admin_menu', array( $this, 'add_submenu_page' ), 102 );

		// Loading assets.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Registers the PayXpert admin submenu under WooCommerce.
	 */
	public function add_submenu_page(): void {
		add_submenu_page(
			'',
			__( 'PayXpert Subscription Detail', 'payxpert' ),
			__( 'PayXpert - Subscription Detail', 'payxpert' ),
			'manage_woocommerce',
			'payxpert-subscription-detail',
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
		if ( isset( $_GET['page'] ) && 'payxpert-subscription-detail' === sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) {
			WC_Payxpert_Admin_Assets::enqueue_admin_settings_assets();
		}
	}

	/**
	 * Render settings page.
	 *
	 * @return mixed
	 */
	public function render_settings_page() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only detail view selected by ID; no state change is performed.
		if ( empty( $_GET['id'] ) ) {
			echo '<p>' . esc_html__( 'No subscription ID provided.', 'payxpert' ) . '</p>';
			return;
		}

		$subscription_id = absint( wp_unslash( $_GET['id'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$sub = Payxpert_Subscription::get_subscription_details( $subscription_id );

		if ( ! $sub ) {
			echo '<p>' . esc_html__( 'Subscription not found.', 'payxpert' ) . '</p>';
			return;
		}

		foreach ( $sub->transactions as &$trans ) {
			$timestamp                 = strtotime( $trans->date_add ); // string to timestamp.
			$trans->date_add_formatted = date_i18n( get_option( 'date_format' ), $timestamp );
		}

		include WC_PAYXPERT_PLUGIN_FILE_PATH . 'templates/views/html-admin-subscription-detail.php';
	}
}
