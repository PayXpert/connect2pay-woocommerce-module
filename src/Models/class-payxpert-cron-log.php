<?php
/**
 * Payxpert Cron Log.
 *
 * @package Payxpert
 */

namespace Payxpert\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Payxpert Cron Log.
 */
class Payxpert_Cron_Log extends Payxpert_Abstract_Model {
	const TABLE_NAME = 'payxpert_cron_log';

	const CRON_TYPE_INSTALLMENT   = 0;
	const CRON_TYPE_REMINDER      = 1;
	const CRON_TYPE_TOKEN_CLEANUP = 2;

	const STATUS_SUCCESS = 0;
	const STATUS_ERROR   = 1;

	/**
	 * Fillable.
	 *
	 * @var list<string>
	 */
	protected $fillable = array(
		'id_payxpert_cron_log',
		'cron_type',
		'duration',
		'status',
		'context',
		'has_error',
	);

	/**
	 * Primary key.
	 *
	 * @var string
	 */
	protected $primary_key = 'id_payxpert_cron_log';

	/**
	 * Construct.
	 */
	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . self::TABLE_NAME;
	}
}
