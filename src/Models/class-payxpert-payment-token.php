<?php
/**
 * Payxpert Payment Token.
 *
 * @package Payxpert
 */

namespace Payxpert\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Payxpert Payment Token.
 */
class Payxpert_Payment_Token extends Payxpert_Abstract_Model {
	const TABLE_NAME = 'payxpert_payment_token';

	/**
	 * Fillable.
	 *
	 * @var list<string>
	 */
	protected $fillable = array(
		'id_payxpert_payment_token',
		'merchant_token',
		'customer_token',
		'user_id',
		'order_id',
		'is_paybylink',
	);

	/**
	 * Primary key.
	 *
	 * @var string
	 */
	protected $primary_key = 'id_payxpert_payment_token';

	/**
	 * Construct.
	 */
	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Find a token by its customer token.
	 *
	 * @param string $token Token.
	 * @return array<string, mixed>|null
	 */
	public static function find_by_customer_token( string $token ): ?array {
		return static::find_one_by( array( 'customer_token' => $token ) );
	}

	/**
	 * Find a token by its merchant token.
	 *
	 * @param string $merchant_token Merchant token.
	 * @return array<string, mixed>|null
	 */
	public static function find_by_merchant_token( string $merchant_token ): ?array {
		return static::find_one_by( array( 'merchant_token' => $merchant_token ) );
	}

	/**
	 * Exists recent token for order.
	 *
	 * @param mixed $order_id Order id.
	 * @param mixed $is_paybylink Is paybylink.
	 * @return mixed
	 */
	public static function exists_recent_token_for_order( $order_id, $is_paybylink = false ) {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe internal table name; values are bound through $wpdb->prepare().
		$query = $wpdb->prepare(
			"
            SELECT id_payxpert_payment_token
            FROM $table_name
            WHERE order_id = %d
            AND is_paybylink = %d
            AND date_add > (NOW() - INTERVAL 30 DAY)
            LIMIT 1
            ",
			$order_id,
			$is_paybylink ? 1 : 0
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $query is already built with $wpdb->prepare() just above.
		$result = $wpdb->get_var( $query );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return ! empty( $result );
	}

	/**
	 * Deletes orphan tokens (order_id = 0): seamless rows older than 1 day;
	 * PayByLink rows older than 45 days.
	 *
	 * @return int Number of deleted rows.
	 */
	public static function delete_expired_orphan_tokens(): int {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_NAME;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Maintenance query on a safe internal table name; no user input.
		$deleted = $wpdb->query(
			"
            DELETE FROM `{$table}`
            WHERE order_id = 0
            AND (
                (is_paybylink = 0 AND date_add < DATE_SUB(NOW(), INTERVAL 1 DAY))
                OR
                (is_paybylink = 1 AND date_add < DATE_SUB(NOW(), INTERVAL 45 DAY))
            )
            "
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		return false === $deleted ? 0 : (int) $deleted;
	}
}
