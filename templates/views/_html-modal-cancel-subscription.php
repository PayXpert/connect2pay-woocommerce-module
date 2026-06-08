<?php

defined('ABSPATH') || exit;

use Payxpert\Models\Payxpert_Subscription;

?>

<div id="cancel-subscription-modal" style="display:none;" class="payxpert-modal">
    <div class="payxpert-modal-overlay"></div>

    <div class="payxpert-modal-content">
        <h4><?php _e('Cancel Subscription', 'payxpert'); ?></h4>

        <form method="post" id="cancel-subscription-form" 
            <?php if (is_admin()): ?>
            action="<?php echo admin_url('admin-post.php'); ?>"
            <?php endif; ?>
        >
            <?php if (is_admin()): ?>
            <input type="hidden" name="action" value="payxpert_cancel_subscription">
            <?php endif; ?>

            <input type="hidden" name="payxpert_cancel_subscription" value="1">
            <input type="hidden" name="subscription_id" id="modal_subscription_id" value="">

            <label for="modal_payxpert_cancel_reason">
                <?php _e('Please select the reason for cancellation', 'payxpert'); ?>
            </label>

            <select style="width:100%" 
                    class="select"
                    name="payxpert_cancel_reason"
                    id="modal_payxpert_cancel_reason"
                    required>

                <?php foreach (Payxpert_Subscription::CUSTOMER_CANCEL_REASONS as $code => $label): ?>
                    <option value="<?php echo esc_attr($code); ?>">
                        <?php echo esc_html__($label, 'payxpert'); ?>
                    </option>
                <?php endforeach; ?>

            </select>

            <div class="wc-block-components-notice-banner is-info">
                <?php _e('Please note: cancelling your subscription is irreversible.', 'payxpert'); ?>
            </div>

            <?php wp_nonce_field('payxpert_cancel_subscription', 'payxpert_cancel_nonce'); ?>

            <div style="margin-top:15px;text-align:right;">
                <button type="button"
                        class="button"
                        id="cancel-modal-close">
                    <?php _e('Close', 'payxpert'); ?>
                </button>

                <button type="submit"
                        class="button button-primary">
                    <?php _e('Confirm Cancel', 'payxpert'); ?>
                </button>
            </div>

        </form>
    </div>
</div>
