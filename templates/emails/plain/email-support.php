<?php
/**
 * Plain text email for PayXpert support request
 *
 * @package WooCommerce\Emails\Plain
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Ligne de séparation
echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( $email->get_heading() ) . "\n";
echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

// Contenu
echo "Shop Name: " . get_bloginfo('name') . "\n";
echo "Public API Key: " . $mid . "\n";
echo "Last Name: " . $lastname . "\n";
echo "First Name: " . $firstname . "\n";
echo "Email: " . $email_customer . "\n\n";

echo "Request:\n";
echo $subject . "\n\n";

echo "Technical Information:\n";
echo "CMS: WordPress\n";
echo "CMS Version: v" . $cms_version . "\n";
echo "WooCommerce Version: v" . $wooc_version . "\n";
echo "PHP Version: v" . $php_version . "\n";
echo "Module Version: v" . $module_version . "\n\n";

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

// Footer (IMPORTANT : pas d'echo ici)
do_action( 'woocommerce_email_footer_plain', $email );
