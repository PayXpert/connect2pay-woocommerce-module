<?php
/**
 * Html Subscription Details.
 *
 * @package Payxpert
 */

use Payxpert\Models\Payxpert_Subscription;
use Payxpert\Models\Payxpert_Payment_Transaction;
use Payxpert\Utils\WC_Payxpert_Utils;

defined( 'ABSPATH' ) || exit;

$payxpert_current_user_id = get_current_user_id();
$payxpert_subscription_id = get_query_var( 'payxpert-subscription-details' );

// Load subscription row for the current customer.
$payxpert_subscription = Payxpert_Subscription::find_one_by(
	array(
		'subscription_id' => $payxpert_subscription_id,
		'customer_id'     => $payxpert_current_user_id,
	)
);

if ( ! $payxpert_subscription ) {
	echo '<p>' . esc_html__( 'Subscription not found.', 'payxpert' ) . '</p>';
	return;
}

// Load transactions to resolve the original WooCommerce order.
$payxpert_all_transactions_full = Payxpert_Payment_Transaction::find_by( array( 'subscription_id' => $payxpert_subscription_id ) );
$payxpert_first_tx              = $payxpert_all_transactions_full[0] ?? null;
$payxpert_original_order        = $payxpert_first_tx ? wc_get_order( $payxpert_first_tx['order_id'] ) : null;

// Transaction list pagination.
$payxpert_page     = get_query_var( 'paged' ) ? max( 1, intval( get_query_var( 'paged' ) ) ) : 1;
$payxpert_per_page = 5;
$payxpert_offset   = ( $payxpert_page - 1 ) * $payxpert_per_page;

// Sort transactions by date (newest first).
usort(
	$payxpert_all_transactions_full,
	function ( $a, $b ) {
		return ( $b['date'] ?? 0 ) <=> ( $a['date'] ?? 0 );
	}
);

$payxpert_total_items = count( $payxpert_all_transactions_full );
$payxpert_total_pages = (int) ceil( $payxpert_total_items / $payxpert_per_page );

$payxpert_transactions = array_slice( $payxpert_all_transactions_full, $payxpert_offset, $payxpert_per_page );
?>

<!-- Subscription summary -->
<?php // translators: %s is the subscription ID. ?>
<h3><?php echo esc_html( sprintf( __( 'Subscription #%s', 'payxpert' ), $payxpert_subscription_id ) ); ?></h3>

