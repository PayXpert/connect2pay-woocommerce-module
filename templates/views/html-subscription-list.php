<?php if (!defined('ABSPATH')) exit; ?>

<div class="enhanced-plugin-settings-wrap">
    <div class="block">
        <div class="header">
            <h1>
                <?php _e('PayXpert Subscriptions', 'payxpert'); ?>
            </h1>
        </div>
    </div>

    <div class="block">
        <?php if (empty($results)): ?>
            <p><?php _e('No subscriptions found.', 'payxpert'); ?></p>
        <?php else: ?>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Order ID', 'payxpert'); ?></th>
                        <th><?php _e('Subscription ID', 'payxpert'); ?></th>
                        <th><?php _e('Subscription Type', 'payxpert'); ?></th>
                        <th><?php _e('State', 'payxpert'); ?></th>
                        <th><?php _e('Initial Amount', 'payxpert'); ?></th>
                        <th><?php _e('Installment Amount', 'payxpert'); ?></th>
                        <th><?php _e('Period', 'payxpert'); ?></th>
                        <th><?php _e('Last Payment', 'payxpert'); ?></th>
                        <th><?php _e('Next Payment', 'payxpert'); ?></th>
                        <th><?php _e('Sales Transactions', 'payxpert'); ?></th>
                        <th><?php _e('Iterations Left', 'payxpert'); ?></th>
                        <th><?php _e('Retries', 'payxpert'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $sub): ?>
                        <tr>
                            <td>
                                <?php if ($sub->order_id): ?>
                                    <a href="<?php echo esc_url(admin_url('post.php?post=' . $sub->order_id . '&action=edit')); ?>">
                                        #<?php echo esc_html($sub->order_id); ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                                <td><?php echo esc_html($sub->subscription_id); ?></td>
                                <td><?php echo esc_html($sub->subscription_type_label); ?></td>
                                <td><?php echo esc_html($sub->state); ?></td>
                                <td><?php echo $sub->trial_amount_formatted; ?></td>
                                <td><?php echo $sub->amount_formatted; ?></td>
                                <td><?php echo esc_html($sub->period); ?></td>
                                <td><?php echo esc_html($sub->period_start_formatted); ?></td>
                                <td><?php echo esc_html($sub->period_end_formatted); ?></td>
                                <td><?php echo esc_html($sub->sale_transactions_count); ?></td>
                                <td><?php echo esc_html($sub->iterations_left); ?></td>
                                <td><?php echo esc_html($sub->retries); ?></td>
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
