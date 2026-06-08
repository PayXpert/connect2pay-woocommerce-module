<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$url = admin_url('admin.php?page=payxpert-settings');
?>

<div class="payxpert-admin-redirect" style="padding: 24px;">
    <div style="max-width: 600px; background: #fff; border: 1px solid #ccd0d4; border-radius: 8px; padding: 24px;">
        
        <h2 style="margin-top: 0;">
            <?php echo '⚙️ ' . esc_html__( 'PayXpert Configuration', 'payxpert' ); ?>
        </h2>
        
        <p style="font-size: 14px; line-height: 1.6;">
            <?php echo esc_html__( 'The settings for this payment method are centralized on a dedicated page for easier management and a simplified configuration experience.', 'payxpert' ); ?>
        </p>

        <p style="margin-top: 20px;">
            <a href="<?php echo esc_url( $url ); ?>" class="button button-primary">
                <?php echo esc_html__( 'Go to PayXpert settings', 'payxpert' ); ?>
            </a>
        </p>

        <hr style="margin: 24px 0;">

        <p style="font-size: 12px; color: #666;">
            <?php echo 'ℹ️ '. esc_html__( 'This WooCommerce page is kept for compatibility, but the settings are managed elsewhere.', 'payxpert' ); ?>
        </p>
    </div>
</div>
