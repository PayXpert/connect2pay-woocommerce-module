<?php

declare(strict_types=1);

namespace Payxpert\Utils;

defined( 'ABSPATH' ) || exit();

use PayXpert\Connect2Pay\Connect2PayClient;
use PayXpert\Connect2Pay\containers\constant\OperationType;
use PayXpert\Connect2Pay\containers\constant\OrderType;
use PayXpert\Connect2Pay\containers\constant\OrderShippingType;
use PayXpert\Connect2Pay\containers\constant\PaymentMethod;
use PayXpert\Connect2Pay\containers\constant\PaymentMode;
use PayXpert\Connect2Pay\containers\constant\SubscriptionType;
use PayXpert\Connect2Pay\containers\Account;
use PayXpert\Connect2Pay\containers\constant\AccountAge;
use PayXpert\Connect2Pay\containers\constant\AccountLastChange;
use PayXpert\Connect2Pay\containers\constant\AccountPwChange;
use PayXpert\Connect2Pay\containers\Order as c2pOrder;
use PayXpert\Connect2Pay\containers\request\PaymentPrepareRequest;
use PayXpert\Connect2Pay\containers\response\RefundStatus;
use PayXpert\Connect2Pay\containers\response\TransactionAttempt;
use PayXpert\Connect2Pay\containers\Shipping;
use PayXpert\Connect2Pay\containers\Shopper;
use Payxpert\Models\Payxpert_Payment_Token;
use Payxpert\WC_Payxpert;

class WC_Payxpert_Webservice
{
    const API_URL = 'https://connect2.payxpert.com';
    const API_PAYXPERT_URL = 'https://api.payxpert.com';

