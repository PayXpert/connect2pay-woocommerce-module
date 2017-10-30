<?php
/*
 * Copyright 2015-2016 PayXpert
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * @author Regis Vidal
 */
if (!defined('ABSPATH')) {
  exit();
}
/**
 * PayXpert Standard Payment Gateway
 *
 * Provides a PayXpert Standard Payment Gateway.
 */
include_once ('includes/Connect2PayClient.php');

class WC_Gateway_PayXpert extends WC_Payment_Gateway {

  /** @var boolean Whether or not logging is enabled */
  public static $log_enabled = false;

  /** @var WC_Logger Logger instance */
  public static $log = false;

  // PayXpert Originator ID
  private $originator_id;
  // PayXpert password
  private $password;
  // PayXpert url to call the payment page
  private $connect2_url;
  // PayXpert url to process refund
  private $api_url;

  // Merchant notifications settings
  private $merchant_notifications;
  private $merchant_notifications_to;
  private $merchant_notifications_lang;

  /**
   * Constructor for the gateway.
   */
  public function __construct() {
    $this->id = 'payxpert';
    $this->has_fields = false;
    $this->order_button_text = __('Proceed to PayXpert', 'payxpert');
    $this->method_title = __('PayXpert', 'payxpert');
    $this->method_description = '';
    $this->supports = array('products', 'refunds');

    // Load the settings.
    $this->init_form_fields();
    $this->init_settings();

    // Define user set variables
    $this->title = $this->get_option('title');
    $this->description = $this->get_option('description');
    $this->testmode = 'no';
    $this->debug = 'yes' === $this->get_option('debug', 'no');
    $this->originator_id = $this->get_option('originator_id');
    $this->password = $this->get_option('password');
    $this->connect2_url = $this->get_option('connect2_url', 'https://connect2.payxpert.com');
    $this->connect2_url .= (substr($this->connect2_url, -1) == '/' ? '' : '/');
    $this->api_url = $this->get_option('api_url', 'https://api.payxpert.com');
    $this->api_url .= (substr($this->api_url, -1) == '/' ? '' : '/');

    $this->merchant_notifications = $this->get_option('merchant_notifications');
    $this->merchant_notifications_to = $this->get_option('merchant_notifications_to');
    $this->merchant_notifications_lang = $this->get_option('merchant_notifications_lang');

    self::$log_enabled = $this->debug;

    add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

    if (!$this->is_valid_for_use()) {
      $this->enabled = 'no';
    } else {
      add_action('woocommerce_api_wc_gateway_payxpert', array($this, 'handle_callback'));
    }
  }

  /**
   * Logging method
   *
   * @param string $message
   */
  public static function log($message) {
    if (self::$log_enabled) {
      if (empty(self::$log)) {
        self::$log = new WC_Logger();
      }
      self::$log->add('PayXpert', $message);
    }
  }

  /**
   * get_icon function.
   *
   * @return string
   */
  public function get_icon() {
    $icon_html = '';

    return apply_filters('woocommerce_gateway_icon', $icon_html, $this->id);
  }

  /**
   * Check if this gateway is enabled and available in the user's country
   *
   * @return bool
   */
  public function is_valid_for_use() {
    // We allow to use the gateway from any where
    return true;
  }

  /**
   * Admin Panel Options
   *
   * @since 1.0.0
   */
  public function admin_options() {
    if ($this->is_valid_for_use()) {
      parent::admin_options();
    } else {
      ?>
<div class="inline error">
 <p>
  <strong><?php _e( 'Gateway Disabled', 'payxpert' ); ?></strong>: <?php _e( 'PayXpert does not support your store currency / country', 'payxpert' ); ?></p>
</div>
<?php
    }
  }

