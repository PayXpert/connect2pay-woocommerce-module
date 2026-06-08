<?php

use Payxpert\Models\Payxpert_Payment_Transaction;
use Payxpert\Models\Payxpert_Subscription;

 if (!defined('ABSPATH')) exit; ?>

<div class="enhanced-plugin-settings-wrap">
    <a href="<?php echo admin_url('admin.php?page=payxpert-subscription-list'); ?>" 
       class="button button-secondary" 
       style="margin-bottom:15px; display:inline-block;">
        ← <?php _e('Back to subscription list', 'payxpert'); ?>
    </a>
    
    <div class="block">
        <div class="header">
            <h1>
                <?php _e('PayXpert Subscription Detail', 'payxpert'); ?>
            </h1>
        </div>
    </div>

    <!-- General information -->
    <div class="block">
        <h2 class="hndle"><?php _e('General Information', 'payxpert'); ?></h2>
        <div class="inside">
            <table class="widefat striped">
                <tr>
                    <th><?php _e('Subscription ID PayXpert', 'payxpert'); ?></th>
                    <td><?php echo esc_html($sub->subscription_id); ?></td>
                </tr>
                <tr>
                    <th><?php _e('Customer', 'payxpert'); ?></th>
                    <td><?php echo esc_html($sub->customer_name); ?> (<?php echo esc_html($sub->customer_email); ?>)</td>
                </tr>
                <tr>
                    <th><?php _e('Product / Variation', 'payxpert'); ?></th>
                     <td>
                        <?php echo esc_html($sub->product_name); ?>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('Quantity', 'payxpert'); ?></th>
                    <td>× <?php echo esc_html($sub->product_quantity); ?></td>
                </tr>
                <tr>
                    <th><?php _e('Statut', 'payxpert'); ?></th>
                    <td>
                        <?php echo esc_html($sub->state); ?>
                        <?php if (in_array($sub->state, [Payxpert_Subscription::STATUS_CANCELED, Payxpert_Subscription::STATUS_DISABLED]) && !empty($sub->cancel_reason)) : 
                            $reason_label = isset(Payxpert_Subscription::CUSTOMER_CANCEL_REASONS[$sub->cancel_reason])
                                ? Payxpert_Subscription::CUSTOMER_CANCEL_REASONS[$sub->cancel_reason]
                                : (isset(Payxpert_Subscription::SERVER_CANCEL_REASONS[$sub->cancel_reason])
                                ? Payxpert_Subscription::SERVER_CANCEL_REASONS[$sub->cancel_reason]
                                : '');
                        ?>
                            <span class="dashicons dashicons-info" title="<?php echo esc_attr($reason_label); ?>"></span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <div class="block">
        <h2 class="hndle"><?php _e('Transactions list', 'payxpert'); ?></h2>

        <div class="inside">
            <?php if (!empty($sub->transactions)) : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php _e('Order ID', 'payxpert'); ?></th>
                            <th><?php _e('Transaction ID', 'payxpert'); ?></th>
                            <th><?php _e('Date', 'payxpert'); ?></th>
                            <th><?php _e('Amount', 'payxpert'); ?></th>
                            <th><?php _e('Currency', 'payxpert'); ?></th>
                            <th><?php _e('Brand', 'payxpert'); ?></th>
                            <th><?php _e('Code', 'payxpert'); ?></th>
                            <th><?php _e('Message', 'payxpert'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sub->transactions as $tx) : ?>
                            <tr>
                                <td>
                                    <?php if (!empty($tx->order_id)) : ?>
                                        <a href="<?php echo esc_url(admin_url('post.php?post=' . (int) $tx->order_id . '&action=edit')); ?>">
                                            #<?php echo esc_html($tx->order_id); ?>
                                        </a>
                                    <?php else : ?>
                                        —
                                    <?php endif; ?>
                                </td>

                                <td><?php echo esc_html($tx->transaction_id); ?></td>

                                <td><?php echo esc_html($tx->date_add_formatted); ?></td>

                                <td><?php echo ($tx->operation == Payxpert_Payment_Transaction::OPERATION_REFUND ? '-' : '') . esc_html($tx->amount); ?></td>

                                <td><?php echo esc_html($tx->currency); ?></td>

                                <td><?php echo esc_html($tx->card_brand ?: '—'); ?></td>

                                <td>
                                    <strong style="color: <?php echo ((int)$tx->result_code === 0) ? 'green' : 'red'; ?>;">
                                        <?php echo esc_html($tx->result_code); ?>
                                    </strong>
                                </td>

                                <td>
                                    <?php echo !empty($tx->result_message)
                                        ? esc_html($tx->result_message)
                                        : '—'; ?>
                                </td>
                            </tr>

                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p><?php _e('No transaction found.', 'payxpert'); ?></p>
            <?php endif; ?>
        </div>
    </div>
</div>
