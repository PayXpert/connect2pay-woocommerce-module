<?php
/**
 * Email Support.
 *
 * @package Payxpert
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email template variables for the support request message.
 *
 * @var \WC_Email_Payxpert_Support $email
 * @var string                     $shop_name
 * @var string                     $mid
 * @var string                     $lastname
 * @var string                     $firstname
 * @var string                     $email_customer
 * @var string                     $subject
 * @var string                     $cms_version
 * @var string                     $wooc_version
 * @var string                     $php_version
 * @var string                     $module_version
 */

// WooCommerce core email header hook (documented in WooCommerce core templates).
do_action( 'woocommerce_email_header', $email->get_heading(), $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks -- WooCommerce core hook, not owned by this plugin.
?>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
	<tr>
		<td style="padding:20px 0;">
			<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-family: Arial, sans-serif; font-size:14px; line-height:1.6; color:#363a41;">
				<tr>
					<td>
						<p><strong>Shop Name:</strong> <?php echo esc_html( $shop_name ); ?></p>
						<p><strong>Public API Key:</strong> <?php echo esc_html( $mid ); ?></p>
						<p><strong>Last Name:</strong> <?php echo esc_html( $lastname ); ?></p>
						<p><strong>First Name:</strong> <?php echo esc_html( $firstname ); ?></p>
						<p><strong>Email:</strong> <?php echo esc_html( $email_customer ); ?></p>
						<p><strong>Request:</strong><br><?php echo nl2br( esc_html( $subject ) ); ?></p>
						<p>
							<strong>CMS:</strong> WordPress<br>
							<strong>CMS Version:</strong> v<?php echo esc_html( $cms_version ); ?><br>
							<strong>WooCommerce Version:</strong> v<?php echo esc_html( $wooc_version ); ?><br>
							<strong>PHP Version:</strong> v<?php echo esc_html( $php_version ); ?><br>
							<strong>Module Version:</strong> v<?php echo esc_html( $module_version ); ?><br><br>
						</p>
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>

<?php
// WooCommerce core email footer hook (documented in WooCommerce core templates).
do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks -- WooCommerce core hook, not owned by this plugin.
?>
