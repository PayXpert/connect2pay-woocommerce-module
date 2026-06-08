<?php
use Payxpert\Models\Payxpert_Subscription;
use Payxpert\Models\Payxpert_Payment_Transaction;
use Payxpert\Utils\WC_Payxpert_Utils;

defined('ABSPATH') || exit;

$current_user_id  = get_current_user_id();
$subscription_id  = get_query_var('payxpert-subscription-details');

// Load subscription row for the current customer.
$subscription = Payxpert_Subscription::findOneBy([
    'subscription_id' => $subscription_id,
    'customer_id'     => $current_user_id
]);

if (!$subscription) {
    echo '<p>' . __('Subscription not found.', 'payxpert') . '</p>';
    return;
}

// Load transactions to resolve the original WooCommerce order.
$all_transactions_full = Payxpert_Payment_Transaction::findBy(['subscription_id' => $subscription_id]) ?? [];
$first_tx = $all_transactions_full[0] ?? null;
$original_order = $first_tx ? wc_get_order($first_tx['order_id']) : null;

// Transaction list pagination.
$page = get_query_var('paged') ? max(1, intval(get_query_var('paged'))) : 1;
$per_page = 5;
$offset   = ($page - 1) * $per_page;

// Sort transactions by date (newest first).
usort($all_transactions_full, function($a, $b){
    return ($b['date'] ?? 0) <=> ($a['date'] ?? 0);
});

$total_items = count($all_transactions_full);
$total_pages = ceil($total_items / $per_page);

$transactions = array_slice($all_transactions_full, $offset, $per_page);
?>

<!-- Subscription summary -->
<h3><?php echo sprintf(__('Subscription #%s', 'payxpert'), $subscription_id); ?></h3>

<p>
    <strong><?php _e('Status', 'payxpert'); ?>:</strong> <?php echo esc_html_e( $subscription['state'], 'payxpert' ); ?><br>
    <strong><?php _e('Type', 'payxpert'); ?>:</strong> <?php echo esc_html_e( $subscription['subscription_type'], 'payxpert' ); ?><br>
    <strong><?php _e('Recurring Amount', 'payxpert'); ?>:</strong> <?php echo wc_price($subscription['amount'] / 100); ?><br>
    <strong><?php _e('Period', 'payxpert'); ?>:</strong> <?php echo WC_Payxpert_Utils::render_human_period($subscription['period']); ?>
</p>

<?php if ( isset($subscription['subscription_type']) && $subscription['subscription_type'] === 'normal' && !empty($subscription['iterations_left']) ) : ?>
    <p><strong><?php _e('Iterations left', 'payxpert'); ?>:</strong> <?php echo intval($subscription['iterations_left']); ?></p>
<?php endif; ?>

<!-- Next payment -->
<?php if ($subscription['period_end'] ?? null): ?>
    <h4><?php _e('Next payment', 'payxpert'); ?></h4>
    <p>
        <strong><?php _e('Amount', 'payxpert'); ?>:</strong> <?php echo wc_price($subscription['amount'] / 100); ?><br>
        <strong><?php _e('Date', 'payxpert'); ?>:</strong> <?php echo date_i18n(get_option('date_format'), $subscription['period_end']); ?><br>
        <?php if (isset($first_tx)): ?>
            <strong><?php _e('Payment Info', 'payxpert'); ?>:</strong>
            <?php echo esc_html(strtoupper($first_tx['card_brand']) . ' - ***' . substr($first_tx['card_number'], -4, 4)); ?>
        <?php endif; ?>
        
    </p>
<?php endif; ?>

<?php if ( isset($subscription['subscription_type']) && $subscription['subscription_type'] === 'normal' && !empty($subscription['iterations_left']) ) : ?>
    <p><strong><?php _e('Iterations left', 'payxpert'); ?>:</strong> <?php echo intval($subscription['iterations_left']); ?></p>
<?php endif; ?>

