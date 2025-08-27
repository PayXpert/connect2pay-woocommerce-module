<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WC_Email_Payxpert_Support
 *
 * Un email personnalisé WooCommerce.
 */
class WC_Email_Payxpert_Support extends WC_Email {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'payxpert_support';
		$this->title          = 'PayXpert Support';
		$this->description    = 'Email envoyé lorsqu\'un utilisateur soumet le formulaire de support PayXpert.';
		$this->customer_email = false;

		// Sujet et titre par défaut
		$this->heading = 'Demande de support PayXpert';
		$this->subject = 'Demande de support PayXpert';

		// Les templates
		$this->template_html  = 'email-support.php';
		$this->template_plain = 'plain/email-support.php';

		// Destinataire par défaut
		$this->recipient = get_option( 'admin_email' );

		parent::__construct();
	}

	/**
	 * Déclenche l'envoi de l'email.
	 */
	public function trigger( $data ) {

		// Tu peux personnaliser ici à qui envoyer :
		if ( ! empty( $data['recipient'] ) ) {
			$this->recipient = $data['recipient'];
		}

		$this->data = $data;

		if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
			return;
		}

		$this->send(
			$this->get_recipient(),
			$this->get_subject(),
			$this->get_content(),
			$this->get_headers(),
			$this->get_attachments()
		);
	}

	/**
	 * Remplace le contenu HTML de l'email.
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
	 * Si tu veux aussi un format texte simple.
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
