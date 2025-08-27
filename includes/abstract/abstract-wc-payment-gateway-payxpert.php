<?php

use Payxpert\Exception\ConfigurationNotFoundException;
use Payxpert\Exception\HandleFailedException;
use Payxpert\Exception\HashFailedException;
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
	public $configuration;
	public $seamless;
	
	public function admin_options()
	{
		wp_redirect(admin_url('admin.php?page=payxpert-settings'));
        exit;
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
                throw new Exception('Missing data, customer or secureToken');
            }

            $payment_token = Payxpert_Payment_Token::findByCustomerToken($customer_token);

            if (!$payment_token) {
                throw new Exception('No paymentToken found for customerToken: ' . substr($customer_token, 0, 50));
            }

            // Handle redirection result via your API/WebService
            $result = WC_Payxpert_Webservice::handleRedirect(
                $this->configuration, 
                $payment_token['merchant_token'], 
                $data
            );

            $order = wc_get_order($payment_token['order_id']);

            if (!$order) {
                throw new Exception('Order not found');
            }

            switch ($result['errorCode']) {
                case Payxpert_Payment_Transaction::RESULT_CODE_SUCCESS:
                    $redirect_url = $order->get_checkout_order_received_url();
                    break;

                case Payxpert_Payment_Transaction::RESULT_CODE_CANCEL:
                    $redirect_url = wc_get_checkout_url();
                    break;

                default:
                    $redirect_url = $order->get_view_order_url();
                break;
            }

        } catch (Exception $e) {
            WC_Payxpert_Logger::critical($e->getMessage());
            $redirect_url = wc_get_page_permalink('cart');
        } finally {
            wp_safe_redirect($redirect_url);
            exit;
        }
    }

    private function handleIPN()
    {
        WC_Payxpert_Logger::info('IPN');
        $responseStatus = 'OK';
        $responseMessage = 'Payment status recorded';

        try {
            if (empty($this->configuration['payxpert_originator_id']) || empty($this->configuration['payxpert_password'])) {
                throw new ConfigurationNotFoundException();
            }

            $handle = WC_Payxpert_Webservice::handleCallback($this->configuration);

            if (!$handle) {
                throw new HandleFailedException();
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

            $customData = [];

            if (isset($handle['customData'])) {
                parse_str($handle['customData'], $customData);
            }

            $order_id = $paymentToken['order_id'];

            if ($order_id == 0) {
                throw new PaymentTokenExpiredException();
            }

            $order = wc_get_order($order_id);

            $status = 'processing';

            if ($this->configuration['payxpert_capture_mode'] == WC_Payxpert::CAPTURE_MODE_MANUAL) {
                $status = WC_Payxpert::ORDER_STATUS_CAPTURE_PENDING;
            }

			if (isset($customData['status'])) {
                $status = $customData['status'];
            }

            if ($handle['errorCode'] === Payxpert_Payment_Transaction::RESULT_CODE_SUCCESS) {
                switch ($status) {
                    case 'processing': 
                        $order->payment_complete($handle['transaction']->getTransactionID());
                    break;

                    case WC_Payxpert::ORDER_STATUS_INSTALLMENT_PENDING:
                        $order->set_transaction_id($handle['transaction']->getTransactionID());
                        $order->add_meta_data('payxpert_installment', true);
                    break;

                    case WC_Payxpert::ORDER_STATUS_CAPTURE_PENDING:
                        $order->add_meta_data('payxpert_capture', true);
                        if ($customData['status'] == WC_Payxpert::ORDER_STATUS_INSTALLMENT_PENDING) {
                            $order->add_meta_data('payxpert_installment', true);
                        }
                    break;
                }
                WC()->cart->empty_cart();
            } else {
                $status = 'failed';
            }
            
            $order->add_meta_data('payxpert_transaction_id', $handle['transaction']->getTransactionID());
            $order->add_meta_data('payxpert_payment_id', $handle['transaction']->getPaymentID());
           
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

            $order->update_status($status, __('PayXpert transaction received.', 'payxpert'));

            $transaction = $handle['transaction'];
            $paymentMeanInfo = $transaction->getPaymentMeanInfo();
            $subscriptionID = $transaction->getSubscriptionID();

            $payxpertPaymentTransaction = new Payxpert_Payment_Transaction();
            $payxpertPaymentTransaction->set([
                'id_shop'               => get_current_blog_id(),
                'transaction_id'        => $transaction->getTransactionId(),
                'transaction_referal_id'=> $transaction->getRefTransactionID(),
                'order_id'              => $order->get_id(),
                'payment_id'            => $transaction->getPaymentID(),
                'liability_shift'       => $paymentMeanInfo && method_exists($paymentMeanInfo, 'getIs3DSecure') ? (bool) $paymentMeanInfo->getIs3DSecure() : false,
                'payment_method'        => $transaction->getPaymentMethod(),
                'operation'             => $transaction->getOperation(),
                'amount'                => number_format($transaction->getAmount() / 100, 2, '.', ''),
                'currency'              => $transaction->getCurrency(),
                'result_code'           => $transaction->getResultCode(),
                'result_message'        => $transaction->getResultMessage(),
                'subscription_id'       => $subscriptionID,
            ]);
            $payxpertPaymentTransaction->save();

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
            http_response_code(500);
            $responseStatus = 'KO';
            $responseMessage = 'Callback validation failed';
        } finally {
            WC_Payxpert_Logger::info('IPN END');
            header('Content-type: application/json');
            wp_send_json([
                'status' => $responseStatus,
                'message' => $responseMessage,
            ]);
            exit;
        }
    }

    public function add_scripts()
    {
        if (is_checkout() && $this->seamless) {
            $ajaxUrl = admin_url('admin-ajax.php');

            if (!wp_script_is('payxpert-seamless', 'enqueued')) {
                wp_register_script(
                    'payxpert-seamless',
                    '', // set Dynamicaly
                    ['jquery'],
                    null,
                    true
                );

                wp_add_inline_script('payxpert-seamless', <<<JS
                    function mountPayxpertIframe(intercept = true) {
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
                                hideCardHolderName: true
                            });
                            configScript.setAttribute('data-payxpert-script', 1);

                            div.appendChild(configScript);
                            placeholder.appendChild(div);

                            const script = document.createElement('script');
                            script.src = "https://connect2.payxpert.com/payment/" + token + "/connect2pay-seamless-v1.5.0.js";
                            script.async = true;
                            script.setAttribute('data-mount-in', "#" + mountId);
                            script.setAttribute('integrity', 'sha384-0IS2bunsGTTsco/UMCa56QRukMlq2pEcjUPMejy6WspCmLpGmsD3z0CmF5LQHF5X');
                            script.setAttribute('crossorigin', 'anonymous');
                            script.setAttribute('data-payxpert-script', 1);

                            document.body.appendChild(script);
                        }

                        // Vérifie Apple Pay uniquement si activé dans la config PHP
                        const applePayEnabled = ($this->configuration['payxpert_applepay_enabled'] === 'yes' ? 'true' : 'false');

                        if (applePayEnabled && typeof sdpx !== 'undefined' && typeof sdpx.isApplePayAvailable === 'function') {
                            sdpx.isApplePayAvailable().then((response) => {
                                const available = response?.responseCode === "00";
                                buildIframe(available);
                            }).catch((err) => {
                                console.warn('ApplePay check failed');
                                buildIframe(false);
                            });
                        } else {
                            buildIframe(false);
                        }

                        if (intercept) {
                            interceptPlaceOrderClickForPayxpert();
                        }
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
                        if (response.statusCode !== 200) {
                            console.error("Payment failed");
                            console.error(response);
                            return;
                        }

                        const selectedMethod = getSelectedPaymentMethod();
                        const container = document.querySelector('.payxpert-seamless-container[data-method="' + selectedMethod + '"]');

                        if (!container) {
                            console.error('Cannot find container for method: ' + selectedMethod);
                            return;
                        }

                        const nonceInput = container.querySelector('.payxpert_nonce');
                        const nonce = nonceInput ? nonceInput.value : null;
                        sendPaymentResult(response, nonce);
                    }

                    function sendPaymentResult(response, nonce, attempt = 1) {
                        if (!nonce) {
                            console.error("Missing nonce for payment result.");
                            return;
                        }

                        fetch("{$ajaxUrl}", {
                            method: "POST",
                            headers: {
                                "Content-Type": "application/x-www-form-urlencoded",
                            },
                            body: new URLSearchParams({
                                action: "payxpert_handle_payment_result",
                                transactionID: response.transaction.transactionID,
                                paymentID: response.transaction.paymentID,
                                security: nonce
                            })
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                window.location.href = data.data.urlRedirect;
                            } else {
                                if (attempt < 3) {
                                    console.warn(`Attempt ` + attempt + ` failed, retrying...`);
                                    setTimeout(() => sendPaymentResult(response, nonce, attempt + 1), 1000);
                                } else {
                                    alert(data.data.message || "Une erreur est survenue.");
                                }
                            }
                        })
                        .catch((error) => {
                            console.error("AJAX request failed:", error);
                            if (attempt < 3) {
                                console.warn(`Retrying AJAX request (attempt ` + (attempt+1) + `)...`);
                                setTimeout(() => sendPaymentResult(response, nonce, attempt + 1), 1000);
                            } else {
                                alert("Impossible de communiquer avec le serveur.");
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
                            const newButton = oldButton.cloneNode(true); // Clône sans les event listeners

                            // Conserve id, classes, et contenu HTML
                            newButton.id = oldButton.id;
                            newButton.className = oldButton.className;
                            newButton.innerHTML = oldButton.innerHTML;

                            // Remplace l'ancien bouton par le nouveau
                            oldButton.parentNode.replaceChild(newButton, oldButton);
                        });
                    }

                    function resetPlaceOrderButton() {
                        const oldButton = document.getElementById('place_order');
                        if (!oldButton) return;

                        const newButton = oldButton.cloneNode(true); // Clône sans event listeners

                        // Conserve l'id et les classes
                        newButton.id = 'place_order';
                        newButton.className = oldButton.className;

                        // Si besoin, copie les inner HTML (comme l’icône ou le texte)
                        newButton.innerHTML = oldButton.innerHTML;

                        // Remplace l’ancien bouton
                        oldButton.parentNode.replaceChild(newButton, oldButton);
                    }

                    function interceptPlaceOrderClickForPayxpert() {
                        const placeOrderBtn = document.querySelector('#place_order');
                        if (!placeOrderBtn) return;

                        // On évite les doublons
                        placeOrderBtn.addEventListener('click', function handleClick(e) {
                            const selectedMethod = getSelectedPaymentMethod();
                            if (selectedMethod && selectedMethod.includes('payxpert')) {
                                e.preventDefault();
                                e.stopImmediatePropagation();
                                console.log('[PayXpert] Intercept WooCommerce place_order click for:', selectedMethod);

                                const submitBtn = document.getElementById('payxpert-internal-submit-' + selectedMethod);
                                if (submitBtn) {
                                    submitBtn.style.display = 'block';

                                    setTimeout(() => {
                                        submitBtn.click();
                                        submitBtn.style.display = 'none';

                                        setTimeout(() => {
                                            const retryBtn = document.querySelector('#place_order');
                                            if (retryBtn) {
                                                console.log('[PayXpert] Re-trigger WooCommerce order submission');
                                                retryBtn.click(); // ⚠️ pas besoin de removeEventListener ici
                                            }
                                        }, 100);
                                    }, 20);
                                }
                            }
                        }, { once: true }); // `once` = on n'intercepte qu'une seule fois
                    }

                    jQuery(document.body).on('updated_checkout', function() {
                        resetAllSeamlessSubmitButtons();   

                        // Supprimer les anciens iframes montés dans les placeholders
                        document.querySelectorAll('.payxpert-placeholder').forEach(placeholder => {
                            placeholder.querySelectorAll('div[id^="payment-container-"]').forEach(el => {
                                el.remove();
                            });
                        });
           
                        resetPlaceOrderButton();
                        togglePlaceOrder();
                        mountPayxpertIframe();
                    });
                    
                    jQuery(document.body).on('change', 'input[name="payment_method"]', function() {
                        resetAllSeamlessSubmitButtons();

                        // Supprimer les anciens iframes montés dans les placeholders
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
                                console.log('[PayXpert] Blocage du submit pour payxpert sur pay_for_order');
                                // IFRAME is leading
                            }
                        }, true);
                    });

