<?php
/**
 * Wc Payxpert Subscription List.
 *
 * @package Payxpert
 */

use Payxpert\Models\Payxpert_Subscription;
use Payxpert\Utils\WC_Payxpert_Admin_Notices;
use Payxpert\Utils\WC_Payxpert_Logger;
use Payxpert\Utils\WC_Payxpert_Utils;
use Payxpert\WC_Payxpert;

/**
 * WC Payxpert Subscription List.
 */
class WC_Payxpert_Subscription_List {

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

		add_action( 'admin_post_payxpert_cancel_subscription', array( $this, 'action_cancel_subscription' ) );
	}

	/**
	 * Registers the PayXpert admin submenu under WooCommerce.
	 */
	public function add_submenu_page(): void {
		add_submenu_page(
			'woocommerce',
			__( 'PayXpert Subscriptions List', 'payxpert' ),
			__( 'PayXpert - Subscription', 'payxpert' ),
			'manage_woocommerce',
			'payxpert-subscription-list',
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
		if ( isset( $_GET['page'] ) && 'payxpert-subscription-list' === sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) {
			WC_Payxpert_Admin_Assets::enqueue_admin_settings_assets();
		}
	}

	/**
	 * Render settings page.
	 *
	 * @return mixed
	 */
	public function render_settings_page() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only listing filters from the admin list screen; no state change is performed.
		$per_page     = 10;
		$current_page = isset( $_GET['paged'] ) ? max( 1, intval( wp_unslash( $_GET['paged'] ) ) ) : 1;

		$filters = array(
			'status'    => isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '',
			'date_from' => isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '',
			'date_to'   => isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '',
			'customer'  => isset( $_GET['customer'] ) ? sanitize_text_field( wp_unslash( $_GET['customer'] ) ) : '',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$results = Payxpert_Subscription::get_all_with_transaction_counts(
			$per_page,
			( $current_page - 1 ) * $per_page,
			$filters
		);
		$this->format_results( $results['items'] );

		// Used in template.
		include WC_PAYXPERT_PLUGIN_FILE_PATH . 'templates/views/html-admin-subscription-list.php';
	}

	/**
	 * Action cancel subscription.
	 */
	public function action_cancel_subscription(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'No permission' );
		}

		$nonce = isset( $_POST['payxpert_cancel_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['payxpert_cancel_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'payxpert_cancel_subscription' ) ) {
			WC_Payxpert_Admin_Notices::set( 'admin_error', __( 'Security check failed.', 'payxpert' ) );
			wp_safe_redirect( admin_url( 'admin.php?page=payxpert-subscription-list' ) );
			exit;
		}

		$subscription_id = isset( $_POST['subscription_id'] ) ? sanitize_text_field( wp_unslash( $_POST['subscription_id'] ) ) : '';
		$reason_id       = isset( $_POST['payxpert_cancel_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['payxpert_cancel_reason'] ) ) : '';

		$result = WC_Payxpert::cancel_subscription( $subscription_id, get_current_user_id(), $reason_id, true );

		if ( $result['success'] ) {
			WC_Payxpert_Logger::info( 'Admin cancelled subscription ID ' . $subscription_id );
			WC_Payxpert_Admin_Notices::set( 'admin_success', $result['message'] );
		} else {
			WC_Payxpert_Logger::error( 'Admin failed to cancel subscription ID ' . $subscription_id . ' : ' . $result['message'] );
			WC_Payxpert_Admin_Notices::set( 'admin_error', $result['message'] );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=payxpert-subscription-list' ) );
		exit;
	}


	/**
	 * Format results.
	 *
	 * @param mixed $results Results.
	 * @return mixed
	 */
	protected function format_results( &$results ) {
		if ( empty( $results ) || ! is_array( $results ) ) {
			return;
		}

		foreach ( $results as &$sub ) {
			$sub->subscription_type_label = ( 'partpayment' === $sub->subscription_type ? __( 'Installment', 'payxpert' ) : __( 'Subscription', 'payxpert' ) );
			$sub->state_label             = (string) __( $sub->state, 'payxpert' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- State comes from a fixed internal set.
			$sub->trial_amount_formatted  = wc_price( $sub->trial_amount / 100 );
			$sub->amount_formatted        = wc_price( $sub->amount / 100 );
			$sub->period                  = WC_Payxpert_Utils::render_human_period( $sub->period );
			$sub->period_start_formatted  = ! empty( $sub->period_start )
				? date_i18n( get_option( 'date_format' ), $sub->period_start )
				: ( 'finished' == $sub->state
					? date_i18n( get_option( 'date_format' ), $sub->cancel_date )
					: '-'
				);
			$sub->period_end_formatted    = ! empty( $sub->period_end )
				? date_i18n( get_option( 'date_format' ), $sub->period_end )
				: '-';
			$sub->sale_transactions_count = (string) $sub->sale_transactions_count;
			$sub->iterations_left         = ( 0 == $sub->iterations_left ? '∞' : (string) $sub->iterations_left );
			$sub->retries                 = (string) $sub->retries;
		}
	}
}
