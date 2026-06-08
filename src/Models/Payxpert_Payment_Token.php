<?php

namespace Payxpert\Models;

defined('ABSPATH') || exit;

class Payxpert_Payment_Token extends Payxpert_Abstract_Model {
    const TABLE_NAME = 'payxpert_payment_token';

    protected $fillable = [
        'id_payxpert_payment_token',
        'merchant_token',
        'customer_token',
        'user_id',
        'order_id',
        'is_paybylink'
    ];

    protected $primary_key = 'id_payxpert_payment_token';

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . self::TABLE_NAME;
    }

    public static function findByCustomerToken(string $token): ?array {
        return static::findOneBy(['customer_token' => $token]);
    }

    public static function findByMerchantToken(string $merchant_token): ?array {
        return static::findOneBy(['merchant_token'=> $merchant_token]);
    }

    public static function exists_recent_token_for_order( $order_id, $is_paybylink = false ) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

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

        $result = $wpdb->get_var( $query );

        return !empty( $result );
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

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is prefixed and fixed.
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

        return false === $deleted ? 0 : (int) $deleted;
    }

}
