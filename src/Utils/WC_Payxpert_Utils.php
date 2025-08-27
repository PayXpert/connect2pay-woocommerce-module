<?php

declare(strict_types=1);

namespace Payxpert\Utils;

defined( 'ABSPATH' ) || exit();

use DateInterval;
use Payxpert\Models\Payxpert_Payment_Transaction;
use Payxpert\Models\Payxpert_Subscription;

require_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/admin/class-wc-payxpert-settings.php';

class WC_Payxpert_Utils
{
    public static function build_instalment_schedule($amount, $firstPercentage, $xTimes)
    {
        $schedule = [];
        $date = new \DateTime();

        // Récupérer la devise active de WooCommerce
        $currency = get_woocommerce_currency();

        // Vérification basique
        if (!$currency) {
            throw new \Exception('Currency not found.');
        }

        // Calculer les montants
        list($instalmentFirstAmount, $rebillAmount) = self::calculate_instalment_amounts(
            (int) round($amount * 100),
            intval($firstPercentage),
            $xTimes
        );

        // Premier paiement
        $schedule[] = [
            'amount' => $instalmentFirstAmount / 100,
            'amountFormatted' => wc_price($instalmentFirstAmount / 100, ['currency' => $currency]),
            'date' => date_i18n(get_option('date_format'), $date->getTimestamp()),
        ];

        // Les paiements suivants
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

    public static function sync_subscription(array $subscription, array $firstTransaction)
    {
        $updated = false;

        foreach ($subscription['transactionList'] as $subscriptionTransaction) {
            if ($subscriptionTransaction['transactionID'] == $firstTransaction['transaction_id']) {
                continue;
            }

            $transactionExist = Payxpert_Payment_Transaction::findByTransactionId((string) $subscriptionTransaction['transactionID']);
            if (!$transactionExist) {
                $payxpertPaymentTransaction = new Payxpert_Payment_Transaction();
                $payxpertPaymentTransaction->set(
                    array_merge(
                        $firstTransaction,
                        [
                            'id_payxpert_payment_transaction' => null,
                            'transaction_id' => $subscriptionTransaction['transactionID'],
                            'transaction_referal_id' => $subscriptionTransaction['referralID'],
                            'amount' => (float) ($subscriptionTransaction['amount']/100),
                            'result_code' => $subscriptionTransaction['errorCode'],
                            'result_message' => $subscriptionTransaction['status'],
                            'date_add' => date('Y-m-d H:i:s', $subscriptionTransaction['date'])
                        ]
                    )
                );
                WC_Payxpert_Logger::info('Create transaction [transactionID : ' . $subscriptionTransaction['transactionID'] . ']');
                $payxpertPaymentTransaction->save();
                $updated = true;
            }
        }

        if ($updated) {
            $payxpertSubscriptionArray = Payxpert_Subscription::findBySubscriptionId((string) $firstTransaction['subscription_id']);
            
            $subscriptionInfo = self::to_snake_case_keys($subscription['subscription']);
            $payxpertSubscription = Payxpert_Subscription::get_instance_from_array($payxpertSubscriptionArray);
            $payxpertSubscription->set($subscriptionInfo);
            WC_Payxpert_Logger::info('Update subscription [subcriptionID : ' . $firstTransaction['subscription_id'] . ']');
            $payxpertSubscription->save();
            
            // Update orderState to paid when all iterations have been done
            if ($subscriptionInfo['iterations_left'] == 0) {
                $order = wc_get_order($firstTransaction['order_id']);
                $new_status = $order->needs_processing() ? 'processing' : 'completed';
                    $order->update_status($new_status, __('Status updated after payment captured via PayXpert.', 'payxpert'));
                }
        }

        return $updated;
    }

    public static function is_installment_payment_available($configuration, $amount)
    {
        $min = $configuration['payxpert_instalment_payment_min_amount'];
        $max = $configuration['payxpert_instalment_payment_max_amount'];

        return $min < $amount && (!$max || $max >= $amount);
    }
}
