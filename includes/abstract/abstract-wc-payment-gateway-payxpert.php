<?php

use PayXpert\Connect2Pay\containers\constant\SubscriptionType;
use Payxpert\Exception\ConfigurationNotFoundException;
use Payxpert\Exception\HandleFailedException;
use Payxpert\Exception\PaymentCancellationException;
use Payxpert\Exception\PaymentTokenExpiredException;
use Payxpert\Exception\PaymentTokenNotFoundException;
use Payxpert\Models\Payxpert_Payment_Token;
use Payxpert\Models\Payxpert_Payment_Transaction;
use Payxpert\Models\Payxpert_Subscription;
use Payxpert\Utils\WC_Payxpert_Logger;
use Payxpert\Utils\WC_Payxpert_Utils;
use Payxpert\Utils\WC_Payxpert_Webservice;
use Payxpert\WC_Payxpert;

defined( 'ABSPATH' ) || exit();

if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
    return;
}

abstract class WC_Payment_Gateway_Payxpert extends WC_Payment_Gateway {
    const DEFAULT_SEAMLESS_VERSION = '1.6.1';
    const DEFAULT_SEAMLESS_HASH = 'BRCgrkMLcVR8+KMJHI+9i9/QUuB1pF20M026MUoWXggnL1OKRKg9latpzAkuXz25';

    public $configuration;
    public $seamless;
    
    public function admin_options() {
        $template_path = plugin_dir_path(__FILE__) . '../../templates/views/html-admin-options.php';

        if ( file_exists( $template_path ) ) {
            include $template_path;
        }
    }

    public function process_admin_options()
    {
        parent::process_admin_options();
    }

    public function callback()
    {
        if (isset($_GET['payxpert_return']) && $_GET['payxpert_return'] === '1') {
            $this->handleFrontRedirect();
        } else {
            $this->handleIPN();
        }
    }

    public function handleFrontRedirect() {
        WC_Payxpert_Logger::info('FrontRedirect');

        try {
            $redirect_url = wc_get_page_permalink('checkout');

            $data = isset($_POST['data']) ? sanitize_text_field($_POST['data']) : null;
            $customer_token = isset($_POST['customer']) ? sanitize_text_field($_POST['customer']) : null;

            if (!$data || !$customer_token) {
                throw new Exception( __( 'Missing data, customer or secure token.', 'payxpert' ) );
            }

            $payment_token = Payxpert_Payment_Token::findByCustomerToken($customer_token);

            if (!$payment_token) {
                throw new Exception(
                    sprintf(
                        __( 'No payment token found for customer token: %s', 'payxpert' ),
                        substr( $customer_token, 0, 50 )
                    )
                );
            }

            // Handle redirection result via your API/WebService
            $result = WC_Payxpert_Webservice::handleRedirect(
                $this->configuration, 
                $payment_token['merchant_token'], 
                $data
            );

            $order = wc_get_order($payment_token['order_id']);

            if (!$order) {
                throw new Exception( __( 'Order not found.', 'payxpert' ) );
            }

            // Extra ownership check for authenticated customers.
            if ( is_user_logged_in() ) {
                $order_user_id = (int) $order->get_user_id();
                if ( $order_user_id > 0 && (int) get_current_user_id() !== $order_user_id ) {
                    throw new Exception( __( 'Unauthorized order.', 'payxpert' ) );
                }
            }

            switch ($result['errorCode']) {
                case Payxpert_Payment_Transaction::RESULT_CODE_SUCCESS:
                    $redirect_url = $order->get_checkout_order_received_url();
                    break;

                case Payxpert_Payment_Transaction::RESULT_CODE_CANCEL:
                    $redirect_url = wc_get_checkout_url();
                    break;

                default:
                    if ( is_user_logged_in() && get_current_user_id() === (int) $order->get_user_id() ) {
                        // Logged-in customer: order view URL.
                        $redirect_url = $order->get_view_order_url();
                    } else {
                        // Guest: order-pay URL with explicit failed-payment flag.
                        $redirect_url = add_query_arg(
                            [
                                'payxpert_payment' => 'failed',
                            ],
                            $order->get_checkout_payment_url()
                        );
                    }
                break;
            }

        } catch (Exception $e) {
            WC_Payxpert_Logger::critical($e->getMessage());
            $redirect_url = wc_get_page_permalink('cart');
        } finally {
            WC_Payxpert_Logger::info('Redirect to : ' . $redirect_url);
            $safe_redirect_url = esc_url_raw($redirect_url);
            if (empty($safe_redirect_url)) {
                $safe_redirect_url = wc_get_page_permalink('checkout');
            }

            while (ob_get_level()) {
                ob_end_clean();
            }

            // For seamless + 3DS flows, the return can happen inside an iframe.
            // Force redirect on the top window so checkout does not remain framed.
            header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
            echo '<!doctype html><html><head><meta charset="' . esc_attr(get_bloginfo('charset')) . '">';
            echo '<meta http-equiv="X-UA-Compatible" content="IE=edge">';
            echo '</head><body>';
            echo '<script>(function(){var u=' . wp_json_encode($safe_redirect_url) . ';try{if(window.top&&window.top!==window.self){window.top.location.href=u;return;}}catch(e){}window.location.href=u;})();</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . esc_url($safe_redirect_url) . '"></noscript>';
            echo '</body></html>';
            exit;
        }
    }

