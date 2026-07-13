<?php
/**
 * Wc Payxpert Utils.
 *
 * @package Payxpert
 */

declare(strict_types=1);

namespace Payxpert\Utils;

defined( 'ABSPATH' ) || exit();

use DateInterval;
use Payxpert\Models\Payxpert_Payment_Transaction;
use Payxpert\Models\Payxpert_Subscription;
use WC_Order;
use WC_Order_Item_Shipping;
use WC_Payment_Gateway_Payxpert_Installment_X2;
use WC_Payment_Gateway_Payxpert_Installment_X3;
use WC_Payment_Gateway_Payxpert_Installment_X4;

require_once WC_PAYXPERT_PLUGIN_FILE_PATH . 'includes/admin/class-wc-payxpert-settings.php';

/**
 * WC Payxpert Utils.
 */
class WC_Payxpert_Utils {

	public const IPN_SECRET_OPTION_KEY = 'payxpert_ipn_secret';

	/**
	 * Build instalment schedule.
	 *
	 * @param mixed $amount Amount.
	 * @param mixed $first_percentage First percentage.
	 * @param mixed $x_times X times.
	 * @return mixed
	 * @throws \Exception If the WooCommerce currency cannot be resolved.
	 */
	public static function build_instalment_schedule( $amount, $first_percentage, $x_times ) {
		$schedule = array();
		$date     = new \DateTime();

		// Active WooCommerce currency.
		$currency = get_woocommerce_currency();

		// Basic validation.
		if ( ! $currency ) {
			throw new \Exception( 'Currency not found.' );
		}

		// Compute instalment amounts.
		list($instalment_first_amount, $rebill_amount) = self::calculate_instalment_amounts(
			(int) round( $amount * 100 ),
			intval( $first_percentage ),
			$x_times
		);

		// First instalment.
		$schedule[] = array(
			'amount'          => $instalment_first_amount / 100,
			'amountFormatted' => wc_price( $instalment_first_amount / 100, array( 'currency' => $currency ) ),
			'date'            => date_i18n( get_option( 'date_format' ), $date->getTimestamp() ),
		);

		// Following instalments.
		for ( $i = 0; $i < $x_times - 1; ++$i ) {
			$date->modify( '+30 days' );

			$schedule[] = array(
				'amount'          => $rebill_amount / 100,
				'amountFormatted' => wc_price( $rebill_amount / 100, array( 'currency' => $currency ) ),
				'date'            => date_i18n( get_option( 'date_format' ), $date->getTimestamp() ),
			);
		}

		return $schedule;
	}

	/**
	 * Calculate instalment amounts.
	 *
	 * @param int $amount Amount.
	 * @param int $first_percentage First percentage.
	 * @param int $x_times X times.
	 * @return array{0: float, 1: float}
	 * @throws \Exception If the number of instalments is invalid.
	 */
	public static function calculate_instalment_amounts( int $amount, int $first_percentage, int $x_times ): array {
		if ( $x_times < 1 ) {
			throw new \Exception( 'xTimes must be at least 1.' );
		}

		$instalment_first_amount = round( ( $first_percentage * $amount ) / 100 );
		$remaining_instalments   = $x_times - 1;
		$rebill_amount           = $remaining_instalments > 0 ? round( ( $amount - $instalment_first_amount ) / $remaining_instalments, 2 ) : 0;
		$total_calculated        = $instalment_first_amount + ( $rebill_amount * $remaining_instalments );
		$difference              = $amount - $total_calculated;

		if ( abs( $difference ) >= 1 ) {
			$instalment_first_amount += $difference;
		}

		return array(
			intval( $instalment_first_amount ),
			intval( $rebill_amount ),
		);
	}

	/**
	 * Get configuration.
	 *
	 * @return mixed
	 */
	public static function get_configuration() {
		$configuration = array(
			'payxpert_originator_id'    => get_option( 'payxpert_originator_id' ),
			'payxpert_password'         => get_option( 'payxpert_password' ),
			'payxpert_conn_status'      => get_option( 'payxpert_conn_status' ),
			'payxpert_seamless_library' => get_option( 'payxpert_seamless_library' ),
		);

		$field_keys = array_reduce(
			\WC_Payxpert_Settings::get_settings(),
			function ( $carry, $s ) {
				return ! empty( $s['fields'] )
					? array_merge( $carry, array_keys( $s['fields'] ) )
					: $carry;
			},
			array()
		);

		foreach ( $field_keys as $key ) {
			$configuration[ $key ] = get_option( $key );
		}

		return $configuration;
	}

