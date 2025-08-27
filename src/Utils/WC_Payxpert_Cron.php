<?php

namespace Payxpert\Utils;

defined( 'ABSPATH' ) || exit();

use Payxpert\Models\Payxpert_Cron_Log;
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
        if (!wp_next_scheduled('payxpert_sync_installment_transactions_cron')) {
            wp_schedule_event(time(), 'hourly', 'payxpert_sync_installment_transactions_cron');
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook('payxpert_sync_installment_transactions_cron');
    }

    /**
     * DEV
     */
    public static function manual_trigger() {
        add_action('admin_init', function () {
            if (isset($_GET['payxpert_cron_test']) && current_user_can('manage_woocommerce')) {
                self::sync_installment_transactions_cron();
                wp_die('Payxpert cron exécuté.');
            }
        });
    }

    /**
     * Hook appelé automatiquement via le cron WordPress : wp_schedule_event
     */
    public function sync_installment_transactions_cron() {
        WC_Payxpert_Logger::debug('start');

        $this->start = microtime(true);
        $this->cronType = Payxpert_Cron_Log::CRON_TYPE_INSTALLMENT;

        $installments = Payxpert_Subscription::get_need_synchronization();
        $cfg = WC_Payxpert_Utils::get_configuration();

        $count = count($installments);
        if ($count === 0) {
            $this->writeln('All installment are already synchronized.', self::TYPE_INFO);
            return $this->finalize();
        }

        $this->writeln("Beginning synchronization of {$count} installment(s)", self::TYPE_INFO);

        foreach ($installments as $inst) {
            try {
                $tx = Payxpert_Payment_Transaction::findByTransactionId((string) $inst['transaction_id']);
                if (!$tx) {
                    $this->writeln(
                        "No initial transaction found for [installmentID={$inst['id_payxpert_subscription']}]", 
                        self::TYPE_ERROR
                    );
                    continue;
                }

                $info = WC_Payxpert_Webservice::get_status_subscription($cfg, $inst['subscription_id']);

                if (WC_Payxpert_Utils::sync_subscription($info, $tx)) {
                    $this->writeln(
                        "Subcription {$inst['subscription_id']} updated",
                        self::TYPE_INFO
                    );
                } else {
                    $this->writeln(
                        "Subcription {$inst['subscription_id']} NOT updated (weirdly)",
                        self::TYPE_INFO
                    );
                }
            } catch (\Exception $e) {
                $this->writeln(
                    "Critical error for [ID={$inst['id_payxpert_subscription']}] : ".$e->getMessage(),
                    self::TYPE_ERROR
                );
            }
        }

        return $this->finalize();
    }

    private function writeln(string $msg, string $type)
    {
        $this->outputBuffer[] = ['type' => $type, 'message' => $msg];
        if ($type === self::TYPE_ERROR) {
            $this->hasError = true;
        }
    }

    /**
     * Enregistre le log et retourne le résultat
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
