<?php

declare(strict_types=1);

namespace Payxpert\Utils;

defined( 'ABSPATH' ) || exit();

use DateInterval;
use Payxpert\Models\Payxpert_Payment_Transaction;
use Payxpert\Models\Payxpert_Subscription;
use WC_Order;
use WC_Order_Item_Shipping;
use WC_Payment_Gateway_Payxpert_Installment_X2;
use WC_Payment_Gateway_Payxpert_Installment_X3;
use WC_Payment_Gateway_Payxpert_Installment_X4;

require_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/admin/class-wc-payxpert-settings.php';

class WC_Payxpert_Utils
{
    public const IPN_SECRET_OPTION_KEY = 'payxpert_ipn_secret';

    public static function build_instalment_schedule($amount, $firstPercentage, $xTimes)
    {
        $schedule = [];
        $date = new \DateTime();

        // Active WooCommerce currency.
        $currency = get_woocommerce_currency();

        // Basic validation.
        if (!$currency) {
            throw new \Exception('Currency not found.');
        }

        // Compute instalment amounts.
        list($instalmentFirstAmount, $rebillAmount) = self::calculate_instalment_amounts(
            (int) round($amount * 100),
            intval($firstPercentage),
            $xTimes
        );

        // First instalment.
        $schedule[] = [
            'amount' => $instalmentFirstAmount / 100,
            'amountFormatted' => wc_price($instalmentFirstAmount / 100, ['currency' => $currency]),
            'date' => date_i18n(get_option('date_format'), $date->getTimestamp()),
        ];

        // Following instalments.
        for ($i = 0; $i < $xTimes - 1; ++$i) {
            $date->modify('+30 days');

            $schedule[] = [
                'amount' => $rebillAmount / 100,
                'amountFormatted' => wc_price($rebillAmount / 100, ['currency' => $currency]),
                'date' => date_i18n(get_option('date_format'), $date->getTimestamp()),
            ];
        }

        return $schedule;
    }

    public static function calculate_instalment_amounts(int $amount, int $firstPercentage, int $xTimes): array
    {
        if ($xTimes < 1) {
            throw new \Exception('xTimes must be at least 1.');
        }

        $instalmentFirstAmount = round(($firstPercentage * $amount) / 100);
        $remainingInstalments = $xTimes - 1;
        $rebillAmount = $remainingInstalments > 0 ? round(($amount - $instalmentFirstAmount) / $remainingInstalments, 2) : 0;
        $totalCalculated = $instalmentFirstAmount + ($rebillAmount * $remainingInstalments);
        $difference = $amount - $totalCalculated;

        if (abs($difference) >= 1) {
            $instalmentFirstAmount += $difference;
        }

        return [
            intval($instalmentFirstAmount),
            intval($rebillAmount),
        ];
    }

    public static function get_configuration()
    {
        $configuration = [
            'payxpert_originator_id' => get_option('payxpert_originator_id'),
            'payxpert_password' => get_option('payxpert_password'),
            'payxpert_conn_status' => get_option('payxpert_conn_status')
        ];

        $field_keys = array_reduce(
            \WC_Payxpert_Settings::get_settings(),
            function($carry, $s) {
                return !empty($s['fields'])
                    ? array_merge($carry, array_keys($s['fields']))
                    : $carry;
            },
            []
        );

        foreach ($field_keys as $key) {
            $configuration[$key] = get_option($key);
        }

        return $configuration;
    }

    public static function get_option($key, $default = null)
    {
        $options = self::get_configuration();
        return $options[$key] ?? $default;
    }

    /**
     * Translate customizable text options when a multilingual plugin is available.
     * Falls back to the original value when no integration exists.
     */
    public static function translate_custom_text(string $value, string $name): string
    {
        if ($value === '') {
            return $value;
        }

        $context = 'PayXpert';
        $sourceValue = $value;

        if (function_exists('pll_register_string')) {
            pll_register_string($name, $sourceValue, $context);
        }

        if (function_exists('pll__')) {
            $value = (string) pll__($sourceValue);
        }

        if (has_action('wpml_register_single_string')) {
            do_action('wpml_register_single_string', $context, $name, $sourceValue);
        }

        if (has_filter('wpml_translate_single_string')) {
            $value = (string) apply_filters('wpml_translate_single_string', $value, $context, $name);
        }

        return $value;
    }

