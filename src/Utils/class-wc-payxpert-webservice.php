<?php
/**
 * Wc Payxpert Webservice.
 *
 * @package Payxpert
 */

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

/**
 * WC Payxpert Webservice.
 */
class WC_Payxpert_Webservice {




	const API_URL          = 'https://connect2.payxpert.com';
	const API_PAYXPERT_URL = 'https://api.payxpert.com';

	/**
	 * Prepare payment.
	 *
	 * @param array<string, mixed> $configuration Configuration.
	 * @param mixed                $gateway Gateway.
	 * @param string               $payment_method Payment method.
	 * @param string               $payment_mode Payment mode.
	 * @param mixed                $order Order.
	 * @param array<string, mixed> $instalment_parameters Instalment parameters.
	 * @param bool                 $is_pay_by_link Is pay by link.
	 * @param mixed                $subscription_in_cart Subscription in cart.
	 * @return mixed
	 */
	public static function prepare_payment( $configuration, $gateway, string $payment_method, string $payment_mode, $order = null, array $instalment_parameters = array(), bool $is_pay_by_link = false, $subscription_in_cart = false ) {
		if ( is_null( $order ) ) {
			$customer    = wp_get_current_user();
			$customer_id = $customer->ID;

			if ( is_null( WC()->cart ) ) {
				return array( 'error' => __( 'Error cart is null', 'payxpert' ) );
			}

			$billing = array(
				'first_name' => get_user_meta( $customer_id, 'billing_first_name', true ),
				'last_name'  => get_user_meta( $customer_id, 'billing_last_name', true ),
				'address_1'  => get_user_meta( $customer_id, 'billing_address_1', true ),
				'address_2'  => get_user_meta( $customer_id, 'billing_address_2', true ),
				'city'       => get_user_meta( $customer_id, 'billing_city', true ),
				'postcode'   => get_user_meta( $customer_id, 'billing_postcode', true ),
				'country'    => get_user_meta( $customer_id, 'billing_country', true ),
			);

			$email         = get_user_meta( $customer_id, 'billing_email', true );
			$billing_phone = get_user_meta( $customer_id, 'billing_phone', true );
			$currency      = get_woocommerce_currency();
			$amount        = (int) round( WC()->cart->get_total( 'edit' ) * 100 );
			$order_id      = 0;
		} else {
			$billing       = $order->get_address( 'billing' );
			$currency      = $order->get_currency();
			$amount        = (int) round( $order->get_total() * 100 );
			$customer_id   = $order->get_customer_id();
			$customer      = get_userdata( $customer_id );
			$email         = $order->get_billing_email();
			$billing_phone = $order->get_billing_phone();
			$order_id      = $order->get_id();
		}

		$billing = array_map(
			function ( $value ) {
				return false === $value ? '' : $value;
			},
			$billing
		);

		$billing_phone = $billing_phone ? $billing_phone : '';

		// Shopper parameters.
		$shopper = new Shopper();
		$shopper->setId( $customer_id );
		$shopper->setFirstName( substr( $billing['first_name'], 0, 35 ) );
		$shopper->setLastName( substr( $billing['last_name'], 0, 35 ) );
		$shopper->setAddress1( self::format_address( $billing['address_1'] ) );
		$shopper->setZipcode( substr( $billing['postcode'], 0, 10 ) );
		$shopper->setCity( substr( $billing['city'], 0, 50 ) );
		$shopper->setCountryCode( strtoupper( $billing['country'] ) );
		$shopper->setEmail( $email );
		$shopper->setHomePhone( self::format_home_phone( $billing_phone, $billing['country'] ) );

		// Optional: set HomePhonePrefix if available.
		$shopper->setHomePhonePrefix( self::get_country_phone_prefix( $billing['country'] ) );

		// SCA / 3DS2: Account hints for registered customers only (not guest checkout).
		if ( PaymentMethod::CREDIT_CARD === $payment_method && (int) $customer_id > 0 ) {
			self::attach_shopper_account_for_sca( $shopper, (int) $customer_id );
		}

		// Order parameters.
		$c2p_order = new c2pOrder();
		$c2p_order->setType( OrderType::GOODS_SERVICE );
		$c2p_order->setShippingType( self::resolve_order_shipping_type( $order ) );
		$c2p_order->setId( $order_id );

		if ( ! is_null( $order ) ) {
			$c2p_order->setDescription( 'Order #' . $order->get_order_number() );
		}

		// Shipping parameters.
		$shipping_obj = new Shipping();

		// Build PaymentPrepareRequest.
		$prepare_request = new PaymentPrepareRequest();
		$prepare_request->setShopper( $shopper );
		$prepare_request->setOrder( $c2p_order );
		$prepare_request->setShipping( $shipping_obj );
		$prepare_request->setCurrency( $currency );
		$prepare_request->setAmount( $amount );
		$prepare_request->setPaymentMethod( $payment_method );
		$prepare_request->setPaymentMode( $payment_mode );

		// Credit Card specific.
		if ( PaymentMethod::CREDIT_CARD === $payment_method ) {
			$prepare_request->setSecure3d( true );
			$prepare_request->setOperation(
				WC_Payxpert::CAPTURE_MODE_MANUAL == $configuration['payxpert_capture_mode']
					? OperationType::AUTHORIZE
					: OperationType::SALE
			);
		}

		// Handle instalments.
		$custom_data = array();
		if ( PaymentMode::INSTALMENTS === $payment_mode ) {
			if ( empty( $instalment_parameters ) ) {
				return array( 'error' => 'Instalment Parameters are required with this payment mode' );
			}

			$prepare_request->setSubscriptionType( SubscriptionType::PARTPAYMENT );
			list($first_amount, $rebill_amount) = WC_Payxpert_Utils::calculate_instalment_amounts(
				$amount,
				intval( $instalment_parameters['firstPercentage'] ),
				$instalment_parameters['xTimes']
			);

			$prepare_request->setAmount( (int) $first_amount );
			$prepare_request->setRebillAmount( (int) $rebill_amount );
			$prepare_request->setRebillMaxIteration( $instalment_parameters['xTimes'] - 1 );
			$prepare_request->setRebillPeriod( 'P30D' );
			$custom_data['status'] = WC_Payxpert::ORDER_STATUS_INSTALLMENT_PENDING;

			$prepare_request->setOperation( OperationType::SALE ); // ! Mandatory
		}

		if ( PaymentMode::RECURRENT === $payment_mode ) {
			if ( ! is_null( $order ) ) {
				$subscription_in_cart = WC_Payxpert_Utils::has_subscription_in_order( $order, true );
			}

			if ( true === $subscription_in_cart ) {
				$subscription_in_cart = WC_Payxpert_Utils::has_subscription_in_cart( WC()->cart->get_cart(), true );
			}

			if ( empty( $subscription_in_cart ) ) {
				return array( 'error' => __( 'Error while retrieving subscription data from order/cart', 'payxpert' ) );
			}

			$shipping_amount = WC()->cart->get_shipping_total() * 100;

			$prepare_request->setSubscriptionType( $subscription_in_cart[ WC_Payxpert::SUBSCRIPTION_DURATION_TYPE ] );
			$prepare_request->setAmount( 'immediate' == $subscription_in_cart[ WC_Payxpert::SUBSCRIPTION_FIRST_PAYMENT ] ? $amount : 0 );
			$prepare_request->setRebillAmount( (int) ( $shipping_amount + $subscription_in_cart['quantity'] * (int) ( $subscription_in_cart[ WC_Payxpert::SUBSCRIPTION_PRICE ] * 100 ) ) );
			$prepare_request->setRebillPeriod( WC_Payxpert_Utils::get_period_by_frequency( $subscription_in_cart[ WC_Payxpert::SUBSCRIPTION_FREQUENCY ] ) );

			if ( 'normal' == $subscription_in_cart[ WC_Payxpert::SUBSCRIPTION_DURATION_TYPE ] ) {
				$prepare_request->setRebillMaxIteration( $subscription_in_cart[ WC_Payxpert::SUBSCRIPTION_MAX_CYCLES ] );
			}

			$prepare_request->setOperation( OperationType::SALE ); // ! Mandatory
		}

		// Ctrl URLs.
		$ipn_secret = WC_Payxpert_Utils::ensure_ipn_secret();
		$prepare_request->setCtrlRedirectURL(
			add_query_arg(
				array(
					'payxpert_return' => true,
				),
				home_url( '/wc-api/' . $gateway )
			)
		);
		$prepare_request->setCtrlCallbackURL(
			add_query_arg(
				array(
					'px_ipn_secret' => $ipn_secret,
				),
				home_url( '/wc-api/' . $gateway )
			)
		);
		$prepare_request->setCtrlCustomData( http_build_query( $custom_data ) );

		// Merchant Notifications.
		if ( $configuration['payxpert_notification_active'] ) {
			$prepare_request->setMerchantNotification( true );
			$prepare_request->setMerchantNotificationTo( $configuration['payxpert_notification_to'] );
			$prepare_request->setMerchantNotificationLang( $configuration['payxpert_notification_language'] );
		}

		if ( $is_pay_by_link ) {
			$prepare_request->setTimeOut( 'P30D' );
		}

		// API request.
		$client = new Connect2PayClient(
			self::API_URL,
			$configuration['payxpert_originator_id'],
			$configuration['payxpert_password']
		);

		$result      = $client->preparePayment( $prepare_request );
		$result_code = (string) $result->getCode();
		if ( '200' !== $result_code ) {
			return array( 'error' => $client->getClientErrorMessage() );
		}

		// Save PaymentToken to register transaction.
		try {
			$payment_token = new Payxpert_Payment_Token();

			$payment_token->set(
				array(
					'merchant_token' => $result->getMerchantToken(),
					'customer_token' => $result->getCustomerToken(),
					'user_id'        => $customer_id,
					'order_id'       => $order_id,
					'is_paybylink'   => $is_pay_by_link ? 1 : 0,
				)
			);

			$payment_token->save();
		} catch ( \Exception $e ) {
			return array( 'error' => $e->getMessage() );
		}

		return array(
			'code'          => $result->getCode(),
			'message'       => $result->getMessage(),
			'customerToken' => $result->getCustomerToken(),
			'merchantToken' => $result->getMerchantToken(),
			'redirectUrl'   => $client->getCustomerRedirectURL( $result ),
		);
	}


