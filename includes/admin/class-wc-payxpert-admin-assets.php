<?php
/**
 * Shared PayXpert admin assets.
 *
 * @package Payxpert
 */

/**
 * Enqueues admin settings script and localized data.
 */
class WC_Payxpert_Admin_Assets {

	/**
	 * Enqueue admin-settings.css/js and payxpert_support localization.
	 *
	 * @param bool $with_support_dialog Load jQuery UI dialog assets (settings page only).
	 */
	public static function enqueue_admin_settings_assets( bool $with_support_dialog = false ): void {
		wp_enqueue_style(
			'payxpert-admin-settings-style',
			WC_PAYXPERT_ASSETS . 'css/admin-settings.css',
			array(),
			WC_PAYXPERT_VERSION
		);

		wp_enqueue_script( 'jquery' );
		wp_enqueue_script(
			'payxpert-admin-settings-script',
			WC_PAYXPERT_ASSETS . 'js/src/admin-settings.js',
			array( 'jquery' ),
			WC_PAYXPERT_VERSION,
			true
		);

		wp_localize_script(
			'payxpert-admin-settings-script',
			'payxpert_support',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'payxpert_support_nonce' ),
				'i18n'     => array(
					'send'  => __( 'Send', 'payxpert' ),
					'close' => __( 'Close', 'payxpert' ),
				),
			)
		);

		if ( $with_support_dialog ) {
			wp_enqueue_script( 'jquery-ui-dialog' );
			wp_enqueue_style( 'wp-jquery-ui-dialog' );
		}
	}
}
