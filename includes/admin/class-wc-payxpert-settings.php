<?php

use Payxpert\Utils\WC_Payxpert_Logger;
use Payxpert\Utils\WC_Payxpert_Utils;
use Payxpert\Utils\WC_Payxpert_Webservice;
use Payxpert\WC_Payxpert;

/**
 * PayXpert Settings Page for WooCommerce
 */
class WC_Payxpert_Settings {

    /**
     * Instance singleton
     */
    protected static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Add sub-menu in WooCommerce > Extensions
        add_action('admin_menu', [$this, 'add_submenu_page'], 100);

        // Loading assets
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        // Save handler
        add_action('admin_post_save_payxpert_settings', [$this, 'save_settings']);

        // Support email handler
        add_action('wp_ajax_payxpert_send_support_email', [$this, 'send_support_email_callback']);

        // Download log handler
        add_action('admin_post_payxpert_download_logs', [$this, 'payxpert_handle_logs_download']);
    }

    public function add_submenu_page() {
        add_submenu_page(
            'woocommerce',
            __('PayXpert Settings', 'payxpert'),
            __('PayXpert', 'payxpert'),
            'manage_woocommerce',
            'payxpert-settings',
            [$this, 'render_settings_page']
        );
    }

    public function enqueue_assets($hook) {
        if (isset($_GET['page']) && $_GET['page'] === 'payxpert-settings') {
            wp_enqueue_style('payxpert-admin-settings-style', WC_PAYXPERT_ASSETS . 'css/admin-settings.css', [], WC_PAYXPERT_VERSION);
            wp_enqueue_script('payxpert-admin-settings-script', WC_PAYXPERT_ASSETS . 'js/src/admin-settings.js', [], WC_PAYXPERT_VERSION);
            wp_localize_script('payxpert-admin-settings-script', 'payxpert_support', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('payxpert_support_nonce'),
                'i18n' => array(
                    'send'   => __('Send', 'payxpert'),
                    'close'  => __('Close', 'payxpert'),
                )
            ]);

            wp_enqueue_script('jquery');
            wp_enqueue_script('jquery-ui-dialog');
            wp_enqueue_style('wp-jquery-ui-dialog');
        }
    }

    public function render_settings_page() {
        $conn_status = get_option('payxpert_conn_status');
        $settings = self::get_settings();

        ?>
        <div class="enhanced-plugin-settings-wrap">
            <img data-target="https://www.payxpert.fr/contactez-nous/" width="100%" src="<?php echo WC_PAYXPERT_ASSETS . 'img/banners/PayXpertBanner.jpg'; ?>" alt="PayXpert banner">
            
            <div class="top-links">
                <a class="button-primary" href="<?php echo esc_url( admin_url( 'admin-post.php?action=payxpert_download_logs' ) ); ?>">
                    <?php echo __('Download LOGs','payxpert'); ?>
                </a>
                <button id="open-payxpert-support" class="button-secondary"><?php echo __('CONTACT US', 'payxpert'); ?></button>
                <a class="button-primary" target="_blank" href="<?php echo esc_url( "https://payxpert-docs.atlassian.net/wiki/spaces/docs/pages/770768897/WooCommerce+Plugin+User+Guide" ); ?>">
                    <?php echo __('User guide','payxpert'); ?>
                </a>
			</div>

            <div class="block">
                <div class="header">
                    <h1><?php echo __('Plugin Settings', 'payxpert'); ?></h1>
                </div>
            </div>

            <?php
            if (get_transient('payxpert_settings_saved')) {
                echo '<div class="payxpert-notice notice notice-success is-dismissible">';
                echo '<p>' . __('Settings saved successfully.', 'payxpert') . '</p>';
                echo '</div>';

                delete_transient('payxpert_settings_saved');
            }
            ?>

            <div class="block">
                <li>
                    <strong><?php echo __('PHP Version:', 'payxpert'); ?></strong>
                    <?php echo PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION; ?>
                </li>
                <li>
                    <strong><?php echo __('CMS Version:', 'payxpert'); ?></strong>
                    <?php echo 'WordPress ' . esc_html(get_bloginfo('version')); ?>
                </li>
                <li>
                    <strong><?php echo __('Woocommerce Version:', 'payxpert'); ?></strong>
                    <?php echo 'WooCommerce ' . WC_VERSION; ?>
                </li>
                <li>
                    <strong><?php echo __('Module Version:', 'payxpert'); ?></strong>
                    <?php echo WC_PAYXPERT_VERSION; ?>
                </li>
                <li>
                    <strong><?php echo __('API keys valid:', 'payxpert'); ?></strong>
                    <?php echo $conn_status ? '✅ ' . __('Yes', 'payxpert') : '❌ ' . __('No', 'payxpert'); ?>
                </li>
            </div>

            <!-- Formulaire de connexion -->
            <div class="block">
                <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="login-form">
                    <input type="hidden" name="action" value="save_payxpert_settings" />
                    <input type="hidden" name="section" value="credentials" />
                    <?php wp_nonce_field('save_payxpert_settings_action', 'save_payxpert_settings_nonce'); ?>
                    
                    <h2><?php echo __('Your merchant account', 'payxpert'); ?></h2>
                    <hr class="section-header">

                    <div class="form-group">
                        <label for="Originator_id">
                            <?php echo __('Public API Key', 'payxpert'); ?>
                        </label>
                        <input type="text" id="payxpert_originator_id" name="payxpert_originator_id"
                            value="<?php echo get_option('payxpert_originator_id'); ?>" required />
                    </div>
                    <div class="form-group">
                        <label for="password">
                            <?php echo __('Private API Key', 'payxpert'); ?>
                        </label>
                        <input type="password" id="payxpert_password" name="payxpert_password"
                            value="<?php echo stripslashes(get_option('payxpert_password')); ?>" required />
                    </div>

                    <button type="submit" class="button-primary">
                        <?php echo __('Login', 'payxpert'); ?>
                    </button>
                </form>
            </div>

            <?php if ($conn_status): ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="save_payxpert_settings" />
                    <input type="hidden" name="section" value="main" />
                    <?php wp_nonce_field('save_payxpert_settings_action', 'save_payxpert_settings_nonce'); ?>

                    <?php
                        foreach ($settings as $section_key => $section) {
                            echo '<div class="block" id="' . $section_key . '">';

                            // Display section title
                            if (!empty($section['title'])) {
                                echo '<h2>' . esc_html($section['title']) . '</h2>';
                            } else {
                                echo '<h2>' . ucfirst($section_key) . '</h2>';
                            }

                            if (!empty($section['description'])) {
                                echo '<p>' . $section['description'] . '</p>';
                            }

                            echo '<hr class="section-header">';

                            $fieldset_open = false;
                            if (!empty($section['fields']) && is_array($section['fields'])) {
                                foreach ($section['fields'] as $field_key => $field) {
                                    if (!is_array($field) || empty($field['type'])) {
                                        continue;
                                    }

                                    if ($field['type'] == 'fieldset') {
                                        $fieldset_open = true;
                                        echo '<fieldset id="' . $field['id'] . '">';
                                        echo '<legend>' . esc_html($field['label']) . '</legend>';
                                        continue;
                                    } 

                                    if ($field['type'] == 'fieldset_close') {
                                        echo '</fieldset>';
                                        $fieldset_open = false;
                                        continue;
                                    }

                                    $value = get_option($field_key, isset($field['default']) ? $field['default'] : '');

                                    echo '<div class="form-group">';

                                    if ($field['type'] !== 'checkbox') {
                                        echo '<label for="' . esc_attr($field_key) . '">' . esc_html($field['label']) . '</label>';
                                    }

                                    switch ($field['type']) {
                                        case 'text':
                                        case 'password':
                                        case 'number':
                                            $step = isset($field['custom_attributes']['step']) ? ' step="' . esc_attr($field['custom_attributes']['step']) . '"' : '';
                                            $min = isset($field['custom_attributes']['min']) ? ' min="' . esc_attr($field['custom_attributes']['min']) . '"' : '';
                                            $max = isset($field['custom_attributes']['max']) ? ' max="' . esc_attr($field['custom_attributes']['max']) . '"' : '';
                                            echo '<input type="' . esc_attr($field['type']) . '" id="' . esc_attr($field_key) . '" name="' . esc_attr($field_key) . '" value="' . esc_attr($value) . '"' . $step . $min . $max . ' />';
                                            break;

                                        case 'select':
                                            echo '<select id="' . esc_attr($field_key) . '" name="' . esc_attr($field_key) . '">';
                                            foreach ($field['options'] as $opt_value => $opt_label) {
                                                echo '<option value="' . esc_attr($opt_value) . '"' . selected($value, $opt_value, false) . '>' . esc_html($opt_label) . '</option>';
                                            }
                                            echo '</select>';
                                            break;

                                        case 'checkbox':
                                            echo '<div class="checkbox-flex">';
                                            echo '<input type="checkbox" id="' . esc_attr($field_key) . '" name="' . esc_attr($field_key) . '" value="yes"' . checked($value, 'yes', false) . ' />';
                                            if (!empty($field['label'])) {
                                                echo '<label for="' . esc_attr($field_key) . '">' . esc_html($field['label']) . '</label>';
                                            }
                                            echo '</div>';
                                            break;

                                        default:
                                            echo '<input type="text" id="' . esc_attr($field_key) . '" name="' . esc_attr($field_key) . '" value="' . esc_attr($value) . '" />';
                                    }

                                    if (!empty($field['description'])) {
                                        echo '<p class="description">' . $field['description'] . '</p>';
                                    }

                                    echo '</div>';
                                }
                            }

                            if ($fieldset_open) {
                                echo '</fieldset>';
                            }
                            echo '</div>';
                        }
                    ?>
                    
                    <?php submit_button(__('Save Settings', 'payxpert')); ?>
                </form>
            <?php endif; ?>

            <img data-target="https://entreprises.sg.fr/formulaires/prendre-rendez-vous" width="100%" src="<?php echo WC_PAYXPERT_ASSETS . 'img/banners/FintectureBanner.png'; ?>" alt="Fintecture banner">

        </div>
        
        <?php

        /* Support dialog */
        include WC_PAYXPERT_PLUGIN_FILE_PATH . 'templates/views/html-settings-support-dialog.php';
    }

    public static function get_settings()
    {
        return [

            'payment_settings' => [
                'title'       => __('Payment Settings', 'payxpert'),
                'description' => '',
                'fields'      => [
                    'payxpert_capture_mode' => [
                        'type'        => 'select',
                        'label'       => __('Capture mode', 'payxpert'),
                        'description' => '<strong style="color:red">' . __('Payments will be authorized but will require manual validation before collection. Make sure to capture transactions within the allotted time to avoid authorization expiration.', 'payxpert') . '</strong>' 
                                        . '<br>' 
                                        . __('To secure payments, the latency period for manual capture is limited to 7 days. It is imperative to capture transactions before this deadline.', 'payxpert'),
                        'default'     => WC_Payxpert::CAPTURE_MODE_AUTOMATIC,
                        'options'     => [
                            WC_Payxpert::CAPTURE_MODE_AUTOMATIC => __('Automatic', 'payxpert'),
                            WC_Payxpert::CAPTURE_MODE_MANUAL    => __('Manual', 'payxpert'),
                        ],
                    ],

                    'payxpert_capture_manual_email' => [
                        'type'        => 'text',
                        'label'       => __('Email notification', 'payxpert'),
                        'description' => __('Enter the email address that will be notified before the automatic initiation of the capture (5 days after the transaction).', 'payxpert'),
                        'default'     => '',
                    ],

                    'payxpert_redirect_mode' => [
                        'type'        => 'select',
                        'label'       => __('Display mode', 'payxpert'),
                        'description' => __('Select the redirect mode for payments.', 'payxpert'),
                        'default'     => WC_Payxpert::REDIRECT_MODE_REDIRECT,
                        'options'     => [
                            WC_Payxpert::REDIRECT_MODE_REDIRECT => __('Redirection', 'payxpert'),
                            WC_Payxpert::REDIRECT_MODE_SEAMLESS => __('IFrame', 'payxpert'),
                        ],
                    ],

                    'payxpert_paybylink_enabled' => [
                        'type'    => 'checkbox',
                        'label'   => __('Enable PayByLink', 'payxpert'),
                        'default' => 'no',
                        'description' => __('By activating this function, it is possible to send a payment link by email from an order in the back office.', 'payxpert')
                    ],

                    // 'payxpert_oneclick' => [
                    //     'type'        => 'checkbox',
                    //     'label'       => __('Enable OneClick (you assume responsibility for fraud and chargebacks)', 'payxpert'),
                    //     'description' => __('Please note: By disabling this option, you will accept payments even if Liability Shift is not active.', 'payxpert'),
                    //     'default'     => 'yes',
                    // ],
                ],
            ],

            'payment_method_settings' => [
                'title'       => __('Payment methods settings', 'payxpert'),
                'description' => '',
                'fields'      => [
                    'payxpert_cc_enabled' => [
                        'type'    => 'checkbox',
                        'label'   => __('Card CB/Visa/Mastercard', 'payxpert'),
                        'default' => 'no',
                    ],
                    'payxpert_amex_enabled' => [
                        'type'    => 'checkbox',
                        'label'   => __('American Express', 'payxpert'),
                        'default' => 'no',
                    ],
                    'payxpert_installment_x2_enabled' => [
                        'type'    => 'checkbox',
                        'label'   => __('Installment x2', 'payxpert'),
                        'default' => 'no',
                    ],
                    'payxpert_installment_x3_enabled' => [
                        'type'    => 'checkbox',
                        'label'   => __('Installment x3', 'payxpert'),
                        'default' => 'no',
                    ],
                    'payxpert_installment_x4_enabled' => [
                        'type'    => 'checkbox',
                        'label'   => __('Installment x4', 'payxpert'),
                        'default' => 'no',
                    ],
                    // #44959 : Prochain lot
                    // 'payxpert_alipay_enabled' => [
                    //     'type'    => 'checkbox',
                    //     'label'   => __('Alipay', 'payxpert'),
                    //     'default' => 'no',
                    // ],
                    // 'payxpert_wechat_enabled' => [
                    //     'type'    => 'checkbox',
                    //     'label'   => __('Wechat', 'payxpert'),
                    //     'default' => 'no',
                    // ],
                    'payxpert_applepay_enabled' => [
                        'type'    => 'checkbox',
                        'label'   => __('Applepay', 'payxpert'),
                        'default' => 'no',
                    ],
                ],
            ],

            'payment_method_labels' => [
                'title'       => __('Customization', 'payxpert'),
                'description' => '',
                'fields'      => [
                    'payxpert_cc_label' => [
                        'type'    => 'text',
                        'label'   => __('Payment label: Credit Card', 'payxpert'),
                        'default' => __('Payment via Credit Card by PayXpert', 'payxpert'),
                    ],
                    // #44959 : Prochain lot
                    // 'payxpert_alipay_label' => [
                    //     'type'    => 'text',
                    //     'label'   => __('Payment label: Alipay', 'payxpert'),
                    //     'default' => __('Payment via Alipay by PayXpert', 'payxpert'),
                    // ],
                    // 'payxpert_wechat_label' => [
                    //     'type'    => 'text',
                    //     'label'   => __('Payment label: Wechat', 'payxpert'),
                    //     'default' => __('Payment via Wechat by PayXpert', 'payxpert'),
                    // ],
                ]
            ],

            'installment_settings' => [
                'title'       => __('Installment Payment Settings', 'payxpert'),
                'description' => '',
                'fields'      => [

                    'payxpert_instalment_payment_min_amount' => [
                        'type'        => 'number',
                        'label'       => __('Minimum amount for installment payment', 'payxpert'),
                        'description' => __('Minimum order amount to enable installment payment.', 'payxpert'),
                        'default'     => '0',
                        'custom_attributes' => [
                            'step' => '1',
                            'min'  => '0',
                        ],
                    ],

                    'payxpert_instalment_payment_max_amount' => [
                        'type'        => 'number',
                        'label'       => __('Maximum amount for installment payment', 'payxpert'),
                        'description' => __('Maximum order amount to enable installment payment.', 'payxpert'),
                        'default'     => '0',
                        'custom_attributes' => [
                            'step' => '1',
                            'min'  => '0',
                        ],
                    ],

                    'payxpert_installment_x2' => [
                        'id'      => 'payxpert_installment_x2',
                        'type'    => 'fieldset',
                        'label'   => __('Installment 2x', 'payxpert'),
                    ],

                    'payxpert_installment_x2_percentage' => [
                        'type'        => 'number',
                        'label'       => __('Percentage', 'payxpert'),
                        'description' => __('Indicate the proportion to be paid in the first monthly payment. The remainder will be distributed equally between the other installments.', 'payxpert'),
                        'default'     => '50',
                        'custom_attributes' => [
                            'step' => '1',
                            'min'  => '1',
                            'max'  => '99',
                        ],
                    ],

                    'payxpert_installment_x2_label' => [
                        'type'    => 'text',
                        'label'   => __('Front label', 'payxpert'),
                        'default' => __('Pay in 2 times', 'payxpert'),
                    ],

                    'payxpert_installment_x2_close' => [
                        'type'    => 'fieldset_close',
                    ],

                    'payxpert_installment_x3' => [
                        'id'      => 'payxpert_installment_x3',
                        'type'    => 'fieldset',
                        'label'   => __('Installment 3x', 'payxpert'),
                    ],

                    'payxpert_installment_x3_percentage' => [
                        'type'        => 'number',
                        'label'       => __('Percentage', 'payxpert'),
                        'description' => __('Indicate the proportion to be paid in the first monthly payment. The remainder will be distributed equally between the other installments.', 'payxpert'),
                        'default'     => '33',
                        'custom_attributes' => [
                            'step' => '1',
                            'min'  => '1',
                            'max'  => '99',
                        ],
                    ],

                    'payxpert_installment_x3_label' => [
                        'type'    => 'text',
                        'label'   => __('Front label', 'payxpert'),
                        'default' => __('Pay in 3 times', 'payxpert'),
                    ],


                    'payxpert_installment_x3_close' => [
                        'type'    => 'fieldset_close',
                    ],

                    'payxpert_installment_x4' => [
                        'id'      => 'payxpert_installment_x4',
                        'type'    => 'fieldset',
                        'label'   => __('Installment 4x', 'payxpert'),
                    ],

                    'payxpert_installment_x4_percentage' => [
                        'type'        => 'number',
                        'label'       => __('Percentage', 'payxpert'),
                        'description' => __('Indicate the proportion to be paid in the first monthly payment. The remainder will be distributed equally between the other installments.', 'payxpert'),
                        'default'     => '25',
                        'custom_attributes' => [
                            'step' => '1',
                            'min'  => '1',
                            'max'  => '99',
                        ],
                    ],

                    'payxpert_installment_x4_label' => [
                        'type'    => 'text',
                        'label'   => __('Front label', 'payxpert'),
                        'default' => __('Pay in 4 times', 'payxpert'),
                    ],
                ],
            ],

            'notification_settings' => [
                'title'       => __('Notification', 'payxpert'),
                'description' => '',
                'fields'      => [

                    'payxpert_notification_active' => [
                        'type'    => 'checkbox',
                        'label'   => __('Activate merchant notification', 'payxpert'),
                        'description' => __('Enable to receive notifications related to payment requests.', 'payxpert'),
                        'default' => 'no',
                    ],

                    'payxpert_notification_to' => [
                        'type'        => 'text',
                        'label'       => __('Notification recipient', 'payxpert'),
                        'description' => __('Enter the email address that will receive notifications.', 'payxpert'),
                        'default'     => '',
                    ],

                    'payxpert_notification_language' => [
                        'type'    => 'select',
                        'label'   => __('Notification language', 'payxpert'),
                        'options' => [
                            'en' => __('English', 'payxpert'),
                            'fr' => __('French', 'payxpert'),
                        ],
                        'default' => 'en',
                    ],
                ],
            ],


        ];
    }

    public function save_settings() {
        WC_Payxpert_Logger::debug('save_settings');

        $this->check_save_rights();

        $section = $_POST['section'];
        
        switch ($section) {
            case 'credentials':
                $this->save_credentials();
            break;

            case 'main':
                $this->save_main();
                set_transient('payxpert_settings_saved', true, 30);
            break;
        }

        wp_redirect(admin_url('admin.php?page=payxpert-settings'));
        exit;
    }

    private function save_credentials()
    {
		$options_to_save = [
			'payxpert_originator_id',
			'payxpert_password'
		];

		$this->save_payxpert_options($options_to_save);

        $configuration = WC_Payxpert_Utils::get_configuration();
        $account = WC_Payxpert_Webservice::getAccountInfo(
            $configuration['payxpert_originator_id'],
            $configuration['payxpert_password']
        );

        if (isset($account['error'])) {
			update_option('payxpert_conn_status', false);
		} else {
			update_option('payxpert_conn_status', true);
            set_transient('payxpert_settings_saved', true, 30);
		}
    }
    
    private function save_main()
    {
        $configuration = WC_Payxpert_Utils::get_configuration();

        unset($configuration['payxpert_originator_id']);
        unset($configuration['payxpert_password']);
        unset($configuration['payxpert_conn_status']);

        $this->save_payxpert_options(array_keys($configuration));
    }

    private function check_save_rights()
	{
        // Permission check
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You are not allowed to perform this action.', 'payxpert'));
        }

        // Nonce check
        check_admin_referer('save_payxpert_settings_action', 'save_payxpert_settings_nonce');
	}

    private function save_payxpert_options($options)
	{
		foreach ($options as $option) {
			if (isset($_POST[$option])) {
				update_option($option, stripslashes(trim(sanitize_text_field($_POST[$option]))));
			} else {
				delete_option($option);
			}
		}
	}

    public function send_support_email_callback() {
        // Nonce check
        check_ajax_referer('payxpert_support_nonce');

        // Get DATA
        $firstname = sanitize_text_field($_POST['firstname']);
        $lastname  = sanitize_text_field($_POST['lastname']);
        $email     = sanitize_email($_POST['email']);
        $subject   = sanitize_textarea_field($_POST['subject']);

        if (empty($firstname) || empty($lastname) || empty($email) || empty($subject)) {
            wp_send_json_error(__('Please fill all required fields.', 'payxpert'));
        }

        if (!is_email($email)) {
            wp_send_json_error(__('The email address is not valid.', 'payxpert'));
        }

        // Custom Email Instance
        $mailer = WC()->mailer();
        $emails = $mailer->get_emails();
        
        if (isset($emails['WC_Email_Payxpert_Support'])) {
            /** @var WC_Email_Payxpert_Support $support_email */
            $support_email = $emails['WC_Email_Payxpert_Support'];

            $support_email->trigger([
                'shop_name'        => get_bloginfo('name'),
                'mid'              => get_option('payxpert_originator_id'),
                'firstname'        => $firstname,
                'lastname'         => $lastname,
                'recipient'        => 'Assistance@payxpert.com',
                'email_customer'   => $email,
                'subject'          => $subject,
                'cms_version'      => get_bloginfo('version'),
                'wooc_version'     => get_option( 'woocommerce_version' ),
                'php_version'      => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION,
                'module_version'   => WC_PAYXPERT_VERSION,
                'email'            => $support_email,
            ]);

            WC_Payxpert_Logger::info('Support request submitted');
            wp_send_json_success(__('Your support request was sent successfully.', 'payxpert'));
        } 

        WC_Payxpert_Logger::error('Support email class not found.');
        wp_send_json_error(__('Failed to initialize the email class.', 'payxpert'));
    }

    public function payxpert_handle_logs_download() {
        if ( ! current_user_can('manage_options') ) {
            wp_die(__('Unauthorized', 'payxpert'));
        }

        $logFile = WC_Payxpert_Logger::getLogFilePath();
        if (!file_exists($logFile)) {
            wp_die(__('Log file not found.', 'payxpert'));
        }

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="log_file_' . date('Y_m_d_His') . '.log"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($logFile));
        ob_clean();

        flush();
        readfile($logFile);

        exit;
    }
}
