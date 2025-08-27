<?php

namespace Payxpert\Models;

defined('ABSPATH') || exit;

class Payxpert_Payment_Transaction extends Payxpert_Abstract_Model {
    const TABLE_NAME = 'payxpert_payment_transaction';

    const OPERATION_SALE = 'sale';
    const OPERATION_AUTHORIZE = 'authorize';
    const OPERATION_REFUND = 'refund';
    const OPERATION_CAPTURE = 'capture';
    const RESULT_CODE_SUCCESS = '000';
    const RESULT_CODE_CANCEL = '-1';
    const RESULT_CODE_CALLBACK_CANCEL = '-2';
    const LIABILITY_SHIFT_OK = 1;

    const CB_ICON = 'cb.png';
    const AMEX_ICON = 'amex.png';
    const VISA_ICON = 'visa.png';
    const MASTERCARD_ICON = 'mastercard.png';

    protected $fillable = [
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
        'order_slip_id',
        'subscription_id',
        'date_add',
    ];

    protected $primary_key = 'id_payxpert_payment_transaction';

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . self::TABLE_NAME;
    }

    public static function findByTransactionId(string $transaction_id): ?array {
        return static::findOneBy(['transaction_id' => $transaction_id]);
    }

    public static function findByTransactionIdAndPaymentId(string $transaction_id, string $paymentId): ?array {
        return static::findOneBy(['transaction_id' => $transaction_id, 'payment_id' => $paymentId]);
    }

    public static function findAllByOrderId(string $order_id) {
        return static::findBy(['order_id' => $order_id]);
    }

    /**
     * Récupère les transactions paginées.
     *
     * @param int $per_page Nombre d'éléments par page
     * @param int $offset Décalage pour la pagination
     * @return array Liste des transactions
     */
    public static function get_all_paginated($per_page = 20, $offset = 0) {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT SQL_CALC_FOUND_ROWS *
                FROM {$table}
                ORDER BY date_add DESC
                LIMIT %d OFFSET %d
                ",
                $per_page,
                $offset
            )
        );

        return $results;
    }

    
}