    public static function preparePayment($configuration, $gateway, string $paymentMethod, string $paymentMode, $order = null, array $instalmentParameters = [], bool $isPayByLink = false, $subscriptionInCart = false)
    {
        if (is_null($order)) {
            $customer = wp_get_current_user();
            $customer_id = $customer->ID;

            if (is_null(WC()->cart)) {
                return ['error' => __('Error cart is null', 'payxpert')];
            }

            $billing = [
                'first_name' => get_user_meta($customer_id, 'billing_first_name', true),
                'last_name'  => get_user_meta($customer_id, 'billing_last_name', true),
                'address_1'  => get_user_meta($customer_id, 'billing_address_1', true),
                'address_2'  => get_user_meta($customer_id, 'billing_address_2', true),
                'city'       => get_user_meta($customer_id, 'billing_city', true),
                'postcode'   => get_user_meta($customer_id, 'billing_postcode', true),
                'country'    => get_user_meta($customer_id, 'billing_country', true),
            ];

            $email = get_user_meta($customer_id, 'billing_email', true);
            $billing_phone = get_user_meta($customer_id, 'billing_phone', true);
            $currency = get_woocommerce_currency();
            $amount = (int) round(WC()->cart->get_total('edit') * 100);
            $order_id = 0;
        } else {
            $billing = $order->get_address('billing');
            $currency = $order->get_currency();
            $amount = (int) round($order->get_total() * 100);
            $customer_id = $order->get_customer_id();
            $customer = get_userdata($customer_id);
            $email = $order->get_billing_email();
            $billing_phone = $order->get_billing_phone();
            $order_id = $order->get_id();
        }

        $billing = array_map(function($value) {
            return $value === false ? '' : $value;
        }, $billing);

        $billing_phone = $billing_phone ?: '';
        
        // Shopper parameters.
        $shopper = new Shopper();
        $shopper->setId($customer_id);
        $shopper->setFirstName(substr($billing['first_name'], 0, 35));
        $shopper->setLastName(substr($billing['last_name'], 0, 35));
        $shopper->setAddress1(self::formatAddress($billing['address_1']));
        $shopper->setZipcode(substr($billing['postcode'], 0, 10));
        $shopper->setCity(substr($billing['city'], 0, 50));
        $shopper->setCountryCode(strtoupper($billing['country']));
        $shopper->setEmail($email);
        $shopper->setHomePhone(substr($billing_phone, 0, 20));

        // Optional: set HomePhonePrefix if available
        $shopper->setHomePhonePrefix(self::getCountryPhonePrefix($billing['country']));

        // SCA / 3DS2: Account hints for registered customers only (not guest checkout).
        if ( PaymentMethod::CREDIT_CARD === $paymentMethod && (int) $customer_id > 0 ) {
            self::attach_shopper_account_for_sca( $shopper, (int) $customer_id );
        }

        // Order parameters.
        $c2pOrder = new c2pOrder();
        $c2pOrder->setType(OrderType::GOODS_SERVICE);
        $c2pOrder->setShippingType(self::resolve_order_shipping_type($order));
        $c2pOrder->setId($order_id);

        if (!is_null($order)) {
            $c2pOrder->setDescription('Order #' . $order->get_order_number());
        }
        // $c2pOrder->setCartContent(self::formatOrderProducts($order)); // TODO: not working on the paygate anyway

        // Shipping parameters.
        $shippingObj = new Shipping();
        // $shippingObj->setName(substr($shipping_method, 0, 50));
        // $shippingObj->setAddress1(self::formatAddress($shipping['address_1']));
        // $shippingObj->setAddress2(self::formatAddress($shipping['address_2']));
        // $shippingObj->setZipcode(substr($shipping['postcode'], 0, 10));
        // $shippingObj->setCity(substr($shipping['city'], 0, 50));
        // $shippingObj->setCountryCode(strtoupper($shipping['country']));
        // $shippingObj->setPhone(substr($billing_phone, 0, 20));

        // Build PaymentPrepareRequest
        $prepareRequest = new PaymentPrepareRequest();
        $prepareRequest->setShopper($shopper);
        $prepareRequest->setOrder($c2pOrder);
        $prepareRequest->setShipping($shippingObj);
        $prepareRequest->setCurrency($currency);
        $prepareRequest->setAmount($amount);
        $prepareRequest->setPaymentMethod($paymentMethod);
        $prepareRequest->setPaymentMode($paymentMode);

        // Credit Card specific
        if (PaymentMethod::CREDIT_CARD === $paymentMethod) {
            $prepareRequest->setSecure3d(true);
            $prepareRequest->setOperation(
                $configuration['payxpert_capture_mode'] == WC_Payxpert::CAPTURE_MODE_MANUAL
                    ? OperationType::AUTHORIZE
                    : OperationType::SALE
            );
        }

        // Handle instalments
        $customData = [];
        if (PaymentMode::INSTALMENTS === $paymentMode) {
            if (empty($instalmentParameters)) {
                return ['error' => 'Instalment Parameters are required with this payment mode'];
            }

            $prepareRequest->setSubscriptionType(SubscriptionType::PARTPAYMENT);
            list($firstAmount, $rebillAmount) = WC_Payxpert_Utils::calculate_instalment_amounts(
                $amount, 
                intval($instalmentParameters['firstPercentage']), 
                $instalmentParameters['xTimes']
            );

            $prepareRequest->setAmount($firstAmount);
            $prepareRequest->setRebillAmount($rebillAmount);
            $prepareRequest->setRebillMaxIteration($instalmentParameters['xTimes'] - 1);
            $prepareRequest->setRebillPeriod('P30D');
            $customData['status'] = WC_Payxpert::ORDER_STATUS_INSTALLMENT_PENDING;

            $prepareRequest->setOperation(OperationType::SALE); //! Mandatory
        }

        if (PaymentMode::RECURRENT === $paymentMode) {
            if (!is_null($order)) {
                $subscriptionInCart = WC_Payxpert_Utils::has_subscription_in_order($order, true);
            }

            if ($subscriptionInCart === true) {
                $subscriptionInCart = WC_Payxpert_Utils::has_subscription_in_cart(WC()->cart->get_cart(), true);
            }
            
            if (empty($subscriptionInCart)) {
                return ['error' => __('Error while retrieving subscription data from order/cart', 'payxpert')];
            }
            
            $shippingAmount = WC()->cart->get_shipping_total() * 100;

            $prepareRequest->setSubscriptionType($subscriptionInCart[WC_Payxpert::SUBSCRIPTION_DURATION_TYPE]);
            $prepareRequest->setAmount($subscriptionInCart[WC_Payxpert::SUBSCRIPTION_FIRST_PAYMENT] == 'immediate' ? $amount : 0);
            $prepareRequest->setRebillAmount($shippingAmount + $subscriptionInCart['quantity'] * (int) ($subscriptionInCart[WC_Payxpert::SUBSCRIPTION_PRICE] * 100));
            $prepareRequest->setRebillPeriod(WC_Payxpert_Utils::get_period_by_frequency($subscriptionInCart[WC_Payxpert::SUBSCRIPTION_FREQUENCY]));
            
            if ($subscriptionInCart[WC_Payxpert::SUBSCRIPTION_DURATION_TYPE] == 'normal') {
                $prepareRequest->setRebillMaxIteration($subscriptionInCart[WC_Payxpert::SUBSCRIPTION_MAX_CYCLES]);
            }

            $prepareRequest->setOperation(OperationType::SALE); //! Mandatory
        }

        // Ctrl URLs
        $ipnSecret = WC_Payxpert_Utils::ensure_ipn_secret();
        $prepareRequest->setCtrlRedirectURL(
            add_query_arg([
                'payxpert_return' => true,
            ], home_url('/wc-api/' . $gateway))
        );
        $prepareRequest->setCtrlCallbackURL(
            add_query_arg([
                'px_ipn_secret' => $ipnSecret,
            ], home_url('/wc-api/' . $gateway))
        ); 
        $prepareRequest->setCtrlCustomData(http_build_query($customData));

        // Merchant Notifications
        if ($configuration['payxpert_notification_active']) {
            $prepareRequest->setMerchantNotification(true);
            $prepareRequest->setMerchantNotificationTo($configuration['payxpert_notification_to']);
            $prepareRequest->setMerchantNotificationLang($configuration['payxpert_notification_language']);
        }

        if ($isPayByLink) {
            $prepareRequest->setTimeOut('P30D');
        }

        // API request.
        $client = new Connect2PayClient(
            self::API_URL,
            $configuration['payxpert_originator_id'],
            $configuration['payxpert_password']
        );

        $result = $client->preparePayment($prepareRequest);

        if (!$result || $result->getCode() !== '200') {
            return ['error' => $client->getClientErrorMessage()];
        }

        // Save PaymentToken to register transaction
        try {
            $paymentToken = new Payxpert_Payment_Token();

            $paymentToken->set([
                'merchant_token' => $result->getMerchantToken(),
                'customer_token' => $result->getCustomerToken(),
                'user_id'        => $customer_id,
                'order_id'       => $order_id,
                'is_paybylink'   => $isPayByLink ? 1 : 0,
            ]);

            $paymentToken->save();

        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }

        return [
            'code'          => $result->getCode(),
            'message'       => $result->getMessage(),
            'customerToken' => $result->getCustomerToken(),
            'merchantToken' => $result->getMerchantToken(),
            'redirectUrl'   => $client->getCustomerRedirectURL($result),
        ];
    }