  /**
   * Initialize Gateway Settings Form Fields
   */
  public function init_form_fields() {
    $this->form_fields = array(
        'enabled' => array( /**/
            'title' => __('Enable/Disable', 'payxpert'), /**/
            'type' => 'checkbox', /**/
            'label' => __('Enable PayXpert payment gateway', 'payxpert'), /**/
            'default' => 'yes' /**/
        ),
        'originator_id' => array(/**/
            'title' => __('Originator ID', 'payxpert'), /**/
            'type' => 'text', /**/
            'description' => __('The identifier of your Originator', 'payxpert'), /**/
            'default' => '' /**/
        ),
        'password' => array(/**/
            'title' => __('Password', 'payxpert'), /**/
            'type' => 'text', /**/
            'description' => __('The password associated with your Originator', 'payxpert'), /**/
            'default' => '' /**/
        ),
        'merchant_notifications' => array( /**/
            'title' => __('Merchant Notifications', 'payxpert'), /**/
            'type' => 'select', /**/
            'class' => 'wc-enhanced-select', /**/
            'description' => __('Determine if you want or not merchant notifications after each payment attempt', 'payxpert'), /**/
            'default' => 'default', /**/
            'options' => array(/**/
                'default' => __('Default value for the account', 'payxpert'), /**/
                'enabled' => __('Enabled', 'payxpert'), /**/
                'disabled' => __('Disabled', 'payxpert') /**/
            ) /**/
        ),
        'merchant_notifications_to' => array(/**/
            'title' => __('Merchant email notifications recipient', 'payxpert'), /**/
            'type' => 'text', /**/
            'description' => __('The email address that will receive merchant notifications', 'payxpert'), /**/
            'default' => '' /**/
        ),
        'merchant_notifications_lang' => array( /**/
            'title' => __('Merchant email notifications language', 'payxpert'), /**/
            'type' => 'select', /**/
            'class' => 'wc-enhanced-select', /**/
            'description' => __('The language that will be used for merchant notifications', 'payxpert'), /**/
            'default' => 'default', /**/
            'options' => array(/**/
                'en' => __('English', 'payxpert'), /**/
                'fr' => __('French', 'payxpert'), /**/
                'es' => __('Spanish', 'payxpert'), /**/
                'it' => __('Italian', 'payxpert'), /**/
                'de' => __('German', 'payxpert'), /**/
                'pl' => __('Polish', 'payxpert'), /**/
                'zh' => __('Chinese', 'payxpert'), /**/
                'ja' => __('Japanese', 'payxpert') /**/
            ) /**/
        ),
        'title' => array(/**/
            'title' => __('Title', 'payxpert'), /**/
            'type' => 'text', /**/
            'description' => __('This controls the title the user sees during checkout.', 'payxpert'), /**/
            'default' => __('Credit Card Payment via PayXpert', 'payxpert'), /**/
            'desc_tip' => true /**/
        ),
        'description' => array(/**/
            'title' => __('Description', 'payxpert'), /**/
            'type' => 'text', /**/
            'desc_tip' => true, /**/
            'description' => __('This controls the description the user sees during checkout.', 'payxpert'), /**/
            'default' => __('Pay via PayXpert: you can pay with your credit / debit card', 'payxpert') /**/
        ),
        'connect2_url' => array(/**/
            'title' => __('Payment Page URL', 'payxpert'), /**/
            'type' => 'text', /**/
            'description' => __('Do not change this field unless you have been given a specific URL', 'payxpert') /**/
        ),
        'api_url' => array(/**/
            'title' => __('Payment Gateway URL (refund)', 'payxpert'), /**/
            'type' => 'text', /**/
            'description' => __('Do not change this field unless you have been given a specific URL', 'payxpert') /**/
        ),
        'debug' => array(/**/
            'title' => __('Debug Log', 'payxpert'), /**/
            'type' => 'checkbox', /**/
            'label' => __('Enable logging', 'payxpert'), /**/
            'default' => 'no', /**/
            'description' => __('Log PayXpert events, such as Callback', 'payxpert') /**/
        ) /**/
    );
  }

