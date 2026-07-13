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
 * Email template variables for the PayByLink message.
 *
 * @var \WC_Email_Payxpert_Paybylink $email
 * @var string                       $shop_name
 * @var string                       $firstname
 * @var string                       $payment_link
 * @var string                       $payment_deadline
 * @var string                       $order_reference
 * @var string                       $order_date
 * @var string                       $order_products
 * @var string                       $order_subtotal
 * @var string                       $order_shipping
 * @var string                       $order_total
 * @var string                       $shop_url
 */

// Custom logo from theme or WooCommerce email settings.
$payxpert_logo_id  = get_option( 'woocommerce_email_header_image' );
$payxpert_logo_url = $payxpert_logo_id ? wp_get_attachment_url( $payxpert_logo_id ) : '';

// WooCommerce core email header hook (documented in WooCommerce core templates).
do_action( 'woocommerce_email_header', $email->get_heading(), $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks -- WooCommerce core hook, not owned by this plugin.
?>

<?php if ( $payxpert_logo_url ) : ?>
	<p style="text-align: center;">
		<img src="<?php echo esc_url( $payxpert_logo_url ); ?>" alt="<?php echo esc_attr( $shop_name ); ?>" style="max-width:200px; height:auto;">
	</p>
<?php endif; ?>

<?php // translators: %s is the customer first name. ?>
<p style="font-size: 16px;"><?php printf( esc_html__( 'Hello %s,', 'payxpert' ), esc_html( $firstname ) ); ?></p>

<p style="font-size: 15px;">
	<?php esc_html_e( 'You have requested to pay your order using PayByLink via PayXpert. Below are the details of your order and the secure payment link.', 'payxpert' ); ?>
</p>

<p style="text-align:center; margin: 30px 0;">
	<a href="<?php echo esc_url( $payment_link ); ?>" 
		style="background-color: #0071a1; color: #fff; padding: 15px 30px; text-decoration: none; font-weight: bold; border-radius: 6px; font-size: 16px;">
		<?php esc_html_e( 'Click here to pay your order', 'payxpert' ); ?>
	</a>
</p>

<p style="text-align:center;"><strong><?php esc_html_e( 'Payment deadline:', 'payxpert' ); ?></strong> <?php echo esc_html( $payment_deadline ); ?></p>

<hr style="margin:30px 0; border: none; border-top: 1px solid #eee;">

<h2 style="border-bottom:1px solid #eee; padding-bottom:5px;"><?php esc_html_e( 'Order Summary', 'payxpert' ); ?></h2>

<p><strong><?php esc_html_e( 'Order Reference:', 'payxpert' ); ?></strong> <?php echo esc_html( $order_reference ); ?></p>
<p><strong><?php esc_html_e( 'Order Date:', 'payxpert' ); ?></strong> <?php echo esc_html( $order_date ); ?></p>

<h3 style="margin-top:20px;"><?php esc_html_e( 'Products:', 'payxpert' ); ?></h3>
<div style="margin-left: 10px;"><?php echo wp_kses_post( $order_products ); ?></div>

<p><strong><?php esc_html_e( 'Subtotal:', 'payxpert' ); ?></strong> <?php echo wp_kses_post( $order_subtotal ); ?></p>
<p><strong><?php esc_html_e( 'Shipping:', 'payxpert' ); ?></strong> <?php echo wp_kses_post( $order_shipping ); ?></p>
<p><strong><?php esc_html_e( 'Total:', 'payxpert' ); ?></strong> <?php echo wp_kses_post( $order_total ); ?></p>

<?php // translators: %s is the shop name. ?>
<p style="font-size: 14px;"><?php printf( esc_html__( 'Thank you for your trust, %s.', 'payxpert' ), esc_html( $shop_name ) ); ?></p>
<p style="font-size: 14px;"><a href="<?php echo esc_url( $shop_url ); ?>"><?php echo esc_html( $shop_url ); ?></a></p>

<?php
// WooCommerce core email footer hook (documented in WooCommerce core templates).
do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks -- WooCommerce core hook, not owned by this plugin.
?>
