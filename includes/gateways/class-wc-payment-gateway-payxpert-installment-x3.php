<?php
/**
 * Wc Payment Gateway Payxpert Installment X3.
 *
 * @package Payxpert
 */

defined( 'ABSPATH' ) || exit;

/**
 * WC Payment Gateway Payxpert Installment X3.
 */
class WC_Payment_Gateway_Payxpert_Installment_X3 extends WC_Payment_Gateway_Payxpert_Installment {
	const ID = 'payxpert_installment_x3';

	/**
	 * Construct.
	 */
	public function __construct() {
		$this->x_times      = 3;
		$this->method_title = __( 'Installment payment x3 by PayXpert', 'payxpert' );

		parent::__construct( self::ID );
	}
}
