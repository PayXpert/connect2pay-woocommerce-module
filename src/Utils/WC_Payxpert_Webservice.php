<?php

declare(strict_types=1);

namespace Payxpert\Utils;

defined( 'ABSPATH' ) || exit();

use Payxpert\Classes\PayxpertConfiguration;
use PayXpert\Connect2Pay\Connect2PayClient;
use PayXpert\Connect2Pay\containers\constant\OperationType;
use PayXpert\Connect2Pay\containers\constant\OrderShippingType;
use PayXpert\Connect2Pay\containers\constant\PaymentMethod;
use PayXpert\Connect2Pay\containers\constant\PaymentMode;
use PayXpert\Connect2Pay\containers\constant\SubscriptionType;
use PayXpert\Connect2Pay\containers\Order as c2pOrder;
use PayXpert\Connect2Pay\containers\request\ExportTransactionsRequest;
use PayXpert\Connect2Pay\containers\request\PaymentPrepareRequest;
use PayXpert\Connect2Pay\containers\response\PaymentStatus;
use PayXpert\Connect2Pay\containers\response\TransactionAttempt;
use PayXpert\Connect2Pay\containers\Shipping;
use PayXpert\Connect2Pay\containers\Shopper;
use Payxpert\Models\Payxpert_Payment_Token;
use Payxpert\WC_Payxpert;
use PrestaShop\PrestaShop\Adapter\SymfonyContainer;
use WC_Order;

class WC_Payxpert_Webservice
{
    const API_URL = 'https://connect2.payxpert.com';
    const API_PAYXPERT_URL = 'https://api.payxpert.com';

