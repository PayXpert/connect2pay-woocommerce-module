<?php

/**
 * Create PayXpert Payment Gateway Option
 */
class PayXpertOption
{

	function __construct()
	{

		if (
			isset($_GET['section']) &&
			$_GET['section'] == 'payxpert'
		) {
			add_filter('woocommerce_get_settings_checkout', array($this, 'payxpert_all_settings'), 10, 2);
		}

		add_filter('woocommerce_get_sections_checkout', array($this, 'payxpert_add_section'));

		// Scripts
		if (isset($_GET['section']) && $_GET['section'] == 'payxpert') {
			add_action('admin_enqueue_scripts', [$this, 'px_plugin_enqueue_assets']);
		}

		// Save settings
		add_action('admin_post_save_payxpert_settings', [$this, 'save_payxpert_settings']);

	}

	public function payxpert_add_section($sections)
	{
		$sections['payxpert'] = __('PayXpert Settings', 'payxpert');

		return $sections;
	}

	public function px_plugin_enqueue_assets($hook)
	{
		wp_enqueue_style('px-enhanced-plugin-style', PX_ASSETS . '/css/admin-styles.css?t=' . time());
		wp_enqueue_script('px-enhanced-plugin-dynamic', PX_ASSETS . '/js/admin-js.js?t=' . time(), [], null, true);
	}

	public function get_links()
	{

		return [
			'documentation' => 'https://payxpert-docs.atlassian.net/wiki/x/AQDxLQ',
			'support' => 'https://support.payxpert.com/hc/en-gb',
			'new_account' => 'https://www.payxpert.com/getting-started/'
		];

	}

	public function get_settings()
	{

		return [
			'methods' => [
				'payxpert_wechat_pay' => __('Enable WeChat Pay', 'payxpert'),
				'payxpert_alipay' => __('Enable AliPay', 'payxpert'),
				'payxpert_seamless_mode' => __('Enable Card payment', 'payxpert'),
			],
			'cc_mods' => [
				'seamless' => __('Seamless', 'payxpert'),
				'redirect' => __('Redirection', 'payxpert'),
			],
			'trans_operations' => [
				'default' => __('Default value for the account', 'payxpert'),
				'sale' => __('Sale', 'payxpert'),
				'authorize' => __('Authorize', 'payxpert')
			],
			'merchant_notifications' => [
				'default' => __('Default value for the account', 'payxpert'),
				'enabled' => __('Enabled', 'payxpert'),
				'disabled' => __('Disabled', 'payxpert')
			],
			'email_notification_lang' => [
				'en' => __('English', 'payxpert'),
				'fr' => __('French', 'payxpert'),
				'es' => __('Spanish', 'payxpert'),
				'it' => __('Italian', 'payxpert'),
				'de' => __('German', 'payxpert'),
				'pl' => __('Polish', 'payxpert'),
				'zh' => __('Chinese', 'payxpert'),
				'ja' => __('Japanese', 'payxpert')
			]
		];

	}