    private function handleIPN()
    {
        WC_Payxpert_Logger::info('IPN');
        $responseStatus = 'OK';
        $responseMessage = __( 'Payment status recorded.', 'payxpert' );

        try {
            if (empty($this->configuration['payxpert_originator_id']) || empty($this->configuration['payxpert_password'])) {
                throw new ConfigurationNotFoundException();
            }

            $this->assertValidIpnSecret();

            $handle = WC_Payxpert_Webservice::handleCallback($this->configuration);

            if (!$handle) {
                throw new HandleFailedException();
            }

            $logs = $handle;
            Payxpert_Payment_Transaction::anonymizeRecursive($logs);
            WC_Payxpert_Logger::info(
                wp_json_encode(
                    $logs,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                )
            );

            $transaction = $handle['transaction'];
            $transactionID = $transaction->getTransactionID();
            $subscriptionID = $transaction->getSubscriptionID();

            if (Payxpert_Payment_Transaction::findByTransactionId($transactionID)) {
                throw new Exception('Duplicate IPN ignored.');
            }

            if ($handle['errorCode'] == Payxpert_Payment_Transaction::RESULT_CODE_CALLBACK_CANCEL) {
                throw new PaymentCancellationException();
            }

            $paymentToken = Payxpert_Payment_Token::findByMerchantToken(
                $handle['transaction']->getPaymentMerchantToken()
            );

            if (!$paymentToken) {
                throw new PaymentTokenNotFoundException();
            }

            $order_id = $paymentToken['order_id'];

            if ($order_id == 0) {
                throw new PaymentTokenExpiredException();
            }

            $order = wc_get_order($order_id);

            if (!$order) {
                throw new Exception('Order not found.');
            }

            $customData = [];
            if (!empty($handle['customData'])) {
                parse_str($handle['customData'], $customData);
            }

            $this->assertValidIpnPayload($order, $paymentToken, $handle, $customData);

            $this->updateOrderFromIPN($order, $handle, $customData);
            $this->createPaymentTransaction($order, $transaction);

            if ($subscriptionID != 0) {
                $subscriptionInfo = WC_Payxpert_Webservice::get_status_subscription(
                    $this->configuration, 
                    $subscriptionID
                );
                if (isset($subscriptionInfo['error'])) {
                    WC_Payxpert_Logger::critical('Error while retrieving subscriptionInfo for paymentTransactionID : ' . $subscriptionID);
                } else {
                    $payxpertSubscription = new Payxpert_Subscription();
                    $subscriptionInfo = WC_Payxpert_Utils::to_snake_case_keys($subscriptionInfo['subscription']);
                    $payxpertSubscription->set($subscriptionInfo);
                    $payxpertSubscription->set(['customer_id' => $order->get_customer_id()]);
                    $payxpertSubscription->save();
                }
            }
        } catch (\Throwable $e) {
            WC_Payxpert_Logger::error('IPN Error');
            WC_Payxpert_Logger::error($e->getMessage());
            $httpCode = stripos($e->getMessage(), 'Unauthorized callback request.') !== false ? 403 : 500;
            http_response_code($httpCode);
            $responseStatus = 'KO';
            $responseMessage = __( 'Callback validation failed.', 'payxpert' );
        } finally {
            WC_Payxpert_Logger::info('IPN END');
            
            
            while (ob_get_level()) {   
                ob_end_clean();
            }

            wp_send_json([
                'status' => $responseStatus,
                'message' => $responseMessage,
            ]);
            exit;
        }
    }

    private function assertValidIpnSecret(): void
    {
        $requestSecret = isset($_GET['px_ipn_secret']) ? sanitize_text_field(wp_unslash($_GET['px_ipn_secret'])) : '';
        $expectedSecret = WC_Payxpert_Utils::ensure_ipn_secret();

        if (!is_string($requestSecret) || $requestSecret === '' || !hash_equals($expectedSecret, $requestSecret)) {
            throw new Exception('Unauthorized callback request.');
        }
    }

    private function assertValidIpnPayload(WC_Order $order, array $paymentToken, array $handle, array $customData): void
    {
        $transaction = $handle['transaction'];

        $expectedAmount = (int) round(((float) $order->get_total()) * 100);
        $receivedAmount = (int) $transaction->getAmount();
        $status = isset($customData['status']) ? (string) $customData['status'] : 'processing';
        $isInstallmentPending = $status === WC_Payxpert::ORDER_STATUS_INSTALLMENT_PENDING;

        if ($isInstallmentPending) {
            if ($receivedAmount <= 0 || $receivedAmount > $expectedAmount) {
                throw new Exception(
                    sprintf(
                        'IPN amount mismatch [order=%d, expected_range=1..%d, got=%d]',
                        (int) $order->get_id(),
                        $expectedAmount,
                        $receivedAmount
                    )
                );
            }
        } elseif ($receivedAmount !== $expectedAmount) {
                throw new Exception(
                    sprintf(
                        'IPN amount mismatch [order=%d, expected=%d, got=%d]',
                        (int) $order->get_id(),
                        $expectedAmount,
                        $receivedAmount
                    )
                );
        }

        $expectedCurrency = strtoupper((string) $order->get_currency());
        $receivedCurrency = strtoupper((string) $transaction->getCurrency());
        if ($receivedCurrency !== $expectedCurrency) {
            throw new Exception(
                sprintf(
                    'IPN currency mismatch [order=%d, expected=%s, got=%s]',
                    (int) $order->get_id(),
                    $expectedCurrency,
                    $receivedCurrency
                )
            );
        }

        $tokenOrderId = (int) ($paymentToken['order_id'] ?? 0);
        if ($tokenOrderId <= 0 || $tokenOrderId !== (int) $order->get_id()) {
            throw new Exception('IPN token/order mismatch.');
        }

        $callbackOrderId = isset($handle['orderId']) ? (int) $handle['orderId'] : 0;
        $transactionOrderId = (int) $transaction->getOrderID();
        $remoteOrderId = $transactionOrderId > 0 ? $transactionOrderId : $callbackOrderId;
        if ($remoteOrderId > 0 && $remoteOrderId !== (int) $order->get_id()) {
            throw new Exception(
                sprintf(
                    'IPN order mismatch [expected=%d, got=%d]',
                    (int) $order->get_id(),
                    $remoteOrderId
                )
            );
        }
    }

