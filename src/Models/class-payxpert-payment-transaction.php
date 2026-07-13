<?php
/**
 * Payxpert Payment Transaction.
 *
 * @package Payxpert
 */

namespace Payxpert\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Payxpert Payment Transaction.
 */
class Payxpert_Payment_Transaction extends Payxpert_Abstract_Model {
	const TABLE_NAME = 'payxpert_payment_transaction';

	const STATUS_SUCCESS = 'success';
	const STATUS_FAILURE = 'failure';

	const OPERATION_SALE              = 'sale';
	const OPERATION_AUTHORIZE         = 'authorize';
	const OPERATION_REFUND            = 'refund';
	const OPERATION_CAPTURE           = 'capture';
	const RESULT_CODE_SUCCESS         = '000';
	const RESULT_CODE_CANCEL          = '-1';
	const RESULT_CODE_CALLBACK_CANCEL = '-2';
	const LIABILITY_SHIFT_OK          = 1;

	const CB_ICON         = 'cb.png';
	const AMEX_ICON       = 'amex.png';
	const VISA_ICON       = 'visa.png';
	const MASTERCARD_ICON = 'mastercard.png';
	const APPLEPAY_ICON   = 'applepay.png';

	const MASKED_VALUE = '******';

	const SENSITIVE_FIELDS = array(
		'transaction',
		'merchantToken',
		'merchant_token',
		'customerToken',
		'customer_token',
		'paymentMerchantToken',
		'payment_merchant_token',
		'cardNumber',
		'card_number',
		'iban',
		'bic',
		'idNumber',
		'email',
		'homePhone',
		'paymentMeanInfo',
	);

	/**
	 * Fillable.
	 *
	 * @var list<string>
	 */
	protected $fillable = array(
		'id_payxpert_payment_transaction',
		'id_shop',
		'transaction_id',
		'transaction_referal_id',
		'order_id',
		'payment_id',
		'liability_shift',
		'payment_method',
		'operation',
		'amount',
		'currency',
		'result_code',
		'result_message',
		'card_number',
		'card_brand',
		'order_slip_id',
		'subscription_id',
		'date_add',
		'card_number',
		'card_brand',
	);

	/**
	 * Primary key.
	 *
	 * @var string
	 */
	protected $primary_key = 'id_payxpert_payment_transaction';

	/**
	 * Construct.
	 */
	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Find a transaction by its transaction id.
	 *
	 * @param string $transaction_id Transaction id.
	 * @return array<string, mixed>|null
	 */
	public static function find_by_transaction_id( string $transaction_id ): ?array {
		return static::find_one_by( array( 'transaction_id' => $transaction_id ) );
	}

	/**
	 * Find a transaction by its transaction id and payment id.
	 *
	 * @param string $transaction_id Transaction id.
	 * @param string $payment_id Payment id.
	 * @return array<string, mixed>|null
	 */
	public static function find_by_transaction_id_and_payment_id( string $transaction_id, string $payment_id ): ?array {
		return static::find_one_by(
			array(
				'transaction_id' => $transaction_id,
				'payment_id'     => $payment_id,
			)
		);
	}

	/**
	 * Find all transactions for a given order id.
	 *
	 * @param int|string $order_id Order id.
	 * @return list<array<string, mixed>>
	 */
	public static function find_all_by_order_id( $order_id ): array {
		return static::find_by( array( 'order_id' => $order_id ) );
	}

	/**
	 * Returns paginated transactions.
	 *
	 * @param int $per_page Items per page.
	 * @param int $offset   Pagination offset.
	 * @return list<object>
	 */
	public static function get_all_paginated( $per_page = 20, $offset = 0 ): array {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_NAME;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe internal table name; values are bound through $wpdb->prepare().
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"
                SELECT *
                FROM {$table}
                ORDER BY date_add DESC
                LIMIT %d OFFSET %d
                ",
				$per_page,
				$offset
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $results;
	}

	/**
	 * Count all.
	 *
	 * @return int
	 */
	public static function count_all(): int {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Count on a safe internal table name; no user input.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Anonymize sensitive values recursively.
	 *
	 * @param mixed $data Data.
	 */
	public static function anonymize_recursive( &$data ): void {
		if ( is_array( $data ) ) {
			foreach ( $data as $key => &$value ) {
				if ( in_array( (string) $key, self::SENSITIVE_FIELDS, true ) ) {
					$value = self::MASKED_VALUE;
					continue;
				}

				self::anonymize_recursive( $value );
			}
			unset( $value );
			return;
		}

		if ( is_object( $data ) ) {
			$normalized = self::normalize_object_to_array( $data );

			if ( is_array( $normalized ) ) {
				self::anonymize_recursive( $normalized );
				$data = $normalized;
				return;
			}

			// Unknown object shape: never leak internals in logs.
			$data = self::MASKED_VALUE;
		}
	}

	/**
	 * Normalize an object into an array.
	 *
	 * @param object $object Object.
	 * @return array<string, mixed>|null
	 */
	private static function normalize_object_to_array( object $object ): ?array {
		$normalized = json_decode( wp_json_encode( $object ), true );

		return is_array( $normalized ) ? $normalized : null;
	}
}
