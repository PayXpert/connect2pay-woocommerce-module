<?php
/**
 * Wc Email Payxpert Support.
 *
 * @package Payxpert
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WC_Email_Payxpert_Support
 *
 * WooCommerce email: PayXpert support form submission.
 */
class WC_Email_Payxpert_Support extends WC_Email {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'payxpert_support';
		$this->title          = 'PayXpert Support';
		$this->description    = __( 'Email sent when a user submits the PayXpert support form.', 'payxpert' );
		$this->customer_email = false;

		// Default heading and subject.
		$this->heading = __( 'PayXpert Support Request', 'payxpert' );
		$this->subject = __( 'PayXpert Support Request', 'payxpert' );

		// Template paths.
		$this->template_html  = 'email-support.php';
		$this->template_plain = 'plain/email-support.php';

		// Default recipient (overridden in trigger()).
		$this->recipient = get_option( 'admin_email' );

		parent::__construct();
	}

	/**
	 * Sends the email.
	 *
	 * @param array<string, mixed> $data Support request data used to build the email.
	 * @return bool
	 */
	public function trigger( $data ): bool {
		// Optional recipient override from $data.
		if ( ! empty( $data['recipient'] ) ) {
			$this->recipient = $data['recipient'];
		}

		$this->data = $data;

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
	 * Builds the HTML body.
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
	 * Plain-text body (optional template).
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
