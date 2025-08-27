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

    public static function findByMerchantTokenAndCustomerToken(string $merchant_token, string $customer_token): ?array {
        return static::findOneBy([
            'merchant_token'=> $merchant_token,
            'customer_token'=> $customer_token
        ]);
    }

    public static function exists_recent_token_for_order( $order_id, $is_paybylink = false ) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $query = $wpdb->prepare(
            "
            SELECT id
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

}
