<?php

namespace Payxpert\Models;

defined('ABSPATH') || exit;

class Payxpert_Subscription extends Payxpert_Abstract_Model {
    const TABLE_NAME = 'payxpert_subscription';

    protected $fillable = [
        'id_payxpert_subscription',
        'customer_id',
        'subscription_id',
        'subscription_type',
        'offer_id',
        'transaction_id',
        'amount',
        'period',
        'trial_amount',
        'trial_period',
        'state',
        'subscription_start',
        'period_start',
        'period_end',
        'cancel_date',
        'cancel_reason',
        'iterations',
        'iterations_left',
        'retries',
        'date_upd',
    ];

    protected $primary_key = 'id_payxpert_subscription';

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . self::TABLE_NAME;
    }

    public static function findBySubscriptionId(string $subscription_id): ?array {
        return static::findOneBy(['subscription_id' => $subscription_id]);
    }

    /**
     * Récupère les abonnements paginés avec le compte des transactions "sale".
     *
     * @param int $per_page Nombre d'éléments par page
     * @param int $offset Décalage pour la pagination
     * @return array Liste des abonnements enrichis
     */
    public static function get_all_with_transaction_counts($per_page = 20, $offset = 0) {
        global $wpdb;

        $subscription_table = $wpdb->prefix . self::TABLE_NAME;
        $transaction_table = $wpdb->prefix . 'payxpert_payment_transaction';

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT SQL_CALC_FOUND_ROWS
                    s.*,
                    t.order_id,
                    (
                        SELECT COUNT(*)
                        FROM {$transaction_table} t2
                        WHERE t2.subscription_id = s.subscription_id
                        AND t2.operation = 'sale'
                    ) AS sale_transactions_count
                FROM {$subscription_table} s
                LEFT JOIN {$transaction_table} t
                    ON t.transaction_id = s.transaction_id
                ORDER BY s.period_start DESC
                LIMIT %d OFFSET %d
                ",
                $per_page,
                $offset
            )
        );

        return $results;
    }

    public static function get_need_synchronization(): array {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

        $results = $wpdb->get_results(
            "
            SELECT transaction_id, id_payxpert_subscription, subscription_id
            FROM {$table}
            WHERE UNIX_TIMESTAMP(NOW()) > period_end
            AND period_end != 0
            ",
            ARRAY_A
        );

        return $results ?: [];
    }

    /**
     * Récupère les abonnements d'un utilisateur
     *
     * @param int $user_id
     * @return WP_Post[]|false
     */
    public static function get_user_subscriptions( $user_id, $limit = 10, $offset = 0 ) {
        global $wpdb;

        if ( ! $user_id ) {
            return false;
        }

        $subscription_table = $wpdb->prefix . self::TABLE_NAME;
        $transaction_table = $wpdb->prefix . Payxpert_Payment_Transaction::TABLE_NAME;

        $results = $wpdb->get_results(
            $wpdb->prepare("
                SELECT 
                    s.*,
                    t.order_id
                FROM {$subscription_table} s
                LEFT JOIN {$transaction_table} t
                    ON t.transaction_id = s.transaction_id
                WHERE s.customer_id = %d
                ORDER BY 
                    CASE 
                        WHEN s.period_end = 0 OR s.period_end IS NULL THEN 1
                        ELSE 0
                    END,
                    s.period_end ASC,
                    s.subscription_start DESC
                LIMIT %d OFFSET %d
            ", $user_id, $limit, $offset )
        );

        return $results ?: false;
    }

    public static function count_user_subscriptions( $user_id ) {
        global $wpdb;

        if ( ! $user_id ) {
            return 0;
        }

        $subscription_table = $wpdb->prefix . self::TABLE_NAME;

        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$subscription_table} WHERE customer_id = %d", $user_id)
        );
    }
}
