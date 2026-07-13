<?php
/**
 * Wc Payment Gateway Payxpert Installment X4.
 *
 * @package Payxpert
 */

defined( 'ABSPATH' ) || exit;

/**
 * WC Payment Gateway Payxpert Installment X4.
 */
class WC_Payment_Gateway_Payxpert_Installment_X4 extends WC_Payment_Gateway_Payxpert_Installment {
	const ID = 'payxpert_installment_x4';

	/**
	 * Construct.
	 */
	public function __construct() {
		$this->x_times      = 4;
		$this->method_title = __( 'Installment payment x4 by PayXpert', 'payxpert' );

		parent::__construct( self::ID );
	}
}
