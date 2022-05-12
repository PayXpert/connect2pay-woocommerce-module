<?php
/*
 * Plugin Name: WooCommerce PayXpert Gateway
 * Plugin URI: http://www.payxpert.com
 * Description: WooCommerce PayXpert Gateway plugin
 * Version: 1.2.0
 * Author: PayXpert
 * Author URI: http://www.payxpert.com
 */

/**
 * PayXpert Standard Payment Gateway Library 
 *
 * Provides a PayXpert Standard Payment Gateway.
 */
include_once ('vendor/autoload.php');



/**
 * Check if WooCommerce is active
 **/
if (in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {


}
/*
* Include Gateway Setting
*/
require_once "includes/class-wc-setting.php";

/*
* Include PayXpert main Class
*/
require_once "includes/class-wc-payxpert.php";

/**
 * The Main Class Of Plugin
 */
final class PayxpertMainClass
{
    // Class construction
    private function __construct()
    {
        $this->define_function();

        add_action('plugins_loaded', [$this, 'init_plugin']);
        add_filter('woocommerce_payment_gateways', [$this, 'woocommerce_payxpert_gateway']);
        add_action('admin_head', [$this, 'redirct_to_another_setting']);
        add_action( 'wp_footer', [$this, 'payxpert_payment_script_footer']  );
        add_action('plugins_loaded', [$this, 'woocommerce_payxpert_init'], 0);
    }

    /*
        Single instence 
    */
    public static function init(){
        static $instance = false;

        if (!$instance) {
            $instance = new self();
        }

        return $instance;
    }


    public function define_function(){
        define("PX_FILE", __FILE__);
        define("PX_PATH", __DIR__);
        define("PX_URL", plugins_url('', PX_FILE));
        define("PX_ASSETS", PX_URL.'/assets');
    }

    public function init_plugin(){
        new PayXpertOption();   

    }
    
    public function woocommerce_payxpert_gateway($methods){
        $methods[] = 'WC_Gateway_PayXpert_WeChat';
        $methods[] = 'WC_Gateway_PayXpert_Alipay';
        $methods[] = 'WC_PayXpert_Seamless_Gateway';
        return $methods;
    }
    
    public function redirct_to_another_setting(){
        if(isset($_GET['page']) && isset($_GET['tab']) && isset($_GET['section'])){
            $getoptionurl = get_admin_url(null, "/admin.php?page=wc-settings&tab=checkout&section=payxpert");
            // PayXpert Seamless Option
            if($_GET['page'] == "wc-settings" && $_GET['tab'] == "checkout" && $_GET['section'] == "payxpert_seamless"){
                wp_safe_redirect($getoptionurl);
            }
            
            // PayXpert WeChat Option
            if($_GET['page'] == "wc-settings" && $_GET['tab'] == "checkout" && $_GET['section'] == "payxpert_wechat"){
                wp_safe_redirect($getoptionurl);
            }
            
            // PayXpert Alipay Option
            if($_GET['page'] == "wc-settings" && $_GET['tab'] == "checkout" && $_GET['section'] == "payxpert_alipay"){
                wp_safe_redirect($getoptionurl);
            }
            
            // PayXpert Alipay Option
            if($_GET['page'] == "wc-settings" && $_GET['tab'] == "checkout" && $_GET['section'] == "payxpert"){
                $optionupdate =  array(
                    'enabled' => 'yes'
                );
            }             
            
        }
    }
    
    public function payxpert_payment_script_footer(){
        if(is_checkout()){
            $arrayavailableid = array();
            $available_payment_methods = WC()->payment_gateways->get_available_payment_gateways();
            if(!empty($available_payment_methods)){
                foreach( $available_payment_methods as $method ) {
                    $arrayavailableid[]= $method->id;
                }
            }
            if(in_array("payxpert_seamless", $arrayavailableid)){
            ?>
            <script type="text/javascript">
            jQuery(document).ready(function( $ ) {
                $( document.body ).on( 'updated_checkout', function(){
                    var gettokken = $("#tokenpass").val();
                    var version = $("#seamless_version").val();
                    var hash = $("#seamless_hash").val();
                    $("#payxpert").remove();
                    var sNew = document.createElement("script");
                    sNew.async = true;
                    sNew.src = "https://connect2.payxpert.com/payment/"+ gettokken  +"/connect2pay-seamless-v" + version + ".js";
                    sNew.setAttribute('data-mount-in', "#payment-container");
                    sNew.setAttribute('id', "payxpert");
                    sNew.setAttribute('integrity', hash);
                    sNew.setAttribute('crossorigin', "anonymous");
                    var s0 = document.getElementsByTagName('script')[0];
                    s0.parentNode.insertBefore(sNew, s0);
                    
                    if($("#billing_first_name").val().length === 0 || $("#billing_first_name").val().length === 0 || $("#billing_address_1").val().length === 0 || $("#billing_country").val().length === 0 || $("#billing_city").val().length === 0 || $("#billing_postcode").val().length === 0 || $("#billing_phone").val().length === 0 || $("#billing_email").val().length === 0){
                        $("#payment-container").hide();
                        $("#error-message-seamless").show();
                    }else{
                        $("#payment-container").show();
                        $("#error-message-seamless").hide();
                    }                    
                });
                
                // On Change Payment Method Check 
                $(document).on( 'change', 'input[name="payment_method"]', function() {
                  var getpaymentvalue = $(this).val();
                  if(getpaymentvalue == 'payxpert_seamless'){
                    $("#place_order").attr("disabled", "disabled");  
                  }else{
                    $("#place_order").removeAttr("disabled"); 
                  }
                });
                
                
                // On Page Load
                $( window ).on( "load", function() {
                    $('input[value="payxpert_seamless"]').trigger('click');           
                });
                
                // check if all of fill 
                $(document).on('change', '.woocommerce input', function(){
                    if($("#billing_first_name").val().length === 0 || $("#billing_first_name").val().length === 0 || $("#billing_address_1").val().length === 0 || $("#billing_country").val().length === 0 || $("#billing_city").val().length === 0 || $("#billing_postcode").val().length === 0 || $("#billing_phone").val().length === 0 || $("#billing_email").val().length === 0){
                        $("#payment-container").hide();
                        $("#error-message-seamless").show();
                    }else{
                        $("#payment-container").show();
                        $("#error-message-seamless").hide();
                    }                    
                });
            });
            function callbackreturn(response) {
                if(response.transaction.resultCode == '000'){
                    document.getElementById("transactionId").value = response.transaction.transactionID;
                    document.getElementById("paymentId").value = response.transaction.paymentID;
                    document.getElementById("paymentstatus").value = response.transaction.resultCode;
                    var proceedOrder = document.getElementById('place_order');
                    proceedOrder.click();
                }    
                // console.log(response);
            }
            </script>  
    <?php
            } 
        }
    }
    
    public function woocommerce_payxpert_init(){
        if (!class_exists('WC_Payment_Gateway')) {
            return;
        }
        if(get_option('payxpert_wechat_pay') == "yes"){    
            require_once (plugin_basename('includes/class-wc-gateway-payxpert-wechat.php'));
        }
        if(get_option('payxpert_seamless_card') == "yes"){
            require_once (plugin_basename('includes/class-wc-gateway-payxpert-card.php'));
        }
        if(get_option('payxpert_alipay') == "yes"){
            require_once (plugin_basename('includes/class-wc-gateway-payxpert-alipay.php'));
        }
    }
}

/*
Initialize the main plugin
*/
function Payxpert_init(){
    return PayxpertMainClass::init();
}

/*
Active Plugin
*/
Payxpert_init();