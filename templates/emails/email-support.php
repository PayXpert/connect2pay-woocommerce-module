<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

do_action( 'woocommerce_email_header', $email->get_heading(), $email );
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
do_action( 'woocommerce_email_footer', $email );
?>
