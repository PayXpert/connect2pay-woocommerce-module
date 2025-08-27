<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WC_Email_Payxpert_Paybylink
 *
 * Envoie un e-mail contenant le lien de paiement PayByLink au client.
 */
class WC_Email_Payxpert_Paybylink extends WC_Email {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'payxpert_paybylink';
		$this->title          = 'PayXpert - PayByLink';
		$this->description    = 'Email envoyé à un client contenant un lien de paiement PayByLink pour sa commande WooCommerce.';
		$this->customer_email = true;

		$this->heading = 'Complétez votre commande';
		$this->subject = 'Paiement en attente pour votre commande sur {site_title}';

		$this->template_html  = 'email-paybylink.php';
		$this->template_plain = 'plain/email-paybylink.php';

		$this->recipient = ''; // Ce sera défini dynamiquement avec trigger()

		parent::__construct();
	}

	/**
	 * Déclenche l’envoi de l’e-mail.
	 *
	 * @param array $data Données à injecter dans le template.
	 */
	public function trigger( $data ) {
		if ( empty( $data['recipient'] ) || empty( $data['order'] ) || empty( $data['payment_link'] ) ) {
			return;
		}

		$this->recipient = $data['recipient'];
		$this->data      = $data;

		$this->object = $data['order'];

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
