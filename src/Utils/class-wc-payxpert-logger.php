<?php
/**
 * Wc Payxpert Logger.
 *
 * @package Payxpert
 */

namespace Payxpert\Utils;

defined( 'ABSPATH' ) || exit();

/**
 * WC Payxpert Logger.
 */
class WC_Payxpert_Logger {
	const FOLDER   = 'logs';
	const FILENAME = 'debug';

	const EMERGENCY = 1;
	const ALERT     = 2;
	const CRITICAL  = 3;
	const ERROR     = 4;
	const WARN      = 5;
	const NOTICE    = 6;
	const INFO      = 7;
	const DEBUG     = 8;

	/**
	 * Severity level codes mapped to their labels.
	 *
	 * @var array<int, string>
	 */
	protected static $levels = array(
		self::EMERGENCY => 'EMERGENCY',
		self::ALERT     => 'ALERT',
		self::CRITICAL  => 'CRITICAL',
		self::ERROR     => 'ERROR',
		self::WARN      => 'WARNING',
		self::NOTICE    => 'NOTICE',
		self::INFO      => 'INFO',
		self::DEBUG     => 'DEBUG',
	);

	/**
	 * Write a message to the log at this severity level.
	 *
	 * @param string $msg Message to log.
	 * @return int|false
	 */
	public static function emergency( $msg ) {
		return self::log( $msg, self::EMERGENCY );
	}

	/**
	 * Write a message to the log at this severity level.
	 *
	 * @param string $msg Message to log.
	 * @return int|false
	 */
	public static function alert( $msg ) {
		return self::log( $msg, self::ALERT );
	}

	/**
	 * Write a message to the log at this severity level.
	 *
	 * @param string $msg Message to log.
	 * @return int|false
	 */
	public static function critical( $msg ) {
		return self::log( $msg, self::CRITICAL );
	}

	/**
	 * Write a message to the log at this severity level.
	 *
	 * @param string $msg Message to log.
	 * @return int|false
	 */
	public static function error( $msg ) {
		return self::log( $msg, self::ERROR );
	}

	/**
	 * Write a message to the log at this severity level.
	 *
	 * @param string $msg Message to log.
	 * @return int|false
	 */
	public static function warn( $msg ) {
		return self::log( $msg, self::WARN );
	}

	/**
	 * Write a message to the log at this severity level.
	 *
	 * @param string $msg Message to log.
	 * @return int|false
	 */
	public static function notice( $msg ) {
		return self::log( $msg, self::NOTICE );
	}

	/**
	 * Write a message to the log at this severity level.
	 *
	 * @param string $msg Message to log.
	 * @return int|false
	 */
	public static function info( $msg ) {
		return self::log( $msg, self::INFO );
	}

	/**
	 * Write a message to the log at this severity level.
	 *
	 * @param string $msg Message to log.
	 * @return int|false
	 */
	public static function debug( $msg ) {
		return self::log( $msg, self::DEBUG );
	}

	/**
	 * Write a message to the log at this severity level.
	 *
	 * @param string $msg          Message to log.
	 * @param int    $level_degree Severity level.
	 * @return int|false
	 */
	private static function log( $msg, int $level_degree ) {
		if ( ! isset( self::$levels[ $level_degree ] ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Direct append with an exclusive lock to a plugin log file; WP_Filesystem offers no atomic locked-append equivalent.
		return file_put_contents(
			self::get_log_file_path(),
			sprintf(
				'[%s][%s] : %s' . PHP_EOL,
				gmdate( 'Y-m-d H:i:s' ),
				self::$levels[ $level_degree ],
				$msg
			),
			FILE_APPEND | LOCK_EX
		);
	}

	/**
	 * Get log file path.
	 *
	 * @return string
	 */
	public static function get_log_file_path(): string {
		return __DIR__ . '/../../' . self::FOLDER . '/' . self::FILENAME . '.log';
	}
}