	/**
	 * Capture transaction.
	 *
	 * @param mixed  $configuration Configuration.
	 * @param string $transaction_id Transaction id.
	 * @param int    $amount Amount.
	 * @return mixed
	 */
	public static function capture_transaction( $configuration, string $transaction_id, int $amount ) {
		$c2p_client = new Connect2PayClient(
			self::API_URL,
			$configuration['payxpert_originator_id'],
			$configuration['payxpert_password']
		);

		$status = $c2p_client->captureTransaction( $transaction_id, $amount );
		if ( null == $status || null == $status->getCode() ) {
			return array( 'error' => $c2p_client->getClientErrorMessage() );
		}

		return array(
			'code'           => $status->getCode(),
			'message'        => $status->getMessage(),
			'transaction_id' => $status->getTransactionID(),
		);
	}

	/**
	 * Refund transaction.
	 *
	 * @param mixed  $configuration Configuration.
	 * @param string $transaction_id Transaction id.
	 * @param int    $amount Amount.
	 * @param bool   $use_other_api Use other api.
	 * @param bool   $cancel_subscription Cancel subscription.
	 * @return mixed
	 */
	public static function refund_transaction( $configuration, string $transaction_id, int $amount, bool $use_other_api = false, bool $cancel_subscription = false ) {
		if ( $use_other_api ) {
			$status = self::refund_transaction_special( $configuration, $transaction_id, $amount, $cancel_subscription );

			if ( null == $status || ( is_array( $status ) && isset( $status['error'] ) ) || null == $status->getCode() ) {
				return array( 'error' => $status['error'] ?? 'unknown' );
			}
		} else {
			$c2p_client = new Connect2PayClient(
				self::API_URL,
				$configuration['payxpert_originator_id'],
				$configuration['payxpert_password']
			);

			$status = $c2p_client->refundTransaction( $transaction_id, $amount );

			if ( null == $status || null == $status->getCode() ) {
				return array( 'error' => $c2p_client->getClientErrorMessage() );
			}
		}

		return array(
			'code'           => $status->getCode(),
			'message'        => $status->getMessage(),
			'transaction_id' => $status->getTransactionID(),
		);
	}

