<?php

use Payxpert\Models\Payxpert_Subscription;
use Payxpert\Utils\WC_Payxpert_Utils;

defined( 'ABSPATH' ) || exit;

$current_user_id = get_current_user_id();
$per_page        = 5;
$page            = max( 1, intval( get_query_var( 'payxpert-subscriptions', 1 ) ) );
$offset          = ($page - 1) * $per_page;

$total_items     = Payxpert_Subscription::count_user_subscriptions( $current_user_id );
$total_pages     = ceil( $total_items / $per_page );

$subscriptions = Payxpert_Subscription::get_user_subscriptions( $current_user_id, $per_page, $offset );

?>

<h3><?php _e('Subscriptions', 'payxpert'); ?></h3>

<?php if ($subscriptions): ?>
    <table class="shop_table shop_table_responsive my_account_orders">
        <thead>
            <tr>
                <th><?php _e('Order ID', 'payxpert'); ?></th>
                <th><?php _e('Type', 'payxpert'); ?></th>
                <th><?php _e('Status', 'payxpert'); ?></th>
                <th><?php _e('Period', 'payxpert'); ?></th>
                <th><?php _e('Next Payment', 'payxpert'); ?></th>
                <th><?php _e('Amount', 'payxpert'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($subscriptions as $subscription): 
            $order_url = wc_get_account_endpoint_url( 'view-order' ) . $subscription->order_id;
        ?>
            <tr>
                <td>    
                    <a href="<?php echo esc_url( $order_url ); ?>">
                        #<?php echo esc_html( $subscription->order_id ); ?>
                    </a>
                </td>
                <td><?php echo $subscription->subscription_type; ?></td>
                <td><?php echo esc_html($subscription->state); ?></td>
                <td><?php echo esc_html(WC_Payxpert_Utils::render_human_period($subscription->period)); ?></td>
                <td><?php echo esc_html(
                    !empty($subscription->period_end)
                    ? date_i18n(get_option('date_format'), $subscription->period_end)
                    : '-'
                ); ?></td>
                <td><?php echo wc_price($subscription->amount / 100); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ( $total_pages > 1 ): ?>
        <div class="woocommerce-pagination woocommerce-pagination--without-numbers">
            <?php
             echo paginate_links([
                'base' => trailingslashit(wc_get_account_endpoint_url('payxpert-subscriptions')) . '%#%/',
                'format' => '%#%/',
                'current' => $page,
                'total' => $total_pages,
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
            ]);
            ?>
        </div>
    <?php endif; ?>

<?php else: ?>
    <p><?php _e('You have no active subscriptions.', 'payxpert'); ?></p>
<?php endif; ?>