	public function payxpert_all_settings($settings, $current_section)
	{

		$conn_status = get_option('payxpert_conn_status');
		if ($conn_status) {
			$block_class = '';
		} else {
			$block_class = 'disabled';
		}

		// Active gateways
		$gateways = WC()->payment_gateways->get_available_payment_gateways();
		$active_gateways = [];
		foreach ($gateways as $id => $gateway) {
			$active_gateways[] = $gateway->title;
		}
		?>


		<div class="enhanced-plugin-settings-wrap">
			<!-- Top Links -->
			<div class="top-links">
				<a href="<?php echo $this->get_links()['documentation']; ?>" target="_blank">
					<?php echo __('Documentation', 'payxpert'); ?>
				</a> |
				<a href="<?php echo $this->get_links()['support']; ?>" target="_blank">
					<?php echo __('Support', 'payxpert'); ?>
				</a>
			</div>

			<!-- Section 1: Logo and Info -->
			<div class="block">
				<div class="header">
					<img src="<?php echo PX_ASSETS . '/img/logo.png'; ?>" alt="Company Logo">
					<h1>
						<?php echo __('Plugin Settings', 'payxpert'); ?>
					</h1>
					<p>
						<?php echo __('Version', 'payxpert'); ?>:
						<?php echo PX_PLUGIN_VERSION; ?>
					</p>
				</div>
			</div>

			<!-- Section 2: Credentials -->
			<?php ?>
			<div class="block" style="display: none;">

				<h2><?php echo __('Credentials', 'payxpert'); ?></h2>
				<p><?php echo __('Please enter your credentials to unlock the settings below.', 'payxpert'); ?></p>

				<form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="px-login-ajax">
					<input type="hidden" name="action" value="save_payxpert_settings" />
					<input type="hidden" name="section" value="credentials" />
					<?php wp_nonce_field('save_payxpert_settings_action', 'save_payxpert_settings_nonce'); ?>

					<div class="form-group">
						<label for="payxpert_originator_id">
							<?php echo __('Originator ID', 'payxpert'); ?>
						</label>

						<input type="text" id="payxpert_originator_id" name="payxpert_originator_id"
							value="<?php echo get_option('payxpert_originator_id'); ?>" required />
					</div>
					<div class="form-group">
						<label for="payxpert_password">
							<?php echo __('Password', 'payxpert'); ?>
						</label>
						<input type="password" id="payxpert_password" name="payxpert_password"
							value="<?php echo get_option('payxpert_password'); ?>" required />
					</div>


					<button type="submit" class="button-primary">
						<?php echo __('Login', 'payxpert'); ?>
					</button>

					<a href="<?php echo $this->get_links()['new_account']; ?>" target="_blank" class="button-secondary">
						<?php echo __('Create Account', 'payxpert'); ?>
					</a>
					<div id="credentials-feedback"></div>

				</form>

			</div>
			<?php ?>

			<!-- Section 2: Credentials -->
			<div class="block">
				<form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="login-form"
					onsubmit="event.stopPropagation();" id="mainform">
					<input type="hidden" name="action" value="save_payxpert_settings" />
					<input type="hidden" name="section" value="credentials" />
					<?php wp_nonce_field('save_payxpert_settings_action', 'save_payxpert_settings_nonce'); ?>

					<div class="form-group">
						<label for="payxpert_originator_id">
							<?php echo __('Originator ID', 'payxpert'); ?>
						</label>
						<input type="text" id="payxpert_originator_id" name="payxpert_originator_id"
							value="<?php echo get_option('payxpert_originator_id'); ?>" required />
					</div>
					<div class="form-group">
						<label for="payxpert_password">
							<?php echo __('Password', 'payxpert'); ?>
						</label>
						<input type="password" id="payxpert_password" name="payxpert_password"
							value="<?php echo get_option('payxpert_password'); ?>" required />
					</div>

					<button type="submit" class="button-primary">
						<?php echo __('Login', 'payxpert'); ?>
					</button>

					<a href="<?php echo $this->get_links()['new_account']; ?>" target="_blank" class="button-secondary">
						<?php echo __('Create Account', 'payxpert'); ?>
					</a>
					<div id="credentials-feedback"></div>

				</form>

				<?php if ($conn_status) { ?>
					<div class="alert alert-success">
						<?php echo __('Connection Successful', 'payxpert'); ?>
					</div>
				<?php } else { ?>
					<div class="alert alert-error">
						<?php echo __('Connection Failed', 'payxpert'); ?>
					</div>
				<?php } ?>

			</div>

			<form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="save_payxpert_settings" />
				<input type="hidden" name="section" value="main" />
				<?php wp_nonce_field('save_payxpert_settings_action', 'save_payxpert_settings_nonce'); ?>

				<!-- Section 3: Payment Methods -->
				<div class="block <?php echo $block_class; ?>" id="payment-methods-section">

					<h2><?php echo __('Payment Methods', 'payxpert'); ?></h2>
					<p>
						<?php echo __('Select and configure the payment methods available for your customers.', 'payxpert'); ?>
					</p>

					<?php foreach ($this->get_settings()['methods'] as $id => $title) { ?>
						<div class="form-group">
							<label>
								<input type="checkbox" name="<?php echo $id; ?>" value="yes" <?php echo get_option($id) == 'yes' ? 'checked' : ''; ?> />
								<?php echo $title; ?>
							</label>
						</div>
					<?php } ?>

					<div class="form-group conditional" id="credit-card-options">
						<label>
							<?php echo __('Credit Card Mode', 'payxpert'); ?>
						</label>

						<select name="payxpert_credit_card_mode">
							<?php foreach ($this->get_settings()['cc_mods'] as $id => $title) { ?>
								<option value="<?php echo $id; ?>" <?php echo get_option('payxpert_credit_card_mode') == $id ? 'selected' : ''; ?>>
									<?php echo $title; ?>
								</option>
							<?php } ?>
						</select>
					</div>

				</div>

				<!-- Section 4: Configuration -->
				<div class="block <?php echo $block_class; ?>">
					<h2>
						<?php echo __('Configuration', 'payxpert'); ?>
					</h2>
					<p>
						<?php echo __('Customize the behavior and appearance of your payment system.', 'payxpert'); ?>
					</p>
					<div class="form-group">
						<label>
							<?php echo __('Transaction Operation', 'payxpert'); ?>
							<span class="tooltip">
								<img src="<?php echo PX_ASSETS . '/img/info.svg'; ?>">
								<span class="tooltiptext">
									<?php echo __('Specifies the payment operation type. Selecting "Authorize" means funds must be manually captured later.', 'payxpert'); ?>
								</span>
							</span>
						</label>
						<select name="payxpert_transaction_operation">
							<?php foreach ($this->get_settings()['trans_operations'] as $id => $title) { ?>
								<option value="<?php echo $id; ?>" <?php echo get_option('payxpert_transaction_operation') == $id ? 'selected' : ''; ?>>
									<?php echo $title; ?>
								</option>
							<?php } ?>
						</select>
					</div>
					<div class="form-group">
						<label>
							<?php echo __('Pay Button Title', 'payxpert'); ?>
						</label>
						<input type="text" name="payxpert_pay_button"
							value="<?php echo get_option('payxpert_pay_button'); ?>" />
					</div>
					<div class="form-group">
						<label>
							<?php echo __('Seamless Checkout Version', 'payxpert'); ?>
							<span class="tooltip">
								<img src="<?php echo PX_ASSETS . '/img/info.svg'; ?>">
								<span class="tooltiptext">
									<?php echo __('The version of Seamless Checkout to be used.', 'payxpert'); ?>
								</span>
							</span>
						</label>
						<input type="text" name="payxpert_seamless_version"
							value="<?php echo get_option('payxpert_seamless_version'); ?>" />
					</div>
					<div class="form-group">
						<label>
							<?php echo __('Integrity Hash', 'payxpert'); ?>
							<span class="tooltip">
								<img src="<?php echo PX_ASSETS . '/img/info.svg'; ?>">
								<span class="tooltiptext">
									<?php echo __('The file hash value of the Seamless Checkout script.', 'payxpert'); ?>
								</span>
							</span>
						</label>
						<input type="text" name="payxpert_seamless_hash"
							value="<?php echo get_option('payxpert_seamless_hash'); ?>" />
					</div>
					<div class="form-group">
						<label>
							<?php echo __('Payment Page URL', 'payxpert'); ?>
							<span class="tooltip">
								<img src="<?php echo PX_ASSETS . '/img/info.svg'; ?>">
								<span class="tooltiptext">
									<?php echo __('Do not change this field unless provided with a specific URL.', 'payxpert'); ?>
								</span>
							</span>
						</label>
						<input type="url" name="payxpert_connect2_url"
							value="<?php echo get_option('payxpert_connect2_url'); ?>" />
					</div>
					<div class="form-group">
						<label>
							<?php echo __('Payment Gateway URL (Refund)', 'payxpert'); ?>
							<span class="tooltip">
								<img src="<?php echo PX_ASSETS . '/img/info.svg'; ?>">
								<span class="tooltiptext">
									<?php echo __('Do not change this field unless provided with a specific URL.', 'payxpert'); ?>
								</span>
							</span>
						</label>
						<input type="url" name="payxpert_api_url" value="<?php echo get_option('payxpert_api_url'); ?>" />
					</div>
					<div class="form-group">
						<label>
							<input type="checkbox" name="payxpert_debug" value="yes" <?php echo get_option('payxpert_debug') == 'yes' ? 'checked' : ''; ?> />
							<?php echo __('Log PayXpert events, such as Callback', 'payxpert'); ?>
						</label>
					</div>
				</div>

				<!-- Section 5: Notifications -->
				<div class="block <?php echo $block_class; ?>">
					<h2>
						<?php echo __('Notifications', 'payxpert'); ?>
					</h2>
					<p>
						<?php echo __('Manage notifications sent to the merchant after payment attempts.', 'payxpert'); ?>
					</p>
					<div class="form-group">
						<label>
							<?php echo __('Merchant Notifications', 'payxpert'); ?>
							<span class="tooltip">
								<img src="<?php echo PX_ASSETS . '/img/info.svg'; ?>">
								<span class="tooltiptext">
									<?php echo __('Decide whether merchants should receive notifications after each payment attempt.', 'payxpert'); ?>
								</span>
							</span>
						</label>
						<select name="payxpert_merchant_notifications">
							<?php foreach ($this->get_settings()['merchant_notifications'] as $id => $title) { ?>
								<option value="<?php echo $id; ?>" <?php echo get_option('payxpert_merchant_notifications') == $id ? 'selected' : ''; ?>>
									<?php echo $title; ?>
								</option>
							<?php } ?>
						</select>
					</div>
					<div class="form-group">
						<label>
							<?php echo __('Merchant Email Notifications Recipient', 'payxpert'); ?>
							<span class="tooltip">
								<img src="<?php echo PX_ASSETS . '/img/info.svg'; ?>">
								<span class="tooltiptext">
									<?php echo __('The email address to which merchant notifications will be sent.', 'payxpert'); ?>
								</span>
							</span>
						</label>
						<input type="email" name="payxpert_merchant_notifications_to"
							value="<?php echo get_option('payxpert_merchant_notifications_to'); ?>">
					</div>
					<div class="form-group">
						<label>
							<?php echo __('Merchant Email Notifications Language', 'payxpert'); ?>
							<span class="tooltip">
								<img src="<?php echo PX_ASSETS . '/img/info.svg'; ?>">
								<span class="tooltiptext">
									<?php echo __('The language in which merchant notifications will be delivered.', 'payxpert'); ?>
								</span>
							</span>
						</label>
						<select name="payxpert_merchant_notifications_lang">
							<?php foreach ($this->get_settings()['email_notification_lang'] as $id => $title) { ?>
								<option value="<?php echo $id; ?>" <?php echo get_option('payxpert_merchant_notifications_lang') == $id ? 'selected' : ''; ?>>
									<?php echo $title; ?>
								</option>
							<?php } ?>
						</select>
					</div>
				</div>

				<!-- Section 6: Status -->
				<div class="block">
					<h2>
						<?php echo __('Status', 'payxpert'); ?>
					</h2>
					<p>
						<?php echo __('Summary of the current website and plugin status.', 'payxpert'); ?>
					</p>
					<ul id="status-info">
						<li>
							<?php echo __('Plugin version', 'payxpert'); ?>:
							<?php echo PX_PLUGIN_VERSION; ?>
						</li>
						<li>
							<?php echo __('WordPress version', 'payxpert'); ?>:
							<?php echo get_bloginfo('version'); ?>
						</li>
						<li>
							<?php echo __('WooCommerce version', 'payxpert'); ?>:
							<?php echo WC()->version; ?>

						</li>
						<li>
							<?php echo __('PHP version', 'payxpert'); ?>: <?php echo phpversion(); ?>
						</li>
						<li>
							<?php echo __('Domain', 'payxpert'); ?>: <?php echo $_SERVER['HTTP_HOST']; ?>
						</li>
						<li>
							<?php echo __('Active theme', 'payxpert'); ?>:
							<?php echo wp_get_theme()->get('Name'); ?> (v. <?php echo wp_get_theme()->get('Version'); ?>)
						</li>
						<li>
							<?php echo __('Secure Connection (HTTPS)', 'payxpert'); ?>: <?php echo is_ssl() ? 'Yes' : 'No'; ?>
						</li>
						<li>
							<?php echo __('Active gateways', 'payxpert'); ?>:
							<?php echo implode(', ', $active_gateways); ?>
						</li>
					</ul>
					<a class="button-secondary" id="copy-to-clipboard">
						<?php echo __('Copy to Clipboard', 'payxpert'); ?>
					</a>
				</div>

				<!-- Save Button -->
				<div class="block">
					<input type="submit" value="<?php echo __('Save Settings', 'payxpert'); ?>" class="button-primary" />
				</div>

			</form>

		</div>

		<style>
			p.submit {
				display: none;
			}
		</style>

		<?php

	}