	/**
	 * Refund transaction special.
	 *
	 * @param mixed  $configuration Configuration.
	 * @param string $transaction_id Transaction id.
	 * @param int    $amount Amount.
	 * @param bool   $cancel_subscription Cancel subscription.
	 * @return mixed
	 */
	public static function refund_transaction_special( $configuration, string $transaction_id, int $amount, bool $cancel_subscription = false ) {
		$url     = self::API_PAYXPERT_URL . str_replace( ':transactionID', (string) $transaction_id, '/transaction/:transactionID/refund' );
		$userpwd = $configuration['payxpert_originator_id'] . ':' . $configuration['payxpert_password'];

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Standard HTTP Basic Authorization header encoding, not code obfuscation.
					'Authorization' => 'Basic ' . base64_encode( $userpwd ),
				),
				'body'    => wp_json_encode(
					array(
						'amount'             => $amount,
						'cancelSubscription' => $cancel_subscription,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'error' => $response->get_error_message() );
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$json      = wp_remote_retrieve_body( $response );

		if ( 200 != $http_code ) {
			return array( 'error' => 'Received HTTP code ' . $http_code . ' from payment page.' );
		}

		if ( '' === $json ) {
			return array( 'error' => 'Error requesting ' . $url );
		}

		$result = json_decode( $json, true );
		WC_Payxpert_Logger::debug( 'refund_transaction_special response: ' . wp_json_encode( $result ) );
		if ( null != $result ) {
			$rf_status = new RefundStatus();

			if ( isset( $result['errorCode'] ) ) {
				$rf_status->setCode( $result['errorCode'] );
			}
			if ( isset( $result['errorMessage'] ) ) {
				$rf_status->setMessage( $result['errorMessage'] );
			}
			if ( isset( $result['transactionID'] ) ) {
				$rf_status->setTransactionID( $result['transactionID'] );
			}
			if ( isset( $result['operation'] ) ) {
				$rf_status->setOperation( $result['operation'] );
			}

			return $rf_status;
		}

		return array( 'error' => 'JSON decoding error.' );
	}

