<?php
/*
 * Copyright 2015-2022 PayXpert
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

class WC_PayXpert_Seamless_Gateway extends WC_Payment_Gateway {

	private $mainclass;

	private $seamless = false;

	private $home_url;
	private $relay_response_url;
	private $testmode;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->id                 = 'payxpert_seamless';
		$this->method_title       = __( 'Credit cards by PayXpert', 'payxpert' );
		$this->method_description = __( 'Credit cards via PayXpert', 'payxpert' );
		$this->supports           = array( 'products', 'refunds' );

		$this->mainclass = new PayXpertMain();

		$this->seamless = get_option('payxpert_credit_card_mode') == 'seamless' ? true : false;

		if( $this->seamless ) {
			$this->has_fields = true;
		} else {
			$this->has_fields = false;
			$this->description = '<img class="payxpert-logo" src="' . PX_ASSETS . '/img/card.svg' . '" > ';
		}

		// Load the settings On/Off
		$this->init_form_fields();
		$this->init_settings();
		
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array(
			$this,
			'process_admin_options'
		));
		
		// Define user set variables
		$this->title             = __( 'Credit cards via PayXpert', 'payxpert' );
		$this->testmode          = 'no';
		$this->order_button_text = $this->mainclass->getOrderButtonText();

		$this->home_url           = is_ssl() ? home_url( '/', 'https' ) : home_url( '/' );
		$this->relay_response_url = add_query_arg( 'wc-api', 'WC_PayXpert_Seamless_Gateway', $this->home_url );

		add_action( 'woocommerce_receipt_payxpert_seamless', array( $this, 'receipt_page' ) );

		add_action('woocommerce_api_'.strtolower(get_class($this)), array($this, 'handle_callback'));

	}

	/**
	 * You will need it if you want your custom credit card form, Step 4 is about it
	 */
	public function payment_fields() {

		if( !$this->seamless ) { return ; }

		if( !isset( $_POST['post_data'] ) ) {
			$_POST['post_data'] = '';
		}

		global $woocommerce;
		$carttotal = $woocommerce->cart->total;
		$this->mainclass->payxpert_seamless_checkout_field( $this->order_button_text, $carttotal, $_POST['post_data'], $this->relay_response_url );
	}

	/*
	* We're processing the payments here
	*/
	public function process_payment( $order_id ) {

		if( $this->seamless ) {
			return $this->mainclass->seamless_credit_card_process_payment( $order_id );
		} else {
			return $this->mainclass->payxpert_process_payment( $order_id, 'CREDIT', $this->relay_response_url, 'WC_PayXpert_Seamless_Gateway' );
		}
		
	}

	/**
	 * Process a refund if supported
	 *
	 * @param int $order_id
	 * @param float $amount
	 * @param string $reason
	 *
	 * @return boolean True or false based on success, or a WP_Error object
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		return $this->mainclass->payxpert_refund( $order_id, $amount, $reason );
	}

	/*
	* Handle the callback if it's not seamless
	*/
	public function handle_callback() {
		return $this->mainclass->payxpert_callback_handle();
	}

	public function receipt_page( $order_id ) {
		return $this->mainclass->payxpert_receipt_page( $order_id );
	}

}