    private function updateOrderFromIPN(WC_Order $order, array $handle, array $customData)
    {
        $transactionId = $handle['transaction']->getTransactionID();

        $status = isset($customData['status']) ? $customData['status'] : 'processing';

        // Keep installment pending status when provided via customData.
        if (
            $this->configuration['payxpert_capture_mode'] == WC_Payxpert::CAPTURE_MODE_MANUAL
            && $status !== WC_Payxpert::ORDER_STATUS_INSTALLMENT_PENDING
        ) {
            $status = WC_Payxpert::ORDER_STATUS_CAPTURE_PENDING;
        }

        if ($handle['errorCode'] !== Payxpert_Payment_Transaction::RESULT_CODE_SUCCESS) {
            $status = 'failed';
        } 

        $updateStatus = !in_array(
            $order->get_status(),
            ['processing', 'completed'],
            true
        );

        switch ($status) {
            case 'processing':
                if ($updateStatus) {
                    $order->add_meta_data('payxpert_transaction_id', $transactionId);
                    $order->add_meta_data('payxpert_payment_id', $handle['transaction']->getPaymentID());

                    $order->payment_complete($transactionId);
                }
            break;

            case WC_Payxpert::ORDER_STATUS_INSTALLMENT_PENDING:
                $order->set_transaction_id($transactionId);
                $order->add_meta_data('payxpert_installment', true);
            break;

            case WC_Payxpert::ORDER_STATUS_CAPTURE_PENDING:
                $order->add_meta_data('payxpert_capture', true);
            break;
        }

        if ( ! $order->get_payment_method() ) {
            $order->set_payment_method( $this );
            $order->set_payment_method_title( $this->get_title() );
        }

        if ( empty( $order->get_customer_ip_address() ) && ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $order->set_customer_ip_address(  WC_Geolocation::get_ip_address() );
        }

        if ( empty( $order->get_customer_user_agent() ) && ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
            $order->set_customer_user_agent( wc_get_user_agent() );
        }

        $order->save();

        if ($updateStatus && $status !== 'processing') {
            $order->update_status(
                $status,
                __('PayXpert transaction received.', 'payxpert')
            );
        }
    }

    private function createPaymentTransaction(WC_Order $order, $transaction): void {

        $paymentMeanInfo = $transaction->getPaymentMeanInfo();

        $payxpertPaymentTransaction = new Payxpert_Payment_Transaction();

        $payxpertPaymentTransaction->set([
            'id_shop'               => get_current_blog_id(),
            'transaction_id'        => $transaction->getTransactionId(),
            'transaction_referal_id'=> $transaction->getRefTransactionID(),
            'order_id'              => $order->get_id(),
            'payment_id'            => $transaction->getPaymentID(),
            'liability_shift'       => $paymentMeanInfo && method_exists($paymentMeanInfo, 'getIs3DSecure')
                ? (bool)$paymentMeanInfo->getIs3DSecure()
                : false,
            'payment_method'        => $transaction->getPaymentMethod(),
            'operation'             => $transaction->getOperation(),
            'amount'                => number_format($transaction->getAmount() / 100, 2, '.', ''),
            'currency'              => $transaction->getCurrency(),
            'result_code'           => $transaction->getResultCode(),
            'result_message'        => $transaction->getResultMessage(),
            'subscription_id'       => $transaction->getSubscriptionID(),
            'card_number'           => $paymentMeanInfo && method_exists($paymentMeanInfo, 'getCardNumber')
                ? $paymentMeanInfo->getCardNumber()
                : null,
            'card_brand'            => $paymentMeanInfo && method_exists($paymentMeanInfo, 'getCardBrand')
                ? $paymentMeanInfo->getCardBrand()
                : null,
        ]);

        $payxpertPaymentTransaction->save();
    }

    public function payment_fields() {
        echo $this->get_payment_fields();
    }