	/**
	 * Retrieve and format transaction information from the PayXpert API.
	 *
	 * @param array<string, mixed> $configuration             PayXpert account configuration (originator id / password).
	 * @param mixed                $transaction_id            Transaction identifier to look up.
	 * @param bool                 $use_other_api             When true, queries the alternate (special) API endpoint.
	 * @param bool                 $with_transaction_attempt  When true, merges internal key _transaction_attempt (Connect2Pay object) into the formatted array so the order can be finalized without a second API call.
	 * @return array<string, mixed>
	 */
	public static function get_transaction_info( $configuration, $transaction_id, bool $use_other_api = false, bool $with_transaction_attempt = false ): array {
		if ( $use_other_api ) {
			return self::get_transaction_info_special( $configuration, $transaction_id, $with_transaction_attempt );
		}

		$c2p_client = new Connect2PayClient(
			self::API_URL,
			$configuration['payxpert_originator_id'],
			$configuration['payxpert_password']
		);

		// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- Inline type hint.
		/** @var TransactionAttempt|null $transaction */
		$transaction = $c2p_client->getTransactionInfo( $transaction_id );
		if ( null == $transaction ) {
			return array( 'error' => $c2p_client->getClientErrorMessage() );
		}

		$formatted = self::format_transaction( $transaction );
		if ( $with_transaction_attempt ) {
			$formatted['_transaction_attempt'] = $transaction;
		}

		return $formatted;
	}

	/**
	 * Get transaction info special.
	 *
	 * @param mixed $configuration Configuration.
	 * @param mixed $transaction_id Transaction id.
	 * @param bool  $with_transaction_attempt With transaction attempt.
	 * @return mixed
	 */
	public static function get_transaction_info_special( $configuration, $transaction_id, bool $with_transaction_attempt = false ) {
		$url     = self::API_PAYXPERT_URL . str_replace( ':transactionID', (string) $transaction_id, '/transaction/:transactionID' );
		$userpwd = $configuration['payxpert_originator_id'] . ':' . $configuration['payxpert_password'];

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Accept'        => 'application/json',
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Standard HTTP Basic Authorization header encoding, not code obfuscation.
					'Authorization' => 'Basic ' . base64_encode( $userpwd ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'error' => $response->get_error_message() );
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$json      = wp_remote_retrieve_body( $response );

		if ( 200 != $http_code ) {
			return array( 'error' => 'Received HTTP code ' . $http_code . ' from payment page.' );
		}

		if ( '' === $json ) {
			return array( 'error' => 'Error requesting ' . $url );
		}

		$result = json_decode( $json, true );

		if ( null != $result ) {
			$json                = self::map_to_transaction_attempt_json( $result );
			$transaction_attempt = TransactionAttempt::getFromJson( (object) $json );
			$formatted           = self::format_transaction( $transaction_attempt );
			if ( $with_transaction_attempt ) {
				$formatted['_transaction_attempt'] = $transaction_attempt;
			}
			return $formatted;
		}

		return array( 'error' => 'JSON decoding error.' );
	}