<p>
	<strong><?php esc_html_e( 'Status', 'payxpert' ); ?>:</strong> <?php echo esc_html( __( $payxpert_subscription['state'], 'payxpert' ) ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- State comes from a fixed internal set. ?><br>
	<strong><?php esc_html_e( 'Type', 'payxpert' ); ?>:</strong> <?php echo esc_html( __( $payxpert_subscription['subscription_type'], 'payxpert' ) ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Type comes from a fixed internal set. ?><br>
	<strong><?php esc_html_e( 'Recurring Amount', 'payxpert' ); ?>:</strong> <?php echo wp_kses_post( wc_price( $payxpert_subscription['amount'] / 100 ) ); ?><br>
	<strong><?php esc_html_e( 'Period', 'payxpert' ); ?>:</strong> <?php echo esc_html( WC_Payxpert_Utils::render_human_period( $payxpert_subscription['period'] ) ); ?>
</p>

<?php if ( ! empty( $payxpert_subscription['iterations_left'] ) ) : ?>
	<p><strong><?php esc_html_e( 'Iterations left', 'payxpert' ); ?>:</strong> <?php echo intval( $payxpert_subscription['iterations_left'] ); ?></p>
<?php endif; ?>

<!-- Next payment -->
<?php if ( $payxpert_subscription['period_end'] ?? null ) : ?>
	<h4><?php esc_html_e( 'Next payment', 'payxpert' ); ?></h4>
	<p>
		<strong><?php esc_html_e( 'Amount', 'payxpert' ); ?>:</strong> <?php echo wp_kses_post( wc_price( $payxpert_subscription['amount'] / 100 ) ); ?><br>
		<strong><?php esc_html_e( 'Date', 'payxpert' ); ?>:</strong> <?php echo esc_html( date_i18n( get_option( 'date_format' ), $payxpert_subscription['period_end'] ) ); ?><br>
		<?php if ( isset( $payxpert_first_tx ) ) : ?>
			<strong><?php esc_html_e( 'Payment Info', 'payxpert' ); ?>:</strong>
			<?php echo esc_html( strtoupper( $payxpert_first_tx['card_brand'] ) ) . ' - ***' . esc_html( substr( $payxpert_first_tx['card_number'], -4, 4 ) ); ?>
		<?php endif; ?>
		
	</p>
<?php endif; ?>

<!-- Original Order -->
<?php
if ( $payxpert_original_order ) :
	?>
	<!-- Delivery address -->
	<h4><?php esc_html_e( 'Shipping address', 'payxpert' ); ?></h4>
	<address>
		<?php echo wp_kses_post( $payxpert_original_order->get_formatted_shipping_address( esc_html__( 'N/A', 'payxpert' ) ) ); ?>

		<?php if ( $payxpert_original_order->get_shipping_phone() ) : ?>
			<p class="woocommerce-customer-details--phone"><?php echo esc_html( $payxpert_original_order->get_shipping_phone() ); ?></p>
		<?php endif; ?>
	</address>
	<p>
		<?php esc_html_e( 'If you want to change your shipping address, please update it ', 'payxpert' ); ?>
		<a href="<?php echo esc_url( wc_get_account_endpoint_url( 'edit-address/shipping' ) ); ?>">
			<?php esc_html_e( 'here', 'payxpert' ); ?>.
		</a>
	</p>


	<!-- Initial order items -->
	<h4><?php esc_html_e( 'Products of the initial order', 'payxpert' ); ?></h4>
	<table class="shop_table shop_table_responsive">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Product', 'payxpert' ); ?></th>
				<th><?php esc_html_e( 'Quantity', 'payxpert' ); ?></th>
				<th><?php esc_html_e( 'Total', 'payxpert' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $payxpert_original_order->get_items() as $payxpert_item ) : ?>
			<tr>
				<td><?php echo esc_html( $payxpert_item->get_name() ); ?></td>
				<td><?php echo esc_html( (string) $payxpert_item->get_quantity() ); ?></td>
				<td><?php echo wp_kses_post( wc_price( $payxpert_item->get_total() ) ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>

<!-- Transaction history -->
<h4><?php esc_html_e( 'Occurences history', 'payxpert' ); ?></h4>

<table class="shop_table shop_table_responsive">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Order ID', 'payxpert' ); ?></th>
			<th><?php esc_html_e( 'Date', 'payxpert' ); ?></th>
			<th><?php esc_html_e( 'Amount', 'payxpert' ); ?></th>
			<th><?php esc_html_e( 'Operation', 'payxpert' ); ?></th>
			<th><?php esc_html_e( 'Status', 'payxpert' ); ?></th>
			<th><?php esc_html_e( 'Payment Method', 'payxpert' ); ?></th>
		</tr>
	</thead>
	<tbody>
	<?php
	foreach ( $payxpert_transactions as $payxpert_tx ) :
		$payxpert_order_url = wc_get_account_endpoint_url( 'view-order' ) . $payxpert_tx['order_id'];
		?>
		<tr>
			<td>
				<a href="<?php echo esc_url( $payxpert_order_url ); ?>">
					#<?php echo esc_html( $payxpert_tx['order_id'] ); ?>
				</a>
			</td>
			<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $payxpert_tx['date'] ) ); ?></td>
			<td><?php echo wp_kses_post( wc_price( $payxpert_tx['amount'] ) ); ?></td>
			<td><?php echo esc_html( $payxpert_tx['operation'] ); ?></td>
			<td>
				<?php
				echo esc_html(
					Payxpert_Payment_Transaction::RESULT_CODE_SUCCESS === $payxpert_tx['result_code']
					? Payxpert_Payment_Transaction::STATUS_SUCCESS
					: Payxpert_Payment_Transaction::STATUS_FAILURE
				);
				?>
			</td>
			<td><?php echo esc_html( strtoupper( $payxpert_tx['card_brand'] ) ) . ' - ' . esc_html( $payxpert_tx['card_number'] ); ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>

<?php if ( $payxpert_total_pages > 1 ) : ?>
	<div class="woocommerce-pagination">
		<?php
		echo wp_kses_post(
			paginate_links(
				array(
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
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
