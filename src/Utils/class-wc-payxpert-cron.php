<?php
/**
 * Wc Payxpert Cron.
 *
 * @package Payxpert
 */

namespace Payxpert\Utils;

defined( 'ABSPATH' ) || exit();

use Payxpert\Models\Payxpert_Cron_Log;
use Payxpert\Models\Payxpert_Payment_Token;
use Payxpert\Models\Payxpert_Payment_Transaction;
use Payxpert\Models\Payxpert_Subscription;
use Payxpert\Utils\WC_Payxpert_Utils;
use Payxpert\Utils\WC_Payxpert_Webservice;

/**
 * WC Payxpert Cron.
 */
class WC_Payxpert_Cron {
	const EXECUTE_SUCCESS = 0;
	const EXECUTE_FAILURE = 1;

	const TYPE_ERROR   = 'error';
	const TYPE_INFO    = 'info';
	const TYPE_COMMENT = 'comment';

	const INTERVAL_DAYS = array( 6, 28 );
	const MAX_DAYS      = 30;

	/**
	 * Whether the current cron run encountered an error.
	 *
	 * @var bool
	 */
	private $has_error = false;
	/**
	 * Start.
	 *
	 * @var mixed
	 */
	private $start;

	/**
	 * Collected log lines for the current cron run.
	 *
	 * @var array<array{type:string,message:string}>
	 */
	private $output_buffer = array();

	/**
	 * Type of cron run being executed.
	 *
	 * @var int
	 */
	private $cron_type;

	/**
	 * Install.
	 *
	 * @return mixed
	 */
	public static function install() {
		if ( ! wp_next_scheduled( 'payxpert_sync_subscriptions_cron' ) ) {
			wp_schedule_event( time(), 'hourly', 'payxpert_sync_subscriptions_cron' );
		}
		if ( ! wp_next_scheduled( 'payxpert_cleanup_payment_tokens_cron' ) ) {
			wp_schedule_event( time(), 'daily', 'payxpert_cleanup_payment_tokens_cron' );
		}
	}

	/**
	 * Deactivate.
	 *
	 * @return mixed
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'payxpert_sync_subscriptions_cron' );
		wp_clear_scheduled_hook( 'payxpert_cleanup_payment_tokens_cron' );
	}

	/**
	 * Scheduled by WordPress cron via {@see wp_schedule_event()}.
	 */
	public function sync_subscriptions_cron(): void {
		WC_Payxpert_Logger::debug( 'start' );

		$this->start     = microtime( true );
		$this->cron_type = Payxpert_Cron_Log::CRON_TYPE_INSTALLMENT;

		$subscriptions = Payxpert_Subscription::get_need_synchronization();
		$cfg           = WC_Payxpert_Utils::get_configuration();
		$count         = count( $subscriptions );

		if ( 0 === $count ) {
			$this->writeln( 'All subscriptions are already synchronized.', self::TYPE_INFO );
			$this->finalize();
			return;
		}
		$this->writeln( "Beginning synchronization of {$count} subscription(s)", self::TYPE_INFO );

		foreach ( $subscriptions as $sub ) {
			try {
				$tx = Payxpert_Payment_Transaction::find_by_transaction_id( (string) $sub['transaction_id'] );
				if ( ! $tx ) {
					$this->writeln(
						"No initial transaction found for [transactionID={$sub['transaction_id']}]",
						self::TYPE_ERROR
					);
					continue;
				}

				$info = WC_Payxpert_Webservice::get_status_subscription( $cfg, $sub['subscription_id'] );

				if ( isset( $info['error'] ) ) {
					$this->writeln(
						"Subscription API error [subscriptionID={$sub['subscription_id']}]: {$info['error']}",
						self::TYPE_ERROR
					);
					continue;
				}

				if ( WC_Payxpert_Utils::sync_subscription( $cfg, $info, $tx ) ) {
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
			} catch ( \Throwable $e ) {
				$this->writeln(
					"Critical error for [ID={$sub['id_payxpert_subscription']}] : " . $e->getMessage(),
					self::TYPE_ERROR
				);
			}
		}

		$this->finalize();
	}

	/**
	 * Cleans preparePayment tokens with no order (order_id = 0) based on age rules.
	 */
	public function cleanup_payment_tokens_cron(): void {
		WC_Payxpert_Logger::debug( 'payxpert_cleanup_payment_tokens_cron start' );

		$this->start         = microtime( true );
		$this->cron_type     = Payxpert_Cron_Log::CRON_TYPE_TOKEN_CLEANUP;
		$this->has_error     = false;
		$this->output_buffer = array();

		try {
			$deleted = Payxpert_Payment_Token::delete_expired_orphan_tokens();
			$this->writeln(
				sprintf( 'Deleted %d orphan payment token row(s) (order_id=0, age rules applied).', $deleted ),
				self::TYPE_INFO
			);
		} catch ( \Throwable $e ) {
			$this->writeln( 'Token cleanup failed: ' . $e->getMessage(), self::TYPE_ERROR );
		}

		$this->finalize();
	}

	/**
	 * Writeln.
	 *
	 * @param string $msg Msg.
	 * @param string $type Type.
	 * @return mixed
	 */
	private function writeln( string $msg, string $type ) {
		$this->output_buffer[] = array(
			'type'    => $type,
			'message' => $msg,
		);
		if ( self::TYPE_ERROR === $type ) {
			$this->has_error = true;
		}
	}

	/**
	 * Persists the log entry and returns the result.
	 *
	 * @return array{status:int,messages:list<array{type:string,message:string}>,duration:float}
	 */
	private function finalize(): array {
		$duration = microtime( true ) - $this->start;
		$status   = $this->has_error ? Payxpert_Cron_Log::STATUS_ERROR : Payxpert_Cron_Log::STATUS_SUCCESS;

		$log = new Payxpert_Cron_Log();
		$log->set(
			array(
				'cron_type' => $this->cron_type,
				'duration'  => $duration,
				'status'    => $status,
				'context'   => wp_json_encode( $this->output_buffer ),
				'has_error' => $this->has_error,
			)
		);
		$log->save();

		return array(
			'status'   => $status,
			'messages' => $this->output_buffer,
			'duration' => $duration,
		);
	}
}
