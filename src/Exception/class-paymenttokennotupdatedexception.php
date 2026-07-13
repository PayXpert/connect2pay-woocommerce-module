<?php
/**
 * Paymenttokennotupdatedexception.
 *
 * @package Payxpert
 */

namespace Payxpert\Exception;

/**
 * Exception thrown when a payment token cannot be updated.
 */
class PaymentTokenNotUpdatedException extends PayxpertException {

	/**
	 * Constructor.
	 *
	 * @param string          $message Custom error message.
	 * @param int             $code Error code (default: 0).
	 * @param \Exception|null $previous Previous exception if nested exception (default: null).
	 */
	public function __construct( string $message = 'Payment token has no order ID linked.', int $code = 0, ?\Exception $previous = null ) {
		parent::__construct( $message, $code, $previous );
	}
}
