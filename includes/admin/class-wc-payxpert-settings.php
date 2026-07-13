<?php
/**
 * Wc Payxpert Settings.
 *
 * @package Payxpert
 */

use Payxpert\Utils\WC_Payxpert_Admin_Notices;
use Payxpert\Utils\WC_Payxpert_Logger;
use Payxpert\Utils\WC_Payxpert_Utils;
use Payxpert\Utils\WC_Payxpert_Webservice;
use Payxpert\WC_Payxpert;

/**
 * PayXpert Settings Page for WooCommerce
 */
class WC_Payxpert_Settings {

	/**
	 * Instance singleton.
	 *
	 * @var self|null
	 */
	protected static $instance = null;

	/**
	 * Instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Construct.
	 */
	public function __construct() {
		// Add sub-menu in WooCommerce > Extensions.
		add_action( 'admin_menu', array( $this, 'add_submenu_page' ), 100 );

		// Loading assets.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Save handler.
		add_action( 'admin_post_save_payxpert_settings', array( $this, 'save_settings' ) );

		// Support email handler.
		add_action( 'wp_ajax_payxpert_send_support_email', array( $this, 'send_support_email_callback' ) );

		// Download log handler.
		add_action( 'admin_post_payxpert_download_logs', array( $this, 'payxpert_handle_logs_download' ) );
	}