	/**
	 * Map to transaction attempt json.
	 *
	 * @param array<string, mixed> $response Response.
	 * @return array<string, mixed>
	 */
	private static function map_to_transaction_attempt_json( array $response ): array {
		$t = $response['transaction'] ?? array();
		$p = $t['paymentMeanInfo'] ?? array();
		$s = $t['shopper'] ?? array();

		return array(
			// Exact TransactionAttempt properties.
			'paymentID'             => null,
			'paymentMerchantToken'  => null,
			'paymentMethod'         => ucfirst( $t['paymentType'] ?? '' ),
			'operation'             => $t['operation'] ?? null,
			'date'                  => isset( $t['date'] ) ? $t['date'] : null,
			'amount'                => $t['amount'] ?? 0,
			'refundedAmount'        => ( ( $t['operation'] ?? '' ) === 'refund' ) ? ( $t['amount'] ?? 0 ) : 0,
			'currency'              => $t['currency'] ?? null,
			'resultCode'            => $response['errorCode'] ?? null,
			'resultMessage'         => $response['errorMessage'] ?? null,
			'status'                => $t['status'] ?? null,
			'shopper'               => array(
				'firstName'   => $s['firstName'] ?? $t['shopperName'] ?? null,
				'lastName'    => $s['lastName'] ?? null,
				'email'       => $s['email'] ?? $t['shopperEmail'] ?? null,
				'address1'    => $s['address1'] ?? null,
				'zipcode'     => $s['zipcode'] ?? null,
				'city'        => $s['city'] ?? null,
				'countryCode' => $s['countryCode'] ?? null,
				'birthDate'   => $s['birthDate'] ?? null,
				'idNumber'    => $s['idNumber'] ?? null,
				'ipAddress'   => $s['ip_address'] ?? $t['customerIP'] ?? null,
			),
			'transactionID'         => $t['transactionID'] ?? null,
			'refTransactionID'      => $t['referralID'] ?? null,
			'providerTransactionID' => $t['transactionID'] ?? null,
			'subscriptionID'        => $t['subscriptionID'] ?? null,
			'paymentMeanInfo'       => array(
				'cardNumber'      => $p['cardNumber'] ?? null,
				'cardExpireYear'  => $p['cardExpireYear'] ?? null,
				'cardExpireMonth' => $p['cardExpireMonth'] ?? null,
				'cardHolderName'  => $p['cardHolderName'] ?? null,
				'cardBrand'       => $p['cardBrand'] ?? null,
				'cardLevel'       => $p['cardLevel'] ?? null,
				'cardSubType'     => $p['cardSubType'] ?? null,
				'iinCountry'      => $p['iinCountry'] ?? null,
				'iinBankName'     => $p['iinBankName'] ?? null,
				'is3DSecure'      => $p['is3DSecure'] ?? false,
			),
			'orderID'               => $t['orderID'] ?? null,
			'orderDescription'      => $t['orderDescription'] ?? null,
		);
	}

	/**
	 * Get account info.
	 *
	 * @param mixed $public_key Public key.
	 * @param mixed $private_key Private key.
	 * @return mixed
	 */
	public static function get_account_info( $public_key, $private_key ) {
		$c2p_client = new Connect2PayClient(
			self::API_URL,
			$public_key,
			$private_key
		);

		$info = $c2p_client->getAccountInformation();

		if ( null == $info ) {
			return array( 'error' => $c2p_client->getClientErrorMessage() );
		}

		$payment_methods     = null;
		$acc_payment_methods = $info->getPaymentMethods();
		if ( $acc_payment_methods ) {
			$payment_methods = array();
			foreach ( $acc_payment_methods as $method_info ) {
				$payment_methods[] = array(
					'paymentNetwork'   => $method_info->getPaymentNetwork(),
					'currencies'       => $method_info->getCurrencies(),
					'defaultOperation' => $method_info->getDefaultOperation(),
					'options'          => null == $method_info->getOptions()
						? null
						: array_map(
							function ( $option ) {
								return array(
									'name'  => $option->getName(),
									'value' => $option->getValue(),
								);
							},
							$method_info->getOptions()
						),
				);
			}
		}

		$newest_seamless_library = null;
		$themes                  = $info->getThemes();

		if ( $themes ) {
			$max_version = null;
			foreach ( $themes as $theme ) {
				foreach ( $theme->getSeamlessLibraries() as $seamless_library ) {

					$current_version = $seamless_library->getVersion();

					if ( null === $max_version || version_compare( $current_version, $max_version, '>' ) ) {
						$max_version             = $current_version;
						$newest_seamless_library = array(
							'version' => $current_version,
							'hash'    => $seamless_library->getHash(),
						);
					}
				}
			}
		}

		return array(
			'name'                     => $info->getName(),
			'displayTerms'             => $info->getDisplayTerms(),
			'termsUrl'                 => $info->getTermsUrl(),
			'supportUrl'               => $info->getSupportUrl(),
			'maxAttempts'              => $info->getMaxAttempts(),
			'notificationOnSuccess'    => $info->getNotificationOnSuccess(),
			'notificationOnFailure'    => $info->getNotificationOnFailure(),
			'notificationSenderName'   => $info->getNotificationSenderName(),
			'notificationSenderEmail'  => $info->getNotificationSenderEmail(),
			'merchantNotification'     => $info->getMerchantNotification(),
			'merchantNotificationTo'   => $info->getMerchantNotificationTo(),
			'merchantNotificationLang' => $info->getMerchantNotificationLang(),
			'paymentMethods'           => $payment_methods,
			'newestSeamlessLibrary'    => $newest_seamless_library,
		);
	}