    public static function capture_transaction($configuration, string $transactionId, int $amount)
    {
        $c2pClient = new Connect2PayClient(
            self::API_URL,
            $configuration['payxpert_originator_id'],
            $configuration['payxpert_password']
        );

        $status = $c2pClient->captureTransaction($transactionId, $amount);
        if (null == $status || null == $status->getCode()) {
            return ['error' => $c2pClient->getClientErrorMessage()];
        }

        return [
            'code' => $status->getCode(),
            'message' => $status->getMessage(),
            'transaction_id' => $status->getTransactionID(),
        ];
    }

    public static function refund_transaction($configuration, string $transactionId, int $amount, bool $useOtherApi = false, bool $cancelSubscription = false)
    {
        if ($useOtherApi) {
            $status = self::refund_transaction_special($configuration, $transactionId, $amount, $cancelSubscription);

            if (null == $status || (is_array($status) && isset($status['error'])) || null == $status->getCode()) {
                return ['error' => $status['error'] ?? 'unknown'];
            }
        } else {
            $c2pClient = new Connect2PayClient(
                self::API_URL,
                $configuration['payxpert_originator_id'],
                $configuration['payxpert_password']
            );

            $status = $c2pClient->refundTransaction($transactionId, $amount);

            if (null == $status || null == $status->getCode()) {
                return ['error' => $c2pClient->getClientErrorMessage()];
            }
        }

        return [
            'code' => $status->getCode(),
            'message' => $status->getMessage(),
            'transaction_id' => $status->getTransactionID(),
        ];
    }

