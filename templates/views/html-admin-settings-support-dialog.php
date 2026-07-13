<div id="payxpert-support-dialog" title="Contactez-nous" style="display:none;">
	<div style="text-align:center; margin-bottom:1em;">
	<img src="<?php /**
					 * Admin settings support dialog view.
					 *
					 * @package Payxpert
					 */

	echo esc_url( WC_PAYXPERT_ASSETS . 'img/logo.png' ); ?>" alt="PayXpert logo" style="max-width:150px;">
	</div>

	<div class="payxpert-notice notice notice-info">
	<p>
		<?php echo esc_html__( 'Your request will be sent to PayXpert Support at assistance@payxpert.com. You will receive a notification that it has been taken into account, and our team will contact you via email.', 'payxpert' ); ?>
	</p>
	</div>

	<div class="payxpert-support-response" style="margin-top:1em;"></div>

	<form id="payxpert-support-form">
	<div class="form-group">
		<label for="support_firstname"><?php echo esc_html__( 'Firstname', 'payxpert' ); ?> *</label>
		<input type="text" id="support_firstname" name="firstname" required>
	</div>

	<div class="form-group">
		<label for="support_lastname"><?php echo esc_html__( 'Lastname', 'payxpert' ); ?> *</label>
		<input type="text" id="support_lastname" name="lastname" required>
	</div>

	<div class="form-group">
		<label for="support_email"><?php echo esc_html__( 'Email', 'payxpert' ); ?> *</label>
		<input type="email" id="support_email" name="email" required>
	</div>

	<div class="form-group">
		<label for="support_subject"><?php echo esc_html__( 'Subject', 'payxpert' ); ?> *</label>
		<textarea id="support_subject" name="subject" rows="4" required></textarea>
	</div>
	</form>

</div>