    /**
     * Markup for seamless iframe container (no preparePayment call).
     *
     * @param array<string, mixed> $seamlessData
     * @param string $container_extra_attrs Optional extra attributes for the root div (e.g. style).
     */
    public function get_seamless_payment_fields_html(array $seamlessData, string $container_extra_attrs = ''): string {
        ob_start(); ?>
        <div class="payxpert-seamless-container" <?php echo $container_extra_attrs; ?>
            data-method="<?php echo esc_attr($this->id); ?>"
            data-customer-token="<?php echo esc_attr($seamlessData['customerToken']); ?>" 
            data-language="<?php echo esc_attr($seamlessData['language']); ?>"
            data-seamless-submit="<?php echo esc_attr($seamlessData['seamlessSubmit']); ?>"
        >
            <div class="payxpert-placeholder"></div>
            <input type="hidden" class="payxpert_nonce" value="<?php echo esc_attr($seamlessData['nonce']); ?>">
            <input type="hidden" class="customerToken" value="<?php echo esc_attr($seamlessData['customerToken']); ?>" name="<?php echo esc_attr($this->id); ?>_customer_token"/>
            <button type="button" class="payxpert_seamless_hidden_submit" id="<?php echo esc_attr($seamlessData['seamlessSubmit']); ?>" style="display:none" ><?php echo esc_html__('Pay', 'payxpert'); ?></button>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public function get_payment_fields() {
        global $wp;
        $order = null;

        if ( isset( $wp->query_vars['order-pay'] ) ) {
            $order_id = absint( $wp->query_vars['order-pay'] );
            $order = wc_get_order( $order_id );
        }

        $seamlessData = $this->get_seamless_data($order);

        if (!$seamlessData) {
            return;
        }

        if (isset($seamlessData['error'])) {
            return esc_html($seamlessData['error']);
        }

        return $this->get_seamless_payment_fields_html($seamlessData);
    }

    public function add_scripts()
    {
        if (is_checkout() && $this->seamless) {
            global $wp;
            $checkout_order_id = 0;
            if ( is_checkout_pay_page() && isset( $wp->query_vars['order-pay'] ) ) {
                $checkout_order_id = absint( $wp->query_vars['order-pay'] );
            }

            $ajaxUrl = admin_url('admin-ajax.php');
            $seamlessConfig = json_decode(get_option('payxpert_seamless_library'), true);
            $seamlessApi = [
                'version' => $seamlessConfig['version'] ?? self::DEFAULT_SEAMLESS_VERSION,
                'hash' => $seamlessConfig['hash'] ?? self::DEFAULT_SEAMLESS_HASH,
            ];
            $applePayEnabled = json_encode(
                isset($this->configuration['payxpert_applepay_enabled'])
                && $this->configuration['payxpert_applepay_enabled'] === 'yes'
            );

            wp_enqueue_style('payxpert-seamless-style', WC_PAYXPERT_ASSETS . 'css/seamless.css', [], WC_PAYXPERT_VERSION);

            if (!wp_script_is('payxpert-seamless', 'enqueued')) {
                wp_register_script(
                    'payxpert-seamless',
                    '',
                    [
                        'jquery',
                    ],
                    null,
                    true
                );

                wp_localize_script(
                    'payxpert-seamless',
                    'payxpertSettings', // global JS object name
                    [
                        'isCheckoutPayPage' => is_checkout_pay_page() ? true : false,
                        'checkoutOrderId' => $checkout_order_id,
                        'redirectMessage' => __( 'Redirect in progress...', 'payxpert' ),
                        'genericErrorMessage' => __( 'An unexpected error occurred.', 'payxpert' ),
                        'communicationErrorMessage' => __( 'Unable to communicate with the server.', 'payxpert' ),
                        'paymentMethodErrorMessage' => __( 'An error occurred while retrieving the selected payment method.', 'payxpert' ),
                        'missingNonceErrorMessage' => __( 'Missing nonce for payment result.', 'payxpert' ),
                        'ajaxRequestFailedMessage' => __( 'AJAX request failed:', 'payxpert' ),
                        'attemptFailedRetryingMessage' => __( 'Attempt %d failed, retrying...', 'payxpert' ),
                        'retryingAjaxRequestMessage' => __( 'Retrying AJAX request (attempt %d)...', 'payxpert' ),
                        'triggerPaymentMessage' => __( 'Triggering PayXpert payment call.', 'payxpert' ),
                        'paymentPendingMessage' => __( 'Your payment is still being confirmed. Please wait a moment…', 'payxpert' ),
                        'paymentConfirmTimeoutMessage' => __( 'Payment confirmation is taking longer than expected. Please check your order status or try again.', 'payxpert' ),
                        'maxPollAttempts' => 30,
                    ]
                );

                wp_add_inline_script('payxpert-seamless', <<<JS
                    window.payxpertCheckoutOrderId = 0;

                    window.setPayxpertCheckoutOrderId = function( orderId ) {
                        const id = parseInt( orderId, 10 );
                        window.payxpertCheckoutOrderId = Number.isFinite( id ) && id > 0 ? id : 0;
                    };

                    window.getPayxpertCheckoutOrderId = function() {
                        return window.payxpertCheckoutOrderId || 0;
                    };

                    window.payxpertStoreOrderIdFromCheckout = function( checkoutData ) {
                        if ( ! checkoutData ) {
                            return 0;
                        }
                        const payload = checkoutData.processingResponse ?? checkoutData;
                        const raw = payload.paymentDetails?.orderId ?? payload.orderId ?? checkoutData.orderId;
                        window.setPayxpertCheckoutOrderId( raw );
                        return window.getPayxpertCheckoutOrderId();
                    };

                    if ( payxpertSettings.checkoutOrderId ) {
                        window.setPayxpertCheckoutOrderId( payxpertSettings.checkoutOrderId );
                    }

                    function mountPayxpertIframe() {
                        const selectedMethod = getSelectedPaymentMethod();

                        if (!selectedMethod || !selectedMethod.includes('payxpert')) return;

                        const container = document.querySelector(`.payxpert-seamless-container[data-method="`+selectedMethod+`"]`);
                        if (!container) return;

                        // Delete div.payxpert-placeholder content due to problem in pay-for-order page
                        if (window.location.href.includes('pay_for_order=true')) {
                            document.querySelectorAll('div.payxpert-placeholder').forEach(el => el.innerHTML = '');
                        }

                        const token = container.dataset.customerToken;
                        const language = container.dataset.language || 'en';
                        const prefix = selectedMethod;
                        const externalPaymentButton = container.dataset.seamlessSubmit;

                        const placeholder = container.querySelector('.payxpert-placeholder');
                        if (!token || !placeholder || !externalPaymentButton) return;

                        function buildIframe(enableApplePay) {
                            placeholder.innerHTML = '';
                            document.querySelectorAll(`script[data-payxpert-script="1"]`).forEach(el => el.remove());

                            const mountId = 'payment-container-' + Date.now();
                            const div = document.createElement('div');
                            div.id = mountId;
                            addIdToPlaceOrderButton();

                            const configScript = document.createElement('script');
                            configScript.type = 'application/json';
                            configScript.textContent = JSON.stringify({
                                onPaymentResult: "onPaymentResult",
                                enableApplePay: enableApplePay,
                                language: language,
                                externalPaymentButton: externalPaymentButton,
                            });
                            configScript.setAttribute('data-payxpert-script', 1);

                            div.appendChild(configScript);
                            placeholder.appendChild(div);

                            const script = document.createElement('script');
                            script.src = "https://connect2.payxpert.com/payment/" + token + "/connect2pay-seamless-v{$seamlessApi['version']}.js";
                            script.async = true;
                            script.setAttribute('data-mount-in', "#" + mountId);
                            script.setAttribute('integrity', "sha384-{$seamlessApi['hash']}");
                            script.setAttribute('crossorigin', 'anonymous');
                            script.setAttribute('data-payxpert-script', 1);

                            document.body.appendChild(script);
                        }

                        // #45580: not available yet
                        // const applePayEnabled = {$applePayEnabled};
                        const applePayEnabled = false; 

                        buildIframe(applePayEnabled);
                    }

                    function togglePlaceOrder() {
                        const selectedMethod = getSelectedPaymentMethod();

                        if (selectedMethod && selectedMethod.includes('payxpert')) {
                            jQuery('#place_order').attr("disabled", "disabled");
                        } else {
                            jQuery('#place_order').removeAttr("disabled");
                        }
                    }

                    function addIdToPlaceOrderButton() {
                        const placeOrderButton = document.querySelector('.wc-block-components-checkout-place-order-button');
                        if (placeOrderButton && !placeOrderButton.id) {
                            placeOrderButton.id = 'place_order';
                        }
                    }

                    function onPaymentResult(response) {
                        const selectedMethod = getSelectedPaymentMethod();
                        const container = document.querySelector('.payxpert-seamless-container[data-method="' + selectedMethod + '"]');

                        if (!container) {
                            console.error('Cannot find container for method: ' + selectedMethod);
                            return;
                        }

                        setTimeout(() => {
                            const loader = document.createElement('div');
                            loader.id = 'iframe-redirect-loader';

                            loader.innerHTML = window.payxpertSettings.redirectMessage + `
                                <div style="display:flex; margin-left:10px;">
                                    <span class="loader-dot"></span>
                                    <span class="loader-dot"></span>
                                    <span class="loader-dot"></span>
                                </div>
                            `;

                            container.appendChild(loader);

                            const nonceInput = container.querySelector('.payxpert_nonce');
                            const nonce = nonceInput ? nonceInput.value : null;
                            const orderId = window.getPayxpertCheckoutOrderId();
                            sendPaymentResult( response, nonce, orderId );
                        }, 2000);
                    }

                    function sendPaymentResult(response, nonce, orderId, attempt = 1) {
                        if (!nonce) {
                            console.error(payxpertSettings.missingNonceErrorMessage);
                            return;
                        }
                        const resolvedOrderId = parseInt( orderId, 10 );
                        if ( ! Number.isFinite( resolvedOrderId ) || resolvedOrderId <= 0 ) {
                            console.error('PayXpert: missing order id for payment status polling.');
                            return;
                        }

                        const maxAttempts = payxpertSettings.maxPollAttempts || 30;

                        fetch("{$ajaxUrl}", {
                            method: "POST",
                            headers: {
                                "Content-Type": "application/x-www-form-urlencoded",
                            },
                            body: new URLSearchParams({
                                action: "payxpert_handle_payment_result",
                                order_id: String( resolvedOrderId ),
                                transactionID: response.transaction.transactionID,
                                paymentID: response.transaction.paymentID,
                                security: nonce
                            })
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success && data.data && data.data.pending) {
                                if (attempt < maxAttempts) {
                                    if (attempt === 1 || attempt % 5 === 0) {
                                        console.info(payxpertSettings.paymentPendingMessage);
                                    }
                                    setTimeout(
                                        () => sendPaymentResult(response, nonce, resolvedOrderId, attempt + 1),
                                        1000
                                    );
                                    return;
                                }
                                alert(payxpertSettings.paymentConfirmTimeoutMessage);
                                return;
                            }
                            if (data.success && data.data && data.data.urlRedirect) {
                                window.location.href = data.data.urlRedirect;
                            } else if (attempt < 3) {
                                console.warn(payxpertSettings.attemptFailedRetryingMessage.replace('%d', attempt));
                                setTimeout(
                                    () => sendPaymentResult(response, nonce, resolvedOrderId, attempt + 1),
                                    1000
                                );
                            } else {
                                alert((data.data && data.data.message) || payxpertSettings.genericErrorMessage);
                            }
                        })
                        .catch((error) => {
                            console.error(payxpertSettings.ajaxRequestFailedMessage, error);
                            if (attempt < 3) {
                                console.warn(payxpertSettings.retryingAjaxRequestMessage.replace('%d', (attempt + 1)));
                                setTimeout(
                                    () => sendPaymentResult(response, nonce, resolvedOrderId, attempt + 1),
                                    1000
                                );
                            } else {
                                alert(payxpertSettings.communicationErrorMessage);
                            }
                        });
                    }

                    function getSelectedPaymentMethod() {
                        let input = document.querySelector('input[name="radio-control-wc-payment-method-options"]:checked');

                        if (!input) {
                            // pay_for_order use case
                            input = document.querySelector('input[name="payment_method"]:checked');
                        }

                        return input ? input.value : null;
                    }

                    function resetAllSeamlessSubmitButtons() {
                        const buttons = document.querySelectorAll('.payxpert_seamless_hidden_submit');

                        buttons.forEach(oldButton => {
                            const newButton = oldButton.cloneNode(true); // Clone without event listeners.

                            // Preserve id, classes, and inner HTML.
                            newButton.id = oldButton.id;
                            newButton.className = oldButton.className;
                            newButton.innerHTML = oldButton.innerHTML;

                            // Replace the old node to drop listeners.
                            oldButton.parentNode.replaceChild(newButton, oldButton);
                        });
                    }

                    function resetPlaceOrderButton() {
                        const oldButton = document.getElementById('place_order');
                        if (!oldButton) return;

                        const newButton = oldButton.cloneNode(true); // Clone without event listeners.

                        // Preserve id and classes.
                        newButton.id = 'place_order';
                        newButton.className = oldButton.className;

                        // Copy inner HTML (e.g. icon or label).
                        newButton.innerHTML = oldButton.innerHTML;

                        // Replace old button node.
                        oldButton.parentNode.replaceChild(newButton, oldButton);
                    }

                    function triggerPayxpertPaymentCall() {
				        console.log(payxpertSettings.triggerPaymentMessage);

                        const submitBtn = document.getElementById('payxpert-internal-submit-' + getSelectedPaymentMethod());
                        if (submitBtn) {
                            submitBtn.style.display = 'block';

                            setTimeout(() => {
                                submitBtn.click();
                                submitBtn.style.display = 'none';
                            }, 20);
                        } else {
                            alert(payxpertSettings.paymentMethodErrorMessage);
                        }
                    }

                    window.mountPayxpertIframe = mountPayxpertIframe;
                    window.togglePlaceOrder = togglePlaceOrder;
                    window.triggerPayxpertPaymentCall = triggerPayxpertPaymentCall;

                    jQuery(document.body).on('updated_checkout', function() {
                        payxpertClassicCheckoutTriggeredForOrder = 0;
                        resetAllSeamlessSubmitButtons();   

                        // Remove mounted iframe containers from placeholders.
                        document.querySelectorAll('.payxpert-placeholder').forEach(placeholder => {
                            placeholder.querySelectorAll('div[id^="payment-container-"]').forEach(el => {
                                el.remove();
                            });
                        });
           
                        resetPlaceOrderButton();
                        togglePlaceOrder();
                        mountPayxpertIframe();
                    });

                    jQuery(document).on('validForm', function() {
                        jQuery('#place_order').removeAttr("disabled");
                    });
                    
                    jQuery(document.body).on('change', 'input[name="payment_method"]', function() {
                        resetAllSeamlessSubmitButtons();

                        // Remove mounted iframe containers from placeholders.
                        document.querySelectorAll('.payxpert-placeholder').forEach(placeholder => {
                            placeholder.querySelectorAll('div[id^="payment-container-"]').forEach(el => {
                                el.remove();
                            });
                        });
                        resetPlaceOrderButton();
                        togglePlaceOrder();
                        mountPayxpertIframe();
                    });

                    function isPayxpertSeamlessCheckoutResponse( response ) {
                        if ( ! response || ! response.order_id ) {
                            return false;
                        }
                        const selectedMethod = getSelectedPaymentMethod();
                        if ( ! selectedMethod || ! selectedMethod.includes( 'payxpert' ) ) {
                            return false;
                        }
                        if ( response.result === 'success' ) {
                            const redirect = response.redirect || '';
                            return (
                                redirect.indexOf( 'payxpert-seamless' ) !== -1
                                || redirect.charAt( 0 ) === '#'
                            );
                        }
                        if ( response.result !== 'failure' || ! response.messages ) {
                            return false;
                        }
                        const messageHTML = response.messages.toLowerCase();
                        const processingHints = [
                            'processing payment',
                            'paiement en cours',
                            'procesando',
                            'pagamento in corso',
                            'zahlung wird ausgeführt',
                            'verarbeitung',
                            'betaling wordt verwerkt',
                            'processamento',
                        ];
                        return processingHints.some( ( hint ) => messageHTML.indexOf( hint ) !== -1 );
                    }

                    let payxpertClassicCheckoutTriggeredForOrder = 0;

                    function handleClassicCheckoutPayxpertResponse( response ) {
                        if ( ! isPayxpertSeamlessCheckoutResponse( response ) ) {
                            return;
                        }
                        const orderId = parseInt( response.order_id, 10 );
                        if ( payxpertClassicCheckoutTriggeredForOrder === orderId ) {
                            return;
                        }
                        payxpertClassicCheckoutTriggeredForOrder = orderId;
                        window.setPayxpertCheckoutOrderId( orderId );
                        togglePlaceOrder();
                        triggerPayxpertPaymentCall();
                    }

                    jQuery( document.body ).on( 'checkout_place_order_success', function ( event, result ) {
                        if ( ! isPayxpertSeamlessCheckoutResponse( result ) ) {
                            return;
                        }
                        handleClassicCheckoutPayxpertResponse( result );
                        return false;
                    } );

                    jQuery( document ).ajaxComplete( function ( event, xhr, settings ) {
                        const requestUrl = settings && settings.url ? settings.url : '';
                        if ( requestUrl.indexOf( 'wc-ajax=checkout' ) === -1 ) {
                            return;
                        }

                        let response = xhr.responseJSON;
                        if ( ! response && xhr.responseText ) {
                            try {
                                response = JSON.parse( xhr.responseText );
                            } catch ( e ) {
                                return;
                            }
                        }

                        handleClassicCheckoutPayxpertResponse( response );
                    } );

                    addIdToPlaceOrderButton();
                    togglePlaceOrder();
                    mountPayxpertIframe();
                    
JS
                );

                wp_enqueue_script('payxpert-seamless');
            }
        }

        if (is_checkout_pay_page() && $this->seamless) {
            if (!wp_script_is('payxpert-seamless-payfororder', 'enqueued')) {

                wp_register_script(
                    'payxpert-seamless-payfororder',
                    '', // set Dynamicaly
                    [ 'jquery' ],
                    null,
                    true
                );

                wp_add_inline_script('payxpert-seamless-payfororder', <<<JS
                    document.addEventListener('DOMContentLoaded', function () {
                        if (!window.location.href.includes('pay_for_order=true')) return;

                        const form = document.getElementById('order_review');
                        if (!form) return;

                        form.addEventListener('submit', function (e) {
                            const method = document.querySelector('input[name="payment_method"]:checked')?.value;
                            if (method && method.includes('payxpert')) {
                                e.preventDefault();
                                e.stopImmediatePropagation();
                                console.log('[PayXpert] Blocking native submit on pay_for_order for PayXpert');
                                // IFRAME is leading

                                triggerPayxpertPaymentCall();
                            }
                        }, true);

                        mountPayxpertIframe();
                    });

JS
                );

                wp_enqueue_script('payxpert-seamless-payfororder');
            }
        }
    }

