<?php if (!defined('ABSPATH')) exit; ?>

<div class="enhanced-plugin-settings-wrap">
    <div class="block">
        <div class="header">
            <h1>
                <?php _e('PayXpert Transactions', 'payxpert'); ?>
            </h1>
        </div>
    </div>

    <div class="block">
        <?php if (empty($transactions)): ?>
            <p><?php _e('No transactions found.', 'payxpert'); ?></p>
        <?php else: ?>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('ID', 'payxpert'); ?></th>
                        <th><?php _e('Order ID', 'payxpert'); ?></th>
                        <th><?php _e('Transaction ID', 'payxpert'); ?></th>
                        <th><?php _e('Amount', 'payxpert'); ?></th>
                        <th><?php _e('Currency', 'payxpert'); ?></th>
                        <th><?php _e('Payment Method', 'payxpert'); ?></th>
                        <th><?php _e('Operation', 'payxpert'); ?></th>
                        <th><?php _e('Result', 'payxpert'); ?></th>
                        <th><?php _e('Date', 'payxpert'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $tx): ?>
                        <tr>
                            <td><?php echo esc_html($tx->id_payxpert_payment_transaction); ?></td>
                            <td>
                                <?php if ($tx->order_id): ?>
                                    <a href="<?php echo esc_url(admin_url('post.php?post=' . $tx->order_id . '&action=edit')); ?>">
                                        #<?php echo esc_html($tx->order_id); ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($tx->transaction_id); ?></td>
                            <td><?php echo esc_html(number_format($tx->amount, 2)); ?></td>
                            <td><?php echo esc_html($tx->currency); ?></td>
                            <td><?php echo esc_html($tx->payment_method); ?></td>
                            <td><?php echo esc_html($tx->operation); ?></td>
                            <td><?php echo esc_html($tx->result_code . ' - ' . $tx->result_message); ?></td>
                            <td><?php echo esc_html($tx->date_add); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php
            // Pagination
            $page_links = paginate_links([
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'prev_text' => __('&laquo; Previous', 'payxpert'),
                'next_text' => __('Next &raquo;', 'payxpert'),
                'total' => ceil($total_items / $per_page),
                'current' => $current_page,
                'type' => 'array',
            ]);

            if ($page_links) {
                echo '<div class="tablenav"><div class="tablenav-pages"><span class="pagination-links">';
                echo join(' ', $page_links);
                echo '</span></div></div>';
            }
            ?>
        <?php endif; ?>
    </div>
</div>
