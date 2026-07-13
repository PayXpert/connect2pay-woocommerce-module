<?php
/**
 * Wc Payment Gateway Payxpert Paybylink.
 *
 * @package Payxpert
 */

use Payxpert\Utils\WC_Payxpert_Utils;

defined( 'ABSPATH' ) || exit;

/**
 * WC Payment Gateway Payxpert Paybylink.
 */
class WC_Payment_Gateway_Payxpert_Paybylink extends WC_Payment_Gateway_Payxpert {
	const ID = 'payxpert_paybylink';

	/**
	 * Configuration.
	 *
	 * @var mixed
	 */
	public $configuration;

	/**
	 * Construct.
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->method_title       = __( 'PayByLink by PayXpert', 'payxpert' );
		$this->method_description = __( 'Credit card gateway.', 'payxpert' );

		$this->supports = array();

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		$this->configuration = WC_Payxpert_Utils::get_configuration();

		// Define user settings.
		$this->title       = $this->method_title;
		$this->description = '';
		$this->enabled     = $this->configuration['payxpert_paybylink_enabled'];
		$this->has_fields  = false;
		$this->icon        = plugins_url( 'assets/img/payment_method_logo/cc.jpg', WC_PAYXPERT_PLUGIN_NAME );
	}


	/**
	 * Is available.
	 *
	 * @return mixed
	 */
	public function is_available() {
		if ( is_admin() || defined( 'REST_REQUEST' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Payment fields.
	 */
	public function payment_fields(): void {
		// Ne rien afficher.
	}
}