    public function process_payment_seamless($order_id) 
    {
        WC_Payxpert_Logger::info('SEAMLESS');

        global $wpdb;
        $customer_token = sanitize_text_field($_POST[$this->id . '_customer_token'] ?? '');

        $payment_token = Payxpert_Payment_Token::findByCustomerToken($customer_token);

        if (is_null($payment_token)) {
            WC_Payxpert_Logger::info('Payment TOKEN not found');

            return [
                'result' => 'failure',
                'message' => __('Payment token not found', 'payxpert')
            ];
        }

        if ($payment_token['order_id'] != $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                return [
                    'result' => 'failure',
                    'message' => __('Order not found', 'payxpert'),
                ];
            }

            $token_uid = (int) $payment_token['user_id'];
            $order_uid = (int) $order->get_customer_id();
            if ($token_uid !== $order_uid && !($token_uid === 0 && $order_uid === 0)) {
                WC_Payxpert_Logger::critical('Seamless token user mismatch for order update');
                return [
                    'result' => 'failure',
                    'message' => __('Payment token does not match this order.', 'payxpert'),
                ];
            }

            WC_Payxpert_Logger::info(
                sprintf(
                    'Update token<-->order by customer token: [token=%s, order_id=%d]',
                    $this->maskTokenForLogs($customer_token),
                    (int) $order_id
                )
            );
            // Update Payxpert_Payment_Token.order_id in DB with real wc_order_id
            $wpdb->update(
                $wpdb->prefix . Payxpert_Payment_Token::TABLE_NAME,
                ['order_id' => $order_id],
                [
                    'customer_token' => $customer_token
                ],
                ['%d'],
                ['%s']
            );

            // Classic checkout only includes order_id in AJAX when result is "success".
            return [
                'result'   => 'success',
                'redirect' => '#payxpert-seamless-payment',
            ];
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return [
                'result' => 'failure',
                'message' => __('Order not found', 'payxpert'),
            ];
        }

