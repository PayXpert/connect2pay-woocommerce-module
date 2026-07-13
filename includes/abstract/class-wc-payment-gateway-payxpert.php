<?php
/**
 * Wc Payment Gateway Payxpert.
 *
 * @package Payxpert
 */

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

/**
 * WC Payment Gateway Payxpert.
 */
abstract class WC_Payment_Gateway_Payxpert extends WC_Payment_Gateway {
	const DEFAULT_SEAMLESS_VERSION = '1.6.1';
	const DEFAULT_SEAMLESS_HASH    = 'BRCgrkMLcVR8+KMJHI+9i9/QUuB1pF20M026MUoWXggnL1OKRKg9latpzAkuXz25';

	/**
	 * Configuration.
	 *
	 * @var mixed
	 */
	public $configuration;
	/**
	 * Seamless.
	 *
	 * @var mixed
	 */
	public $seamless;

	/**
	 * Admin options.
	 *
	 * @return mixed
	 */
	public function admin_options() {
		$template_path = plugin_dir_path( __FILE__ ) . '../../templates/views/html-admin-options.php';

		if ( file_exists( $template_path ) ) {
			include $template_path;
		}
	}

	/**
	 * WordPress action wrapper for {@see process_admin_options()}.
	 */
	public function save_admin_options(): void {
		$this->process_admin_options();
	}

	/**
	 * Process admin options.
	 */
	public function process_admin_options(): bool {
		return parent::process_admin_options();
	}

	/**
	 * Callback.
	 */
	public function callback(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Gateway callback endpoint reached via PayXpert server-to-server / redirect, authenticated by payment token and IPN signature, not a WP nonce.
		if ( isset( $_GET['payxpert_return'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['payxpert_return'] ) ) ) {
			$this->handle_front_redirect();
		} else {
			$this->handle_ipn();
		}
	}

