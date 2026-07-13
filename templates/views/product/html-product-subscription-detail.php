<?php
/**
 * Html Product Subscription Detail.
 *
 * @package Payxpert
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Variables disponibles :
 *
 * @var string $container_id
 * @var bool   $is_variable
 * @var string $frequency_text
 * @var string $price_html
 * @var string $first_payment_text
 */
?>

<div id="<?php echo esc_attr( $container_id ); ?>"
	class="subscription-plan-box"
	style="
		border: 1px solid #ddd;
		padding: 18px;
		border-radius: 6px;
		background: #fafafa;
		margin: 25px 0;
		<?php echo $is_variable ? 'display:none;' : ''; ?>
	">
	<h3 style="margin-top:0;">
		<?php esc_html_e( 'Subscription', 'payxpert' ); ?>
	</h3>

	<p>
		<strong><?php esc_html_e( 'Frequency', 'payxpert' ); ?>:</strong>
		<span class="payxpert-frequency"><?php echo esc_html( $frequency_text ); ?></span>
	</p>

	<p>
		<strong><?php esc_html_e( 'Recurring amount', 'payxpert' ); ?>:</strong>
		<span class="payxpert-price"><?php echo wp_kses_post( $price_html ); ?></span>
	</p>

	<p>
		<strong><?php esc_html_e( 'First due', 'payxpert' ); ?>:</strong>
		<span class="payxpert-first-payment"><?php echo esc_html( $first_payment_text ); ?></span>
	</p>
</div>

<?php if ( $is_variable ) : ?>
<script type="text/javascript">
jQuery(function($){
	var form = $('form.variations_form');
	var container = $('#<?php echo esc_js( $container_id ); ?>');

	function updatePayxpertInfo() {
		var variation_id = form.find('input.variation_id').val();
		if (!variation_id) {
			container.hide();
			return;
		}

		var variation_data = form.data('product_variations');
		if (!variation_data) return;

		var sub_info = false;
		$.each(variation_data, function(i, v){
			if (v.variation_id == variation_id) {
				sub_info = v.payxpert_subscription;
				return false;
			}
		});

		if (sub_info) {
			container.find('.payxpert-frequency').text(sub_info.frequency);
			container.find('.payxpert-price').html(sub_info.price_html);
			container.find('.payxpert-first-payment').text(sub_info.first_payment);
			container.show();
		} else {
			container.hide();
		}
	}

	updatePayxpertInfo();

	form.on('show_variation', updatePayxpertInfo);
	form.on('hide_variation', function(){ container.hide(); });
});
</script>
<?php endif; ?>