	/**
	 * Get status subscription.
	 *
	 * @param mixed $configuration Configuration.
	 * @param int   $subscription_id Subscription id.
	 * @return mixed
	 */
	public static function get_status_subscription( $configuration, int $subscription_id ) {
		$url = self::API_PAYXPERT_URL . '/subscription/' . $subscription_id;

		$userpwd  = $configuration['payxpert_originator_id'] . ':' . $configuration['payxpert_password'];
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type'  => 'application/x-www-form-urlencoded',
					'Accept'        => 'application/json',
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Standard HTTP Basic Authorization header encoding, not code obfuscation.
					'Authorization' => 'Basic ' . base64_encode( $userpwd ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'error' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return array( 'error' => sprintf( 'Invalid API response (HTTP %d).', (int) $code ) );
		}

		if ( ( $data['errorCode'] ?? '' ) !== '000' ) {
			return array( 'error' => $data['errorMessage'] ?? 'Unknown API error' );
		}

		return $data;
	}

	/**
	 * Handle redirect.
	 *
	 * @param mixed  $configuration Configuration.
	 * @param string $merchant_token Merchant token.
	 * @param string $data Data.
	 * @return mixed
	 */
	public static function handle_redirect( $configuration, string $merchant_token, string $data ) {
		$c2p_client = new Connect2PayClient(
			self::API_URL,
			$configuration['payxpert_originator_id'],
			$configuration['payxpert_password']
		);

		if ( ! $c2p_client->handleRedirectStatus( $data, $merchant_token ) ) {
			return false;
		}

		$status = $c2p_client->getStatus();

		return array(
			'errorCode'  => $status->getErrorCode(),
			'customData' => $status->getCtrlCustomData(),
		);
	}

	/**
	 * Handle callback.
	 *
	 * @param array<string, mixed> $configuration Configuration.
	 * @return array<string, mixed>|false
	 */
	public static function handle_callback( array $configuration ) {
		$c2p_client = new Connect2PayClient(
			self::API_URL,
			$configuration['payxpert_originator_id'],
			$configuration['payxpert_password']
		);

		// @phpstan-ignore identical.alwaysFalse (Connect2Pay stub types handleCallbackStatus as always true)
		if ( false === $c2p_client->handleCallbackStatus() ) {
			return false;
		}

		$status = $c2p_client->getStatus();

		return array(
			'errorCode'    => $status->getErrorCode(),
			'errorMessage' => $status->getErrorMessage(),
			'customData'   => $status->getCtrlCustomData(),
			'transaction'  => $status->getLastInitialTransactionAttempt(),
			'orderId'      => $status->getOrderId(),
		);
	}

	/**
	 * Cancel subscription.
	 *
	 * @param mixed $configuration Configuration.
	 * @param int   $subscription_id Subscription id.
	 * @param int   $cancel_reason Cancel reason.
	 * @return mixed
	 */
	public static function cancel_subscription( $configuration, int $subscription_id, int $cancel_reason ) {
		$c2p_client = new Connect2PayClient(
			self::API_URL,
			$configuration['payxpert_originator_id'],
			$configuration['payxpert_password']
		);

		$code = $c2p_client->cancelSubscription( $subscription_id, $cancel_reason );

		if ( null == $code || 200 != $code ) {
			return array( 'error' => $c2p_client->getClientErrorMessage() );
		}

		return array(
			'code'    => $code,
			'message' => $c2p_client->getClientErrorMessage(),
		);
	}

	/**
	 * Resolve the Connect2Pay order shipping type for an order.
	 *
	 * @param \WC_Order|null $order Order to inspect, or null.
	 * @return string
	 */
	private static function resolve_order_shipping_type( $order ): string {
		if ( $order instanceof \WC_Order ) {
			if ( method_exists( $order, 'needs_shipping' ) ) {
				return $order->needs_shipping() ? OrderShippingType::TO_CARDHOLDER : OrderShippingType::DIGITAL_GOODS;
			}

			// Backward compatibility for older WooCommerce versions.
			if ( method_exists( $order, 'needs_shipping_address' ) ) {
				return $order->needs_shipping_address() ? OrderShippingType::TO_CARDHOLDER : OrderShippingType::DIGITAL_GOODS;
			}

			if ( method_exists( $order, 'get_items' ) ) {
				$shipping_items = $order->get_items( 'shipping' );
				return ! empty( $shipping_items ) ? OrderShippingType::TO_CARDHOLDER : OrderShippingType::DIGITAL_GOODS;
			}
		}

		if ( function_exists( 'WC' ) && WC()->cart && WC()->cart->needs_shipping() ) {
			return OrderShippingType::TO_CARDHOLDER;
		}

		return OrderShippingType::DIGITAL_GOODS;
	}