        if ( in_array( $order->get_status(), [ 'processing', 'completed' ], true ) ) {
            WC_Payxpert_Logger::info('Redirect on order_received_url');

            return [
                'result' => 'success',
                'redirect' => $order->get_checkout_order_received_url()
            ];
        }
        
        WC_Payxpert_Logger::info('Token already updated & order not complete yet');
        return;
    }

    public function process_payment_cc($gateway, $order_id, $payment_method, $payment_mode, $instalment_parameters = []) 
    {
        WC_Payxpert_Logger::info('Process payment call');

        if ( $this->seamless ) {
            return $this->process_payment_seamless($order_id);
        }

        WC_Payxpert_Logger::info('REDIRECTION');
        
        // Redirect to PayXpert gateway
        $preparedPayment = WC_Payxpert_Webservice::preparePayment(
            $this->configuration,
            $gateway,
            $payment_method,
            $payment_mode,
            wc_get_order($order_id),
            $instalment_parameters,
            false
        );

        if (isset($preparedPayment['error'])) {
            wc_add_notice(__('An error occurred while trying to generate the payment link. Please try again or contact support.', 'payxpert'), 'error');
            WC_Payxpert_Logger::critical(
                sprintf( __( 'PayXpert API error: %s', 'payxpert' ), $preparedPayment['error'] )
            );

            return ['result' => 'failure'];
        }

        return [
            'result'   => 'success',
            'redirect' => $preparedPayment['redirectUrl'],
        ];
    }

    /**
     * Prepares seamless checkout data (token, nonce, order context). Overridden per gateway.
     *
     * @param \WC_Order|null $order
     * @return array<string, mixed>|null
     */
    public function get_seamless_data( $order = null ) {
        return null;
    }

    /**
     * Builds seamless iframe payload from a preparePayment() response.
     * Gateways call this via get_seamless_data( $order ) after preparePayment().
     *
     * @param array<string, mixed> $preparedPayment
     * @param \WC_Order|null       $order
     * @return array<string, mixed>|null
     */
    protected function assemble_seamless_data( $preparedPayment, $order = null ) {
        if (isset($preparedPayment['error'])) {
            WC_Payxpert_Logger::critical($preparedPayment['error']);
            return [
                'error' => __('An error occurred during payment preparation.', 'payxpert')
            ];
        }

        $locale = determine_locale();
        $lang = substr($locale, 0, 2);
        $seamless_language = in_array($lang, ['fr', 'es'], true) ? $lang : 'en';

        $order_id = ( $order instanceof \WC_Order ) ? (int) $order->get_id() : 0;

        return [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'locale' => $locale,
            'language' => $seamless_language,
            'customerToken' => $preparedPayment['customerToken'],
            'nonce' => wp_create_nonce( WC_Payxpert::payment_result_nonce_action( $order_id ) ),
            'prefix' => $this->id,
            'seamlessSubmit' => 'payxpert-internal-submit-' . $this->id,
        ];
    }

    public function process_refund($order_id, $amount = null, $reason = '') {
        WC_Payxpert_Logger::info('Process refund on order ID : ' . (int) $order_id);
        
        $order = wc_get_order($order_id);
        if (!$order || !$amount) {
            WC_Payxpert_Logger::info('Order or Amount to refund not found');
            return false;
        }

        $amount_to_refund = (int) round($amount * 100);
        $refunded_total = 0;

        $orderTransactions = Payxpert_Payment_Transaction::findAllByOrderId($order->get_id());
        $orderTransactionsFormatted = WC_Payxpert_Utils::get_order_transactions_formatted($orderTransactions);

        if (empty($orderTransactionsFormatted['refundable'])) {
            return new WP_Error('no_refundable_tx', __('No refundable transactions found for this order.', 'payxpert'));
        }

        foreach ($orderTransactionsFormatted['refundable'] as $tx) {
            $refundable = (int) round($tx['refundable_amount'] * 100);
            if ($refundable <= 0) {
                continue;
            }

            $to_refund = min($amount_to_refund - $refunded_total, $refundable);

            if ($to_refund <= 0) {
                break;
            }

            $isSubscriptionOccurrence = false;
            if ($tx['subscription_id']) {
                $subscription = Payxpert_Subscription::findBySubscriptionId($tx['subscription_id']);
                if ($subscription && in_array($subscription['subscription_type'], [SubscriptionType::INFINITE, SubscriptionType::LIFETIME, SubscriptionType::NORMAL])) {
                    $isSubscriptionOccurrence = true;
                }
            }

            try {
                $response = WC_Payxpert_Webservice::refund_transaction(
                    $this->configuration, 
                    $tx['transaction_id'], 
                    $to_refund,
                    $isSubscriptionOccurrence
                );
    
                if (isset($response['error'])) {
                    WC_Payxpert_Logger::critical("[{$tx['transaction_id']}] " . $response['error']);
                    return new WP_Error('refund_failed', __('An error occurred during the refund process: ', 'payxpert') . $response['error']);
                }

                if (Payxpert_Payment_Transaction::RESULT_CODE_SUCCESS !== $response['code']) {
                    WC_Payxpert_Logger::critical("[{$tx['transaction_id']}] " .$response['message']);
                    return new WP_Error('refund_failed', __('An error occurred during the refund process: ', 'payxpert') . $response['message']);
                }

                WC_Payxpert_Logger::info('Refund transaction : ' . $response['transaction_id']);
                $tx_info = WC_Payxpert_Webservice::get_transaction_info($this->configuration, $response['transaction_id'], $isSubscriptionOccurrence);
                $payxpert_payment_transaction = new Payxpert_Payment_Transaction();
                $payxpert_payment_transaction->set($tx_info);
                $payxpert_payment_transaction->set([
                    'order_id' => $order_id,
                    'transaction_referal_id' => $tx['transaction_id']
                ]);
                $payxpert_payment_transaction->save();

                $order->add_order_note(
                    sprintf(
                        __('Refunded %s %s from transaction %s', 'payxpert'), 
                        wc_price($to_refund / 100), 
                        get_woocommerce_currency(), 
                        $tx['transaction_id']
                    )
                );

                $refunded_total += $to_refund;
            } catch (Exception $e) {
                return new WP_Error('refund_exception', __('Exception during refund: ', 'payxpert') . $e->getMessage());
            }

            if ($refunded_total >= $amount_to_refund) {
                break;
            }
        }

        return true;
    }

    private function maskTokenForLogs(string $token): string
    {
        if ($token === '') {
            return '[empty]';
        }

        return substr($token, 0, 6) . '***';
    }
}
