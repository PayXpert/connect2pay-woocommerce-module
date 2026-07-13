<?php
/**
 * Html Subscription List.
 *
 * @package Payxpert
 */

use Payxpert\Models\Payxpert_Subscription;
use Payxpert\Utils\WC_Payxpert_Utils;

defined( 'ABSPATH' ) || exit;

$payxpert_current_user_id = get_current_user_id();
$payxpert_per_page        = 5;
$payxpert_page            = max( 1, intval( get_query_var( 'payxpert-subscription-list', 1 ) ) );
$payxpert_offset          = ( $payxpert_page - 1 ) * $payxpert_per_page;

$payxpert_total_items = Payxpert_Subscription::count_user_subscriptions( $payxpert_current_user_id );
$payxpert_total_pages = (int) ceil( $payxpert_total_items / $payxpert_per_page );

$payxpert_subscriptions = Payxpert_Subscription::get_user_subscriptions( $payxpert_current_user_id, $payxpert_per_page, $payxpert_offset );

?>

<h3><?php esc_html_e( 'Subscriptions', 'payxpert' ); ?></h3>

<?php if ( $payxpert_subscriptions ) : ?>
	<table class="shop_table shop_table_responsive" style="font-size:0.85rem">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Referral Order ID', 'payxpert' ); ?></th>
				<th><?php esc_html_e( 'Type', 'payxpert' ); ?></th>
				<th><?php esc_html_e( 'Status', 'payxpert' ); ?></th>
				<th><?php esc_html_e( 'Period', 'payxpert' ); ?></th>
				<th><?php esc_html_e( 'Next Payment', 'payxpert' ); ?></th>
				<th><?php esc_html_e( 'Amount', 'payxpert' ); ?></th>
				<th><?php esc_html_e( 'Payment Method', 'payxpert' ); ?></th>
				<th><?php esc_html_e( 'Details', 'payxpert' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php
		foreach ( $payxpert_subscriptions as $payxpert_subscription ) :
			$payxpert_order_url      = wc_get_account_endpoint_url( 'view-order' ) . $payxpert_subscription->order_id;
			$payxpert_payment_method = WC_Payxpert_Utils::format_payment_method_display(
				$payxpert_subscription->card_brand ?? '',
				$payxpert_subscription->card_number ?? '',
				$payxpert_subscription->payment_method ?? ''
			);

			?>
			<tr>
				<td>    
					<a href="<?php echo esc_url( $payxpert_order_url ); ?>">
						#<?php echo esc_html( $payxpert_subscription->order_id ); ?>
					</a>
				</td>
				<td><?php echo esc_html( $payxpert_subscription->subscription_type ); ?></td>
				<td><?php echo esc_html( __( $payxpert_subscription->state, 'payxpert' ) ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- State comes from a fixed internal set. ?></td>
				<td><?php echo esc_html( WC_Payxpert_Utils::render_human_period( $payxpert_subscription->period ) ); ?></td>
				<td>
				<?php
				echo esc_html(
					! empty( $payxpert_subscription->period_end )
					? date_i18n( get_option( 'date_format' ), $payxpert_subscription->period_end )
					: '-'
				);
				?>
				</td>
				<td><?php echo wp_kses_post( wc_price( $payxpert_subscription->amount / 100 ) ); ?></td>
				<td><?php echo esc_html( $payxpert_payment_method ); ?></td>
				<td style="display:flex;flex-direction: column;gap: 2px;align-items: flex-start;">
					<a class="woocommerce-button wp-element-button button view" href="<?php echo esc_url( wc_get_account_endpoint_url( 'payxpert-subscription-details' ) . $payxpert_subscription->subscription_id ); ?>">
						<?php esc_html_e( 'View', 'payxpert' ); ?>
					</a>
					<?php if ( Payxpert_Subscription::STATUS_ACTIVE === $payxpert_subscription->state ) : ?>
						<button type="button" 
							class="woocommerce-button wp-element-button button cancel-subscription" 
							data-subscription-id="<?php echo esc_attr( $payxpert_subscription->subscription_id ); ?>">
							<?php esc_html_e( 'Cancel', 'payxpert' ); ?>
						</button>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<?php include WC_PAYXPERT_PLUGIN_FILE_PATH . 'templates/views/html-modal-cancel-subscription.php'; ?>

	<?php if ( $payxpert_total_pages > 1 ) : ?>
		<div class="woocommerce-pagination woocommerce-pagination--without-numbers">
			<?php
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => trailingslashit( wc_get_account_endpoint_url( 'payxpert-subscription-list' ) ) . '%#%/',
						'format'    => '%#%/',
						'current'   => $payxpert_page,
						'total'     => (int) $payxpert_total_pages,
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
					)
				)
			);
			?>
		</div>
	<?php endif; ?>

<?php else : ?>
	<p><?php esc_html_e( 'You have no active subscriptions.', 'payxpert' ); ?></p>
<?php endif; ?>
