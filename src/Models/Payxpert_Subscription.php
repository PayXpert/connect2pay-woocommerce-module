<?php

namespace Payxpert\Models;

use WC_Product;

defined('ABSPATH') || exit;

class Payxpert_Subscription extends Payxpert_Abstract_Model {
    const STATUS_DISABLED = 'disabled';
    const STATUS_CANCELED = 'canceled';
    const STATUS_ACTIVE = 'active';

    const CUSTOMER_CANCEL_REASONS = [
        '1007' => "Didn’t like the site",
        '1008' => "Disagree (‘did not do it’ or ‘do not recognize the transaction’)",
        '1010' => "I could not get in to the site",
        '1011' => "No problem, just moving on",
        '1012' => "Not enough updates",
        '1013' => "Problems with the movies/videos",
        '1014' => "Site was too slow",
        '1015' => "The site did not work",
        '1016' => "Too expensive",
        '1017' => "Un-authorized signup by family member",
        '1019' => "Webmaster requested to cancel",
        '1020' => "I haven’t received my item",
        '1021' => "The item was damaged or defective",
        '1022' => "The box was empty",
        '1023' => "The order was incomplete",
    ];

    const SERVER_CANCEL_REASONS = [
        '1000' => "Bank denial",
        '1001' => "Canceled due to refund",
        '1002' => "Canceled due to retrieval request",
        '1003' => "Cancellation letter sent by bank",
        '1004' => "Chargeback",
        '1005' => "Company account closed",
        '1006' => "Site account closed",
        '1009' => "Fraud from webmaster",
        '1018' => "Undetermined reasons",
        '1099' => "Unknown reason",
    ];

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
     * Returns paginated subscriptions with aggregated "sale" transaction counts.
     *
     * @param int   $per_page Items per page.
     * @param int   $offset   Pagination offset.
     * @param array $filters  Optional filters (status, dates, customer).
     * @return array
     */
    public static function get_all_with_transaction_counts($per_page = 10, $offset = 0, $filters = array()) {
        global $wpdb;

        $subscription_table = $wpdb->prefix . self::TABLE_NAME;
        $transaction_table  = $wpdb->prefix . 'payxpert_payment_transaction';
        $users_table        = $wpdb->prefix . 'users';
    
        $where = '1=1';
    
        // Status
        if ( ! empty($filters['status']) ) {
            $where .= $wpdb->prepare(' AND s.state = %s', $filters['status']);
        }
    
        // Dates
        if ( ! empty($filters['date_from']) ) {
            $where .= $wpdb->prepare(' AND s.date_add >= %s', $filters['date_from']);
        }
    
        if ( ! empty($filters['date_to']) ) {
            $where .= $wpdb->prepare(' AND s.date_add <= %s', $filters['date_to']);
        }
    
        // Customer
        if ( ! empty($filters['customer']) ) {
            $like = '%' . $wpdb->esc_like($filters['customer']) . '%';
            $where .= $wpdb->prepare(
                ' AND (u.display_name LIKE %s OR u.user_email LIKE %s)',
                $like,
                $like
            );
        }
    
        $data_sql = "
            SELECT
                s.*,
                t.order_id,
                u.display_name AS customer_name,
                u.user_email AS customer_email,
                (
                    SELECT COUNT(*)
                    FROM {$transaction_table} t2
                    WHERE t2.subscription_id = s.subscription_id
                    AND t2.operation = 'sale'
                ) AS sale_transactions_count
            FROM {$subscription_table} s
            LEFT JOIN {$transaction_table} t
                ON t.transaction_id = s.transaction_id
            LEFT JOIN {$users_table} u
                ON u.ID = s.customer_id
            WHERE {$where}
            ORDER BY s.period_start DESC
            LIMIT %d OFFSET %d
        ";
    
        $results = $wpdb->get_results(
            $wpdb->prepare($data_sql, $per_page, $offset)
        );
    
        $count_sql = "
            SELECT COUNT(*)
            FROM {$subscription_table} s
            LEFT JOIN {$users_table} u
                ON u.ID = s.customer_id
            WHERE {$where}
        ";
    
        $total = (int) $wpdb->get_var($count_sql);
    
        return [
            'items' => $results,
            'total_items' => $total,
            'per_page' => $per_page,
            'offset' => $offset,
        ];
    }


    public static function get_need_synchronization(): array {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

        $results = $wpdb->get_results(
            "
            SELECT transaction_id, id_payxpert_subscription, subscription_id
            FROM {$table}
            WHERE UNIX_TIMESTAMP(NOW()) > period_end
            AND state = 'active'
            AND period_end != 0
            LIMIT 500
            ",
            ARRAY_A
        );

        return $results ?: [];
    }

    public static function count_all(): int {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    /**
     * Returns subscriptions for a given user.
     *
     * @param int $user_id User ID.
     * @return array|false
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
                    t.order_id DESC,
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

    public static function get_subscription_details( $subscription_id ) {
        global $wpdb;

        if ( ! $subscription_id ) {
            return false;
        }

        $subscription_table = $wpdb->prefix . self::TABLE_NAME;
        $transaction_table  = $wpdb->prefix . Payxpert_Payment_Transaction::TABLE_NAME;
        $users_table        = $wpdb->prefix . 'users';

        $sub = $wpdb->get_row(
            $wpdb->prepare(
                "
                SELECT 
                    s.*,
                    t.order_id,
                    u.user_email,
                    u.display_name
                FROM {$subscription_table} s
                LEFT JOIN {$transaction_table} t
                    ON t.transaction_id = s.transaction_id
                LEFT JOIN {$users_table} u
                    ON u.ID = s.customer_id
                WHERE s.subscription_id = %d
                LIMIT 1
                ",
                $subscription_id
            )
        );

        if ( ! $sub ) {
            return false;
        }

        $sub->transactions = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT
                    order_id,
                    transaction_id,
                    subscription_id,
                    amount, 
                    currency,
                    result_code,
                    result_message,
                    date_add,
                    card_brand,
                    operation
                FROM {$transaction_table} 
                WHERE subscription_id = %d
                ORDER BY date_add ASC
                ",
                (int) $subscription_id
            )
        );

        $sub->customer_email = $sub->user_email;
        $sub->customer_name  = $sub->display_name;
        $sub->product_id        = null;
        $sub->variation_id      = null;
        $sub->product_name      = '';
        $sub->product_quantity  = 0;

        if ( ! empty($sub->order_id) ) {

            $order = wc_get_order( $sub->order_id );

            if ( $order ) {
                foreach ( $order->get_items() as $item ) {
                    $product = $item->get_product();
                    if ( ! $product ) {
                        continue;
                    }

                    $sub->product_id       = $item->get_product_id();
                    $sub->variation_id     = $item->get_variation_id();
                    $sub->product_quantity = (int) $item->get_quantity();
                    $sub->product_name = $product->get_name();

                    // Use the first line item only.
                    break;
                }
            }
        }

        return $sub;
    }

}
