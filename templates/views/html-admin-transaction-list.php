<?php
/**
 * Admin transaction list view.
 *
 * @package Payxpert
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template variables provided by the admin transaction list controller.
 *
 * @var list<object> $transactions
 * @var int          $total_items
 * @var int          $per_page
 * @var int          $current_page
 */

?>

<div class="enhanced-plugin-settings-wrap">
	<div class="block">
		<div class="header">
			<h1>
				<?php esc_html_e( 'PayXpert Transactions', 'payxpert' ); ?>
			</h1>
		</div>
	</div>

	<div class="block" style="overflow: auto;">
		<?php if ( empty( $transactions ) ) : ?>
			<p><?php esc_html_e( 'No transactions found.', 'payxpert' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'payxpert' ); ?></th>
						<th><?php esc_html_e( 'Order ID', 'payxpert' ); ?></th>
						<th><?php esc_html_e( 'Transaction ID', 'payxpert' ); ?></th>
						<th><?php esc_html_e( 'Amount', 'payxpert' ); ?></th>
						<th><?php esc_html_e( 'Currency', 'payxpert' ); ?></th>
						<th><?php esc_html_e( 'Payment Method', 'payxpert' ); ?></th>
						<th><?php esc_html_e( 'Operation', 'payxpert' ); ?></th>
						<th><?php esc_html_e( 'Result', 'payxpert' ); ?></th>
						<th><?php esc_html_e( 'Date', 'payxpert' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $transactions as $payxpert_tx ) : ?>
						<tr>
							<td><?php echo esc_html( $payxpert_tx->id_payxpert_payment_transaction ); ?></td>
							<td>
								<?php if ( $payxpert_tx->order_id ) : ?>
									<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $payxpert_tx->order_id . '&action=edit' ) ); ?>">
										#<?php echo esc_html( $payxpert_tx->order_id ); ?>
									</a>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $payxpert_tx->transaction_id ); ?></td>
							<td><?php echo esc_html( number_format( $payxpert_tx->amount, 2 ) ); ?></td>
							<td><?php echo esc_html( $payxpert_tx->currency ); ?></td>
							<td><?php echo esc_html( $payxpert_tx->payment_method ); ?></td>
							<td><?php echo esc_html( $payxpert_tx->operation ); ?></td>
							<td><?php echo esc_html( $payxpert_tx->result_code . ' - ' . $payxpert_tx->result_message ); ?></td>
							<td><?php echo esc_html( $payxpert_tx->date_add ); ?></td>
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
					'total'     => (int) ceil( $total_items / $per_page ),
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
