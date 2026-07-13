<?php
/**
 * Email Paybylink.
 *
 * @package Payxpert
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template Email PayByLink - Plain Text
 *
 * Variables disponibles :
 *
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

// translators: %s is the customer first name.
echo esc_html( sprintf( __( 'Hello %s,', 'payxpert' ), $firstname ) ) . "\n\n";

echo esc_html( __( 'You have requested to pay your order using PayByLink via PayXpert. Below are the details of your order and the payment link.', 'payxpert' ) ) . "\n\n";

echo esc_url( $payment_link ) . "\n\n";

echo esc_html( __( 'Payment deadline:', 'payxpert' ) ) . ' ' . esc_html( $payment_deadline ) . "\n\n";

echo '==== ' . esc_html( __( 'Order Summary', 'payxpert' ) ) . " ====\n";
echo esc_html( __( 'Order Reference:', 'payxpert' ) ) . ' ' . esc_html( $order_reference ) . "\n";
echo esc_html( __( 'Order Date:', 'payxpert' ) ) . ' ' . esc_html( $order_date ) . "\n\n";

echo esc_html( __( 'Products:', 'payxpert' ) ) . "\n";
echo esc_html( wp_strip_all_tags( $order_products ) ) . "\n";

echo esc_html( __( 'Subtotal:', 'payxpert' ) ) . ' ' . esc_html( wp_strip_all_tags( $order_subtotal ) ) . "\n";
echo esc_html( __( 'Shipping:', 'payxpert' ) ) . ' ' . esc_html( wp_strip_all_tags( $order_shipping ) ) . "\n";
echo esc_html( __( 'Total:', 'payxpert' ) ) . ' ' . esc_html( wp_strip_all_tags( $order_total ) ) . "\n\n";

// translators: %s is the shop name.
echo esc_html( sprintf( __( 'Thank you for your trust, %s', 'payxpert' ), $shop_name ) ) . "\n";
echo esc_url( $shop_url ) . "\n";
