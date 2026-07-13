<?php
/**
 * Email Support.
 *
 * @package Payxpert
 */

/**
 * Plain text email for PayXpert support request
 *
 * @package WooCommerce\Emails\Plain
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plain-text email template variables for the support request message.
 *
 * @var \WC_Email_Payxpert_Support $email
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

// Separator line.
echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( $email->get_heading() ) . "\n";
echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

// Contenu.
echo 'Shop Name: ' . esc_html( get_bloginfo( 'name' ) ) . "\n";
echo 'Public API Key: ' . esc_html( $mid ) . "\n";
echo 'Last Name: ' . esc_html( $lastname ) . "\n";
echo 'First Name: ' . esc_html( $firstname ) . "\n";
echo 'Email: ' . esc_html( $email_customer ) . "\n\n";

echo "Request:\n";
echo esc_html( $subject ) . "\n\n";

echo "Technical Information:\n";
echo "CMS: WordPress\n";
echo 'CMS Version: v' . esc_html( $cms_version ) . "\n";
echo 'WooCommerce Version: v' . esc_html( $wooc_version ) . "\n";
echo 'PHP Version: v' . esc_html( $php_version ) . "\n";
echo 'Module Version: v' . esc_html( $module_version ) . "\n\n";

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

// Footer (IMPORTANT : pas d'echo ici)
// WooCommerce core plain email footer hook (documented in WooCommerce core templates).
do_action( 'woocommerce_email_footer_plain', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks -- WooCommerce core hook, not owned by this plugin.
