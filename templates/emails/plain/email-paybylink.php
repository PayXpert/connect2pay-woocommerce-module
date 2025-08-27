<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template Email PayByLink - Plain Text
 *
 * Variables disponibles :
 * @var string $firstname
 * @var string $lastname
 * @var string $shop_name
 * @var string $order_reference
 * @var string $payment_link
 * @var string $order_date
 * @var string $order_products
 * @var string $order_subtotal
 * @var string $order_shipping
 * @var string $order_total
 * @var string $payment_deadline
 * @var string $shop_url
 */

echo sprintf( __( 'Hello %s,', 'payxpert' ), $firstname ) . "\n\n";

echo __( 'You have requested to pay your order using PayByLink via PayXpert. Below are the details of your order and the payment link.', 'payxpert' ) . "\n\n";

echo $payment_link . "\n\n";

echo __( 'Payment deadline:', 'payxpert' ) . ' ' . $payment_deadline . "\n\n";

echo "==== " . __( 'Order Summary', 'payxpert' ) . " ====\n";
echo __( 'Order Reference:', 'payxpert' ) . ' ' . $order_reference . "\n";
echo __( 'Order Date:', 'payxpert' ) . ' ' . $order_date . "\n\n";

echo __( 'Products:', 'payxpert' ) . "\n";
echo strip_tags( $order_products ) . "\n";

echo __( 'Subtotal:', 'payxpert' ) . ' ' . $order_subtotal . "\n";
echo __( 'Shipping:', 'payxpert' ) . ' ' . $order_shipping . "\n";
echo __( 'Total:', 'payxpert' ) . ' ' . $order_total . "\n\n";

echo sprintf( __( 'Thank you for your trust, %s', 'payxpert' ), $shop_name ) . "\n";
echo $shop_url . "\n";