	/**
	 * Add submenu page.
	 */
	public function add_submenu_page(): void {
		add_submenu_page(
			'woocommerce',
			__( 'PayXpert Settings', 'payxpert' ),
			__( 'PayXpert', 'payxpert' ),
			'manage_woocommerce',
			'payxpert-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Enqueue assets.
	 *
	 * @param mixed $hook Hook.
	 */
	public function enqueue_assets( $hook ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check of the current admin page to scope asset enqueue.
		if ( isset( $_GET['page'] ) && 'payxpert-settings' === sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) {
			WC_Payxpert_Admin_Assets::enqueue_admin_settings_assets( true );
		}
	}

	/**
	 * Render settings page.
	 *
	 * @return mixed
	 */
	public function render_settings_page() {
		$conn_status = get_option( 'payxpert_conn_status' );
		$settings    = self::get_settings();

		?>
		<div class="enhanced-plugin-settings-wrap">
			<img data-target="https://www.payxpert.fr/contactez-nous/" width="100%" src="<?php echo esc_url( WC_PAYXPERT_ASSETS . 'img/banners/PayXpertBanner.jpg' ); ?>" alt="PayXpert banner">
			
			<div class="top-links">
				<a class="button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=payxpert_download_logs' ), 'payxpert_download_logs' ) ); ?>">
					<?php echo esc_html__( 'Download LOGs', 'payxpert' ); ?>
				</a>
				<button id="open-payxpert-support" class="button-secondary"><?php echo esc_html__( 'CONTACT US', 'payxpert' ); ?></button>
				<a class="button-primary" target="_blank" href="<?php echo esc_url( 'https://payxpert-docs.atlassian.net/wiki/spaces/docs/pages/770768897/WooCommerce+Plugin+User+Guide' ); ?>">
					<?php echo esc_html__( 'User guide', 'payxpert' ); ?>
				</a>
			</div>

			<div class="block">
				<div class="header">
					<h1><?php echo esc_html__( 'Plugin Settings', 'payxpert' ); ?></h1>
				</div>
			</div>

			<?php
			if ( WC_Payxpert_Admin_Notices::get_and_delete( 'settings_saved' ) ) {
				echo '<div class="payxpert-notice notice notice-success is-dismissible">';
				echo '<p>' . esc_html__( 'Settings saved successfully.', 'payxpert' ) . '</p>';
				echo '</div>';
			}

			$admin_error = WC_Payxpert_Admin_Notices::get_and_delete( 'admin_error' );
			if ( $admin_error ) {
				echo '<div class="payxpert-notice notice notice-error is-dismissible">';
				echo '<p>' . esc_html( $admin_error ) . '</p>';
				echo '</div>';
			}

			if ( ! $conn_status ) {
				echo '<div class="payxpert-notice notice notice-error is-dismissible">';
				echo '<p>' . esc_html__( 'Account credentials are not valids.', 'payxpert' ) . '</p>';
				echo '</div>';
			}
			?>

			<div class="block">
				<li>
					<strong><?php echo esc_html__( 'PHP Version:', 'payxpert' ); ?></strong>
					<?php echo esc_html( PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION ); ?>
				</li>
				<li>
					<strong><?php echo esc_html__( 'CMS Version:', 'payxpert' ); ?></strong>
					<?php echo 'WordPress ' . esc_html( get_bloginfo( 'version' ) ); ?>
				</li>
				<li>
					<strong><?php echo esc_html__( 'Woocommerce Version:', 'payxpert' ); ?></strong>
					<?php echo 'WooCommerce ' . esc_html( WC_VERSION ); ?>
				</li>
				<li>
					<strong><?php echo esc_html__( 'Module Version:', 'payxpert' ); ?></strong>
					<?php echo esc_html( WC_PAYXPERT_VERSION ); ?>
				</li>
				<li>
					<strong><?php echo esc_html__( 'API keys valid:', 'payxpert' ); ?></strong>
					<?php echo $conn_status ? '✅ ' . esc_html__( 'Yes', 'payxpert' ) : '❌ ' . esc_html__( 'No', 'payxpert' ); ?>
				</li>
			</div>

			<!-- Formulaire de connexion -->
			<div class="block">
				<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="login-form">
					<input type="hidden" name="action" value="save_payxpert_settings" />
					<input type="hidden" name="section" value="credentials" />
					<?php wp_nonce_field( 'save_payxpert_settings_action', 'save_payxpert_settings_nonce' ); ?>
					
					<h2><?php echo esc_html__( 'Your merchant account', 'payxpert' ); ?></h2>
					<hr class="section-header">

					<div class="form-group">
						<label for="Originator_id">
							<?php echo esc_html__( 'Public API Key', 'payxpert' ); ?>
						</label>
						<input type="text" id="payxpert_originator_id" name="payxpert_originator_id"
							value="<?php echo esc_attr( get_option( 'payxpert_originator_id' ) ); ?>"
							pattern="[0-9]+"
							inputmode="numeric"
							autocomplete="off"
							required />
					</div>
					<div class="form-group">
						<label for="payxpert_password">
							<?php echo esc_html__( 'Private API Key', 'payxpert' ); ?>
						</label>
						<?php
						$has_private_key = (string) get_option( 'payxpert_password' ) !== '';
						?>
						<input
							type="password"
							id="payxpert_password"
							name="payxpert_password"
							value=""
							autocomplete="new-password"
							placeholder="<?php echo esc_attr( $has_private_key ? '********' : '' ); ?>"
							<?php echo $has_private_key ? '' : ' required'; ?>
						/>
						<?php if ( $has_private_key ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep the current private key.', 'payxpert' ); ?></p>
						<?php endif; ?>
					</div>

					<button type="submit" class="button-primary">
						<?php echo esc_html__( 'Login', 'payxpert' ); ?>
					</button>
				</form>
			</div>

			<?php if ( $conn_status ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="save_payxpert_settings" />
					<input type="hidden" name="section" value="main" />
					<?php wp_nonce_field( 'save_payxpert_settings_action', 'save_payxpert_settings_nonce' ); ?>

					<?php
					foreach ( $settings as $section_key => $section ) {
						echo '<div class="block" id="' . esc_attr( $section_key ) . '">';

						// Display section title.
						if ( ! empty( $section['title'] ) ) {
							echo '<h2>' . esc_html( $section['title'] ) . '</h2>';
						} else {
							echo '<h2>' . esc_html( ucfirst( $section_key ) ) . '</h2>';
						}

						if ( ! empty( $section['description'] ) ) {
							echo '<p>' . wp_kses_post( $section['description'] ) . '</p>';
						}

						echo '<hr class="section-header">';

						$fieldset_open = false;
						if ( ! empty( $section['fields'] ) && is_array( $section['fields'] ) ) {
							foreach ( $section['fields'] as $field_key => $field ) {
								if ( ! is_array( $field ) || empty( $field['type'] ) ) {
									continue;
								}

								if ( 'fieldset' == $field['type'] ) {
									$fieldset_open = true;
									echo '<fieldset id="' . esc_attr( $field['id'] ) . '">';
									echo '<legend>' . esc_html( $field['label'] ) . '</legend>';
									continue;
								}

								if ( 'fieldset_close' == $field['type'] ) {
									echo '</fieldset>';
									$fieldset_open = false;
									continue;
								}

								$value = get_option( $field_key, isset( $field['default'] ) ? $field['default'] : '' );

								echo '<div class="form-group">';

								if ( 'checkbox' !== $field['type'] ) {
									echo '<label for="' . esc_attr( $field_key ) . '">' . esc_html( $field['label'] ) . '</label>';
								}

								switch ( $field['type'] ) {
									case 'text':
									case 'password':
									case 'number':
										$step = isset( $field['custom_attributes']['step'] ) ? ' step="' . esc_attr( $field['custom_attributes']['step'] ) . '"' : '';
										$min  = isset( $field['custom_attributes']['min'] ) ? ' min="' . esc_attr( $field['custom_attributes']['min'] ) . '"' : '';
										$max  = isset( $field['custom_attributes']['max'] ) ? ' max="' . esc_attr( $field['custom_attributes']['max'] ) . '"' : '';
										// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $step/$min/$max are pre-escaped attribute fragments built with esc_attr() just above; all other values are escaped inline.
										echo '<input type="' . esc_attr( $field['type'] ) . '" id="' . esc_attr( $field_key ) . '" name="' . esc_attr( $field_key ) . '" value="' . esc_attr( $value ) . '"' . $step . $min . $max . ' />';
										break;

									case 'select':
										echo '<select id="' . esc_attr( $field_key ) . '" name="' . esc_attr( $field_key ) . '">';
										foreach ( $field['options'] as $opt_value => $opt_label ) {
											echo '<option value="' . esc_attr( $opt_value ) . '"' . selected( $value, $opt_value, false ) . '>' . esc_html( $opt_label ) . '</option>';
										}
										echo '</select>';
										break;

									case 'checkbox':
										echo '<div class="checkbox-flex">';
										echo '<input type="checkbox" id="' . esc_attr( $field_key ) . '" name="' . esc_attr( $field_key ) . '" value="yes"' . checked( $value, 'yes', false ) . ' />';
										if ( ! empty( $field['label'] ) ) {
											echo '<label for="' . esc_attr( $field_key ) . '">' . esc_html( $field['label'] ) . '</label>';
										}
										echo '</div>';
										break;

									default:
										echo '<input type="text" id="' . esc_attr( $field_key ) . '" name="' . esc_attr( $field_key ) . '" value="' . esc_attr( $value ) . '" />';
								}

								if ( ! empty( $field['description'] ) ) {
									echo '<p class="description">' . wp_kses_post( $field['description'] ) . '</p>';
								}

								echo '</div>';
							}
						}

						if ( $fieldset_open ) {
							echo '</fieldset>';
						}
						echo '</div>';
					}
					?>
					
					<?php submit_button( __( 'Save Settings', 'payxpert' ) ); ?>
				</form>
			<?php endif; ?>

		</div>
		
		<?php

		/* Support dialog */
		include WC_PAYXPERT_PLUGIN_FILE_PATH . 'templates/views/html-admin-settings-support-dialog.php';
	}

	/**
	 * Get settings.
	 *
	 * @return mixed
	 */
	public static function get_settings() {
		return array(

			'payment_settings'        => array(
				'title'       => __( 'Payment Settings', 'payxpert' ),
				'description' => '',
				'fields'      => array(
					'payxpert_capture_mode'         => array(
						'type'        => 'select',
						'label'       => __( 'Capture mode', 'payxpert' ),
						'description' => '<strong style="color:red">' . __( 'Payments will be authorized but will require manual validation before collection. Make sure to capture transactions within the allotted time to avoid authorization expiration.', 'payxpert' ) . '</strong>'
										. '<br>'
										. __( 'To secure payments, the latency period for manual capture is limited to 7 days. It is imperative to capture transactions before this deadline.', 'payxpert' ),
						'default'     => WC_Payxpert::CAPTURE_MODE_AUTOMATIC,
						'options'     => array(
							WC_Payxpert::CAPTURE_MODE_AUTOMATIC => __( 'Automatic', 'payxpert' ),
							WC_Payxpert::CAPTURE_MODE_MANUAL    => __( 'Manual', 'payxpert' ),
						),
					),

					'payxpert_capture_manual_email' => array(
						'type'        => 'text',
						'label'       => __( 'Email notification', 'payxpert' ),
						'description' => __( 'Enter the email address that will be notified before the automatic initiation of the capture (5 days after the transaction).', 'payxpert' ),
						'default'     => '',
					),

					'payxpert_redirect_mode'        => array(
						'type'        => 'select',
						'label'       => __( 'Display mode', 'payxpert' ),
						'description' => __( 'Select the redirect mode for payments.', 'payxpert' ),
						'default'     => WC_Payxpert::REDIRECT_MODE_REDIRECT,
						'options'     => array(
							WC_Payxpert::REDIRECT_MODE_REDIRECT => __( 'Redirection', 'payxpert' ),
							WC_Payxpert::REDIRECT_MODE_SEAMLESS => __( 'IFrame', 'payxpert' ),
						),
					),

					'payxpert_paybylink_enabled'    => array(
						'type'        => 'checkbox',
						'label'       => __( 'Enable PayByLink', 'payxpert' ),
						'default'     => 'no',
						'description' => __( 'By activating this function, it is possible to send a payment link by email from an order in the back office.', 'payxpert' ),
					),

					// 'payxpert_oneclick' => [
					// 'type'        => 'checkbox',
					// 'label'       => __('Enable OneClick (you assume responsibility for fraud and chargebacks)', 'payxpert'),
					// 'description' => __('Please note: By disabling this option, you will accept payments even if Liability Shift is not active.', 'payxpert'),
					// 'default'     => 'yes',
					// ],
				),
			),

			'payment_method_settings' => array(
				'title'       => __( 'Payment methods settings', 'payxpert' ),
				'description' => '',
				'fields'      => array(
					'payxpert_cc_enabled'             => array(
						'type'    => 'checkbox',
						'label'   => __( 'Card CB/Visa/Mastercard', 'payxpert' ),
						'default' => 'no',
					),
					'payxpert_amex_enabled'           => array(
						'type'    => 'checkbox',
						'label'   => __( 'American Express', 'payxpert' ),
						'default' => 'no',
					),
					'payxpert_installment_x2_enabled' => array(
						'type'    => 'checkbox',
						'label'   => __( 'Installment x2', 'payxpert' ),
						'default' => 'no',
					),
					'payxpert_installment_x3_enabled' => array(
						'type'    => 'checkbox',
						'label'   => __( 'Installment x3', 'payxpert' ),
						'default' => 'no',
					),
					'payxpert_installment_x4_enabled' => array(
						'type'    => 'checkbox',
						'label'   => __( 'Installment x4', 'payxpert' ),
						'default' => 'no',
					),
					// #44959 : Prochain lot
					// 'payxpert_alipay_enabled' => [
					// 'type'    => 'checkbox',
					// 'label'   => __('Alipay', 'payxpert'),
					// 'default' => 'no',
					// ],
					// 'payxpert_wechat_enabled' => [
					// 'type'    => 'checkbox',
					// 'label'   => __('Wechat', 'payxpert'),
					// 'default' => 'no',
					// ],
					'payxpert_applepay_enabled'       => array(
						'type'        => 'checkbox',
						'label'       => __( 'Applepay', 'payxpert' ),
						'default'     => 'no',
						'description' => __( 'This payment method is not available yet in display mode: IFrame.', 'payxpert' ),
					),
					'payxpert_subscription_enabled'   => array(
						'type'    => 'checkbox',
						'label'   => __( 'Subscription', 'payxpert' ),
						'default' => 'no',
					),
				),
			),
			'payment_method_labels'   => array(
				'title'       => __( 'Customization', 'payxpert' ),
				'description' => '',
				'fields'      => array(
					'payxpert_cc_label'           => array(
						'type'    => 'text',
						'label'   => __( 'Payment label: Credit Card', 'payxpert' ),
						'default' => __( 'Payment via Credit Card by PayXpert', 'payxpert' ),
					),
					'payxpert_subscription_label' => array(
						'type'    => 'text',
						'label'   => __( 'Payment label: Subscription', 'payxpert' ),
						'default' => __( 'Paiement par Abonnement via Carte de crédit par PayXpert', 'payxpert' ),
					),
					// #44959 : Prochain lot
					// 'payxpert_alipay_label' => [
					// 'type'    => 'text',
					// 'label'   => __('Payment label: Alipay', 'payxpert'),
					// 'default' => __('Payment via Alipay by PayXpert', 'payxpert'),
					// ],
					// 'payxpert_wechat_label' => [
					// 'type'    => 'text',
					// 'label'   => __('Payment label: Wechat', 'payxpert'),
					// 'default' => __('Payment via Wechat by PayXpert', 'payxpert'),
					// ],
				),
			),

			'installment_settings'    => array(
				'title'       => __( 'Installment Payment Settings', 'payxpert' ),
				'description' => '',
				'fields'      => array(
					'payxpert_installment_payment_logo_enabled' => array(
						'type'    => 'checkbox',
						'label'   => __( 'Activate logo', 'payxpert' ),
						'default' => 'no',
					),

					'payxpert_instalment_payment_min_amount' => array(
						'type'              => 'number',
						'label'             => __( 'Minimum amount for installment payment', 'payxpert' ),
						'description'       => __( 'Minimum order amount to enable installment payment.', 'payxpert' ),
						'default'           => '0',
						'custom_attributes' => array(
							'step' => '1',
							'min'  => '0',
						),
					),

					'payxpert_instalment_payment_max_amount' => array(
						'type'              => 'number',
						'label'             => __( 'Maximum amount for installment payment', 'payxpert' ),
						'description'       => __( 'Maximum order amount to enable installment payment.', 'payxpert' ),
						'default'           => '0',
						'custom_attributes' => array(
							'step' => '1',
							'min'  => '0',
						),
					),

					'payxpert_installment_x2'            => array(
						'id'    => 'payxpert_installment_x2',
						'type'  => 'fieldset',
						'label' => __( 'Installment 2x', 'payxpert' ),
					),

					'payxpert_installment_x2_percentage' => array(
						'type'              => 'number',
						'label'             => __( 'Percentage', 'payxpert' ),
						'description'       => __( 'Indicate the proportion to be paid in the first monthly payment. The remainder will be distributed equally between the other installments.', 'payxpert' ),
						'default'           => '50',
						'custom_attributes' => array(
							'step' => '1',
							'min'  => '1',
							'max'  => '99',
						),
					),

					'payxpert_installment_x2_label'      => array(
						'type'    => 'text',
						'label'   => __( 'Front label', 'payxpert' ),
						'default' => __( 'Pay in 2 times', 'payxpert' ),
					),

					'payxpert_installment_x2_close'      => array(
						'type' => 'fieldset_close',
					),

					'payxpert_installment_x3'            => array(
						'id'    => 'payxpert_installment_x3',
						'type'  => 'fieldset',
						'label' => __( 'Installment 3x', 'payxpert' ),
					),

					'payxpert_installment_x3_percentage' => array(
						'type'              => 'number',
						'label'             => __( 'Percentage', 'payxpert' ),
						'description'       => __( 'Indicate the proportion to be paid in the first monthly payment. The remainder will be distributed equally between the other installments.', 'payxpert' ),
						'default'           => '33',
						'custom_attributes' => array(
							'step' => '1',
							'min'  => '1',
							'max'  => '99',
						),
					),

					'payxpert_installment_x3_label'      => array(
						'type'    => 'text',
						'label'   => __( 'Front label', 'payxpert' ),
						'default' => __( 'Pay in 3 times', 'payxpert' ),
					),

					'payxpert_installment_x3_close'      => array(
						'type' => 'fieldset_close',
					),

					'payxpert_installment_x4'            => array(
						'id'    => 'payxpert_installment_x4',
						'type'  => 'fieldset',
						'label' => __( 'Installment 4x', 'payxpert' ),
					),

					'payxpert_installment_x4_percentage' => array(
						'type'              => 'number',
						'label'             => __( 'Percentage', 'payxpert' ),
						'description'       => __( 'Indicate the proportion to be paid in the first monthly payment. The remainder will be distributed equally between the other installments.', 'payxpert' ),
						'default'           => '25',
						'custom_attributes' => array(
							'step' => '1',
							'min'  => '1',
							'max'  => '99',
						),
					),

					'payxpert_installment_x4_label'      => array(
						'type'    => 'text',
						'label'   => __( 'Front label', 'payxpert' ),
						'default' => __( 'Pay in 4 times', 'payxpert' ),
					),
				),
			),

			'notification_settings'   => array(
				'title'       => __( 'Notification', 'payxpert' ),
				'description' => '',
				'fields'      => array(

					'payxpert_notification_active'   => array(
						'type'        => 'checkbox',
						'label'       => __( 'Activate merchant notification', 'payxpert' ),
						'description' => __( 'Enable to receive notifications related to payment requests.', 'payxpert' ),
						'default'     => 'no',
					),

					'payxpert_notification_to'       => array(
						'type'        => 'text',
						'label'       => __( 'Notification recipient', 'payxpert' ),
						'description' => __( 'Enter the email address that will receive notifications.', 'payxpert' ),
						'default'     => '',
					),

					'payxpert_notification_language' => array(
						'type'    => 'select',
						'label'   => __( 'Notification language', 'payxpert' ),
						'options' => array(
							'en' => __( 'English', 'payxpert' ),
							'fr' => __( 'French', 'payxpert' ),
						),
						'default' => 'en',
					),
				),
			),

		);
	}

	/**
	 * Save settings.
	 */
	public function save_settings(): void {
		WC_Payxpert_Logger::debug( 'save_settings' );

		$this->check_save_rights();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in check_save_rights() (check_admin_referer) immediately above.
		$section = sanitize_key( wp_unslash( $_POST['section'] ?? '' ) );

		switch ( $section ) {
			case 'credentials':
				$this->save_credentials();
				break;

			case 'main':
				$this->save_main();
				WC_Payxpert_Admin_Notices::set( 'settings_saved', true );
				break;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=payxpert-settings' ) );
		exit;
	}

	/**
	 * Save credentials.
	 *
	 * @return mixed
	 */
	private function save_credentials() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Reached only from save_settings(), which verifies the nonce via check_save_rights() before dispatching here.
		$new_originator_id = stripslashes(
			trim( sanitize_text_field( wp_unslash( $_POST['payxpert_originator_id'] ?? '' ) ) )
		);

		$new_private_key = trim(
			sanitize_text_field( wp_unslash( $_POST['payxpert_password'] ?? '' ) )
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$existing_private_key  = (string) get_option( 'payxpert_password', '' );
		$private_key_for_check = '' !== $new_private_key ? stripslashes( $new_private_key ) : $existing_private_key;

		if ( '' === $new_originator_id || '' === $private_key_for_check ) {
			WC_Payxpert_Admin_Notices::set(
				'admin_error',
				__( 'Public and private API keys are required.', 'payxpert' )
			);
			return;
		}

		if ( ! preg_match( '/^\d+$/', $new_originator_id ) ) {
			WC_Payxpert_Admin_Notices::set(
				'admin_error',
				__( 'The public API key must contain digits only.', 'payxpert' )
			);
			return;
		}

		$account = WC_Payxpert_Webservice::get_account_info(
			$new_originator_id,
			$private_key_for_check
		);

		if ( isset( $account['error'] ) ) {
			WC_Payxpert_Admin_Notices::set( 'admin_error', $account['error'] );
			return;
		}

		update_option( 'payxpert_originator_id', $new_originator_id );

		if ( '' !== $new_private_key ) {
			update_option( 'payxpert_password', stripslashes( $new_private_key ) );
		}

		update_option( 'payxpert_conn_status', true );
		update_option( 'payxpert_seamless_library', wp_json_encode( $account['newestSeamlessLibrary'] ) );
		WC_Payxpert_Admin_Notices::set( 'settings_saved', true );
	}

	/**
	 * Save main.
	 *
	 * @return mixed
	 */
	private function save_main() {
		$configuration = WC_Payxpert_Utils::get_configuration();

		unset( $configuration['payxpert_originator_id'] );
		unset( $configuration['payxpert_password'] );
		unset( $configuration['payxpert_conn_status'] );
		unset( $configuration['payxpert_seamless_library'] );

		$this->save_payxpert_options( array_keys( $configuration ) );
	}

	/**
	 * Check save rights.
	 *
	 * @return mixed
	 */
	private function check_save_rights() {
		// Permission check.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this action.', 'payxpert' ) );
		}

		// Nonce check.
		check_admin_referer( 'save_payxpert_settings_action', 'save_payxpert_settings_nonce' );
	}

	/**
	 * Save payxpert options.
	 *
	 * @param mixed $options Options.
	 * @return mixed
	 */
	private function save_payxpert_options( $options ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Reached only from save_settings()/save_main(), which verify the nonce via check_save_rights() before dispatching here.
		foreach ( $options as $option ) {
			if ( isset( $_POST[ $option ] ) ) {
				update_option(
					$option,
					stripslashes( trim( sanitize_text_field( wp_unslash( $_POST[ $option ] ?? '' ) ) ) )
				);
			} else {
				delete_option( $option );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Send support email callback.
	 */
	public function send_support_email_callback(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Unauthorized', 'payxpert' ), 403 );
		}

		check_ajax_referer( 'payxpert_support_nonce' );

		$firstname = sanitize_text_field( wp_unslash( $_POST['firstname'] ?? '' ) );
		$lastname  = sanitize_text_field( wp_unslash( $_POST['lastname'] ?? '' ) );
		$email     = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$subject   = sanitize_textarea_field( wp_unslash( $_POST['subject'] ?? '' ) );

		if ( empty( $firstname ) || empty( $lastname ) || empty( $email ) || empty( $subject ) ) {
			wp_send_json_error( __( 'Please fill all required fields.', 'payxpert' ) );
		}

		if ( ! is_email( $email ) ) {
			wp_send_json_error( __( 'The email address is not valid.', 'payxpert' ) );
		}

		// Custom Email Instance.
		$mailer = WC()->mailer();
		$emails = $mailer->get_emails();

		if ( isset( $emails['WC_Email_Payxpert_Support'] ) ) {
			// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- Inline type hint.
			/** @var WC_Email_Payxpert_Support $support_email */
			$support_email = $emails['WC_Email_Payxpert_Support'];

			$support_email->trigger(
				array(
					'shop_name'      => get_bloginfo( 'name' ),
					'mid'            => get_option( 'payxpert_originator_id' ),
					'firstname'      => $firstname,
					'lastname'       => $lastname,
					'recipient'      => 'Assistance@payxpert.com',
					'email_customer' => $email,
					'subject'        => $subject,
					'cms_version'    => get_bloginfo( 'version' ),
					'wooc_version'   => get_option( 'woocommerce_version' ),
					'php_version'    => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION,
					'module_version' => WC_PAYXPERT_VERSION,
					'email'          => $support_email,
				)
			);

			WC_Payxpert_Logger::info( 'Support request submitted' );
			wp_send_json_success( __( 'Your support request was sent successfully.', 'payxpert' ) );
		}

		WC_Payxpert_Logger::error( 'Support email class not found.' );
		wp_send_json_error( __( 'Failed to initialize the email class.', 'payxpert' ) );
	}

	/**
	 * Payxpert handle logs download.
	 */
	public function payxpert_handle_logs_download(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'payxpert' ) );
		}

		check_admin_referer( 'payxpert_download_logs' );

		$log_file = WC_Payxpert_Logger::get_log_file_path();
		if ( ! file_exists( $log_file ) ) {
			wp_die( esc_html__( 'Log file not found.', 'payxpert' ) );
		}

		header( 'Content-Description: File Transfer' );
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="log_file_' . gmdate( 'Y_m_d_His' ) . '.log"' );
		header( 'Expires: 0' );
		header( 'Cache-Control: must-revalidate' );
		header( 'Pragma: public' );
		header( 'Content-Length: ' . filesize( $log_file ) );
		ob_clean();

		flush();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming a binary log file to the browser; WP_Filesystem has no streaming equivalent.
		readfile( $log_file );

		exit;
	}
}
