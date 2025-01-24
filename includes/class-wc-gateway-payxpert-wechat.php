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

/**
 * Initialize the gateway.
 *
 * @since 1.0.0
 */
class WC_Gateway_PayXpert_WeChat extends WC_Payment_Gateway {

	private $mainclass;
	private $home_url;
	private $relay_response_url;
	private $testmode;

	/**
	 * Constructor for the gateway.
	 */
	public function __construct() {
		$this->id                 = 'payxpert_wechat';
		$this->has_fields         = false;
		$this->method_title       = __( 'WeChat by PayXpert', 'payxpert' );
		$this->method_description = __( 'WeChat Pay method', 'payxpert' );
		$this->supports           = array( 'products', 'refunds' );

		$this->mainclass = new PayXpertMain();

		// Load the settings On/Off
		$this->init_form_fields();
		$this->init_settings();

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array(
			$this,
			'process_admin_options'
		));

		// Define user set variables
		$this->title             = __( 'WeChat via PayXpert', 'payxpert' );
		$this->description       = '<img src="' . PX_ASSETS . '/img/wechat.svg' . '" > ';
		$this->testmode          = 'no';
		$this->order_button_text = $this->mainclass->getOrderButtonText();

		// URLs
		$this->home_url           = is_ssl() ? home_url( '/', 'https' ) : home_url( '/' ); //set the urls (cancel or return) based on SSL
		$this->relay_response_url = add_query_arg( 'wc-api', 'WC_Gateway_PayXpert_WeChat', $this->home_url );

		if ( $this->mainclass->is_iframe_on() ) {
			add_action( 'woocommerce_receipt_payxpert_wechat', array( $this, 'receipt_page' ) );
		}

		if ( ! $this->mainclass->is_valid_for_use() ) {
			$this->enabled = 'no';
		} else {
			add_action( 'woocommerce_api_wc_gateway_payxpert_wechat', array( $this, 'handle_callback' ) );
		}
	}


	/**
	 * Process the payment and return the result
	 *
	 * @param int $order_id
	 *
	 * @return array
	 */
	public function process_payment( $order_id ) {
		return $this->mainclass->payxpert_process_payment( $order_id, 'WECHAT', $this->relay_response_url, 'WC_Gateway_PayXpert_WeChat' );
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


	/**
	 * Check for PayXpert Callback Response
	 */
	public function handle_callback() {
		return $this->mainclass->payxpert_callback_handle();
	}


	public function receipt_page( $order_id ) {
		return $this->mainclass->payxpert_receipt_page( $order_id );
	}
}