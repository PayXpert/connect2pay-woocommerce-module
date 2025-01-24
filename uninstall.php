<?php
// Remove all options
$options = [
    'payxpert_originator_id',
    'payxpert_password',
    'payxpert_wechat_pay',
    'payxpert_alipay',
    'payxpert_seamless_mode',
    'payxpert_credit_card_mode',
    'payxpert_transaction_operation',
    'payxpert_pay_button',
    'payxpert_seamless_version',
    'payxpert_seamless_hash',
    'payxpert_connect2_url',
    'payxpert_api_url',
    'payxpert_debug',
    'payxpert_merchant_notifications',
    'payxpert_merchant_notifications_to',
    'payxpert_merchant_notifications_lang',
    'payxpert_conn_status'
];

// Loop through the options and delete them
foreach ( $options as $option ) {
    delete_option( $option );
}