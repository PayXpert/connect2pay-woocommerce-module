<?php

namespace Payxpert\Models;

defined('ABSPATH') || exit;

class Payxpert_Cron_Log extends Payxpert_Abstract_Model {
    const TABLE_NAME = 'payxpert_cron_log';

    const CRON_TYPE_INSTALLMENT = 0;
    const CRON_TYPE_REMINDER = 1;

    CONST STATUS_SUCCESS = 0;
    CONST STATUS_ERROR = 1;

    protected $fillable = [
        'id_payxpert_cron_log',
        'cron_type',
        'duration',
        'status',
        'context',
        'has_error',
    ];

    protected $primary_key = 'id_payxpert_cron_log';

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . self::TABLE_NAME;
    }

    public function findByType(int $cron_type, int $limit = 10): array {
        return $this->findAll(['cron_type' => $cron_type], $limit);
    }
}