    public static function refund_transaction_special($configuration, string $transactionId, int $amount, bool $cancelSubscription = false)
    {
        $url = self::API_PAYXPERT_URL . str_replace(":transactionID", (string) $transactionId, '/transaction/:transactionID/refund');
        $userpwd = $configuration['payxpert_originator_id'] . ':' . $configuration['payxpert_password'];

        $response = wp_remote_post(
            $url,
            [
                'timeout' => 30,
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                    'Authorization' => 'Basic ' . base64_encode($userpwd),
                ],
                'body' => wp_json_encode(['amount' => $amount, 'cancelSubscription' => $cancelSubscription]),
            ]
        );

        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }

        $httpCode = wp_remote_retrieve_response_code($response);
        $json = wp_remote_retrieve_body($response);

        if ($httpCode != 200) {
            return ['error' => "Received HTTP code " . $httpCode . " from payment page."];
        }

        if ($json === '') {
            return ['error' => 'Error requesting ' . $url];
        }

        $result = json_decode($json, true);
        WC_Payxpert_Logger::debug(print_r($result, true));
        if ($result != null) {
            $rfStatus = new RefundStatus();

            if (isset($result['errorCode'])) {
                $rfStatus->setCode($result['errorCode']);
            }
            if (isset($result['errorMessage'])) {
                $rfStatus->setMessage($result['errorMessage']);
            }
            if (isset($result['transactionID'])) {
                $rfStatus->setTransactionID($result['transactionID']);
            }
            if (isset($result['operation'])) {
                $rfStatus->setOperation($result['operation']);
            }

            return $rfStatus;
        }

        return ['error' => 'JSON decoding error.'];
    }

    /**
     * @param bool $with_transaction_attempt When true, merges internal key _transaction_attempt (Connect2Pay object) into the formatted array so the order can be finalized without a second API call.
     */
    public static function get_transaction_info($configuration, $transactionId, bool $useOtherApi = false, bool $with_transaction_attempt = false)
    {
        if ($useOtherApi) {
            return self::get_transaction_info_special($configuration, $transactionId, $with_transaction_attempt);
        }

        $c2pClient = new Connect2PayClient(
            self::API_URL,
            $configuration['payxpert_originator_id'],
            $configuration['payxpert_password']
        );

        /** @var TransactionAttempt|null $transaction */
        $transaction = $c2pClient->getTransactionInfo($transactionId);
        if (null == $transaction) {
            return ['error' => $c2pClient->getClientErrorMessage()];
        }

        $formatted = self::formatTransaction($transaction);
        if ($with_transaction_attempt) {
            $formatted['_transaction_attempt'] = $transaction;
        }

        return $formatted;
    }

    public static function get_transaction_info_special($configuration, $transactionId, bool $with_transaction_attempt = false)
    {
        $url = self::API_PAYXPERT_URL . str_replace(":transactionID", (string) $transactionId, '/transaction/:transactionID');
        $userpwd = $configuration['payxpert_originator_id'] . ':' . $configuration['payxpert_password'];

        $response = wp_remote_get(
            $url,
            [
                'timeout' => 30,
                'headers' => [
                    'Accept'        => 'application/json',
                    'Authorization' => 'Basic ' . base64_encode($userpwd),
                ],
            ]
        );

        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }

        $httpCode = wp_remote_retrieve_response_code($response);
        $json = wp_remote_retrieve_body($response);

        if ($httpCode != 200) {
            return ['error' => "Received HTTP code " . $httpCode . " from payment page."];
        }

        if ($json === '') {
            return ['error' => 'Error requesting ' . $url];
        }

        $result = json_decode($json, true);

        if ($result != null) {
            $json = self::mapToTransactionAttemptJson($result);
            $transactionAttempt = TransactionAttempt::getFromJson((object) $json);
            $formatted = self::formatTransaction($transactionAttempt);
            if ($with_transaction_attempt) {
                $formatted['_transaction_attempt'] = $transactionAttempt;
            }
            return $formatted;
        }

        return ['error' => 'JSON decoding error.'];
    }

    private static function mapToTransactionAttemptJson(array $response)
    {
        $t = $response['transaction'] ?? [];
        $p = $t['paymentMeanInfo'] ?? [];
        $s = $t['shopper'] ?? [];

        return [
            // Exact TransactionAttempt properties.
            'paymentID' => null,
            'paymentMerchantToken' => null,
            'paymentMethod' => ucfirst($t['paymentType'] ?? ''),
            'operation' => $t['operation'] ?? null,
            'date' => isset($t['date']) ? $t['date'] : null,
            'amount' => $t['amount'] ?? 0,
            'refundedAmount' => (($t['operation'] ?? '') === 'refund') ? ($t['amount'] ?? 0) : 0,
            'currency' => $t['currency'] ?? null,
            'resultCode' => $response['errorCode'] ?? null,
            'resultMessage' => $response['errorMessage'] ?? null,
            'status' => $t['status'] ?? null,
            'shopper' => [
                'firstName' => $s['firstName'] ?? $t['shopperName'] ?? null,
                'lastName' => $s['lastName'] ?? null,
                'email' => $s['email'] ?? $t['shopperEmail'] ?? null,
                'address1' => $s['address1'] ?? null,
                'zipcode' => $s['zipcode'] ?? null,
                'city' => $s['city'] ?? null,
                'countryCode' => $s['countryCode'] ?? null,
                'birthDate' => $s['birthDate'] ?? null,
                'idNumber' => $s['idNumber'] ?? null,
                'ipAddress' => $s['ip_address'] ?? $t['customerIP'] ?? null,
            ],
            'transactionID' => $t['transactionID'] ?? null,
            'refTransactionID' => $t['referralID'] ?? null,
            'providerTransactionID' => $t['transactionID'] ?? null,
            'subscriptionID' => $t['subscriptionID'] ?? null,
            'paymentMeanInfo' => [
                'cardNumber' => $p['cardNumber'] ?? null,
                'cardExpireYear' => $p['cardExpireYear'] ?? null,
                'cardExpireMonth' => $p['cardExpireMonth'] ?? null,
                'cardHolderName' => $p['cardHolderName'] ?? null,
                'cardBrand' => $p['cardBrand'] ?? null,
                'cardLevel' => $p['cardLevel'] ?? null,
                'cardSubType' => $p['cardSubType'] ?? null,
                'iinCountry' => $p['iinCountry'] ?? null,
                'iinBankName' => $p['iinBankName'] ?? null,
                'is3DSecure' => $p['is3DSecure'] ?? false,
            ],
            'orderID' => $t['orderID'] ?? null,
            'orderDescription' => $t['orderDescription'] ?? null,
        ];
    }

    public static function getAccountInfo($publicKey, $privateKey)
    {
        $c2pClient = new Connect2PayClient(
            self::API_URL,
            $publicKey,
            $privateKey
        );

        $info = $c2pClient->getAccountInformation();

        if (null == $info) {
            return ['error' => $c2pClient->getClientErrorMessage()];
        }

        $paymentMethods = null;
        $accPaymentMethods = $info->getPaymentMethods();
        if ($accPaymentMethods) {
            $paymentMethods = [];
            foreach ($accPaymentMethods as $methodInfo) {
                $paymentMethods[] = [
                    'paymentNetwork' => $methodInfo->getPaymentNetwork(),
                    'currencies' => $methodInfo->getCurrencies(),
                    'defaultOperation' => $methodInfo->getDefaultOperation(),
                    'options' => null == $methodInfo->getOptions()
                        ? null
                        : array_map(function ($option) {
                            return ['name' => $option->getName(), 'value' => $option->getValue()];
                        }, $methodInfo->getOptions()),
                ];
            }
        }

        $newestSeamlessLibrary = null;
        $themes = $info->getThemes();

        if ($themes) {
            $maxVersion = null;
            foreach ($themes as $theme) {
                foreach ($theme->getSeamlessLibraries() as $seamlessLibrary) {

                    $currentVersion = $seamlessLibrary->getVersion();

                    if ($maxVersion === null || version_compare($currentVersion, $maxVersion, '>')) {
                        $maxVersion = $currentVersion;
                        $newestSeamlessLibrary = [
                            'version' => $currentVersion,
                            'hash' => $seamlessLibrary->getHash()
                        ];
                    }
                }
            }
        }

        return [
            'name' => $info->getName(),
            'displayTerms' => $info->getDisplayTerms(),
            'termsUrl' => $info->getTermsUrl(),
            'supportUrl' => $info->getSupportUrl(),
            'maxAttempts' => $info->getMaxAttempts(),
            'notificationOnSuccess' => $info->getNotificationOnSuccess(),
            'notificationOnFailure' => $info->getNotificationOnFailure(),
            'notificationSenderName' => $info->getNotificationSenderName(),
            'notificationSenderEmail' => $info->getNotificationSenderEmail(),
            'merchantNotification' => $info->getMerchantNotification(),
            'merchantNotificationTo' => $info->getMerchantNotificationTo(),
            'merchantNotificationLang' => $info->getMerchantNotificationLang(),
            'paymentMethods' => $paymentMethods,
            'newestSeamlessLibrary' => $newestSeamlessLibrary
        ];
    }

    /**
     * @param int $start Use mktime to create unix timestamp
     * @param int $end Use mktime to create unix timestamp
     */
    // public static function getTransactionList(PayxpertConfiguration $configuration, int $start, int $end)
    // {
    //     // Init api
    //     $c2pClient = new Connect2PayClient(
    //         self::API_URL,
    //        $configuration['payxpert_originator_id'],
    //        $configuration['payxpert_password']
    //     );

    //     $request = new ExportTransactionsRequest();
    //     $request->setStartTime($start);
    //     $request->setEndTime($end);

    //     $result = $c2pClient->exportTransactions($request);

    //     if (null == $result) {
    //         return ['error' => $c2pClient->getClientErrorMessage()];
    //     }

    //     $transactions = [];
    //     foreach ($result->getTransactions() as $transaction) {
    //         $transactions[] = self::formatTransaction($transaction);
    //     }

    //     return $transactions;
    // }

    public static function get_status_subscription($configuration, int $subscriptionID)
    {
        $url = self::API_PAYXPERT_URL . '/subscription/' . $subscriptionID;

        $userpwd = $configuration['payxpert_originator_id'] . ':' . $configuration['payxpert_password'];
        $response = wp_remote_get(
            $url,
            [
                'timeout' => 30,
                'headers' => [
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                    'Accept'        => 'application/json',
                    'Authorization' => 'Basic ' . base64_encode($userpwd),
                ],
            ]
        );

        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data)) {
            return ['error' => sprintf('Invalid API response (HTTP %d).', (int) $code)];
        }

        if (($data['errorCode'] ?? '') !== '000') {
            return ['error' => $data['errorMessage'] ?? 'Unknown API error'];
        }

        return $data;
    }

    public static function handleRedirect($configuration, string $merchantToken, string $data)
    {
        $c2pClient = new Connect2PayClient(
            self::API_URL,
            $configuration['payxpert_originator_id'],
            $configuration['payxpert_password']
        );

        if (!$c2pClient->handleRedirectStatus($data, $merchantToken)) {
            return false;
        }

        $status = $c2pClient->getStatus();

        return [
            'errorCode' => $status->getErrorCode(),
            'customData' => $status->getCtrlCustomData(),
        ];
    }

    public static function handleCallback(array $configuration)
    {
        $c2pClient = new Connect2PayClient(
            self::API_URL,
            $configuration['payxpert_originator_id'],
            $configuration['payxpert_password']
        );

        if ($c2pClient->handleCallbackStatus() === false) {
            return false;
        }

        $status = $c2pClient->getStatus();

        return [
            'errorCode' => $status->getErrorCode(),
            'errorMessage' => $status->getErrorMessage(),
            'customData' => $status->getCtrlCustomData(),
            'transaction' => $status->getLastInitialTransactionAttempt(),
            'orderId' => $status->getOrderId(),
        ];
    }

    public static function cancelSubscription($configuration, int $subscriptionID, int $cancelReason)
    {
        $c2pClient = new Connect2PayClient(
            self::API_URL,
            $configuration['payxpert_originator_id'],
            $configuration['payxpert_password']
        );

        $code = $c2pClient->cancelSubscription($subscriptionID, $cancelReason);

        if (null == $code || $code != 200) {
            return ['error' => $c2pClient->getClientErrorMessage()];
        }

        return [
            'code' => $code,
            'message' => $c2pClient->getClientErrorMessage()
        ];
    }
    
    /**
     * @param \WC_Order|null $order
     */
    private static function resolve_order_shipping_type($order): string
    {
        if ($order instanceof \WC_Order) {
            if (method_exists($order, 'needs_shipping')) {
                return $order->needs_shipping() ? OrderShippingType::TO_CARDHOLDER : OrderShippingType::DIGITAL_GOODS;
            }

            // Backward compatibility for older WooCommerce versions.
            if (method_exists($order, 'needs_shipping_address')) {
                return $order->needs_shipping_address() ? OrderShippingType::TO_CARDHOLDER : OrderShippingType::DIGITAL_GOODS;
            }

            if (method_exists($order, 'get_items')) {
                $shipping_items = $order->get_items('shipping');
                return !empty($shipping_items) ? OrderShippingType::TO_CARDHOLDER : OrderShippingType::DIGITAL_GOODS;
            }
        }

        if (function_exists('WC') && WC()->cart && WC()->cart->needs_shipping()) {
            return OrderShippingType::TO_CARDHOLDER;
        }

        return OrderShippingType::DIGITAL_GOODS;
    }

    private static function getFormatPaymentMeanInfo(TransactionAttempt $transaction, $paymentMeanInfo = null)
    {
        $result = [];

        if (!$paymentMeanInfo) {
            return null;
        }

        switch ($transaction->getPaymentMethod()) {
            case PaymentMethod::CREDIT_CARD:
                $result['is_3D_secure'] = $paymentMeanInfo->getIs3DSecure();

                if (null !== $paymentMeanInfo->getCardNumber()) {
                    $result['card_holder_name'] = $paymentMeanInfo->getCardHolderName();
                    $result['card_number'] = $paymentMeanInfo->getCardNumber();
                    $result['card_expiration'] = $paymentMeanInfo->getCardExpireMonth() . '/' . $paymentMeanInfo->getCardExpireYear();
                    $result['card_brand'] = $paymentMeanInfo->getCardBrand();
                    $result['card_level'] = null;
                    $result['card_country_code'] = null;
                    $result['card_bank_name'] = null;

                    if (null !== $paymentMeanInfo->getCardLevel()) {
                        $result['card_level'] = $paymentMeanInfo->getCardLevel();
                        $result['card_country_code'] = $paymentMeanInfo->getIinCountry();
                        $result['card_bank_name'] = $paymentMeanInfo->getIinBankName();
                    }
                }

                break;
            case PaymentMethod::BANK_TRANSFER:
                $sender = $paymentMeanInfo->getSender();
                $recipient = $paymentMeanInfo->getRecipient();

                $result = [
                    'sender' => null == $sender ? null : [
                        'holder_name' => $sender->getHolderName(),
                        'bank_name' => $sender->getBankName(),
                        'iban' => $sender->getIban(),
                        'bic' => $sender->getBic(),
                        'country_code' => $sender->getCountryCode(),
                    ],
                    'recipient' => null == $recipient ? null : [
                        'holder_name' => $recipient->getHolderName(),
                        'bank_name' => $recipient->getBankName(),
                        'iban' => $recipient->getIban(),
                        'bic' => $recipient->getBic(),
                        'country_code' => $recipient->getCountryCode(),
                    ],
                ];

                break;
            case PaymentMethod::DIRECT_DEBIT:
                $result = ['account' => null];

                $account = $paymentMeanInfo->getBankAccount();
                if (null !== $account) {
                    $sepaMandate = $account->getSepaMandate();

                    $result['account'] = [
                        'statement_descriptor' => $paymentMeanInfo->getStatementDescriptor(),
                        'collected_at' => $paymentMeanInfo->getCollectedAtAsDateTime() ? ($paymentMeanInfo->getCollectedAtAsDateTime())->format('Y-m-d H:i:s T') : null,
                        'bank_account' => [
                            'holder_name' => $account->getHolderName(),
                            'bank_name' => $account->getBankName(),
                            'iban' => $account->getIban(),
                            'bic' => $account->getBic(),
                            'country_code' => $account->getCountryCode(),
                        ],
                        'sepa_mandate' => null == $sepaMandate ? null : [
                            'description' => $sepaMandate->getDescription(),
                            'status' => $sepaMandate->getStatus(),
                            'type' => $sepaMandate->getType(),
                            'scheme' => $sepaMandate->getScheme(),
                            'signature_type' => $sepaMandate->getSignatureType(),
                            'phone_number' => $sepaMandate->getPhoneNumber(),
                            'signed_at' => null == $sepaMandate->getSignedAtAsDateTime() ? null : $sepaMandate->getSignedAtAsDateTime()->format('Y-m-d H:i:s T'),
                            'created_at' => null == $sepaMandate->getCreatedAtAsDateTime() ? null : $sepaMandate->getCreatedAtAsDateTime()->format('Y-m-d H:i:s T'),
                            'last_used_at' => null == $sepaMandate->getLastUsedAtAsDateTime() ? null : $sepaMandate->getLastUsedAtAsDateTime()->format('Y-m-d H:i:s T'),
                            'download_url' => $sepaMandate->getDownloadUrl(),
                        ],
                    ];
                }

                break;
            case PaymentMethod::WECHAT:
            case PaymentMethod::ALIPAY:
                $result = [
                    'total_fee' => $paymentMeanInfo->getTotalFee(),
                    'exchange_rate' => $paymentMeanInfo->getExchangeRate(),
                ];

                break;
        }

        return $result;
    }

    private static function getFormatShopperInfo($shopper = null)
    {
        if (!$shopper) {
            return null;
        }

        return [
            'name' => $shopper->getFirstName(),
            'address1' => $shopper->getAddress1(),
            'zip_code' => $shopper->getZipcode(),
            'city' => $shopper->getCity(),
            'country_code' => $shopper->getCountryCode(),
            'email' => $shopper->getEmail(),
            'birth_date' => $shopper->getBirthDate(),
            'id_number' => $shopper->getIdNumber(),
            'ip_address' => $shopper->getIpAddress(),
        ];
    }

    // public static function getContainer()
    // {
    //     $container = SymfonyContainer::getInstance();

    //     if (null === $container) {
    //         $kernel = new \AppKernel('prod', false);
    //         $kernel->boot();
    //         $container = $kernel->getContainer();
    //         $container->get('logger'); // Force intialisation of all services
    //     }

    //     return $container;
    // }

    /**
     * Return array of product to fill Api Product properties.
     *
     * @return array
     */
    // private static function formatProductsApi(\Cart $cart)
    // {
    //     $products = [];

    //     foreach ($cart->getProducts() as $product) {
    //         $obj = new \Product((int) $product['id_product']);
    //         $products[] = [
    //             'CartProductId' => $product['id_product'],
    //             'CartProductName' => $product['name'],
    //             'CartProductUnitPrice' => $product['price'],
    //             'CartProductQuantity' => $product['quantity'],
    //             'CartProductBrand' => $obj->manufacturer_name,
    //             'CartProductMPN' => $product['ean13'],
    //             'CartProductCategoryName' => $product['category'],
    //             'CartProductCategoryID' => $product['id_category_default'],
    //         ];
    //     }

    //     return $products;
    // }

    private static function formatTransaction($transaction)
    {
        $paymentMeanInfo = $transaction->getPaymentMeanInfo();
        $shopper = $transaction->getShopper();

        return [
            'payment_id' => $transaction->getPaymentID(),
            'payment_merchant_token' => $transaction->getPaymentMerchantToken(),
            'order_id' => $transaction->getOrderID(),
            'transaction_id' => $transaction->getTransactionID(),
            'ref_transaction_id' => $transaction->getRefTransactionID(),
            'provider_transaction_id' => $transaction->getProviderTransactionID(),
            'payment_method' => $transaction->getPaymentMethod(),
            'operation' => $transaction->getOperation(),
            'amount100' => $transaction->getAmount(),
            'amount' => $transaction->getAmount() / 100,
            'refunded_amount100' => $transaction->getRefundedAmount(),
            'refunded_amount' => $transaction->getRefundedAmount() / 100,
            'currency' => $transaction->getCurrency(),
            'result_code' => $transaction->getResultCode(),
            'result_message' => $transaction->getResultMessage(),
            'transaction_date' => null == $transaction->getDateAsDateTime() ? null : ($transaction->getDateAsDateTime())->format('Y-m-d H:i:s T'),
            'subscription_id' => $transaction->getSubscriptionID(),
            'payment_mean_info' => self::getFormatPaymentMeanInfo($transaction, $paymentMeanInfo),
            'shopper' => self::getFormatShopperInfo($shopper),
        ];
    }

    /**
     * Connect2Pay Shopper Account (SCA / 3DS v2 hints). Only for logged-in customers (not guest).
     */
    private static function attach_shopper_account_for_sca( Shopper $shopper, int $customer_id ): void {
        if ( $customer_id <= 0 ) {
            return;
        }

        $user = get_userdata( $customer_id );
        if ( ! $user instanceof \WP_User ) {
            return;
        }

        $reg_ts = strtotime( $user->user_registered );
        if ( ! $reg_ts ) {
            $reg_ts = time();
        }

        $days = (int) floor( ( time() - $reg_ts ) / DAY_IN_SECONDS );

        if ( $days < 30 ) {
            $age = AccountAge::LESS_30_DAYS;
            $lastChange = AccountLastChange::LESS_30_DAYS;
            $pwChange = AccountPwChange::LESS_30_DAYS;
        } elseif ( $days <= 60 ) {
            $age = AccountAge::BETWEEN_30_60_DAYS;
            $lastChange = AccountLastChange::BETWEEN_30_60_DAYS;
            $pwChange = AccountPwChange::BETWEEN_30_60_DAYS;
        } else {
            $age = AccountAge::MORE_60_DAYS;
            $lastChange = AccountLastChange::MORE_60_DAYS;
            $pwChange = AccountPwChange::MORE_60_DAYS;
        }

        $dateStr = gmdate( 'Ymd', $reg_ts );

        $account = new Account();
        $account->setAge( $age );
        $account->setDate( $dateStr );
        $account->setLastChange( $lastChange );
        $account->setLastChangeDate( $dateStr );
        $account->setPwChange( $pwChange );
        $account->setPwChangeDate( $dateStr );

        if ( function_exists( 'wc_get_customer_order_count' ) ) {
            // Field name is six-month window; we send total WC order count capped (best-effort when no date filter).
            $account->setOrderSixMonths( min( 99, (int) wc_get_customer_order_count( $customer_id ) ) );
        }

        $shopper->setAccount( $account );
    }

    private static function getCountryPhonePrefix($country_code) {
        $prefixes = [
            'FR' => '33',
            'BE' => '32',
            'CH' => '41',
            'US' => '1',
            // todo
        ];
        return $prefixes[strtoupper($country_code)] ?? '';
    }

    // private static function formatOrderProducts(WC_Order $order) {
    //     $items = [];

    //     foreach ($order->get_items() as $item) {
    //         $product = $item->get_product();
    //         $items[] = [
    //             'name'     => $item->get_name(),
    //             'ref'      => $product ? $product->get_sku() : '',
    //             'price'    => $item->get_total(),
    //             'quantity' => $item->get_quantity(),
    //         ];
    //     }

    //     return $items;
    // }

    private static function formatAddress($address)
    {
        return substr(trim(str_replace("'", " ", $address)), 0, 255);
    }
}