	/**
	 * Get option.
	 *
	 * @param mixed $key Key.
	 * @param mixed $default Default.
	 * @return mixed
	 */
	public static function get_option( $key, $default = null ) {
		$options = self::get_configuration();
		return $options[ $key ] ?? $default;
	}

	/**
	 * Translate customizable text options when a multilingual plugin is available.
	 * Falls back to the original value when no integration exists.
	 *
	 * @param string $value Text value to translate.
	 * @param string $name  Option name used as the translation string key.
	 * @return string
	 */
	public static function translate_custom_text( string $value, string $name ): string {
		if ( '' === $value ) {
			return $value;
		}

		$context      = 'PayXpert';
		$source_value = $value;

		if ( function_exists( 'pll_register_string' ) ) {
			pll_register_string( $name, $source_value, $context );
		}

		if ( function_exists( 'pll__' ) ) {
			$value = (string) pll__( $source_value );
		}

		if ( has_action( 'wpml_register_single_string' ) ) {
			// WPML-owned action that registers a translatable string.
			do_action( 'wpml_register_single_string', $context, $name, $source_value ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks -- Third-party (WPML) hook, not owned by this plugin.
		}

		if ( has_filter( 'wpml_translate_single_string' ) ) {
			// WPML-owned filter that translates a registered string.
			$value = (string) apply_filters( 'wpml_translate_single_string', $value, $context, $name ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks -- Third-party (WPML) hook, not owned by this plugin.
		}

		return $value;
	}

	/**
	 * Ensure ipn secret.
	 *
	 * @return string
	 */
	public static function ensure_ipn_secret(): string {
		$secret = (string) get_option( self::IPN_SECRET_OPTION_KEY, '' );
		if ( strlen( $secret ) >= 43 ) {
			return $secret;
		}

		// 32 random bytes encoded in base64url gives >= 43 chars.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding random bytes into a URL-safe secret token, not obfuscating code.
		$secret = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		update_option( self::IPN_SECRET_OPTION_KEY, $secret, false );

		return $secret;
	}

	/**
	 * To snake case keys.
	 *
	 * @param array<string, mixed> $array Array.
	 * @return array<string, mixed>
	 */
	public static function to_snake_case_keys( array $array ): array {
		$result = array();

		foreach ( $array as $key => $value ) {
			// Convert camelCase or PascalCase to snake_case.
			$snake_key            = strtolower( preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', $key ) );
			$result[ $snake_key ] = $value;
		}

		return $result;
	}

	/**
	 * Render human period.
	 *
	 * @param mixed $period Period.
	 * @return mixed
	 */
	public static function render_human_period( $period ) {
		try {
			$interval = new DateInterval( $period );
			$parts    = array();
			if ( $interval->y ) {
				$parts[] = $interval->y . ' ' . __( 'year(s)', 'payxpert' );
			}
			if ( $interval->m ) {
				$parts[] = $interval->m . ' ' . __( 'month(s)', 'payxpert' );
			}
			if ( $interval->d ) {
				$parts[] = $interval->d . ' ' . __( 'day(s)', 'payxpert' );
			}
			if ( $interval->h ) {
				$parts[] = $interval->h . ' ' . __( 'hour(s)', 'payxpert' );
			}
			if ( $interval->i ) {
				$parts[] = $interval->i . ' ' . __( 'minute(s)', 'payxpert' );
			}
			if ( $interval->s ) {
				$parts[] = $interval->s . ' ' . __( 'second(s)', 'payxpert' );
			}
			$joined = implode( ', ', $parts );
			return $joined ? $joined : '—';
		} catch ( \Exception $e ) {
			return $period;
		}
	}

	/**
	 * Get order transactions formatted.
	 *
	 * @param list<array<string, mixed>> $transactions Transactions.
	 * @return array<string, mixed>
	 */
	public static function get_order_transactions_formatted( array $transactions ): array {
		$refundable      = array();
		$capturable      = array();
		$captured        = array();
		$refunded        = array();
		$order_slip_used = array();

		foreach ( $transactions as $transaction ) {
			if ( Payxpert_Payment_Transaction::RESULT_CODE_SUCCESS !== $transaction['result_code'] ) {
				continue;
			}

			if (
				Payxpert_Payment_Transaction::OPERATION_SALE == $transaction['operation']
				|| Payxpert_Payment_Transaction::OPERATION_CAPTURE == $transaction['operation']
			) {
				$refundable[ $transaction['transaction_id'] ] = $transaction;
			}

			if ( Payxpert_Payment_Transaction::OPERATION_AUTHORIZE === $transaction['operation'] ) {
				$capturable[ $transaction['transaction_id'] ] = $transaction;
			}

			if ( isset( $transaction['transaction_referal_id'] ) ) {
				if ( Payxpert_Payment_Transaction::OPERATION_CAPTURE == $transaction['operation'] ) {
					$captured[ $transaction['transaction_referal_id'] ] = $transaction;
				} elseif ( Payxpert_Payment_Transaction::OPERATION_REFUND == $transaction['operation'] ) {
					$refunded[ $transaction['transaction_referal_id'] ][] = $transaction;
				}
			}

			if ( ! is_null( $transaction['order_slip_id'] ) ) {
				$order_slip_used[] = $transaction['order_slip_id'];
			}
		}

		foreach ( $refundable as $transaction_id => &$refundable_transaction ) {
			$refundable_transaction['refundable_amount'] = $refundable_transaction['amount'];

			if ( isset( $refunded[ $transaction_id ] ) ) {
				$refunded_amount                              = array_sum( array_column( $refunded[ $transaction_id ], 'amount' ) );
				$refundable_transaction['refundable_amount'] -= $refunded_amount;
			}

			if ( $refundable_transaction['refundable_amount'] <= 0 ) {
				unset( $refundable[ $transaction_id ] );
			}
		}

		$capturable = array_diff_key( $capturable, $captured );

		return array(
			'refundable'      => $refundable,
			'capturable'      => $capturable,
			'refunded'        => $refunded,
			'captured'        => $captured,
			'order_slip_used' => $order_slip_used,
		);
	}

	/**
	 * Is hpos enabled.
	 *
	 * @return mixed
	 */
	public static function is_hpos_enabled() {
		return class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' )
			&& \Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled( 'custom_order_tables' );
	}

	/**
	 * Sync subscription.
	 *
	 * @param array<string, mixed> $configuration Configuration.
	 * @param array<string, mixed> $subscription Subscription.
	 * @param array<string, mixed> $first_transaction First transaction.
	 */
	public static function sync_subscription( array $configuration, array $subscription, array $first_transaction ): bool {
		$updated           = false;
		$subscription_type = $subscription['subscription']['subscriptionType'] ?? null;

		// Closure to persist a PayXpert transaction row.
		$create_transaction = function ( array $first_transaction_data, array $sub_transaction_data, $order_id = null ) {
			$transaction = new Payxpert_Payment_Transaction();
			$transaction->set(
				array_merge(
					$first_transaction_data,
					array(
						'id_payxpert_payment_transaction' => null,
						'order_id'                        => $order_id ?? $sub_transaction_data['order_id'],
						'transaction_id'                  => $sub_transaction_data['transactionID'],
						'transaction_referal_id'          => $sub_transaction_data['referralID'],
						'amount'                          => (float) ( $sub_transaction_data['amount'] / 100 ),
						'result_code'                     => $sub_transaction_data['errorCode'],
						'result_message'                  => $sub_transaction_data['status'],
						'date_add'                        => gmdate( 'Y-m-d H:i:s', $sub_transaction_data['date'] ),
						'card_number'                     => $sub_transaction_data['paymentMeanInfo']['cardNumber'],
						'card_brand'                      => $sub_transaction_data['paymentMeanInfo']['cardBrand'],
					)
				)
			);
			$transaction->save();
		};

		// Reference order for non-instalment subscription types.
		$original_order = null;
		if ( 'partpayment' !== $subscription_type ) {
			$original_order = wc_get_order( $first_transaction['order_id'] );
		}

		// Iterate API transaction list.
		foreach ( $subscription['transactionList'] as $subscription_transaction ) {
			// Skip the first (already stored) transaction.
			if ( $subscription_transaction['transactionID'] == $first_transaction['transaction_id'] ) {
				continue;
			}

			// Skip if already imported.
			$transaction_exist = Payxpert_Payment_Transaction::find_by_transaction_id( (string) $subscription_transaction['transactionID'] );
			if ( $transaction_exist ) {
				continue;
			}

			$order_id = null;

			// Non-instalment: create a child order per renewal transaction.
			if ( 'partpayment' !== $subscription_type && $original_order ) {
				$new_order = wc_create_order();

				// Copy line items.
				foreach ( $original_order->get_items() as $item ) {
					if ( ! $item instanceof \WC_Order_Item_Product ) {
						continue;
					}
					$new_order->add_product(
						$item->get_product(),
						$item->get_quantity(),
						array(
							'subtotal' => $item->get_subtotal(),
							'total'    => $item->get_total(),
							'tax'      => $item->get_total_tax(),
						)
					);
				}

				foreach ( $original_order->get_items( 'shipping' ) as $shipping_item_id => $shipping_item ) {
					if ( ! $shipping_item instanceof WC_Order_Item_Shipping ) {
						continue;
					}
					$new_shipping_item = new WC_Order_Item_Shipping();

					// Copy shipping line from the original order.
					$new_shipping_item->set_method_title( $shipping_item->get_method_title() );
					$new_shipping_item->set_method_id( $shipping_item->get_method_id() );
					$new_shipping_item->set_total( $shipping_item->get_total() );
					$new_shipping_item->set_taxes( $shipping_item->get_taxes() );

					$new_order->add_item( $new_shipping_item );
				}

				$current_user_id  = $original_order->get_user_id();
				$shipping_address = array(
					'first_name' => get_user_meta( $current_user_id, 'shipping_first_name', true ),
					'last_name'  => get_user_meta( $current_user_id, 'shipping_last_name', true ),
					'company'    => get_user_meta( $current_user_id, 'shipping_company', true ),
					'address_1'  => get_user_meta( $current_user_id, 'shipping_address_1', true ),
					'address_2'  => get_user_meta( $current_user_id, 'shipping_address_2', true ),
					'city'       => get_user_meta( $current_user_id, 'shipping_city', true ),
					'postcode'   => get_user_meta( $current_user_id, 'shipping_postcode', true ),
					'country'    => get_user_meta( $current_user_id, 'shipping_country', true ),
					'state'      => get_user_meta( $current_user_id, 'shipping_state', true ),
				);

				$new_order->set_address( $original_order->get_address( 'billing' ), 'billing' );
				$new_order->set_address( $shipping_address, 'shipping' );
				$new_order->set_customer_id( $original_order->get_customer_id() );
				$new_order->set_payment_method( $original_order->get_payment_method() );
				$new_order->set_payment_method_title( $original_order->get_payment_method_title() );
				$new_order->set_parent_id( $first_transaction['order_id'] );
				$new_order->set_date_paid( gmdate( 'Y-m-d H:i:s', $subscription_transaction['date'] ) );
				$new_order->add_meta_data( 'payxpert_transaction_id', $subscription_transaction['transactionID'] );

				$transaction_info = WC_Payxpert_Webservice::get_transaction_info( $configuration, $subscription_transaction['transactionID'] );
				if ( empty( $transaction_info['error'] ) ) {
					$new_order->add_meta_data( 'payxpert_payment_id', $transaction_info['payment_id'] );
				}

				$is_success = Payxpert_Payment_Transaction::RESULT_CODE_SUCCESS == $subscription_transaction['errorCode'];

				$new_order->calculate_totals();
				// Sub price might be different from total_product + total_shipping.
				$new_order->set_total( wc_format_decimal( $subscription['subscription']['amount'] / 100, wc_get_price_decimals() ) );
				$new_order->set_shipping_total( '0' );

				$new_order->add_order_note( __( 'New order created by subscription.', 'payxpert' ) );

				if ( $is_success ) {
					// Payment OK.
					$new_order->payment_complete( $subscription_transaction['transactionID'] );
				} else {
					// Payment KO.
					$new_order->update_status(
						'failed',
						__( 'Payment failed on subscription renewal.', 'payxpert' )
					);
				}

				WC_Payxpert_Logger::info( 'New order created [originalOrderID: ' . $original_order->get_id() . ', newOrderID: ' . $new_order->get_id() . ']' );

				$order_id = $new_order->get_id();
			} else {
				$order_id = $first_transaction['order_id'];
			}

			// Persist transaction row for this order.
			$create_transaction( $first_transaction, $subscription_transaction, $order_id );
			WC_Payxpert_Logger::info( 'Create transaction [transactionID : ' . $subscription_transaction['transactionID'] . ']' );
			$updated = true;
		}

		// Update local subscription row from API payload.
		$payxpert_subscription_array = Payxpert_Subscription::find_by_subscription_id( (string) $first_transaction['subscription_id'] );
		$subscription_info           = self::to_snake_case_keys( $subscription['subscription'] );
		$payxpert_subscription       = Payxpert_Subscription::get_instance_from_array( $payxpert_subscription_array );
		$payxpert_subscription->set( $subscription_info );
		$payxpert_subscription->save();

		// TODO : maybe status 'finished' is better.
		if ( $updated && 'partpayment' == $subscription_type && ( $subscription_info['iterations_left'] ?? 0 ) == 0 ) {
			$order = wc_get_order( $first_transaction['order_id'] );
			if ( $order ) {
				$new_status = $order->needs_processing() ? 'processing' : 'completed';
				$order->update_status( $new_status, __( 'Status updated after payment captured via PayXpert.', 'payxpert' ) );
			}
		}

		return true;
	}


	/**
	 * Is installment payment available.
	 *
	 * @param mixed $configuration Configuration.
	 * @param mixed $amount Amount.
	 * @return mixed
	 */
	public static function is_installment_payment_available( $configuration, $amount ) {
		$min = $configuration['payxpert_instalment_payment_min_amount'];
		$max = $configuration['payxpert_instalment_payment_max_amount'];

		return $min < $amount && ( ! $max || $max >= $amount );
	}

	/**
	 * Has subscription in cart.
	 *
	 * @param mixed $cart Cart.
	 * @param mixed $return_meta Return meta.
	 * @return mixed
	 */
	public static function has_subscription_in_cart( $cart, $return_meta = false ) {
		return self::get_subscription_from_items(
			$cart,
			fn( $item ) => $item['data'],
			fn( $item ) => $item['quantity'],
			$return_meta
		);
	}

	/**
	 * Has subscription in order.
	 *
	 * @param mixed $order Order.
	 * @param mixed $return_meta Return meta.
	 * @return mixed
	 */
	public static function has_subscription_in_order( $order, $return_meta = false ) {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		return self::get_subscription_from_items(
			$order->get_items(),
			fn( $item ) => $item->get_product(),
			fn( $item ) => $item->get_quantity(),
			$return_meta
		);
	}

	/**
	 * Get subscription from items.
	 *
	 * @param mixed    $items Items.
	 * @param callable $get_product Get product.
	 * @param callable $get_quantity Get quantity.
	 * @param mixed    $return_meta Return meta.
	 * @return mixed
	 */
	private static function get_subscription_from_items( $items, callable $get_product, callable $get_quantity, $return_meta = false ) {
		foreach ( $items as $item ) {

			$product = $get_product( $item );
			if ( ! $product ) {
				continue;
			}

			if ( $product->get_meta( '_payxpert_subscription' ) === 'yes' ) {

				if ( ! $return_meta ) {
					return true;
				}

				$subscription_meta = array();

				foreach ( $product->get_meta_data() as $meta ) {
					if ( strpos( $meta->key, '_payxpert_subscription' ) === 0 ) {
						$subscription_meta[ $meta->key ] = $meta->value;
					}
				}

				$subscription_meta['quantity'] = $get_quantity( $item );

				return $subscription_meta;
			}
		}

		return false;
	}

	/**
	 * Get period by frequency.
	 *
	 * @param mixed $frequency Frequency.
	 * @return mixed
	 */
	public static function get_period_by_frequency( $frequency ) {
		$frequency_map = array(
			// Journalier.
			'daily'     => 'P1D',
			'weekly'    => 'P1W',
			'monthly'   => 'P1M',
			'quarterly' => 'P3M',
			'yearly'    => 'P12M',
		);

		return $frequency_map[ $frequency ];
	}

	/**
	 * Build a human-readable payment method label.
	 *
	 * @param string $card_brand     Card brand (e.g. VISA).
	 * @param string $card_number    Full or masked card number; only the last 4 digits are used.
	 * @param string $payment_method Fallback payment method label when no card brand is provided.
	 * @return string
	 */
	public static function format_payment_method_display( $card_brand, $card_number = '', $payment_method = '' ) {
		if ( ! empty( $card_brand ) ) {
			$display = strtoupper( $card_brand );
			if ( ! empty( $card_number ) ) {
				$display .= ' - ***' . substr( $card_number, -4, 4 );
			}
			return $display;
		}

		return $payment_method ? $payment_method : '—';
	}

	/**
	 * Get humanize subscription frequency.
	 *
	 * @param mixed $frequency Frequency.
	 * @return mixed
	 */
	public static function get_humanize_subscription_frequency( $frequency ) {

		switch ( $frequency ) {

			case 'daily':
				return __( 'every day', 'payxpert' );

			case 'weekly':
				return __( 'every week', 'payxpert' );

			case 'monthly':
				return __( 'every month', 'payxpert' );

			case 'quarterly':
				return __( 'every 3 months', 'payxpert' );

			case 'yearly':
				return __( 'every year', 'payxpert' );

			default:
				return __( 'frequency not specified', 'payxpert' );
		}
	}

	/**
	 * Return a human-readable first payment description.
	 * Translatable via WordPress.
	 *
	 * @param string     $rule          Payment rule (only 'immediate' is handled).
	 * @param string     $frequency     Billing frequency code.
	 * @param string     $duration_type 'finite' | 'infinite'.
	 * @param int|string $for           Number of occurrences (e.g. 5).
	 * @return string
	 */
	public static function get_subscription_first_payment_text(
		$rule,
		$frequency,
		$duration_type,
		$for
	) {
		$frequency_text = self::get_humanize_subscription_frequency( $frequency );

		// Only handling "immediate" for now.
		if ( 'immediate' !== $rule && ! empty( $rule ) ) {
			return __( 'Unknown payment rule.', 'payxpert' );
		}

		// Infinite duration.
		if ( 'infinite' === $duration_type ) {
			return sprintf(
				/* translators: %s = frequency */
				__( 'First payment today, then %s.', 'payxpert' ),
				$frequency_text
			);
		}

		// Finite duration (X times).
		return sprintf(
			/* translators: 1: frequency, 2: number of times */
			__( 'First payment today, then %1$s (%2$s times).', 'payxpert' ),
			$frequency_text,
			esc_html( $for )
		);
	}

	/**
	 * Returns the PayXpert gateway icon file name for the given configuration.
	 *
	 * @param array<string, mixed> $configuration Gateway settings.
	 * @param string               $gateway_id    Gateway ID.
	 *
	 * @return string|null Public URL to the icon asset.
	 */
	public static function get_gateway_icon_name( array $configuration, string $gateway_id ): ?string {
		if ( in_array(
			$gateway_id,
			array(
				WC_Payment_Gateway_Payxpert_Installment_X2::ID,
				WC_Payment_Gateway_Payxpert_Installment_X3::ID,
				WC_Payment_Gateway_Payxpert_Installment_X4::ID,
			)
		) ) {
			return null;
		}

		$has_amex = isset( $configuration['payxpert_amex_enabled'] )
					? 'yes' === $configuration['payxpert_amex_enabled']
					: true;

		$has_apple_pay = isset( $configuration['payxpert_applepay_enabled'] )
					? 'yes' === $configuration['payxpert_applepay_enabled']
					: false;

		$icon = 'cc'; // default.

		if ( ! $has_amex && ! $has_apple_pay ) {
			$icon = 'cc-no-amex-no-applepay';
		} elseif ( ! $has_amex ) {
			$icon = 'cc-no-amex';
		} elseif ( ! $has_apple_pay ) {
			$icon = 'cc-no-applepay';
		}

		return plugins_url( 'assets/img/payment_method_logo/' . $icon . '.jpg', WC_PAYXPERT_PLUGIN_NAME );
	}

	/**
	 * Get gateway block icons.
	 *
	 * @param array<string, mixed> $configuration Configuration.
	 * @return list<string>
	 */
	public static function get_gateway_block_icons( array $configuration ): array {
		$icons = array( Payxpert_Payment_Transaction::VISA_ICON, Payxpert_Payment_Transaction::MASTERCARD_ICON );
		if ( 'yes' === $configuration['payxpert_amex_enabled'] ) {
			$icons[] = Payxpert_Payment_Transaction::AMEX_ICON;
		}
		if ( 'yes' === $configuration['payxpert_applepay_enabled'] ) {
			$icons[] = Payxpert_Payment_Transaction::APPLEPAY_ICON;
		}

		return $icons;
	}
}