  /**
   * Process the payment and return the result
   *
   * @param int $order_id
   * @return array
   */
  public function process_payment($order_id) {
    $order = wc_get_order($order_id);

    $order->get_checkout_payment_url(true);

    // init api
    $c2pClient = new Connect2PayClient($this->connect2_url, $this->originator_id, $this->password);

    // customer informations
    $c2pClient->setShopperID($order->customer_user);
    $c2pClient->setShopperEmail($order->billing_email);
    $c2pClient->setShopperFirstName(substr($order->billing_first_name, 0, 35));
    $c2pClient->setShopperLastName(substr($order->billing_last_name, 0, 35));
    $c2pClient->setShopperCompany(substr($order->billing_company, 0, 128));
    $c2pClient->setShopperAddress(substr(trim($order->billing_address_1 . ' ' . $order->billing_address_2), 0, 255));
    $c2pClient->setShopperZipcode(substr($order->billing_postcode, 0, 10));
    $c2pClient->setShopperCity(substr($order->billing_city, 0, 50));
    $c2pClient->setShopperState(substr($order->billing_state, 0, 30));
    $c2pClient->setShopperCountryCode($order->billing_country);
    $c2pClient->setShopperPhone(substr(trim($order->billing_country), 0, 20));
    $c2pClient->setShippingType(Connect2PayClient::_SHIPPING_TYPE_VIRTUAL);

    // Shipping information
    if ('yes' == $this->get_option('send_shipping')) {
      $c2pClient->setShipToFirstName(substr($order->shipping_first_name, 0, 35));
      $c2pClient->setShipToLastName(substr($order->shipping_last_name, 0, 35));
      $c2pClient->setShipToCompany(substr($order->shipping_company, 0, 128));

      $c2pClient->setShipToPhone(substr(trim(), 0, 20));

      $c2pClient->setShipToAddress(substr(trim($order->shipping_address_1 . " " . $order->shipping_address_2), 0, 255));
      $c2pClient->setShipToZipcode(substr($order->shipping_postcode, 0, 10));
      $c2pClient->setShipToCity(substr($order->shipping_city, 0, 50));
      $c2pClient->setShipToState(substr($order->shipping_state, 0, 30));
      $c2pClient->setShipToCountryCode($order->shipping_country);
      $c2pClient->setShippingType(Connect2PayClient::_SHIPPING_TYPE_PHYSICAL);
    }

    // Order informations
    $c2pClient->setOrderID(substr($order->id, 0, 100));
    $c2pClient->setOrderDescription(substr('Invoice:' . $order->id, 0, 255));
    $c2pClient->setCurrency($order->order_currency);

    $total = number_format($order->order_total * 100, 0, '.', '');
    $c2pClient->setAmount($total);
    $c2pClient->setPaymentMode(Connect2PayClient::_PAYMENT_MODE_SINGLE);
    $c2pClient->setPaymentType(Connect2PayClient::_PAYMENT_TYPE_CREDITCARD);

    $c2pClient->setCtrlCallbackURL(WC()->api_request_url('WC_Gateway_PayXpert'));
    $c2pClient->setCtrlRedirectURL($order->get_checkout_order_received_url());

    // Merchant notifications
    if (isset($this->merchant_notifications) && $this->merchant_notifications != null) {
      if ($this->merchant_notifications == 'enabled') {
        $c2pClient->setMerchantNotification(true);
        $c2pClient->setMerchantNotificationTo($this->merchant_notifications_to);
        $c2pClient->setMerchantNotificationLang($this->merchant_notifications_lang);
      } else if ($this->merchant_notifications == 'disabled') {
        $c2pClient->setMerchantNotification(false);
      }
    }

    // prepare API
    if ($c2pClient->prepareTransaction() == false) {
      $message = "can't prepare transaction - " . $c2pClient->getClientErrorMessage();
      $this->log($message);
      echo $message;
      return array('result' => 'fail', 'redirect' => '');
    }

    // Save the merchant token for callback verification
    update_post_meta($order_id, '_payxpert_merchant_token', $c2pClient->getMerchantToken());

    return array('result' => 'success', 'redirect' => $c2pClient->getCustomerRedirectURL());
  }

