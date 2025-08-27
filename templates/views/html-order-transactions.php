<?php if (!defined('ABSPATH')) exit; 

$show_actions = !empty($orderTransactionsFormatted['capturable']);
?>

<?php wp_nonce_field('payxpert_capture_action', 'payxpert_capture_action_nonce'); ?>
<input type="hidden" id="payxpert_order_id" value="<?php echo esc_attr($order->get_id()); ?>">

<table class="payxpert-table widefat fixed striped">
    <thead>
        <tr>
            <th class="text-center"><?php esc_html_e('Transaction ID', 'payxpert'); ?></th>
            <th class="text-center"><?php esc_html_e('Referal Transaction', 'payxpert'); ?></th>
            <th><?php esc_html_e('Created At', 'payxpert'); ?></th>
            <th><?php esc_html_e('Operation', 'payxpert'); ?></th>
            <th class="text-right"><?php esc_html_e('Amount', 'payxpert'); ?></th>
            <th class="text-right"><?php esc_html_e('Refundable amount', 'payxpert'); ?></th>
            <th class="text-center"><?php esc_html_e('Currency', 'payxpert'); ?></th>
            <th class="text-center"><?php esc_html_e('Status', 'payxpert'); ?></th>
            <th class="text-center" title="<?php esc_attr_e('Liability Shift', 'payxpert'); ?>"><?php esc_html_e('LS*', 'payxpert'); ?></th>
            <th class="text-center"><?php esc_html_e('Code', 'payxpert'); ?></th>
            <th><?php esc_html_e('Message', 'payxpert'); ?></th>
            <?php if ($show_actions): ?>
                <th class="text-center"><?php esc_html_e('Action', 'payxpert'); ?></th>
            <?php endif; ?>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($transactions as $transaction):
            $amount = number_format((float) $transaction['amount'], 2, ',', ' ');
            $refundable = isset($transaction['refundable_amount'])
                ? number_format((float) $transaction['refundable_amount'], 2, ',', ' ')
                : '';
            $badge_status = $transaction['result_code'] === '000'
                ? '<span style="color:green;">✓</span>'
                : '<span style="color:red;">⤬</span>';
            $badge_ls = !empty($transaction['liability_shift']) && $transaction['liability_shift'] == 1
                ? '<span style="color:green;">✓</span>'
                : '<span style="color:red;">⤬</span>';
        ?>
        <tr>
            <td class="text-center"><?php echo esc_html($transaction['transaction_id']); ?></td>
            <td class="text-center"><?php echo esc_html($transaction['transaction_referal_id'] ?? ''); ?></td>
            <td><?php echo esc_html(date_i18n('d/m/Y H:i:s', strtotime($transaction['date_add']))); ?></td>
            <td><?php echo esc_html(ucfirst($transaction['operation'])); ?></td>
            <td class="text-right"><?php echo ($transaction['operation'] === 'refund' ? '-' : '') . $amount; ?></td>
            <td class="text-right"><?php echo $refundable; ?></td>
            <td class="text-center"><?php echo esc_html($transaction['currency']); ?></td>
            <td class="text-center"><?php echo $badge_status; ?></td>
            <td class="text-center"><?php echo $badge_ls; ?></td>
            <td class="text-center"><?php echo esc_html($transaction['result_code']); ?></td>
            <td><?php echo esc_html($transaction['result_message']); ?></td>
            <?php if ($show_actions): ?>
                <td class="text-center">
                    <?php
                    $is_capturable = isset($orderTransactionsFormatted['capturable'][$transaction['transaction_id']]);
                    if ($is_capturable): ?>
                        <button type="button"
                            name="capture_transaction_id"
                            value="<?php echo esc_attr($transaction['transaction_id']); ?>"
                            class="button button-primary payxpert-capture-btn">
                            <?php esc_html_e('Capture', 'payxpert'); ?>
                        </button>
                    <?php endif; ?>
                </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const orderId = document.getElementById('payxpert_order_id')?.value;
        const nonce = document.getElementById('payxpert_capture_action_nonce')?.value;

        document.querySelectorAll('.payxpert-capture-btn').forEach(button => {
            button.addEventListener('click', function () {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '<?php echo esc_url(admin_url('admin-post.php')); ?>';

                const inputs = {
                    action: 'payxpert_capture_transaction',
                    order_id: orderId,
                    capture_transaction_id: this.value,
                    payxpert_capture_action_nonce: nonce
                };

                for (const [name, value] of Object.entries(inputs)) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = name;
                    input.value = value;
                    form.appendChild(input);
                }

                document.body.appendChild(form);
                form.submit();
            });
        });
    });
</script>