    public static function preparePayment($configuration, $gateway, string $paymentMethod, string $paymentMode, $order = null, array $instalmentParameters = [], bool $isPayByLink = false)
    {
        if (is_null($order)) {
            $billing = [
                'first_name' => '',
                'last_name'  => '',
                'address_1'  => '',
                'address_2'  => '',
                'city'       => '',
                'postcode'   => '',
                'country'    => '',
            ];
            
            $currency = get_woocommerce_currency();
            $amount = (int) round(WC()->cart->get_total('edit') * 100);
            $customer = wp_get_current_user();
            $customer_id = $customer->ID;
            $email = '';
            $billing_phone = '';
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
        
        // Param Shopper
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

        // Param Order
        $c2pOrder = new c2pOrder();
        $c2pOrder->setShippingType(OrderShippingType::DIGITAL_GOODS);
        $c2pOrder->setId($order_id);

        if (!is_null($order)) {
            $c2pOrder->setDescription('Order #' . $order->get_order_number());
        }
        // $c2pOrder->setCartContent(self::formatOrderProducts($order)); // TODO: not working on the paygate anyway

        // Param Shipping
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

        // Ctrl URLs
        $prepareRequest->setCtrlRedirectURL(
            add_query_arg([
                'payxpert_return' => true,
            ], home_url('/wc-api/' . $gateway))
        );
        $prepareRequest->setCtrlCallbackURL(
            add_query_arg([
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

        // Appel à l’API
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

    public static function refund_transaction($configuration, string $transactionId, int $amount)
    {
        $c2pClient = new Connect2PayClient(
            self::API_URL,
           $configuration['payxpert_originator_id'],
           $configuration['payxpert_password']
        );

        $status = $c2pClient->refundTransaction($transactionId, $amount);
        if (null == $status || null == $status->getCode()) {
            return ['error' => $c2pClient->getClientErrorMessage()];
        }

        return [
            'code' => $status->getCode(),
            'message' => $status->getMessage(),
            'transaction_id' => $status->getTransactionID(),
        ];
    }

    // public static function getPaymentStatus(PayxpertConfiguration $configuration, string $merchantToken)
    // {
    //     // Init api
    //     $c2pClient = new Connect2PayClient(
    //         self::API_URL,
    //        $configuration['payxpert_originator_id'],
    //        $configuration['payxpert_password']
    //     );

    //     /** @var PaymentStatus */
    //     $status = $c2pClient->getPaymentStatus($merchantToken);
    //     if (null == $status || null == $status->getErrorCode()) {
    //         return ['error' => $c2pClient->getClientErrorMessage()];
    //     }

    //     $result = [
    //         'merchant_token' => $status->getMerchantToken(),
    //         'status' => $status->getStatus(),
    //         'error_code' => $status->getErrorCode(),
    //         'custom_data' => $status->getCtrlCustomData(),
    //         'transaction_number' => count($status->getTransactions()),
    //         'last_transaction' => null,
    //         'others_transactions' => null,
    //     ];

    //     $transactionsCount = count($status->getTransactions());
    //     if ($transactionsCount > 0) {
    //         $transaction = $status->getLastInitialTransactionAttempt();

    //         /* @phpstan-ignore-next-line */
    //         if ($transaction) {
    //             $shopper = $transaction->getShopper();
    //             $paymentMeanInfo = $transaction->getPaymentMeanInfo();

    //             $result['last_transaction'] = [
    //                 'transaction_id' => $transaction->getTransactionID(),
    //                 'payment_method' => $transaction->getPaymentMethod(),
    //                 'payment_network' => $transaction->getPaymentNetwork(),
    //                 'operation' => $transaction->getOperation(),
    //                 'amount100' => $transaction->getAmount(),
    //                 'amount' => $transaction->getAmount() / 100,
    //                 'currency' => $status->getCurrency(),
    //                 'result_code' => $transaction->getResultCode(),
    //                 'result_message' => $transaction->getResultMessage(),
    //                 'transaction_date' => $transaction->getDateAsDateTime() ? ($transaction->getDateAsDateTime())->format('Y-m-d H:i:s T') : null,
    //                 'subscription_id' => $transaction->getSubscriptionID(),
    //                 'payment_mean_info' => self::getFormatPaymentMeanInfo($transaction, $paymentMeanInfo),
    //                 'shopper' => self::getFormatShopperInfo($shopper),
    //             ];
    //         }

    //         if ($transactionsCount > 1) {
    //             foreach ($status->getTransactions() as $attempt) {
    //                 if ($attempt->getTransactionId() != $transaction->getTransactionId()) {
    //                     $result['others_transactions'][] = [
    //                         'transaction_id' => $attempt->getTransactionID(),
    //                         'attempt_date' => null == $attempt->getDateAsDateTime() ? null : ($attempt->getDateAsDateTime())->format('Y-m-d H:i:s T'),
    //                         'payment_method' => $attempt->getPaymentMethod(),
    //                         'operation' => $attempt->getOperation(),
    //                         'amount100' => $attempt->getAmount(),
    //                         'amount' => $attempt->getAmount() / 100,
    //                         'currency' => $status->getCurrency(),
    //                         'result_code' => $attempt->getResultCode(),
    //                         'result_message' => $attempt->getResultMessage(),
    //                     ];
    //                 }
    //             }
    //         }
    //     }

    //     return $result;
    // }

    public static function get_transaction_info($configuration, string $transactionId)
    {
        // Init api
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

        return self::formatTransaction($transaction);
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

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Pour obtenir la réponse
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $configuration['payxpert_originator_id'] . ":" . $configuration['payxpert_password']);

        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json'
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            return ['error' => curl_error($ch)];
        }

        curl_close($ch);

        $data = json_decode($response, true);

        if ($data['errorCode'] != '000') {
            return ['error' => $data['errorMessage']];
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

        /* @phpstan-ignore-next-line */
        if (false == $c2pClient->handleCallbackStatus()) {
            return false;
        }

        $status = $c2pClient->getStatus();

        return [
            'errorCode' => $status->getErrorCode(),
            'errorMessage' => $status->getErrorMessage(),
            'customData' => $status->getCtrlCustomData(),
            'transaction' => $status->getLastTransactionAttempt(),
            'orderId' => $status->getOrderId(),
        ];
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