<!-- Next payment -->
<?php if ($subscription['period_end'] ?? null): ?>
    <h4><?php _e('Next payment', 'payxpert'); ?></h4>
    <p>
        <strong><?php _e('Amount', 'payxpert'); ?>:</strong> <?php echo wc_price($subscription['amount'] / 100); ?><br>
        <strong><?php _e('Date', 'payxpert'); ?>:</strong> <?php echo date_i18n(get_option('date_format'), $subscription['period_end']); ?><br>
        <?php if (isset($first_tx)): ?>
            <strong><?php _e('Payment Info', 'payxpert'); ?>:</strong>
            <?php echo esc_html(strtoupper($first_tx['card_brand']) . ' - ***' . substr($first_tx['card_number'], -4, 4)); ?>
        <?php endif; ?>
        
    </p>
<?php endif; ?>

<!-- Original Order -->
<?php if ($original_order): 
?>
    <!-- Delivery address -->
    <h4><?php esc_html_e( 'Shipping address', 'woocommerce' ); ?></h2>
    <address>
        <?php echo wp_kses_post( $original_order->get_formatted_shipping_address( esc_html__( 'N/A', 'woocommerce' ) ) ); ?>

        <?php if ( $original_order->get_shipping_phone() ) : ?>
            <p class="woocommerce-customer-details--phone"><?php echo esc_html( $original_order->get_shipping_phone() ); ?></p>
        <?php endif; ?>
    </address>
    <p>
        <?php _e('If you want to change your shipping address, please update it ', 'payxpert') ?>
        <a href="<?php echo esc_url( wc_get_account_endpoint_url( 'edit-address/shipping' ) ); ?>">
            <?php _e('here', 'payxpert'); ?>.
        </a>
    </p>


    <!-- Initial order items -->
    <h4><?php _e('Products of the initial order', 'payxpert'); ?></h4>
    <table class="shop_table shop_table_responsive">
        <thead>
            <tr>
                <th><?php _e('Product', 'payxpert'); ?></th>
                <th><?php _e('Quantity', 'payxpert'); ?></th>
                <th><?php _e('Total', 'payxpert'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($original_order->get_items() as $item): ?>
            <tr>
                <td><?php echo esc_html($item->get_name()); ?></td>
                <td><?php echo esc_html($item->get_quantity()); ?></td>
                <td><?php echo wc_price($item->get_total()); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<!-- Transaction history -->
<h4><?php _e('Occurences history', 'payxpert'); ?></h4>

<table class="shop_table shop_table_responsive">
    <thead>
        <tr>
            <th><?php _e('Order ID', 'payxpert'); ?></th>
            <th><?php _e('Date', 'payxpert'); ?></th>
            <th><?php _e('Amount', 'payxpert'); ?></th>
            <th><?php _e('Operation', 'payxpert'); ?></th>
            <th><?php _e('Status', 'payxpert'); ?></th>
            <th><?php _e('Payment Method', 'payxpert'); ?></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($transactions as $tx): 
        $order_url = wc_get_account_endpoint_url('view-order') . $tx['order_id'];
    ?>
        <tr>
            <td>
                <a href="<?php echo esc_url($order_url); ?>">
                    #<?php echo esc_html($tx['order_id']); ?>
                </a>
            </td>
            <td><?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $tx['date']); ?></td>
            <td><?php echo wc_price($tx['amount']); ?></td>
            <td><?php echo esc_html($tx['operation']); ?></td>
            <td>
                <?php
                echo esc_html(
                    $tx['result_code'] === Payxpert_Payment_Transaction::RESULT_CODE_SUCCESS
                    ? Payxpert_Payment_Transaction::STATUS_SUCCESS
                    : Payxpert_Payment_Transaction::STATUS_FAILURE
                );
                ?>
            </td>
            <td><?php echo strtoupper($tx['card_brand']) . ' - ' . $tx['card_number']; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php if ($total_pages > 1): ?>
    <div class="woocommerce-pagination">
        <?php
        echo paginate_links([
            'base'      => add_query_arg('paged', '%#%'),
            'format'    => '',
            'current'   => $page,
            'total'     => $total_pages,
            'prev_text' => '&laquo;',
            'next_text' => '&raquo;',
        ]);
        ?>
    </div>
<?php endif; ?>
