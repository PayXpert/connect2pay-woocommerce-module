<?php

namespace Payxpert\Utils;

defined( 'ABSPATH' ) || exit();

use Payxpert\Models\Payxpert_Cron_Log;
use Payxpert\Models\Payxpert_Payment_Token;
use Payxpert\Models\Payxpert_Payment_Transaction;
use Payxpert\Models\Payxpert_Subscription;
use Payxpert\Utils\WC_Payxpert_Utils;
use Payxpert\Utils\WC_Payxpert_Webservice;

class WC_Payxpert_Cron {
    const EXECUTE_SUCCESS = 0;
    const EXECUTE_FAILURE = 1;

    const TYPE_ERROR   = 'error';
    const TYPE_INFO    = 'info';
    const TYPE_COMMENT = 'comment';

    const INTERVAL_DAYS = [6, 28];
    const MAX_DAYS = 30;

    /** @var bool */
    private $hasError = false;
    private $start;

    /** @var array<array{type:string,message:string}> */
    private $outputBuffer = [];

    /** @var int */
    private $cronType;

    public static function install() {
        if (!wp_next_scheduled('payxpert_sync_subscriptions_cron')) {
            wp_schedule_event(time(), 'hourly', 'payxpert_sync_subscriptions_cron');
        }
        if (!wp_next_scheduled('payxpert_cleanup_payment_tokens_cron')) {
            wp_schedule_event(time(), 'daily', 'payxpert_cleanup_payment_tokens_cron');
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook('payxpert_sync_subscriptions_cron');
        wp_clear_scheduled_hook('payxpert_cleanup_payment_tokens_cron');
    }

    /**
     * Scheduled by WordPress cron via {@see wp_schedule_event()}.
     */
    public function sync_subscriptions_cron() {
        WC_Payxpert_Logger::debug('start');

        $this->start = microtime(true);
        $this->cronType = Payxpert_Cron_Log::CRON_TYPE_INSTALLMENT;

        $subscriptions = Payxpert_Subscription::get_need_synchronization();
        $cfg = WC_Payxpert_Utils::get_configuration();
        $count = count($subscriptions);

        if ($count === 0) {
            $this->writeln('All subscriptions are already synchronized.', self::TYPE_INFO);
            return $this->finalize();
        }
        $this->writeln("Beginning synchronization of {$count} subscription(s)", self::TYPE_INFO);

        foreach ($subscriptions as $sub) {
            try {
                $tx = Payxpert_Payment_Transaction::findByTransactionId((string) $sub['transaction_id']);
                if (!$tx) {
                    $this->writeln(
                        "No initial transaction found for [transactionID={$sub['transaction_id']}]", 
                        self::TYPE_ERROR
                    );
                    continue;
                }

                $info = WC_Payxpert_Webservice::get_status_subscription($cfg, $sub['subscription_id']);

                if (isset($info['error'])) {
                    $this->writeln(
                        "Subscription API error [subscriptionID={$sub['subscription_id']}]: {$info['error']}",
                        self::TYPE_ERROR
                    );
                    continue;
                }

                if (WC_Payxpert_Utils::sync_subscription($cfg, $info, $tx)) {
                    $this->writeln(
                        "Subcription {$sub['subscription_id']} updated",
                        self::TYPE_INFO
                    );
                } else {
                    $this->writeln(
                        "Subcription {$sub['subscription_id']} NOT updated (weirdly)",
                        self::TYPE_INFO
                    );
                }
            } catch (\Throwable $e) {
                $this->writeln(
                    "Critical error for [ID={$sub['id_payxpert_subscription']}] : ".$e->getMessage(),
                    self::TYPE_ERROR
                );
            }
        }

        return $this->finalize();
    }

    /**
     * Cleans preparePayment tokens with no order (order_id = 0) based on age rules.
     */
    public function cleanup_payment_tokens_cron(): void {
        WC_Payxpert_Logger::debug('payxpert_cleanup_payment_tokens_cron start');

        $this->start = microtime(true);
        $this->cronType = Payxpert_Cron_Log::CRON_TYPE_TOKEN_CLEANUP;
        $this->hasError = false;
        $this->outputBuffer = [];

        try {
            $deleted = Payxpert_Payment_Token::delete_expired_orphan_tokens();
            $this->writeln(
                sprintf('Deleted %d orphan payment token row(s) (order_id=0, age rules applied).', $deleted),
                self::TYPE_INFO
            );
        } catch (\Throwable $e) {
            $this->writeln('Token cleanup failed: ' . $e->getMessage(), self::TYPE_ERROR);
        }

        $this->finalize();
    }

    private function writeln(string $msg, string $type)
    {
        $this->outputBuffer[] = ['type' => $type, 'message' => $msg];
        if ($type === self::TYPE_ERROR) {
            $this->hasError = true;
        }
    }

    /**
     * Persists the log entry and returns the result.
     *
     * @return array{status:int,messages:array,duration:float}
     */
    private function finalize(): array
    {
        $duration = microtime(true) - $this->start;
        $status = $this->hasError ? Payxpert_Cron_Log::STATUS_ERROR : Payxpert_Cron_Log::STATUS_SUCCESS;

        $log = new Payxpert_Cron_Log();
        $log->set([
            'cron_type' => $this->cronType,
            'duration' => $duration,
            'status' => $status,
            'context' => json_encode($this->outputBuffer),
            'has_error' => $this->hasError,
        ]);
        $log->save();

        return [
            'status'   => $status,
            'messages' => $this->outputBuffer,
            'duration' => $duration,
        ];
    }
}