	public function save_payxpert_settings()
	{

		// Check rights
		$this->check_save_rights();

		// Save credentials
		if (
			isset($_POST['section']) &&
			$_POST['section'] == 'credentials'
		) {
			$this->save_payxpert_credentials();
		}

		// Save main settings
		if (
			isset($_POST['section']) &&
			$_POST['section'] == 'main'
		) {
			$this->save_payxpert_settings_main();
		}

		// Redirect back to the settings page
		wp_redirect(admin_url('admin.php?page=wc-settings&tab=checkout&section=payxpert'));
		exit;
	}

	public function check_save_rights()
	{

		// Verify nonce
		if (
			!isset($_POST['save_payxpert_settings_nonce']) ||
			!wp_verify_nonce($_POST['save_payxpert_settings_nonce'], 'save_payxpert_settings_action')
		) {
			wp_die(__('Invalid nonce', 'payxpert'));
		}

		// Check permissions
		if (
			!current_user_can('manage_options')
		) {
			wp_die(__('You do not have permission to save settings', 'payxpert'));
		}

	}

	public function save_payxpert_credentials()
	{

		// Save each option
		$options_to_save = [
			'payxpert_originator_id',
			'payxpert_password',
		];
		$this->save_payxpert_options($options_to_save);

		// Check for account info
		$px = new PayXpertMain;
		$account = $px->get_account_info();

		if ($account == null) {
			update_option('payxpert_conn_status', false);
		} else {
			update_option('payxpert_conn_status', true);
		}

	}

