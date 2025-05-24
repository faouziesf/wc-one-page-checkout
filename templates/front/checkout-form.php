<?php
/**
 * Template du formulaire de checkout
 */

// Sortir si accès direct
if (!defined('ABSPATH')) {
    exit;
}

// Récupérer les paramètres du formulaire
$form_title = get_option('wc_opc_form_title', __('Commander maintenant', 'wc-one-page-checkout'));
$button_text = get_option('wc_opc_button_text', __('Commander', 'wc-one-page-checkout'));

// Récupérer l'ID du produit
$product_id = $product->get_id();
?>

<div class="wc-opc-checkout-form-container">
    <h3 class="wc-opc-form-title"><?php echo esc_html($form_title); ?></h3>
    
    <form class="wc-opc-checkout-form" method="post" id="wc_opc_checkout_form">
        <?php wp_nonce_field('wc-opc-nonce', 'nonce', true, true); ?>
        <input type="hidden" name="product_id" value="<?php echo esc_attr($product_id); ?>" />
        <input type="hidden" name="draft_order_id" id="wc_opc_draft_order_id" value="" />
        <input type="hidden" name="action" value="wc_opc_process_checkout" />
        
        <div class="wc-opc-product-info">
            <div class="wc-opc-product-image">
                <?php echo $product->get_image('thumbnail'); ?>
            </div>
            <div class="wc-opc-product-details">
                <h4 class="wc-opc-product-title"><?php echo esc_html($product->get_name()); ?></h4>
                <div class="wc-opc-product-price"><?php echo $product->get_price_html(); ?></div>
            </div>
        </div>
        
        <div class="wc-opc-form-fields">
            <div class="wc-opc-form-field">
                <div class="wc-opc-input-wrapper">
                    <i class="wc-opc-icon wc-opc-icon-user"></i>
                    <input type="text" name="customer_name" id="wc_opc_customer_name" placeholder="<?php _e('Votre nom', 'wc-one-page-checkout'); ?>" />
                </div>
            </div>
            
            <div class="wc-opc-form-field">
                <div class="wc-opc-input-wrapper">
                    <i class="wc-opc-icon wc-opc-icon-phone"></i>
                    <input type="tel" name="customer_phone" id="wc_opc_customer_phone" placeholder="<?php _e('Votre numéro de téléphone', 'wc-one-page-checkout'); ?>" required />
                </div>
                <div class="wc-opc-phone-validation">
                    <div class="wc-opc-phone-digits">
                        <span class="wc-opc-digits-count">0</span> / <span class="wc-opc-digits-min">8</span> <?php _e('chiffres', 'wc-one-page-checkout'); ?>
                    </div>
                    <div class="wc-opc-phone-message"></div>
                </div>
            </div>
            
            <div class="wc-opc-form-field">
                <div class="wc-opc-input-wrapper">
                    <i class="wc-opc-icon wc-opc-icon-location"></i>
                    <textarea name="customer_address" id="wc_opc_customer_address" placeholder="<?php _e('Votre adresse', 'wc-one-page-checkout'); ?>"></textarea>
                </div>
            </div>
        </div>
        
        <div class="wc-opc-quantity-field">
            <div class="wc-opc-quantity-selector">
                <button type="button" class="wc-opc-quantity-minus">-</button>
                <input type="number" name="quantity" id="wc_opc_quantity" value="1" min="1" step="1" />
                <button type="button" class="wc-opc-quantity-plus">+</button>
            </div>
            <div class="wc-opc-submit-field">
                <button type="submit" class="wc-opc-submit-button" id="wc_opc_submit_button"><?php echo esc_html($button_text); ?></button>
                <div class="wc-opc-loading" style="display: none;">
                    <div class="wc-opc-spinner">
                        <div class="bounce1"></div>
                        <div class="bounce2"></div>
                        <div class="bounce3"></div>
                    </div>
                    <span class="message"><?php _e('Traitement en cours...', 'wc-one-page-checkout'); ?></span>
                </div>
            </div>
        </div>
    </form>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    // Nettoyer automatiquement le stockage local si c'est une nouvelle session
    if (typeof wc_opc_params !== 'undefined' && wc_opc_params.reset_session === 'yes') {
        console.log('🔄 Nouvelle session détectée, nettoyage du stockage local');
        
        if (typeof(Storage) !== 'undefined') {
            try {
                localStorage.removeItem('wc_opc_draft_state');
                localStorage.removeItem('wc_opc_form_data');
                localStorage.removeItem('wc_opc_tracking_state');
                console.log('✅ Stockage local nettoyé pour nouvelle session');
            } catch (e) {
                console.error('❌ Erreur lors du nettoyage:', e);
            }
        }
    }
});
</script>