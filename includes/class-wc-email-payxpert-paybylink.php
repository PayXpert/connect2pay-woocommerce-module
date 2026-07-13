<?php
/**
 * Wc Email Payxpert Paybylink.
 *
 * @package Payxpert
 */

use Payxpert\Utils\WC_Payxpert_Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WC_Email_Payxpert_Paybylink
 *
 * Sends the PayByLink payment URL to the customer by email.
 */
class WC_Email_Payxpert_Paybylink extends WC_Email {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'payxpert_paybylink';
		$this->title          = 'PayXpert - PayByLink';
		$this->description    = __( 'Email sent to a customer with a payment link', 'payxpert' );
		$this->customer_email = true;

		$this->heading = __( 'Complete your order', 'payxpert' );
		$this->subject = __( 'Pending payment for your order on {site_title}', 'payxpert' );

		$this->template_html  = 'email-paybylink.php';
		$this->template_plain = 'plain/email-paybylink.php';

		$this->recipient = ''; // Set in trigger() from $data.

		parent::__construct();
	}

	/**
	 * Sends the email.
	 *
	 * @param array<string, mixed> $data Template variables.
	 * @return bool
	 */
	public function trigger( $data ): bool {
		if ( empty( $data['recipient'] ) || empty( $data['order'] ) || empty( $data['payment_link'] ) ) {
			return false;
		}

		$this->recipient = $data['recipient'];
		$this->data      = $data;

		$this->object = $data['order'];

		if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
			return false;
		}

		return $this->send(
			$this->get_recipient(),
			$this->get_subject(),
			$this->get_content(),
			$this->get_headers(),
			$this->get_attachments()
		);
	}

	/**
	 * Contenu HTML.
	 */
	public function get_content_html() {
		ob_start();
		wc_get_template(
			$this->template_html,
			$this->data,
			'',
			WC_PAYXPERT_PLUGIN_FILE_PATH . 'templates/emails/'
		);
		return ob_get_clean();
	}

	/**
	 * Contenu texte brut (si besoin).
	 */
	public function get_content_plain() {
		ob_start();
		wc_get_template(
			$this->template_plain,
			$this->data,
			'',
			WC_PAYXPERT_PLUGIN_FILE_PATH . 'templates/emails/'
		);
		return ob_get_clean();
	}
}
