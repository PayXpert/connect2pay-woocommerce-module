<?php if (!empty($schedule) && is_array($schedule)) : ?>
<style>
    .table-instalment {
        width: 100%;
        border-collapse: collapse;
        margin: 1em 0;
        font-family: Arial, sans-serif;
        font-size: 14px;
    }

    .table-instalment thead tr {
        background-color: #f0f0f0;
    }

    .table-instalment th,
    .table-instalment td {
        padding: 8px;
        border: 1px solid #ddd !important;
        text-align: center !important;
    }

    .table-instalment tbody tr:nth-child(even) {
        background-color: #fafafa;
    }

    .table-instalment tbody tr:hover {
        background-color: #f5f5f5;
    }
</style>

<table class="table table-bordered table-instalment mb-1">
    <thead>
        <tr>
            <th><?php esc_html_e('Date', 'payxpert'); ?></th>
            <th><?php esc_html_e('Amount', 'payxpert'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($schedule as $index => $payment) : ?>
            <tr>
                <td class="text-sm-center">
                    <?php if ($index === 0) : ?>
                        <?php esc_html_e('To be paid immediately', 'payxpert'); ?>
                    <?php else : ?>
                        <?php echo esc_html($payment['date']); ?>
                    <?php endif; ?>
                </td>
                <td class="text-sm-center">
                    <?php echo wp_kses_post($payment['amountFormatted']); ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