	/**
	 * Get format payment mean info.
	 *
	 * @param TransactionAttempt $transaction Transaction.
	 * @param mixed              $payment_mean_info Payment mean info.
	 * @return mixed
	 */
	private static function get_format_payment_mean_info( TransactionAttempt $transaction, $payment_mean_info = null ) {
		$result = array();

		if ( ! $payment_mean_info ) {
			return null;
		}

		switch ( $transaction->getPaymentMethod() ) {
			case PaymentMethod::CREDIT_CARD:
				$result['is_3D_secure'] = $payment_mean_info->getIs3DSecure();

				if ( null !== $payment_mean_info->getCardNumber() ) {
					$result['card_holder_name']  = $payment_mean_info->getCardHolderName();
					$result['card_number']       = $payment_mean_info->getCardNumber();
					$result['card_expiration']   = $payment_mean_info->getCardExpireMonth() . '/' . $payment_mean_info->getCardExpireYear();
					$result['card_brand']        = $payment_mean_info->getCardBrand();
					$result['card_level']        = null;
					$result['card_country_code'] = null;
					$result['card_bank_name']    = null;

					if ( null !== $payment_mean_info->getCardLevel() ) {
						$result['card_level']        = $payment_mean_info->getCardLevel();
						$result['card_country_code'] = $payment_mean_info->getIinCountry();
						$result['card_bank_name']    = $payment_mean_info->getIinBankName();
					}
				}

				break;
			case PaymentMethod::BANK_TRANSFER:
				$sender    = $payment_mean_info->getSender();
				$recipient = $payment_mean_info->getRecipient();

				$result = array(
					'sender'    => null == $sender ? null : array(
						'holder_name'  => $sender->getHolderName(),
						'bank_name'    => $sender->getBankName(),
						'iban'         => $sender->getIban(),
						'bic'          => $sender->getBic(),
						'country_code' => $sender->getCountryCode(),
					),
					'recipient' => null == $recipient ? null : array(
						'holder_name'  => $recipient->getHolderName(),
						'bank_name'    => $recipient->getBankName(),
						'iban'         => $recipient->getIban(),
						'bic'          => $recipient->getBic(),
						'country_code' => $recipient->getCountryCode(),
					),
				);

				break;
			case PaymentMethod::DIRECT_DEBIT:
				$result = array( 'account' => null );

				$account = $payment_mean_info->getBankAccount();
				if ( null !== $account ) {
					$sepa_mandate = $account->getSepaMandate();

					$result['account'] = array(
						'statement_descriptor' => $payment_mean_info->getStatementDescriptor(),
						'collected_at'         => $payment_mean_info->getCollectedAtAsDateTime() ? ( $payment_mean_info->getCollectedAtAsDateTime() )->format( 'Y-m-d H:i:s T' ) : null,
						'bank_account'         => array(
							'holder_name'  => $account->getHolderName(),
							'bank_name'    => $account->getBankName(),
							'iban'         => $account->getIban(),
							'bic'          => $account->getBic(),
							'country_code' => $account->getCountryCode(),
						),
						'sepa_mandate'         => null == $sepa_mandate ? null : array(
							'description'    => $sepa_mandate->getDescription(),
							'status'         => $sepa_mandate->getStatus(),
							'type'           => $sepa_mandate->getType(),
							'scheme'         => $sepa_mandate->getScheme(),
							'signature_type' => $sepa_mandate->getSignatureType(),
							'phone_number'   => $sepa_mandate->getPhoneNumber(),
							'signed_at'      => null == $sepa_mandate->getSignedAtAsDateTime() ? null : $sepa_mandate->getSignedAtAsDateTime()->format( 'Y-m-d H:i:s T' ),
							'created_at'     => null == $sepa_mandate->getCreatedAtAsDateTime() ? null : $sepa_mandate->getCreatedAtAsDateTime()->format( 'Y-m-d H:i:s T' ),
							'last_used_at'   => null == $sepa_mandate->getLastUsedAtAsDateTime() ? null : $sepa_mandate->getLastUsedAtAsDateTime()->format( 'Y-m-d H:i:s T' ),
							'download_url'   => $sepa_mandate->getDownloadUrl(),
						),
					);
				}

				break;
			case PaymentMethod::WECHAT:
			case PaymentMethod::ALIPAY:
				$result = array(
					'total_fee'     => $payment_mean_info->getTotalFee(),
					'exchange_rate' => $payment_mean_info->getExchangeRate(),
				);

				break;
		}

		return $result;
	}

	/**
	 * Get format shopper info.
	 *
	 * @param mixed $shopper Shopper.
	 * @return mixed
	 */
	private static function get_format_shopper_info( $shopper = null ) {
		if ( ! $shopper ) {
			return null;
		}

		return array(
			'name'         => $shopper->getFirstName(),
			'address1'     => $shopper->getAddress1(),
			'zip_code'     => $shopper->getZipcode(),
			'city'         => $shopper->getCity(),
			'country_code' => $shopper->getCountryCode(),
			'email'        => $shopper->getEmail(),
			'birth_date'   => $shopper->getBirthDate(),
			'id_number'    => $shopper->getIdNumber(),
			'ip_address'   => $shopper->getIpAddress(),
		);
	}