    public static function ensure_ipn_secret(): string
    {
        $secret = (string) get_option(self::IPN_SECRET_OPTION_KEY, '');
        if (strlen($secret) >= 43) {
            return $secret;
        }

        // 32 random bytes encoded in base64url gives >= 43 chars.
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        update_option(self::IPN_SECRET_OPTION_KEY, $secret, false);

        return $secret;
    }

    public static function to_snake_case_keys(array $array): array {
        $result = [];

        foreach ($array as $key => $value) {
            // Convert camelCase or PascalCase to snake_case
            $snakeKey = strtolower(preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key));
            $result[$snakeKey] = $value;
        }

        return $result;
    }

    public static function render_human_period($period)
    {
        try {
            $interval = new DateInterval($period);
            $parts = [];
            if ($interval->y) $parts[] = $interval->y . ' ' . __('year(s)', 'payxpert');
            if ($interval->m) $parts[] = $interval->m . ' ' . __('month(s)', 'payxpert');
            if ($interval->d) $parts[] = $interval->d . ' ' . __('day(s)', 'payxpert');
            if ($interval->h) $parts[] = $interval->h . ' ' . __('hour(s)', 'payxpert');
            if ($interval->i) $parts[] = $interval->i . ' ' . __('minute(s)', 'payxpert');
            if ($interval->s) $parts[] = $interval->s . ' ' . __('second(s)', 'payxpert');
            return implode(', ', $parts) ?: '—';
        } catch (\Exception $e) {
            return $period;
        }
    }
    
    public static function get_order_transactions_formatted(array $transactions)
    {
        $refundable = [];
        $capturable = [];
        $captured = [];
        $refunded = [];
        $orderSlipUsed = [];

        foreach ($transactions as $transaction) {
            if (Payxpert_Payment_Transaction::RESULT_CODE_SUCCESS !== $transaction['result_code']) {
                continue;
            }

            if (
                Payxpert_Payment_Transaction::OPERATION_SALE == $transaction['operation']
                || Payxpert_Payment_Transaction::OPERATION_CAPTURE == $transaction['operation']
            ) {
                $refundable[$transaction['transaction_id']] = $transaction;
            }

            if (Payxpert_Payment_Transaction::OPERATION_AUTHORIZE === $transaction['operation']) {
                $capturable[$transaction['transaction_id']] = $transaction;
            }

            if (isset($transaction['transaction_referal_id'])) {
                if (Payxpert_Payment_Transaction::OPERATION_CAPTURE == $transaction['operation']) {
                    $captured[$transaction['transaction_referal_id']] = $transaction;
                } elseif (Payxpert_Payment_Transaction::OPERATION_REFUND == $transaction['operation']) {
                    $refunded[$transaction['transaction_referal_id']][] = $transaction;
                }
            }

            if (!is_null($transaction['order_slip_id'])) {
                $orderSlipUsed[] = $transaction['order_slip_id'];
            }
        }

        foreach ($refundable as $transactionID => &$refundableTransaction) {
            $refundableTransaction['refundable_amount'] = $refundableTransaction['amount'];

            if (isset($refunded[$transactionID])) {
                $refundedAmount = array_sum(array_column($refunded[$transactionID], 'amount'));
                $refundableTransaction['refundable_amount'] -= $refundedAmount;
            }

            if ($refundableTransaction['refundable_amount'] <= 0) {
                unset($refundable[$transactionID]);
            }
        }

        $capturable = array_diff_key($capturable, $captured);

        return [
            'refundable' => $refundable,
            'capturable' => $capturable,
            'refunded' => $refunded,
            'captured' => $captured,
            'order_slip_used' => $orderSlipUsed,
        ];
    }

    public static function is_hpos_enabled()
    {
        return class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')
            && \Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled('custom_order_tables');
    }

    public static function sync_subscription(array $configuration, array $subscription, array $firstTransaction)
    {
        $updated = false;
        $subscriptionType = $subscription['subscription']['subscriptionType'] ?? null;

        // Closure to persist a PayXpert transaction row.
        $createTransaction = function(array $firstTransactionData, array $subTransactionData, $orderId = null) {
            $transaction = new Payxpert_Payment_Transaction();
            $transaction->set(array_merge(
                $firstTransactionData,
                [
                    'id_payxpert_payment_transaction' => null,
                    'order_id' => $orderId ?? $subTransactionData['order_id'],
                    'transaction_id' => $subTransactionData['transactionID'],
                    'transaction_referal_id' => $subTransactionData['referralID'],
                    'amount' => (float) ($subTransactionData['amount']/100),
                    'result_code' => $subTransactionData['errorCode'],
                    'result_message' => $subTransactionData['status'],
                    'date_add' => date('Y-m-d H:i:s', $subTransactionData['date']),
                    'card_number' => $subTransactionData['paymentMeanInfo']['cardNumber'],
                    'card_brand' => $subTransactionData['paymentMeanInfo']['cardBrand'],
                ]
            ));
            $transaction->save();
        };

        // Reference order for non-instalment subscription types.
        $originalOrder = null;
        if ($subscriptionType !== 'partpayment') {
            $originalOrder = wc_get_order($firstTransaction['order_id']);
        }

        // Iterate API transaction list.
        foreach ($subscription['transactionList'] as $subscriptionTransaction) {
            // Skip the first (already stored) transaction.
            if ($subscriptionTransaction['transactionID'] == $firstTransaction['transaction_id']) {
                continue;
            }

            // Skip if already imported.
            $transactionExist = Payxpert_Payment_Transaction::findByTransactionId((string)$subscriptionTransaction['transactionID']);
            if ($transactionExist) {
                continue;
            }

            $orderId = null;

            // Non-instalment: create a child order per renewal transaction.
            if ($subscriptionType !== 'partpayment' && $originalOrder) {
                $newOrder = wc_create_order();

                // Copy line items.
                foreach ($originalOrder->get_items() as $item) {
                    $newOrder->add_product(
                        $item->get_product(),
                        $item->get_quantity(),
                        [
                            'subtotal' => $item->get_subtotal(),
                            'total' => $item->get_total(),
                            'tax' => $item->get_total_tax(),
                        ]
                    );
                }

                foreach ( $originalOrder->get_items('shipping') as $shipping_item_id => $shipping_item ) {
                    $new_shipping_item = new WC_Order_Item_Shipping();
                    
                    // Copy shipping line from the original order.
                    $new_shipping_item->set_method_title( $shipping_item->get_method_title() );
                    $new_shipping_item->set_method_id( $shipping_item->get_method_id() );
                    $new_shipping_item->set_total( $shipping_item->get_total() );
                    $new_shipping_item->set_taxes( $shipping_item->get_taxes() );
                    
                    $newOrder->add_item( $new_shipping_item );
                }

                $current_user_id = $originalOrder->get_user_id();
                $shipping_address = [
                    'first_name' => get_user_meta($current_user_id, 'shipping_first_name', true),
                    'last_name'  => get_user_meta($current_user_id, 'shipping_last_name', true),
                    'company'    => get_user_meta($current_user_id, 'shipping_company', true),
                    'address_1'  => get_user_meta($current_user_id, 'shipping_address_1', true),
                    'address_2'  => get_user_meta($current_user_id, 'shipping_address_2', true),
                    'city'       => get_user_meta($current_user_id, 'shipping_city', true),
                    'postcode'   => get_user_meta($current_user_id, 'shipping_postcode', true),
                    'country'    => get_user_meta($current_user_id, 'shipping_country', true),
                    'state'      => get_user_meta($current_user_id, 'shipping_state', true),
                ];

                $newOrder->set_address($originalOrder->get_address('billing'), 'billing');
                $newOrder->set_address($shipping_address, 'shipping');
                $newOrder->set_customer_id($originalOrder->get_customer_id());
                $newOrder->set_payment_method($originalOrder->get_payment_method());
                $newOrder->set_payment_method_title($originalOrder->get_payment_method_title());
                $newOrder->set_parent_id($firstTransaction['order_id']);
                $newOrder->set_date_paid(date('Y-m-d H:i:s', $subscriptionTransaction['date']));
                $newOrder->add_meta_data('payxpert_transaction_id', $subscriptionTransaction['transactionID']);

                $transactionInfo = WC_Payxpert_Webservice::get_transaction_info($configuration, $subscriptionTransaction['transactionID']);
                if (empty($transactionInfo['error'])) {
                    $newOrder->add_meta_data('payxpert_payment_id', $transactionInfo['payment_id']);
                }

                $is_success = $subscriptionTransaction['errorCode'] == Payxpert_Payment_Transaction::RESULT_CODE_SUCCESS;
                
                $newOrder->calculate_totals();
                // Sub price might be different from total_product + total_shipping
                $newOrder->set_total(wc_format_decimal( $subscription['subscription']['amount']/100, wc_get_price_decimals() ));
                $newOrder->set_shipping_total(0);

                $newOrder->add_order_note(__('New order created by subscription.', 'payxpert'));

                if ($is_success) {
                    // Payment OK
                    $newOrder->payment_complete($subscriptionTransaction['transactionID']);
                } else {
                    // Payment KO
                    $newOrder->update_status(
                        'failed',
                        __('Payment failed on subscription renewal.', 'payxpert')
                    );
                }

                WC_Payxpert_Logger::info('New order created [originalOrderID: ' . $originalOrder->get_id() . ', newOrderID: ' . $newOrder->get_id() . ']');

                $orderId = $newOrder->get_id();
            } else {
                $orderId = $firstTransaction['order_id'];
            }

            // Persist transaction row for this order.
            $createTransaction($firstTransaction, $subscriptionTransaction, $orderId);
            WC_Payxpert_Logger::info('Create transaction [transactionID : ' . $subscriptionTransaction['transactionID'] . ']');
            $updated = true;
        }

        // Update local subscription row from API payload.
        $payxpertSubscriptionArray = Payxpert_Subscription::findBySubscriptionId((string)$firstTransaction['subscription_id']);
        $subscriptionInfo = self::to_snake_case_keys($subscription['subscription']);
        $payxpertSubscription = Payxpert_Subscription::get_instance_from_array($payxpertSubscriptionArray);
        $payxpertSubscription->set($subscriptionInfo);
        $payxpertSubscription->save();

        // TODO : maybe status 'finished' is better
        if ($updated && $subscriptionType == 'partpayment' && ($subscriptionInfo['iterations_left'] ?? 0) == 0) {
            $order = wc_get_order($firstTransaction['order_id']);
            if ($order) {
                $new_status = $order->needs_processing() ? 'processing' : 'completed';
                $order->update_status($new_status, __('Status updated after payment captured via PayXpert.', 'payxpert'));
            }
        }

        return true;
    }


    public static function is_installment_payment_available($configuration, $amount)
    {
        $min = $configuration['payxpert_instalment_payment_min_amount'];
        $max = $configuration['payxpert_instalment_payment_max_amount'];

        return $min < $amount && (!$max || $max >= $amount);
    }

    public static function has_subscription_in_cart( $cart, $return_meta = false ) {
        return self::get_subscription_from_items(
            $cart,
            fn( $item ) => $item['data'],
            fn( $item ) => $item['quantity'],
            $return_meta
        );
    }

    public static function has_subscription_in_order( $order, $return_meta = false ) {
        if ( ! $order instanceof WC_Order ) {
            return false;
        }

        return self::get_subscription_from_items(
            $order->get_items(),
            fn( $item ) => $item->get_product(),
            fn( $item ) => $item->get_quantity(),
            $return_meta
        );
    }

    private static function get_subscription_from_items( $items, callable $get_product, callable $get_quantity, $return_meta = false ) {
        foreach ( $items as $item ) {

            $product = $get_product( $item );
            if ( ! $product ) {
                continue;
            }

            if ( $product->get_meta( '_payxpert_subscription' ) === 'yes' ) {

                if ( ! $return_meta ) {
                    return true;
                }

                $subscription_meta = [];

                foreach ( $product->get_meta_data() as $meta ) {
                    if ( strpos( $meta->key, '_payxpert_subscription' ) === 0 ) {
                        $subscription_meta[ $meta->key ] = $meta->value;
                    }
                }

                $subscription_meta['quantity'] = $get_quantity( $item );

                return $subscription_meta;
            }
        }

        return false;
    }

    public static function get_period_by_frequency($frequency)
    {
        $frequency_map = [
            // Journalier
            'daily' => 'P1D',
            'weekly' => 'P1W',
            'monthly' => 'P1M',
            'quarterly' => 'P3M',
            'yearly' => 'P12M'
        ];

        return $frequency_map[$frequency];
    }

    /**
     * Convert a subscription frequency code into human-readable English text.
     * Translatable via WordPress.
     *
     * @param string $frequency
     * @return string
     */
    public static function get_humanize_subscription_frequency( $frequency ) {

        switch ( $frequency ) {

            case 'daily':
                return __( "every day", 'payxpert' );

            case 'weekly':
                return __( "every week", 'payxpert' );

            case 'monthly':
                return __( "every month", 'payxpert' );

            case 'quarterly':
                return __( "every 3 months", 'payxpert' );

            case 'yearly':
                return __( "every year", 'payxpert' );

            default:
                return __( "frequency not specified", 'payxpert' );
        }
    }

    /**
     * Return a human-readable first payment description.
     * Translatable via WordPress.
     *
     * @param string $rule
     * @param string $frequency
     * @param string $durationType  'finite' | 'infinite'
     * @param int|string $for       Number of occurrences (e.g. 5)
     * @return string
     */
    public static function get_subscription_first_payment_text(
        $rule,
        $frequency,
        $durationType,
        $for
    ) {
        $frequency_text = self::get_humanize_subscription_frequency( $frequency );

        // Only handling "immediate" for now
        if ( $rule !== 'immediate' && ! empty( $rule ) ) {
            return esc_html__( $rule, 'payxpert' );
        }

        // Infinite duration
        if ( $durationType === 'infinite' ) {
            return sprintf(
                /* translators: %s = frequency */
                __( 'First payment today, then %s.', 'payxpert' ),
                $frequency_text
            );
        }

        // Finite duration (X times)
        return sprintf(
            /* translators: 1: frequency, 2: number of times */
            __( 'First payment today, then %1$s (%2$s times).', 'payxpert' ),
            $frequency_text,
            esc_html( $for )
        );
    }

    /**
     * Returns the PayXpert gateway icon file name for the given configuration.
     *
     * @param array  $configuration Gateway settings.
     * @param string $gateway_id    Gateway ID.
     *
     * @return string|null Public URL to the icon asset.
     */
    public static function get_gateway_icon_name(array $configuration, string $gateway_id)
    {
        if (in_array($gateway_id, [
            WC_Payment_Gateway_Payxpert_Installment_X2::ID,
            WC_Payment_Gateway_Payxpert_Installment_X3::ID,
            WC_Payment_Gateway_Payxpert_Installment_X4::ID,
        ])) {
            return null;
        }

        $hasAmex = isset($configuration['payxpert_amex_enabled']) 
                    ? $configuration['payxpert_amex_enabled'] === 'yes' 
                    : true;

        $hasApplePay = isset($configuration['payxpert_applepay_enabled']) 
                    ? $configuration['payxpert_applepay_enabled'] === 'yes' 
                    : false;

        $icon = 'cc'; // default

        if (!$hasAmex && !$hasApplePay) {
            $icon = 'cc-no-amex-no-applepay';
        } elseif (!$hasAmex) {
            $icon = 'cc-no-amex';
        } elseif (!$hasApplePay) {
            $icon = 'cc-no-applepay';
        }

        return plugins_url('assets/img/payment_method_logo/' . $icon . '.jpg', WC_PAYXPERT_PLUGIN_NAME);
    }

    public static function get_gateway_block_icons(array $configuration)
    {
        $icons = [Payxpert_Payment_Transaction::VISA_ICON, Payxpert_Payment_Transaction::MASTERCARD_ICON];
        if ($configuration['payxpert_amex_enabled'] === 'yes') {
            $icons[] = Payxpert_Payment_Transaction::AMEX_ICON;
        }
        if ($configuration['payxpert_applepay_enabled'] === 'yes') {
			$icons[] = Payxpert_Payment_Transaction::APPLEPAY_ICON;
		}

        return $icons;
    }
}