JS
                );

                wp_enqueue_script('payxpert-seamless-payfororder');
            }
        }
    }

    public function process_payment_seamless($order_id) 
    {
        global $wpdb;
        $merchant_token = sanitize_text_field($_POST[$this->id . '_merchant_token'] ?? '');
        $customer_token = sanitize_text_field($_POST[$this->id . '_customer_token'] ?? '');

        $payment_token = Payxpert_Payment_Token::findByMerchantTokenAndCustomerToken($merchant_token, $customer_token);

        if (is_null($payment_token)) {
            return [
                'result' => 'failure',
                'message' => __('Payment token not found')
            ];
        }

        if ($payment_token['order_id'] != $order_id) {
            wc_add_notice( __('Progressing Payment....'), 'notice');

            // Update Payxpert_Payment_Token.order_id in DB with real wc_order_id
            $wpdb->update(
                $wpdb->prefix . Payxpert_Payment_Token::TABLE_NAME,
                ['order_id' => $order_id],
                [
                    'merchant_token' => $merchant_token,
                    'customer_token' => $customer_token
                ],
                ['%d'],
                ['%s', '%s']
            );

            return ['result'  => 'pending'];
        }

        $order = wc_get_order($order_id);
        if ( in_array( $order->get_status(), [ 'processing', 'completed' ], true ) ) {
            return [
                'result' => 'success',
                'redirect' => $order->get_checkout_order_received_url()
            ];
        }
        
        return;
    }

    public function process_payment_cc($gateway, $order_id, $payment_method, $payment_mode, $instalment_parameters = []) 
    {
        if ( $this->seamless ) {
			return $this->process_payment_seamless($order_id);
		} 
		
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
            wc_add_notice(__('An error occured while trying to generate the payment link. Please try again or contact the support.', 'payxpert'), 'error');
            WC_Payxpert_Logger::error('Erreur API PayXpert : ' . $preparedPayment['error']);

            return ['result' => 'failure'];
        }

        return [
            'result'   => 'success',
            'redirect' => $preparedPayment['redirectUrl'],
        ];
    }

    public function get_seamless_data($preparedPayment)
    {
        if (isset($preparedPayment['error'])) {
            WC_Payxpert_Logger::critical($preparedPayment['error']);
            return [
                'error' => __('An error occurred during payment preparation.', 'payxpert')
            ];
        }

        $locale = determine_locale();
        return [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'locale' => $locale,
            'language' => substr($locale, 0, 2) == 'fr' ? 'fr' : 'en',
            'customerToken' => $preparedPayment['customerToken'],
            'merchantToken' => $preparedPayment['merchantToken'],
            'nonce' => wp_create_nonce('payxpert_payment_nonce'),
            'prefix' => $this->id,
            'seamlessSubmit' => 'payxpert-internal-submit-' . $this->id,
        ];
    }

    public function process_refund($order_id, $amount = null, $reason = '') {
        $order = wc_get_order($order_id);
        if (!$order || !$amount) {
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

            try {
                $response = WC_Payxpert_Webservice::refund_transaction($this->configuration, $tx['transaction_id'], $to_refund);

                if (isset($response['error'])) {
                    WC_Payxpert_Logger::critical("[{$tx['transaction_id']}] " . $response['error']);
                    return new WP_Error('refund_failed', __('An error occured during the refund process : ', 'payxpert') . $response['error']);
                }

                if (Payxpert_Payment_Transaction::RESULT_CODE_SUCCESS !== $response['code']) {
                    WC_Payxpert_Logger::critical("[{$tx['transaction_id']}] " .$response['message']);
                    return new WP_Error('refund_failed', __('An error occured during the refund process : ', 'payxpert') . $response['message']);
                }

                $tx_info = WC_Payxpert_Webservice::get_transaction_info($this->configuration, $response['transaction_id']);
                
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
}