	public function save_payxpert_settings_main()
	{

		// Save each option
		$options_to_save = [
			'payxpert_wechat_pay',
			'payxpert_alipay',
			'payxpert_seamless_mode',
			'payxpert_credit_card_mode',
			'payxpert_transaction_operation',
			'payxpert_pay_button',
			'payxpert_seamless_version',
			'payxpert_seamless_hash',
			'payxpert_connect2_url',
			'payxpert_api_url',
			'payxpert_debug',
			'payxpert_merchant_notifications',
			'payxpert_merchant_notifications_to',
			'payxpert_merchant_notifications_lang'
		];
		$this->save_payxpert_options($options_to_save);

		// Enable/Disable gateways
		$gateway_options = [
			'payxpert_wechat_pay' => 'payxpert_wechat',
			'payxpert_alipay' => 'payxpert_alipay',
			'payxpert_seamless_mode' => 'payxpert_seamless'
		];
		foreach ($gateway_options as $option => $gateway) {
			if (get_option($option) == 'yes') {
				$this->activate_gateway($gateway);
			} else {
				$this->deactivate_gateway($gateway);
			}
		}


	}

	public function save_payxpert_options($options)
	{

		foreach ($options as $option) {
			if (isset($_POST[$option])) {
				update_option($option, sanitize_text_field($_POST[$option]));
			} else {
				delete_option($option);
			}
		}

		$this->activate_gateway('payxpert_seamless');

	}

	private function activate_gateway($code)
	{
		$this->set_gateway_option($code, 'enabled', 'yes');
	}

	private function deactivate_gateway($code)
	{
		$this->set_gateway_option($code, 'enabled', 'no');
	}

	private function set_gateway_option($code, $key, $value)
	{

		$option_key = 'woocommerce_' . $code . '_settings';

		$option = get_option($option_key);

		$option[$key] = $value;
		update_option($option_key, $option);

	}

}

