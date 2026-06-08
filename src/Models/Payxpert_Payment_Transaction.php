<?php

namespace Payxpert\Models;

defined('ABSPATH') || exit;

class Payxpert_Payment_Transaction extends Payxpert_Abstract_Model {
    const TABLE_NAME = 'payxpert_payment_transaction';

    const STATUS_SUCCESS = 'success';
    const STATUS_FAILURE = 'failure';

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
    const APPLEPAY_ICON = 'applepay.png';

    const MASKED_VALUE = '******';

    const SENSITIVE_FIELDS = [
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
        'paymentMeanInfo'
    ];

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
        'card_number',
        'card_brand',
        'order_slip_id',
        'subscription_id',
        'date_add',
        'card_number',
        'card_brand'
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
     * Returns paginated transactions.
     *
     * @param int $per_page Items per page.
     * @param int $offset   Pagination offset.
     * @return array
     */
    public static function get_all_paginated($per_page = 20, $offset = 0) {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

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

        return $results;
    }

    public static function count_all(): int {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    public static function anonymizeRecursive(&$data): void
    {
        if (is_array($data)) {
            foreach ($data as $key => &$value) {
                if (in_array((string) $key, self::SENSITIVE_FIELDS, true)) {
                    $value = self::MASKED_VALUE;
                    continue;
                }

                self::anonymizeRecursive($value);
            }
            unset($value);
            return;
        }

        if (is_object($data)) {
            $normalized = self::normalizeObjectToArray($data);

            if (is_array($normalized)) {
                self::anonymizeRecursive($normalized);
                $data = $normalized;
                return;
            }

            // Unknown object shape: never leak internals in logs.
            $data = self::MASKED_VALUE;
        }
    }

    private static function normalizeObjectToArray(object $object): ?array
    {
        $normalized = json_decode(wp_json_encode($object), true);

        return is_array($normalized) ? $normalized : null;
    }
}