	/**
	 * HandleFrontRedirect.
	 *
	 * @throws \Exception If the redirect payload cannot be processed.
	 */
	public function handle_front_redirect(): void {
		WC_Payxpert_Logger::info( 'FrontRedirect' );

		$redirect_url = wc_get_page_permalink( 'checkout' );

		try {
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- PayXpert posts the payment result back on redirect; the request is authenticated by the customer payment token below, not a WP nonce.
			$data           = isset( $_POST['data'] ) ? sanitize_text_field( wp_unslash( $_POST['data'] ) ) : null;
			$customer_token = isset( $_POST['customer'] ) ? sanitize_text_field( wp_unslash( $_POST['customer'] ) ) : null;
			// phpcs:enable WordPress.Security.NonceVerification.Missing

			if ( ! $data || ! $customer_token ) {
				throw new Exception( __( 'Missing data, customer or secure token.', 'payxpert' ) );
			}

			$payment_token = Payxpert_Payment_Token::find_by_customer_token( $customer_token );

			if ( ! $payment_token ) {
				throw new Exception(
					sprintf(
						// translators: %s is the customer token.
						__( 'No payment token found for customer token: %s', 'payxpert' ),
						substr( $customer_token, 0, 50 )
					)
				);
			}

			// Handle redirection result via your API/WebService.
			$result = WC_Payxpert_Webservice::handle_redirect(
				$this->configuration,
				$payment_token['merchant_token'],
				$data
			);

			$order = wc_get_order( $payment_token['order_id'] );

			if ( ! $order ) {
				throw new Exception( __( 'Order not found.', 'payxpert' ) );
			}

			// Extra ownership check for authenticated customers.
			if ( is_user_logged_in() ) {
				$order_user_id = (int) $order->get_user_id();
				if ( $order_user_id > 0 && (int) get_current_user_id() !== $order_user_id ) {
					throw new Exception( __( 'Unauthorized order.', 'payxpert' ) );
				}
			}

			switch ( $result['errorCode'] ) {
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
							array(
								'payxpert_payment' => 'failed',
							),
							$order->get_checkout_payment_url()
						);
					}
					break;
			}
		} catch ( Exception $e ) {
			WC_Payxpert_Logger::critical( $e->getMessage() );
			$redirect_url = wc_get_page_permalink( 'cart' );
		}

		WC_Payxpert_Logger::info( 'Redirect to : ' . $redirect_url );
		$safe_redirect_url = esc_url_raw( $redirect_url );
		if ( empty( $safe_redirect_url ) ) {
			$safe_redirect_url = wc_get_page_permalink( 'checkout' );
		}

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		// For seamless + 3DS flows, the return can happen inside an iframe.
		// Force redirect on the top window so checkout does not remain framed.
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		echo '<!doctype html><html><head><meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '">';
		echo '<meta http-equiv="X-UA-Compatible" content="IE=edge">';
		echo '</head><body>';
		echo '<script>(function(){var u=' . wp_json_encode( $safe_redirect_url ) . ';try{if(window.top&&window.top!==window.self){window.top.location.href=u;return;}}catch(e){}window.location.href=u;})();</script>';
		echo '<noscript><meta http-equiv="refresh" content="0;url=' . esc_url( $safe_redirect_url ) . '"></noscript>';
		echo '</body></html>';
		exit;
	}

	/**
	 * HandleIPN.
	 *
	 * @throws ConfigurationNotFoundException If the gateway configuration is missing.
	 * @throws HandleFailedException If the payment handle cannot be retrieved.
	 * @throws PaymentCancellationException If the payment was cancelled.
	 * @throws PaymentTokenNotFoundException If the payment token cannot be found.
	 * @throws PaymentTokenExpiredException If the payment token has expired.
	 * @throws Exception For duplicate notifications or a missing order.
	 */
	private function handle_ipn(): void {
		WC_Payxpert_Logger::info( 'IPN' );
		$response_status  = 'OK';
		$response_message = __( 'Payment status recorded.', 'payxpert' );

		try {
			if ( empty( $this->configuration['payxpert_originator_id'] ) || empty( $this->configuration['payxpert_password'] ) ) {
				throw new ConfigurationNotFoundException();
			}

			$this->assert_valid_ipn_secret();

			$handle = WC_Payxpert_Webservice::handle_callback( $this->configuration );

			if ( ! $handle ) {
				throw new HandleFailedException();
			}

			$logs = $handle;
			Payxpert_Payment_Transaction::anonymize_recursive( $logs );
			WC_Payxpert_Logger::info(
				wp_json_encode(
					$logs,
					JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
				)
			);

			$transaction     = $handle['transaction'];
			$transaction_id  = $transaction->getTransactionID();
			$subscription_id = $transaction->getSubscriptionID();

			if ( Payxpert_Payment_Transaction::find_by_transaction_id( $transaction_id ) ) {
				throw new Exception( 'Duplicate IPN ignored.' );
			}

			if ( Payxpert_Payment_Transaction::RESULT_CODE_CALLBACK_CANCEL == $handle['errorCode'] ) {
				throw new PaymentCancellationException();
			}

			$payment_token = Payxpert_Payment_Token::find_by_merchant_token(
				$handle['transaction']->getPaymentMerchantToken()
			);

			if ( ! $payment_token ) {
				throw new PaymentTokenNotFoundException();
			}

			$order_id = $payment_token['order_id'];

			if ( 0 == $order_id ) {
				throw new PaymentTokenExpiredException();
			}

			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				throw new Exception( 'Order not found.' );
			}

			$custom_data = array();
			if ( ! empty( $handle['customData'] ) ) {
				parse_str( $handle['customData'], $custom_data );
			}

			$this->assert_valid_ipn_payload( $order, $payment_token, $handle, $custom_data );

			$this->update_order_from_ipn( $order, $handle, $custom_data );
			$this->create_payment_transaction( $order, $transaction );

			if ( 0 != $subscription_id ) {
				$subscription_info = WC_Payxpert_Webservice::get_status_subscription(
					$this->configuration,
					$subscription_id
				);
				if ( isset( $subscription_info['error'] ) ) {
					WC_Payxpert_Logger::critical( 'Error while retrieving subscriptionInfo for paymentTransactionID : ' . $subscription_id );
				} else {
					$payxpert_subscription = new Payxpert_Subscription();
					$subscription_info     = WC_Payxpert_Utils::to_snake_case_keys( $subscription_info['subscription'] );
					$payxpert_subscription->set( $subscription_info );
					$payxpert_subscription->set( array( 'customer_id' => $order->get_customer_id() ) );
					$payxpert_subscription->save();
				}
			}
		} catch ( \Throwable $e ) {
			WC_Payxpert_Logger::error( 'IPN Error' );
			WC_Payxpert_Logger::error( $e->getMessage() );
			$http_code = stripos( $e->getMessage(), 'Unauthorized callback request.' ) !== false ? 403 : 500;
			http_response_code( $http_code );
			$response_status  = 'KO';
			$response_message = __( 'Callback validation failed.', 'payxpert' );
		}

		WC_Payxpert_Logger::info( 'IPN END' );

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		wp_send_json(
			array(
				'status'  => $response_status,
				'message' => $response_message,
			)
		);
	}

	/**
	 * AssertValidIpnSecret.
	 *
	 * @throws \Exception If the IPN secret is missing or invalid.
	 */
	private function assert_valid_ipn_secret(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This secret IS the authentication of the PayXpert server-to-server IPN callback; no browser nonce applies.
		$request_secret  = isset( $_GET['px_ipn_secret'] ) ? sanitize_text_field( wp_unslash( $_GET['px_ipn_secret'] ) ) : '';
		$expected_secret = WC_Payxpert_Utils::ensure_ipn_secret();

		if ( ! is_string( $request_secret ) || '' === $request_secret || ! hash_equals( $expected_secret, $request_secret ) ) {
			throw new Exception( 'Unauthorized callback request.' );
		}
	}

	/**
	 * AssertValidIpnPayload.
	 *
	 * @param WC_Order             $order Order.
	 * @param array<string, mixed> $payment_token Payment token.
	 * @param array<string, mixed> $handle Handle.
	 * @param array<string, mixed> $custom_data Custom data.
	 * @throws \Exception If the payload does not match the expected order.
	 */
	private function assert_valid_ipn_payload( WC_Order $order, array $payment_token, array $handle, array $custom_data ): void {
		$transaction = $handle['transaction'];

		$expected_amount        = (int) round( ( (float) $order->get_total() ) * 100 );
		$received_amount        = (int) $transaction->getAmount();
		$status                 = isset( $custom_data['status'] ) ? (string) $custom_data['status'] : 'processing';
		$is_installment_pending = WC_Payxpert::ORDER_STATUS_INSTALLMENT_PENDING === $status;

		if ( $is_installment_pending ) {
			if ( $received_amount <= 0 || $received_amount > $expected_amount ) {
				throw new Exception(
					esc_html(
						sprintf(
							'IPN amount mismatch [order=%d, expected_range=1..%d, got=%d]',
							(int) $order->get_id(),
							$expected_amount,
							$received_amount
						)
					)
				);
			}
		} elseif ( $received_amount !== $expected_amount ) {
				throw new Exception(
					esc_html(
						sprintf(
							'IPN amount mismatch [order=%d, expected=%d, got=%d]',
							(int) $order->get_id(),
							$expected_amount,
							$received_amount
						)
					)
				);
		}

		$expected_currency = strtoupper( (string) $order->get_currency() );
		$received_currency = strtoupper( (string) $transaction->getCurrency() );
		if ( $received_currency !== $expected_currency ) {
			throw new Exception(
				esc_html(
					sprintf(
						'IPN currency mismatch [order=%d, expected=%s, got=%s]',
						(int) $order->get_id(),
						$expected_currency,
						$received_currency
					)
				)
			);
		}

		$token_order_id = (int) ( $payment_token['order_id'] ?? 0 );
		if ( $token_order_id <= 0 || $token_order_id !== (int) $order->get_id() ) {
			throw new Exception( 'IPN token/order mismatch.' );
		}

		$callback_order_id    = isset( $handle['orderId'] ) ? (int) $handle['orderId'] : 0;
		$transaction_order_id = (int) $transaction->getOrderID();
		$remote_order_id      = $transaction_order_id > 0 ? $transaction_order_id : $callback_order_id;
		if ( $remote_order_id > 0 && $remote_order_id !== (int) $order->get_id() ) {
			throw new Exception(
				esc_html(
					sprintf(
						'IPN order mismatch [expected=%d, got=%d]',
						(int) $order->get_id(),
						$remote_order_id
					)
				)
			);
		}
	}

	/**
	 * UpdateOrderFromIPN.
	 *
	 * @param WC_Order             $order Order.
	 * @param array<string, mixed> $handle Handle.
	 * @param array<string, mixed> $custom_data Custom data.
	 */
	private function update_order_from_ipn( WC_Order $order, array $handle, array $custom_data ): void {
		$transaction_id = $handle['transaction']->getTransactionID();

		$status = isset( $custom_data['status'] ) ? $custom_data['status'] : 'processing';

		// Keep installment pending status when provided via customData.
		if (
			WC_Payxpert::CAPTURE_MODE_MANUAL == $this->configuration['payxpert_capture_mode']
			&& WC_Payxpert::ORDER_STATUS_INSTALLMENT_PENDING !== $status
		) {
			$status = WC_Payxpert::ORDER_STATUS_CAPTURE_PENDING;
		}

		if ( Payxpert_Payment_Transaction::RESULT_CODE_SUCCESS !== $handle['errorCode'] ) {
			$status = 'failed';
		}

		$update_status = ! in_array(
			$order->get_status(),
			array( 'processing', 'completed' ),
			true
		);

		switch ( $status ) {
			case 'processing':
				if ( $update_status ) {
					$order->add_meta_data( 'payxpert_transaction_id', $transaction_id );
					$order->add_meta_data( 'payxpert_payment_id', $handle['transaction']->getPaymentID() );

					$order->payment_complete( $transaction_id );
				}
				break;

			case WC_Payxpert::ORDER_STATUS_INSTALLMENT_PENDING:
				$order->set_transaction_id( $transaction_id );
				$order->add_meta_data( 'payxpert_installment', '1' );
				break;

			case WC_Payxpert::ORDER_STATUS_CAPTURE_PENDING:
				$order->add_meta_data( 'payxpert_capture', '1' );
				break;
		}

		if ( ! $order->get_payment_method() ) {
			$order->set_payment_method( $this->id );
			$order->set_payment_method_title( $this->get_title() );
		}

		if ( empty( $order->get_customer_ip_address() ) && ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$order->set_customer_ip_address( WC_Geolocation::get_ip_address() );
		}

		if ( empty( $order->get_customer_user_agent() ) && ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$order->set_customer_user_agent( wc_get_user_agent() );
		}

		$order->save();

		if ( $update_status && 'processing' !== $status ) {
			$order->update_status(
				$status,
				__( 'PayXpert transaction received.', 'payxpert' )
			);
		}
	}

	/**
	 * CreatePaymentTransaction.
	 *
	 * @param WC_Order $order Order.
	 * @param mixed    $transaction Transaction.
	 */
	private function create_payment_transaction( WC_Order $order, $transaction ): void {

		$payment_mean_info = $transaction->getPaymentMeanInfo();

		$payxpert_payment_transaction = new Payxpert_Payment_Transaction();

		$payxpert_payment_transaction->set(
			array(
				'id_shop'                => get_current_blog_id(),
				'transaction_id'         => $transaction->getTransactionId(),
				'transaction_referal_id' => $transaction->getRefTransactionID(),
				'order_id'               => $order->get_id(),
				'payment_id'             => $transaction->getPaymentID(),
				'liability_shift'        => $payment_mean_info && method_exists( $payment_mean_info, 'getIs3DSecure' )
					? (bool) $payment_mean_info->getIs3DSecure()
					: false,
				'payment_method'         => $transaction->getPaymentMethod(),
				'operation'              => $transaction->getOperation(),
				'amount'                 => number_format( $transaction->getAmount() / 100, 2, '.', '' ),
				'currency'               => $transaction->getCurrency(),
				'result_code'            => $transaction->getResultCode(),
				'result_message'         => $transaction->getResultMessage(),
				'subscription_id'        => $transaction->getSubscriptionID(),
				'card_number'            => $payment_mean_info && method_exists( $payment_mean_info, 'getCardNumber' )
					? $payment_mean_info->getCardNumber()
					: null,
				'card_brand'             => $payment_mean_info && method_exists( $payment_mean_info, 'getCardBrand' )
					? $payment_mean_info->getCardBrand()
					: null,
			)
		);

		$payxpert_payment_transaction->save();
	}

	/**
	 * Payment fields.
	 */
	public function payment_fields(): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted gateway markup built internally (seamless/iframe payment fields); dynamic error paths are escaped in get_payment_fields().
		echo $this->get_payment_fields();
	}

	/**
	 * Markup for seamless iframe container (no prepare_payment call).
	 *
	 * @param array<string, mixed> $seamless_data         Seamless payment data.
	 * @param string               $container_extra_attrs Optional extra attributes for the root div (e.g. style).
	 */
	public function get_seamless_payment_fields_html( array $seamless_data, string $container_extra_attrs = '' ): string {
		ob_start(); ?>
		<?php /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Developer-controlled literal attribute string (e.g. 'style="width:100%"'), never user input. */ ?>
		<div class="payxpert-seamless-container" <?php echo $container_extra_attrs; ?>
			data-method="<?php echo esc_attr( $this->id ); ?>"
			data-customer-token="<?php echo esc_attr( $seamless_data['customerToken'] ); ?>" 
			data-language="<?php echo esc_attr( $seamless_data['language'] ); ?>"
			data-seamless-submit="<?php echo esc_attr( $seamless_data['seamlessSubmit'] ); ?>"
		>
			<div class="payxpert-placeholder"></div>
			<input type="hidden" class="payxpert_nonce" value="<?php echo esc_attr( $seamless_data['nonce'] ); ?>">
			<input type="hidden" class="customerToken" value="<?php echo esc_attr( $seamless_data['customerToken'] ); ?>" name="<?php echo esc_attr( $this->id ); ?>_customer_token"/>
			<button type="button" class="payxpert_seamless_hidden_submit" id="<?php echo esc_attr( $seamless_data['seamlessSubmit'] ); ?>" style="display:none" ><?php echo esc_html__( 'Pay', 'payxpert' ); ?></button>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Get payment fields.
	 *
	 * @return mixed
	 */
	public function get_payment_fields() {
		global $wp;
		$order = null;

		if ( isset( $wp->query_vars['order-pay'] ) ) {
			$order_id = absint( $wp->query_vars['order-pay'] );
			$order    = wc_get_order( $order_id );
		}

		$seamless_data = $this->get_seamless_data( $order );

		if ( ! $seamless_data ) {
			return;
		}

		if ( isset( $seamless_data['error'] ) ) {
			return esc_html( $seamless_data['error'] );
		}

		return $this->get_seamless_payment_fields_html( $seamless_data );
	}

	/**
	 * Register seamless script assets.
	 *
	 * @param mixed $checkout_order_id Checkout order id.
	 * @return mixed
	 */
	public function register_seamless_script_assets( $checkout_order_id = 0 ) {
		if ( ! $this->seamless || wp_script_is( 'payxpert-seamless', 'registered' ) ) {
			return;
		}

		if ( ! wp_style_is( 'payxpert-seamless-style', 'registered' ) ) {
			wp_register_style(
				'payxpert-seamless-style',
				WC_PAYXPERT_ASSETS . 'css/seamless.css',
				array(),
				WC_PAYXPERT_VERSION
			);
		}

		$ajax_url          = admin_url( 'admin-ajax.php' );
		$seamless_config   = json_decode( get_option( 'payxpert_seamless_library' ), true );
		$seamless_api      = array(
			'version' => $seamless_config['version'] ?? self::DEFAULT_SEAMLESS_VERSION,
			'hash'    => $seamless_config['hash'] ?? self::DEFAULT_SEAMLESS_HASH,
		);
		$apple_pay_enabled = wp_json_encode(
			isset( $this->configuration['payxpert_applepay_enabled'] )
			&& 'yes' === $this->configuration['payxpert_applepay_enabled']
		);

		wp_register_script(
			'payxpert-seamless',
			'',
			array(
				'jquery',
			),
			null,
			true
		);

		wp_localize_script(
			'payxpert-seamless',
			'payxpertSettings', // global JS object name.
			array(
				'isCheckoutPayPage'            => is_checkout_pay_page() ? true : false,
				'checkoutOrderId'              => $checkout_order_id,
				'redirectMessage'              => __( 'Redirect in progress...', 'payxpert' ),
				'genericErrorMessage'          => __( 'An unexpected error occurred.', 'payxpert' ),
				'communicationErrorMessage'    => __( 'Unable to communicate with the server.', 'payxpert' ),
				'paymentMethodErrorMessage'    => __( 'An error occurred while retrieving the selected payment method.', 'payxpert' ),
				'missingNonceErrorMessage'     => __( 'Missing nonce for payment result.', 'payxpert' ),
				'ajaxRequestFailedMessage'     => __( 'AJAX request failed:', 'payxpert' ),
				// translators: %d is the attempt number.
				'attemptFailedRetryingMessage' => __( 'Attempt %d failed, retrying...', 'payxpert' ),
				// translators: %d is the attempt number.
				'retryingAjaxRequestMessage'   => __( 'Retrying AJAX request (attempt %d)...', 'payxpert' ),
				'triggerPaymentMessage'        => __( 'Triggering PayXpert payment call.', 'payxpert' ),
				'paymentPendingMessage'        => __( 'Your payment is still being confirmed. Please wait a moment…', 'payxpert' ),
				'paymentConfirmTimeoutMessage' => __( 'Payment confirmation is taking longer than expected. Please check your order status or try again.', 'payxpert' ),
				'maxPollAttempts'              => 30,
			)
		);

				wp_add_inline_script(
					'payxpert-seamless',
					<<<JS
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
                            script.src = "https://connect2.payxpert.com/payment/" + token + "/connect2pay-seamless-v{$seamless_api['version']}.js";
                            script.async = true;
                            script.setAttribute('data-mount-in', "#" + mountId);
                            script.setAttribute('integrity', "sha384-{$seamless_api['hash']}");
                            script.setAttribute('crossorigin', 'anonymous');
                            script.setAttribute('data-payxpert-script', 1);

                            document.body.appendChild(script);
                        }

                        // #45580: not available yet
                        // const applePayEnabled = {$apple_pay_enabled};
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

                        fetch("{$ajax_url}", {
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
	}

	/**
	 * Add scripts.
	 */
	public function add_scripts(): void {
		if ( is_checkout() && $this->seamless ) {
			global $wp;
			$checkout_order_id = 0;
			if ( is_checkout_pay_page() && isset( $wp->query_vars['order-pay'] ) ) {
				$checkout_order_id = absint( $wp->query_vars['order-pay'] );
			}

			wp_enqueue_style( 'payxpert-seamless-style' );
			$this->register_seamless_script_assets( $checkout_order_id );
			wp_enqueue_script( 'payxpert-seamless' );
		}

		if ( is_checkout_pay_page() && $this->seamless ) {
			if ( ! wp_script_is( 'payxpert-seamless-payfororder', 'enqueued' ) ) {

				wp_register_script(
					'payxpert-seamless-payfororder',
					'', // set Dynamicaly.
					array( 'jquery' ),
					null,
					true
				);

				wp_add_inline_script(
					'payxpert-seamless-payfororder',
					<<<'JS'
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

				wp_enqueue_script( 'payxpert-seamless-payfororder' );
			}
		}
	}

	/**
	 * Process payment seamless.
	 *
	 * @param mixed $order_id Order id.
	 * @return mixed
	 */
	public function process_payment_seamless( $order_id ) {
		WC_Payxpert_Logger::info( 'SEAMLESS' );

		global $wpdb;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Runs inside WooCommerce process_payment(), after WooCommerce has verified the checkout nonce; the token is also validated below.
		$customer_token = isset( $_POST[ $this->id . '_customer_token' ] ) ? sanitize_text_field( wp_unslash( $_POST[ $this->id . '_customer_token' ] ) ) : '';

		$payment_token = Payxpert_Payment_Token::find_by_customer_token( $customer_token );

		if ( is_null( $payment_token ) ) {
			WC_Payxpert_Logger::info( 'Payment TOKEN not found' );

			return array(
				'result'  => 'failure',
				'message' => __( 'Payment token not found', 'payxpert' ),
			);
		}

		if ( $payment_token['order_id'] != $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return array(
					'result'  => 'failure',
					'message' => __( 'Order not found', 'payxpert' ),
				);
			}

			$token_uid = (int) $payment_token['user_id'];
			$order_uid = (int) $order->get_customer_id();
			if ( $token_uid !== $order_uid && ! ( 0 === $token_uid && 0 === $order_uid ) ) {
				WC_Payxpert_Logger::critical( 'Seamless token user mismatch for order update' );
				return array(
					'result'  => 'failure',
					'message' => __( 'Payment token does not match this order.', 'payxpert' ),
				);
			}

			WC_Payxpert_Logger::info(
				sprintf(
					'Update token<-->order by customer token: [token=%s, order_id=%d]',
					$this->mask_token_for_logs( $customer_token ),
					(int) $order_id
				)
			);
			// Update Payxpert_Payment_Token.order_id in DB with real wc_order_id.
			$wpdb->update(
				$wpdb->prefix . Payxpert_Payment_Token::TABLE_NAME,
				array( 'order_id' => $order_id ),
				array(
					'customer_token' => $customer_token,
				),
				array( '%d' ),
				array( '%s' )
			);

			// Classic checkout only includes order_id in AJAX when result is "success".
			return array(
				'result'   => 'success',
				'redirect' => '#payxpert-seamless-payment',
			);
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array(
				'result'  => 'failure',
				'message' => __( 'Order not found', 'payxpert' ),
			);
		}

		if ( in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) {
			WC_Payxpert_Logger::info( 'Redirect on order_received_url' );

			return array(
				'result'   => 'success',
				'redirect' => $order->get_checkout_order_received_url(),
			);
		}

		WC_Payxpert_Logger::info( 'Token already updated & order not complete yet' );
		return;
	}

	/**
	 * Process payment cc.
	 *
	 * @param mixed $gateway Gateway.
	 * @param mixed $order_id Order id.
	 * @param mixed $payment_method Payment method.
	 * @param mixed $payment_mode Payment mode.
	 * @param mixed $instalment_parameters Instalment parameters.
	 * @return mixed
	 */
	public function process_payment_cc( $gateway, $order_id, $payment_method, $payment_mode, $instalment_parameters = array() ) {
		WC_Payxpert_Logger::info( 'Process payment call' );

		if ( $this->seamless ) {
			return $this->process_payment_seamless( $order_id );
		}

		WC_Payxpert_Logger::info( 'REDIRECTION' );

		// Redirect to PayXpert gateway.
		$prepared_payment = WC_Payxpert_Webservice::prepare_payment(
			$this->configuration,
			$gateway,
			$payment_method,
			$payment_mode,
			wc_get_order( $order_id ),
			$instalment_parameters,
			false
		);

		if ( isset( $prepared_payment['error'] ) ) {
			wc_add_notice( __( 'An error occurred while trying to generate the payment link. Please try again or contact support.', 'payxpert' ), 'error' );
			WC_Payxpert_Logger::critical(
				// translators: %s is the API error message.
				sprintf( __( 'PayXpert API error: %s', 'payxpert' ), $prepared_payment['error'] )
			);

			return array( 'result' => 'failure' );
		}

		return array(
			'result'   => 'success',
			'redirect' => $prepared_payment['redirectUrl'],
		);
	}

	/**
	 * Prepares seamless checkout data (token, nonce, order context). Overridden per gateway.
	 *
	 * @param \WC_Order|null $order Order to build the seamless data for, or null for the cart.
	 * @return array<string, mixed>|null
	 */
	public function get_seamless_data( $order = null ) {
		return null;
	}

	/**
	 * Builds seamless iframe payload from a prepare_payment() response.
	 * Gateways call this via get_seamless_data( $order ) after prepare_payment().
	 *
	 * @param array<string, mixed> $prepared_payment Response returned by prepare_payment().
	 * @param \WC_Order|null       $order            Order context, or null for the cart.
	 * @return array<string, mixed>|null
	 */
	protected function assemble_seamless_data( $prepared_payment, $order = null ) {
		if ( isset( $prepared_payment['error'] ) ) {
			WC_Payxpert_Logger::critical( $prepared_payment['error'] );
			return array(
				'error' => __( 'An error occurred during payment preparation.', 'payxpert' ),
			);
		}

		$locale            = determine_locale();
		$lang              = substr( $locale, 0, 2 );
		$seamless_language = in_array( $lang, array( 'fr', 'es' ), true ) ? $lang : 'en';

		$order_id = ( $order instanceof \WC_Order ) ? (int) $order->get_id() : 0;

		return array(
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'locale'         => $locale,
			'language'       => $seamless_language,
			'customerToken'  => $prepared_payment['customerToken'],
			'nonce'          => wp_create_nonce( WC_Payxpert::payment_result_nonce_action( $order_id ) ),
			'prefix'         => $this->id,
			'seamlessSubmit' => 'payxpert-internal-submit-' . $this->id,
		);
	}

	/**
	 * Process refund.
	 *
	 * @param mixed $order_id Order id.
	 * @param mixed $amount Amount.
	 * @param mixed $reason Reason.
	 * @return mixed
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		WC_Payxpert_Logger::info( 'Process refund on order ID : ' . (int) $order_id );

		$order = wc_get_order( $order_id );
		if ( ! $order || ! $amount ) {
			WC_Payxpert_Logger::info( 'Order or Amount to refund not found' );
			return false;
		}

		$amount_to_refund = (int) round( $amount * 100 );
		$refunded_total   = 0;

		$order_transactions           = Payxpert_Payment_Transaction::find_all_by_order_id( $order->get_id() );
		$order_transactions_formatted = WC_Payxpert_Utils::get_order_transactions_formatted( $order_transactions );

		if ( empty( $order_transactions_formatted['refundable'] ) ) {
			return new WP_Error( 'no_refundable_tx', __( 'No refundable transactions found for this order.', 'payxpert' ) );
		}

		foreach ( $order_transactions_formatted['refundable'] as $tx ) {
			$refundable = (int) round( $tx['refundable_amount'] * 100 );
			if ( $refundable <= 0 ) {
				continue;
			}

			$to_refund = min( $amount_to_refund - $refunded_total, $refundable );

			if ( $to_refund <= 0 ) {
				break;
			}

			$is_subscription_occurrence = false;
			if ( $tx['subscription_id'] ) {
				$subscription = Payxpert_Subscription::find_by_subscription_id( $tx['subscription_id'] );
				if ( $subscription && in_array( $subscription['subscription_type'], array( SubscriptionType::INFINITE, SubscriptionType::LIFETIME, SubscriptionType::NORMAL ) ) ) {
					$is_subscription_occurrence = true;
				}
			}

			try {
				$response = WC_Payxpert_Webservice::refund_transaction(
					$this->configuration,
					$tx['transaction_id'],
					$to_refund,
					$is_subscription_occurrence
				);

				if ( isset( $response['error'] ) ) {
					WC_Payxpert_Logger::critical( "[{$tx['transaction_id']}] " . $response['error'] );
					return new WP_Error( 'refund_failed', __( 'An error occurred during the refund process: ', 'payxpert' ) . $response['error'] );
				}

				if ( Payxpert_Payment_Transaction::RESULT_CODE_SUCCESS !== $response['code'] ) {
					WC_Payxpert_Logger::critical( "[{$tx['transaction_id']}] " . $response['message'] );
					return new WP_Error( 'refund_failed', __( 'An error occurred during the refund process: ', 'payxpert' ) . $response['message'] );
				}

				WC_Payxpert_Logger::info( 'Refund transaction : ' . $response['transaction_id'] );
				$tx_info                      = WC_Payxpert_Webservice::get_transaction_info( $this->configuration, $response['transaction_id'], $is_subscription_occurrence );
				$payxpert_payment_transaction = new Payxpert_Payment_Transaction();
				$payxpert_payment_transaction->set( $tx_info );
				$payxpert_payment_transaction->set(
					array(
						'order_id'               => $order_id,
						'transaction_referal_id' => $tx['transaction_id'],
					)
				);
				$payxpert_payment_transaction->save();

				$order->add_order_note(
					sprintf(
						// translators: %1$s is the amount, %2$s the currency, %3$s the transaction ID.
						__( 'Refunded %1$s %2$s from transaction %3$s', 'payxpert' ),
						wc_price( $to_refund / 100 ),
						get_woocommerce_currency(),
						$tx['transaction_id']
					)
				);

				$refunded_total += $to_refund;
			} catch ( Exception $e ) {
				return new WP_Error( 'refund_exception', __( 'Exception during refund: ', 'payxpert' ) . $e->getMessage() );
			}

			if ( $refunded_total >= $amount_to_refund ) {
				break;
			}
		}

		return true;
	}

	/**
	 * MaskTokenForLogs.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private function mask_token_for_logs( string $token ): string {
		if ( '' === $token ) {
			return '[empty]';
		}

		return substr( $token, 0, 6 ) . '***';
	}
}
