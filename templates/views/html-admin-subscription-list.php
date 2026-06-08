<?php

use Payxpert\Models\Payxpert_Subscription;

 if (!defined('ABSPATH')) exit; ?>

<div class="enhanced-plugin-settings-wrap">
    <div class="block">
        <div class="header">
            <h1>
                <?php _e('PayXpert Subscriptions', 'payxpert'); ?>
            </h1>
        </div>
    </div>

    <div class="block" style="overflow: auto";>
        <?php
            // $filters is set before this template is included.
            $current_status   = isset($filters['status']) ? $filters['status'] : '';
            $current_date_from = isset($filters['date_from']) ? $filters['date_from'] : '';
            $current_date_to   = isset($filters['date_to']) ? $filters['date_to'] : '';
            $current_customer  = isset($filters['customer']) ? $filters['customer'] : '';

            // URL du reset : seulement page=payxpert-subscriptions
            $reset_url = admin_url('admin.php?page=payxpert-subscription-list');
        ?>

        <form method="get" class="tablenav top">
            <input type="hidden" name="page" value="payxpert-subscription-list">

            <!-- Status -->
            <select name="status">
                <option value=""><?php _e('All statuses', 'payxpert'); ?></option>
                <option value="active" <?php selected($current_status, 'active'); ?>><?php _e('active', 'payxpert'); ?></option>
                <option value="paused" <?php selected($current_status, 'paused'); ?>><?php _e('paused', 'payxpert'); ?></option>
                <option value="canceled" <?php selected($current_status, 'canceled'); ?>><?php _e('canceled', 'payxpert'); ?></option>
                <option value="disabled" <?php selected($current_status, 'disabled'); ?>><?php _e('disabled', 'payxpert'); ?></option>
                <option value="finished" <?php selected($current_status, 'finished'); ?>><?php _e('finished', 'payxpert'); ?></option>
            </select>

            <!-- Date from -->
            <input type="date" name="date_from" value="<?php echo esc_attr($current_date_from); ?>">

            <!-- Date to -->
            <input type="date" name="date_to" value="<?php echo esc_attr($current_date_to); ?>">

            <!-- Customer -->
            <input type="text" name="customer" placeholder="<?php esc_attr_e('Customer name or email', 'payxpert'); ?>" value="<?php echo esc_attr($current_customer); ?>">

            <!-- Filter button -->
            <button class="button"><?php _e('Filter', 'payxpert'); ?></button>

            <!-- Reset button -->
            <a href="<?php echo esc_url($reset_url); ?>" class="button"><?php _e('Reset', 'payxpert'); ?></a>
        </form>

        <?php if (empty($results)): ?>
            <p><?php _e('No subscriptions found.', 'payxpert'); ?></p>
        <?php else: ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php _e('Order ID', 'payxpert'); ?></th>
                        <th><?php _e('Subscription ID', 'payxpert'); ?></th>
                        <th><?php _e('Customer', 'payxpert'); ?></th>
                        <th><?php _e('Type', 'payxpert'); ?></th>
                        <th><?php _e('State', 'payxpert'); ?></th>
                        <th><?php _e('Amount', 'payxpert'); ?></th>
                        <th><?php _e('Period', 'payxpert'); ?></th>
                        <th><?php _e('Last Payment', 'payxpert'); ?></th>
                        <th><?php _e('Next Payment', 'payxpert'); ?></th>
                        <th><?php _e('Nb Transactions', 'payxpert'); ?></th>
                        <th><?php _e('Iterations Left', 'payxpert'); ?></th>
                        <th><?php _e('Detail', 'payxpert'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results['items'] as $sub): ?>
                        <tr>
                            <td>
                                <?php if ($sub->order_id): ?>
                                    <a href="<?php echo esc_url(admin_url('post.php?post=' . $sub->order_id . '&action=edit')); ?>">
                                        #<?php echo esc_html($sub->order_id); ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                                <td><?php echo esc_html($sub->subscription_id); ?></td>
                                <td><?php echo esc_html($sub->customer_email); ?></td>
                                <td><?php echo esc_html($sub->subscription_type_label); ?></td>
                                <td>
                                    <?php echo esc_html_e($sub->state, 'payxpert'); ?>
                                    
                                    <?php
                                    // Canceled or disabled state.
                                    if ( in_array( $sub->state, array( Payxpert_Subscription::STATUS_CANCELED, Payxpert_Subscription::STATUS_DISABLED ), true ) && ! empty( $sub->cancel_reason ) ) :

                                        // Reason label by subscription type.
                                        $reason_label = '';

                                        if ( $sub->state === Payxpert_Subscription::STATUS_CANCELED && isset( Payxpert_Subscription::CUSTOMER_CANCEL_REASONS[ $sub->cancel_reason ] ) ) {
                                            $reason_label = Payxpert_Subscription::CUSTOMER_CANCEL_REASONS[ $sub->cancel_reason ];
                                        }
                                        elseif ( $sub->state === 'disabled' && isset( Payxpert_Subscription::SERVER_CANCEL_REASONS[ $sub->cancel_reason ] ) ) {
                                            $reason_label = Payxpert_Subscription::SERVER_CANCEL_REASONS[ $sub->cancel_reason ];
                                        }

                                        if ( $reason_label ) :
                                    ?>
                                        <span
                                            class="dashicons dashicons-info payxpert-info-icon"
                                            title="<?php echo esc_html_e( $reason_label , 'payxpert' ); ?>"
                                        ></span>
                                    <?php
                                        endif;
                                    endif;
                                    ?>
                                </td>
                                <td>
                                    <?php echo $sub->trial_amount_formatted; ?> 
                                    + x<?php echo $sub->amount_formatted; ?>
                                </td>
                                <td><?php echo esc_html($sub->period); ?></td>
                                <td><?php echo esc_html($sub->period_start_formatted); ?></td>
                                <td><?php echo esc_html($sub->period_end_formatted); ?></td>
                                <td><?php echo esc_html($sub->sale_transactions_count); ?></td>
                                <td>
                                    <?php echo esc_html($sub->iterations_left); ?>
                                    <?php if ( ! empty( $sub->retries ) && (int) $sub->retries > 0 ) : ?>
                                        <span
                                            class="dashicons dashicons-warning payxpert-warning"
                                            title="<?php echo esc_attr(
                                                sprintf(
                                                    __( '%d retry attempt(s) already performed', 'payxpert' ),
                                                    (int) $sub->retries
                                                )
                                            ); ?>"
                                        ></span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:center">
                                    <a class="woocommerce-button wp-element-button button" href="<?php echo esc_url(admin_url('admin.php?page=payxpert-subscription-detail&id=' . $sub->subscription_id)); ?>">
                                        <?php _e('View', 'payxpert'); ?>
                                    </a>

                                    <?php if ($sub->state === Payxpert_Subscription::STATUS_ACTIVE): ?>
                                    <button type="button" 
                                        class="woocommerce-button wp-element-button button cancel-subscription" 
                                        data-subscription-id="<?php echo esc_attr($sub->subscription_id); ?>">
                                        <?php _e('Cancel', 'payxpert'); ?>
                                    </button>
                                    <?php endif; ?>
                                </td>
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
                'total' => ceil($results['total_items'] / $results['per_page']),
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

<?php include WC_PAYXPERT_PLUGIN_FILE_PATH . '/templates/views/_html-modal-cancel-subscription.php'; ?>
