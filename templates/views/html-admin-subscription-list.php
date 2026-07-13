<?php
/**
 * Html Admin Subscription List.
 *
 * @package Payxpert
 */

use Payxpert\Models\Payxpert_Subscription;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template variables provided by the admin subscription list controller.
 *
 * @var array{items: list<object>, total_items: int, per_page: int} $results
 * @var int                                                         $current_page
 * @var array<string, string>                                       $filters
 */

?>

<div class="enhanced-plugin-settings-wrap">
	<div class="block">
		<div class="header">
			<h1>
				<?php esc_html_e( 'PayXpert Subscriptions', 'payxpert' ); ?>
			</h1>
		</div>
	</div>

	<div class="block" style="overflow: auto";>
		<?php
			// $filters is set before this template is included.
			$payxpert_current_status    = isset( $filters['status'] ) ? $filters['status'] : '';
			$payxpert_current_date_from = isset( $filters['date_from'] ) ? $filters['date_from'] : '';
			$payxpert_current_date_to   = isset( $filters['date_to'] ) ? $filters['date_to'] : '';
			$payxpert_current_customer  = isset( $filters['customer'] ) ? $filters['customer'] : '';

			// URL du reset : seulement page=payxpert-subscriptions.
			$payxpert_reset_url = admin_url( 'admin.php?page=payxpert-subscription-list' );
		?>

		<form method="get" class="tablenav top">
			<input type="hidden" name="page" value="payxpert-subscription-list">

			<!-- Status -->
			<select name="status">
				<option value=""><?php esc_html_e( 'All statuses', 'payxpert' ); ?></option>
				<option value="active" <?php selected( $payxpert_current_status, 'active' ); ?>><?php esc_html_e( 'active', 'payxpert' ); ?></option>
				<option value="paused" <?php selected( $payxpert_current_status, 'paused' ); ?>><?php esc_html_e( 'paused', 'payxpert' ); ?></option>
				<option value="canceled" <?php selected( $payxpert_current_status, 'canceled' ); ?>><?php esc_html_e( 'canceled', 'payxpert' ); ?></option>
				<option value="disabled" <?php selected( $payxpert_current_status, 'disabled' ); ?>><?php esc_html_e( 'disabled', 'payxpert' ); ?></option>
				<option value="finished" <?php selected( $payxpert_current_status, 'finished' ); ?>><?php esc_html_e( 'finished', 'payxpert' ); ?></option>
			</select>

			<!-- Date from -->
			<input type="date" name="date_from" value="<?php echo esc_attr( $payxpert_current_date_from ); ?>">

			<!-- Date to -->
			<input type="date" name="date_to" value="<?php echo esc_attr( $payxpert_current_date_to ); ?>">

			<!-- Customer -->
			<input type="text" name="customer" placeholder="<?php esc_attr_e( 'Customer name or email', 'payxpert' ); ?>" value="<?php echo esc_attr( $payxpert_current_customer ); ?>">

			<!-- Filter button -->
			<button class="button"><?php esc_html_e( 'Filter', 'payxpert' ); ?></button>

			<!-- Reset button -->
			<a href="<?php echo esc_url( $payxpert_reset_url ); ?>" class="button"><?php esc_html_e( 'Reset', 'payxpert' ); ?></a>
		</form>

		<?php if ( empty( $results['items'] ) ) : ?>
			<p><?php esc_html_e( 'No subscriptions found.', 'payxpert' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Order ID', 'payxpert' ); ?></th>
						<th><?php esc_html_e( 'Subscription ID', 'payxpert' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'payxpert' ); ?></th>
						<th><?php esc_html_e( 'Type', 'payxpert' ); ?></th>
						<th><?php esc_html_e( 'State', 'payxpert' ); ?></th>
						<th><?php esc_html_e( 'Amount', 'payxpert' ); ?></th>
						<th><?php esc_html_e( 'Period', 'payxpert' ); ?></th>
						<th><?php esc_html_e( 'Last Payment', 'payxpert' ); ?></th>
						<th><?php esc_html_e( 'Next Payment', 'payxpert' ); ?></th>
						<th><?php esc_html_e( 'Nb Transactions', 'payxpert' ); ?></th>
						<th><?php esc_html_e( 'Iterations Left', 'payxpert' ); ?></th>
						<th><?php esc_html_e( 'Detail', 'payxpert' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $results['items'] as $payxpert_sub ) : ?>
						<tr>
							<td>
								<?php if ( $payxpert_sub->order_id ) : ?>
									<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $payxpert_sub->order_id . '&action=edit' ) ); ?>">
										#<?php echo esc_html( $payxpert_sub->order_id ); ?>
									</a>
								<?php endif; ?>
							</td>
								<td><?php echo esc_html( $payxpert_sub->subscription_id ); ?></td>
								<td><?php echo esc_html( $payxpert_sub->customer_email ); ?></td>
								<td><?php echo esc_html( $payxpert_sub->subscription_type_label ); ?></td>
								<td>
									<?php echo esc_html( $payxpert_sub->state_label ); ?>
									
									<?php
									// Canceled or disabled state.
									if ( in_array( $payxpert_sub->state, array( Payxpert_Subscription::STATUS_CANCELED, Payxpert_Subscription::STATUS_DISABLED ), true ) && ! empty( $payxpert_sub->cancel_reason ) ) :

										// Reason label by subscription type.
										$payxpert_reason_label = '';

										if ( Payxpert_Subscription::STATUS_CANCELED === $payxpert_sub->state && isset( Payxpert_Subscription::CUSTOMER_CANCEL_REASONS[ $payxpert_sub->cancel_reason ] ) ) {
											$payxpert_reason_label = Payxpert_Subscription::CUSTOMER_CANCEL_REASONS[ $payxpert_sub->cancel_reason ];
										} elseif ( 'disabled' === $payxpert_sub->state && isset( Payxpert_Subscription::SERVER_CANCEL_REASONS[ $payxpert_sub->cancel_reason ] ) ) {
											$payxpert_reason_label = Payxpert_Subscription::SERVER_CANCEL_REASONS[ $payxpert_sub->cancel_reason ];
										}

										if ( $payxpert_reason_label ) :
											?>
										<span
											class="dashicons dashicons-info payxpert-info-icon"
											title="<?php echo esc_attr( $payxpert_reason_label ); ?>"
										></span>
											<?php
										endif;
									endif;
									?>
								</td>
								<td>
									<?php echo wp_kses_post( $payxpert_sub->trial_amount_formatted ); ?> 
									+ x<?php echo wp_kses_post( $payxpert_sub->amount_formatted ); ?>
								</td>
								<td><?php echo esc_html( $payxpert_sub->period ); ?></td>
								<td><?php echo esc_html( $payxpert_sub->period_start_formatted ); ?></td>
								<td><?php echo esc_html( $payxpert_sub->period_end_formatted ); ?></td>
								<td><?php echo esc_html( $payxpert_sub->sale_transactions_count ); ?></td>
								<td>
									<?php echo esc_html( $payxpert_sub->iterations_left ); ?>
									<?php if ( ! empty( $payxpert_sub->retries ) && (int) $payxpert_sub->retries > 0 ) : ?>
										<span
											class="dashicons dashicons-warning payxpert-warning"
											title="
											<?php
											echo esc_attr(
												sprintf(
													// translators: %d is the number of retry attempts.
													__( '%d retry attempt(s) already performed', 'payxpert' ),
													(int) $payxpert_sub->retries
												)
											);
											?>
											"
										></span>
									<?php endif; ?>
								</td>
								<td style="text-align:center">
									<a class="woocommerce-button wp-element-button button" href="<?php echo esc_url( admin_url( 'admin.php?page=payxpert-subscription-detail&id=' . $payxpert_sub->subscription_id ) ); ?>">
										<?php esc_html_e( 'View', 'payxpert' ); ?>
									</a>

									<?php if ( Payxpert_Subscription::STATUS_ACTIVE === $payxpert_sub->state ) : ?>
									<button type="button" 
										class="woocommerce-button wp-element-button button cancel-subscription" 
										data-subscription-id="<?php echo esc_attr( $payxpert_sub->subscription_id ); ?>">
										<?php esc_html_e( 'Cancel', 'payxpert' ); ?>
									</button>
									<?php endif; ?>
								</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php
			// Pagination.
			$payxpert_page_links = paginate_links(
				array(
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'prev_text' => __( '&laquo; Previous', 'payxpert' ),
					'next_text' => __( 'Next &raquo;', 'payxpert' ),
					'total'     => (int) ceil( $results['total_items'] / $results['per_page'] ),
					'current'   => $current_page,
					'type'      => 'array',
				)
			);

			if ( $payxpert_page_links ) {
				echo '<div class="tablenav"><div class="tablenav-pages"><span class="pagination-links">';
				echo wp_kses_post( join( ' ', $payxpert_page_links ) );
				echo '</span></div></div>';
			}
			?>
		<?php endif; ?>
	</div>
</div>

<?php require WC_PAYXPERT_PLUGIN_FILE_PATH . '/templates/views/html-modal-cancel-subscription.php'; ?>
