<?php
/**
 * Html Admin Subscription Detail.
 *
 * @package Payxpert
 */

use Payxpert\Models\Payxpert_Payment_Transaction;
use Payxpert\Models\Payxpert_Subscription;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template variables for the admin subscription detail view.
 *
 * @var object $sub
 */

?>

<div class="enhanced-plugin-settings-wrap">
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=payxpert-subscription-list' ) ); ?>" 
		class="button button-secondary" 
		style="margin-bottom:15px; display:inline-block;">
		← <?php esc_html_e( 'Back to subscription list', 'payxpert' ); ?>
	</a>
	
	<div class="block">
		<div class="header">
			<h1>
				<?php esc_html_e( 'PayXpert Subscription Detail', 'payxpert' ); ?>
			</h1>
		</div>
	</div>

	<!-- General information -->
	<div class="block">
		<h2 class="hndle"><?php esc_html_e( 'General Information', 'payxpert' ); ?></h2>
		<div class="inside">
			<table class="widefat striped">
				<tr>
					<th><?php esc_html_e( 'Subscription ID PayXpert', 'payxpert' ); ?></th>
					<td><?php echo esc_html( $sub->subscription_id ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Customer', 'payxpert' ); ?></th>
					<td><?php echo esc_html( $sub->customer_name ); ?> (<?php echo esc_html( $sub->customer_email ); ?>)</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Product / Variation', 'payxpert' ); ?></th>
					<td>
						<?php echo esc_html( $sub->product_name ); ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Quantity', 'payxpert' ); ?></th>
					<td>× <?php echo esc_html( $sub->product_quantity ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Statut', 'payxpert' ); ?></th>
					<td>
						<?php echo esc_html( $sub->state ); ?>
						<?php
						if ( in_array( $sub->state, array( Payxpert_Subscription::STATUS_CANCELED, Payxpert_Subscription::STATUS_DISABLED ) ) && ! empty( $sub->cancel_reason ) ) :
							$payxpert_reason_label = isset( Payxpert_Subscription::CUSTOMER_CANCEL_REASONS[ $sub->cancel_reason ] )
								? Payxpert_Subscription::CUSTOMER_CANCEL_REASONS[ $sub->cancel_reason ]
								: ( isset( Payxpert_Subscription::SERVER_CANCEL_REASONS[ $sub->cancel_reason ] )
								? Payxpert_Subscription::SERVER_CANCEL_REASONS[ $sub->cancel_reason ]
								: '' );
							?>
							<span class="dashicons dashicons-info" title="<?php echo esc_attr( $payxpert_reason_label ); ?>"></span>
						<?php endif; ?>
					</td>
				</tr>
			</table>
		</div>
	</div>

	<div class="block">
		<h2 class="hndle"><?php esc_html_e( 'Transactions list', 'payxpert' ); ?></h2>

		<div class="inside">
			<?php if ( ! empty( $sub->transactions ) ) : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Order ID', 'payxpert' ); ?></th>
							<th><?php esc_html_e( 'Transaction ID', 'payxpert' ); ?></th>
							<th><?php esc_html_e( 'Date', 'payxpert' ); ?></th>
							<th><?php esc_html_e( 'Amount', 'payxpert' ); ?></th>
							<th><?php esc_html_e( 'Currency', 'payxpert' ); ?></th>
							<th><?php esc_html_e( 'Brand', 'payxpert' ); ?></th>
							<th><?php esc_html_e( 'Code', 'payxpert' ); ?></th>
							<th><?php esc_html_e( 'Message', 'payxpert' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $sub->transactions as $payxpert_tx ) : ?>
							<tr>
								<td>
									<?php if ( ! empty( $payxpert_tx->order_id ) ) : ?>
										<a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $payxpert_tx->order_id . '&action=edit' ) ); ?>">
											#<?php echo esc_html( $payxpert_tx->order_id ); ?>
										</a>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>

								<td><?php echo esc_html( $payxpert_tx->transaction_id ); ?></td>

								<td><?php echo esc_html( $payxpert_tx->date_add_formatted ); ?></td>

								<td><?php echo esc_html( ( Payxpert_Payment_Transaction::OPERATION_REFUND === $payxpert_tx->operation ? '-' : '' ) . $payxpert_tx->amount ); ?></td>

								<td><?php echo esc_html( $payxpert_tx->currency ); ?></td>

								<td><?php echo esc_html( $payxpert_tx->card_brand ? $payxpert_tx->card_brand : '—' ); ?></td>

								<td>
									<strong style="color: <?php echo esc_attr( ( 0 === (int) $payxpert_tx->result_code ) ? 'green' : 'red' ); ?>;">
										<?php echo esc_html( $payxpert_tx->result_code ); ?>
									</strong>
								</td>

								<td>
									<?php
									echo ! empty( $payxpert_tx->result_message )
										? esc_html( $payxpert_tx->result_message )
										: '—';
									?>
								</td>
							</tr>

						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'No transaction found.', 'payxpert' ); ?></p>
			<?php endif; ?>
		</div>
	</div>
</div>