	/**
	 * Format transaction.
	 *
	 * @param mixed $transaction Transaction.
	 * @return mixed
	 */
	private static function format_transaction( $transaction ) {
		$payment_mean_info = $transaction->getPaymentMeanInfo();
		$shopper           = $transaction->getShopper();

		return array(
			'payment_id'              => $transaction->getPaymentID(),
			'payment_merchant_token'  => $transaction->getPaymentMerchantToken(),
			'order_id'                => $transaction->getOrderID(),
			'transaction_id'          => $transaction->getTransactionID(),
			'ref_transaction_id'      => $transaction->getRefTransactionID(),
			'provider_transaction_id' => $transaction->getProviderTransactionID(),
			'payment_method'          => $transaction->getPaymentMethod(),
			'operation'               => $transaction->getOperation(),
			'amount100'               => $transaction->getAmount(),
			'amount'                  => $transaction->getAmount() / 100,
			'refunded_amount100'      => $transaction->getRefundedAmount(),
			'refunded_amount'         => $transaction->getRefundedAmount() / 100,
			'currency'                => $transaction->getCurrency(),
			'result_code'             => $transaction->getResultCode(),
			'result_message'          => $transaction->getResultMessage(),
			'transaction_date'        => null == $transaction->getDateAsDateTime() ? null : ( $transaction->getDateAsDateTime() )->format( 'Y-m-d H:i:s T' ),
			'subscription_id'         => $transaction->getSubscriptionID(),
			'payment_mean_info'       => self::get_format_payment_mean_info( $transaction, $payment_mean_info ),
			'shopper'                 => self::get_format_shopper_info( $shopper ),
		);
	}

	/**
	 * Connect2Pay Shopper Account (SCA / 3DS v2 hints). Only for logged-in customers (not guest).
	 *
	 * @param Shopper $shopper     Shopper object to enrich with account hints.
	 * @param int     $customer_id WordPress customer ID (0 for guests).
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
			$age         = AccountAge::LESS_30_DAYS;
			$last_change = AccountLastChange::LESS_30_DAYS;
			$pw_change   = AccountPwChange::LESS_30_DAYS;
		} elseif ( $days <= 60 ) {
			$age         = AccountAge::BETWEEN_30_60_DAYS;
			$last_change = AccountLastChange::BETWEEN_30_60_DAYS;
			$pw_change   = AccountPwChange::BETWEEN_30_60_DAYS;
		} else {
			$age         = AccountAge::MORE_60_DAYS;
			$last_change = AccountLastChange::MORE_60_DAYS;
			$pw_change   = AccountPwChange::MORE_60_DAYS;
		}

		$date_str = gmdate( 'Ymd', $reg_ts );

		$account = new Account();
		$account->setAge( $age );
		$account->setDate( $date_str );
		$account->setLastChange( $last_change );
		$account->setLastChangeDate( $date_str );
		$account->setPwChange( $pw_change );
		$account->setPwChangeDate( $date_str );

		if ( function_exists( 'wc_get_customer_order_count' ) ) {
			// Field name is six-month window; we send total WC order count capped (best-effort when no date filter).
			$account->setOrderSixMonths( min( 99, (int) wc_get_customer_order_count( $customer_id ) ) );
		}

		$shopper->setAccount( $account );
	}

	/**
	 * Format home phone as local number (without country prefix).
	 *
	 * @param string $phone        Raw billing phone.
	 * @param string $country_code ISO country code.
	 * @return string
	 */
	private static function format_home_phone( string $phone, string $country_code ): string {
		$digits = preg_replace( '/\D/', '', $phone );

		if ( '' === $digits ) {
			return '';
		}

		$prefix = self::get_country_phone_prefix( $country_code );

		if ( str_starts_with( $digits, '00' ) ) {
			$digits = substr( $digits, 2 );
		}

		if ( $prefix && str_starts_with( $digits, $prefix ) ) {
			$digits = substr( $digits, strlen( $prefix ) );
		}

		$digits = ltrim( $digits, '0' );

		return substr( $digits, 0, 20 );
	}

	/**
	 * Get country phone prefix.
	 *
	 * @param mixed $country_code Country code.
	 * @return mixed
	 */
	private static function get_country_phone_prefix( $country_code ) {
		$prefixes = array(
			'FR' => '33',
			'BE' => '32',
			'CH' => '41',
			'US' => '1',
			// todo.
		);
		return $prefixes[ strtoupper( $country_code ) ] ?? '';
	}

	/**
	 * Format address.
	 *
	 * @param mixed $address Address.
	 * @return mixed
	 */
	private static function format_address( $address ) {
		return substr( trim( str_replace( "'", ' ', $address ) ), 0, 255 );
	}
}
