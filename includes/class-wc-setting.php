<?php 
/**
 * Create PayXpert Payment Gateway Option 
 */
class PayXpertOption
{
	
	function __construct()
	{
		add_filter( 'woocommerce_get_sections_checkout', array($this, 'payxpert_add_section' ));
		add_filter( 'woocommerce_get_settings_checkout', array($this, 'payxpert_all_settings'), 10, 2 );
	}

	public function payxpert_add_section( $sections ) {	
		$sections['payxpert'] = __( 'PayXpert Setting', 'payxpert' );
		return $sections;	
	}

	public function payxpert_all_settings( $settings, $current_section ) {
	/**
	 * Check the current section is what we want
	 **/
	if ( $current_section == 'payxpert' ){
		$settings_payxpert = array();
        $settings_payxpert[] = array( 
            'name' => __( 'PayXpert Options', 'payxpert' ), 
            'type' => 'title', 
            'desc' => __( 'The following options are used to configure PayXpert Payment Gateway', 'payxpert' ), 
            'id' => 'payxpert_id' 
        );
		$settings_payxpert[] = array(
			'name'     => __( 'Originator ID', 'payxpert' ),
			'desc_tip' => __( 'Please Add Originator ID', 'payxpert' ),
			'id'       => 'payxpert_originator_id',
			'type'     => 'text',
			'desc'     => __( 'Please Add your Originator ID', 'payxpert' ),
		);
		$settings_payxpert[] = array(
			'name'     => __( 'Password', 'payxpert' ),
			'desc_tip' => __( 'Please Add Password', 'payxpert' ),
			'id'       => 'payxpert_password',
			'type'     => 'password',
			'desc'     => __( 'Please Add your Password', 'payxpert' ),
		);
		$settings_payxpert[] = array(
			'name'     => __( 'Enable WeChat Pay', 'payxpert' ),
			'desc_tip' => __( 'Enable WeChat Pay', 'payxpert' ),
			'id'       => 'payxpert_wechat_pay',
			'type'     => 'checkbox',
			'desc'     => __( 'Enable WeChat Pay', 'payxpert' ),
		);		
		$settings_payxpert[] = array(
			'name'     => __( 'Enable AliPay', 'payxpert' ),
			'desc_tip' => __( 'Enable AliPay', 'payxpert' ),
			'id'       => 'payxpert_alipay',
			'type'     => 'checkbox',
			'desc'     => __( 'Enable AliPay', 'payxpert' ),
		);			
		$settings_payxpert[] = array(
			'name'     => __( 'Enable Seamless Card', 'payxpert' ),
			'desc_tip' => __( 'Enable Seamless Card', 'payxpert' ),
			'id'       => 'payxpert_seamless_card',
			'type'     => 'checkbox',
			'desc'     => __( 'Enable Seamless Card Pay', 'payxpert' ),
		);		
		$settings_payxpert[] = array(
			'name'     => __( 'Merchant Notifications', 'payxpert' ),
			'desc_tip' => __( 'Determine if you want or not merchant notifications after each payment attempt', 'payxpert' ),
			'id'       => 'payxpert_merchant_notifications',
			'type'     => 'select',
			'desc'     => __('Determine if you want or not merchant notifications after each payment attempt', 'payxpert'),
			'options' => array(
                'default' => __('Default value for the account', 'payxpert'),
                'enabled' => __('Enabled', 'payxpert'),
                'disabled' => __('Disabled', 'payxpert')
            )
		);
		$settings_payxpert[] = array(
			'name'     => __( 'Merchant email notifications recipient', 'payxpert' ),
			'desc_tip' => __( 'The email address that will receive merchant notifications', 'payxpert' ),
			'id'       => 'payxpert_merchant_notifications_to',
			'type'     => 'text',
			'desc'     => __( 'The email address that will receive merchant notifications', 'payxpert' ),
		);
		$settings_payxpert[] = array(
			'name'     => __( 'Merchant email notifications language', 'payxpert' ),
			'desc_tip' => __( 'The language that will be used for merchant notifications', 'payxpert' ),
			'id'       => 'payxpert_merchant_notifications_lang',
			'type'     => 'select',
			'desc'     => __( 'The language that will be used for merchant notifications', 'payxpert' ),
			'options' => array(
                'en' => __('English', 'payxpert'),
                'fr' => __('French', 'payxpert'), 
                'es' => __('Spanish', 'payxpert'),
                'it' => __('Italian', 'payxpert'),
                'de' => __('German', 'payxpert'),
                'pl' => __('Polish', 'payxpert'),
                'zh' => __('Chinese', 'payxpert'),
                'ja' => __('Japanese', 'payxpert')
            )
		);
		$settings_payxpert[] = array(
			'name'     => __( 'Pay Button', 'payxpert' ),
			'desc_tip' => __( 'Pay Button Text', 'payxpert' ),
			'id'       => 'payxpert_pay_button',
			'type'     => 'text',
			'desc'     => __( 'Pay Button Text', 'payxpert' ),
		);
		
		$settings_payxpert[] = array(
			'name'     => __( 'Payment Page URL', 'payxpert' ),
			'desc_tip' => __( 'Do not change this field unless you have been given a specific URL', 'payxpert' ),
			'id'       => 'payxpert_connect2_url',
			'default' => 'https://connect2.payxpert.com',
			'type'     => 'text',
			'desc'     => __( 'Do not change this field unless you have been given a specific URL', 'payxpert' ),
		);
		
		$settings_payxpert[] = array(
			'name'     => __( 'Payment Gateway URL (refund)', 'payxpert' ),
			'desc_tip' => __( 'Do not change this field unless you have been given a specific URL', 'payxpert' ),
			'id'       => 'payxpert_api_url',
			'type'     => 'text',
			'desc'     => __( 'Do not change this field unless you have been given a specific URL', 'payxpert' ),
		);
		
		$settings_payxpert[] = array(
			'name'     => __( 'Debug Log', 'payxpert' ),
			'desc_tip' => __( 'Log PayXpert events, such as Callback', 'payxpert' ),
			'id'       => 'payxpert_debug',
			'type'     => 'checkbox',
			'desc'     => __( 'Log PayXpert events, such as Callback', 'payxpert' ),
		);
		
		$settings_payxpert[] = array(
			'name'     => __( 'Iframe mode', 'payxpert' ),
			'desc_tip' => __( 'Enables iframe mode (no redirection)', 'payxpert' ),
			'id'       => 'payxpert_iframe_mode',
			'type'     => 'checkbox',
			'desc'     => __( 'Enables iframe mode (no redirection)', 'payxpert' ),
		);		
		
		$settings_payxpert[] = array( 'type' => 'sectionend', 'id' => 'payxpert' );
		return $settings_payxpert;
	
		/**
		 * If not, return the standard settings
		 **/
		} else {
			return $settings;
		}
	}
}