  /**
   * Can the order be refunded via PayPal?
   *
   * @param WC_Order $order
   * @return bool
   */
  public function can_refund_order($order) {
    return $order && $order->get_transaction_id();
  }

  /**
   * Process a refund if supported
   *
   * @param int $order_id
   * @param float $amount
   * @param string $reason
   * @return boolean True or false based on success, or a WP_Error object
   */
  public function process_refund($order_id, $amount = null, $reason = '') {
    $order = wc_get_order($order_id);

    if (!$this->can_refund_order($order)) {
      $this->log('Refund Failed: No transaction ID');
      return false;
    }

    $transactionId = $order->get_transaction_id();

    include_once ('includes/GatewayClient.php');

    $client = new GatewayClient($this->api_url, $this->originator_id, $this->password);

    $transaction = $client->newTransaction('Refund');
    if ($amount <= 0) {
      $amount = $order->order_total;
    }

    $total = number_format($amount * 100, 0, '.', '');

    $transaction->setReferralInformation($transactionId, $total);

    $response = $transaction->send();

    if ('000' === $response->errorCode) {
      $this->log("Refund Successful: Transaction ID {$response->transactionID}");
      $order->add_order_note(sprintf(__('Refunded %s - Refund ID: %s', 'payxpert'), $amount, $response->transactionID));
      return true;
    } else {
      $this->log(
          "Refund Failed: Transaction ID {$response->transactionID}, Error {$response->errorCode} with message {$response->errorMessage}");
      return false;
    }
  }

  /**
   * Complete order, add transaction ID and note
   *
   * @param WC_Order $order
   * @param string $txn_id
   * @param string $note
   */
  protected function payment_complete($order, $txn_id = '', $note = '') {
    $order->add_order_note($note);
    $order->payment_complete($txn_id);
  }

  /**
   * Check for PayXpert Callback Response
   */
  public function handle_callback() {
    $c2pClient = new Connect2PayClient($this->connect2_url, $this->originator_id, $this->password);

    if ($c2pClient->handleCallbackStatus()) {

      $status = $c2pClient->getStatus();

      // get the Error code
      $errorCode = $status->getErrorCode();
      $errorMessage = $status->getErrorMessage();
      $transactionId = $status->getTransactionID();

      $order_id = $status->getOrderID();

      $order = wc_get_order($order_id);
      $merchantToken = $status->getMerchantToken();

      $amount = number_format($status->getAmount() / 100, 2, '.', '');

      $data = compact("errorCode", "errorMessage", "transactionId", "invoiceId", "amount");

      $payxpert_merchant_token = get_post_meta($order_id, '_payxpert_merchant_token', true);

      // Be sure we have the same merchant token
      if ($payxpert_merchant_token == $merchantToken) {
        // errorCode = 000 transaction is successfull
        if ($errorCode == '000') {

          $message = "Successful transaction Callback received with transaction Id: " . $transactionId;
          $this->payment_complete($order, $transactionId, $message, 'payxpert');
          $this->log($message);
        } else {

          $message = "Unsuccessful transaction Callback received with the following information: " . print_r($data, true);
          $order->update_status('failed', $message);
          $this->log($message);
        }
      } else {
        // We do not update the status of the transaction, we just log the
        // message
        $message = "Error. Invalid token " . $merchantToken . " for order " . $order->id . " in callback from " . $_SERVER["REMOTE_ADDR"];
        $this->log($message);
      }

      // Send a response to mark this transaction as notified
      $response = array("status" => "OK", "message" => "Status recorded");
      header("Content-type: application/json");
      echo json_encode($response);
      exit();
    } else {

      $this->log("Error: Callback received an incorrect status from " . $_SERVER["REMOTE_ADDR"]);
      wp_die("PayXpert Callback Failed", "PayXpert", array('response' => 500));
    }
  }
}
