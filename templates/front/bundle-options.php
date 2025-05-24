<?php
/**
 * Template pour les options de bundle
 */

// Sortir si accès direct
if (!defined('ABSPATH')) {
    exit;
}

// Si pas d'options, sortir
if (empty($bundle_options)) {
    return;
}
?>

<div class="wc-opc-bundle-options">
    <h4 class="wc-opc-bundle-title"><?php _e('Options d\'achat', 'wc-one-page-checkout'); ?></h4>
    
    <div class="wc-opc-bundle-list">
        <?php 
        // Variable pour suivre si une option a été cochée
        $option_checked = false;
        
        foreach ($bundle_options as $option_id => $option) : 
            // Cocher la première option par défaut
            $checked = !$option_checked ? 'checked="checked"' : '';
            if (!$option_checked && $checked) {
                $option_checked = true;
            }
        ?>
            <div class="wc-opc-bundle-option">
                <label>
                    <input type="radio" name="bundle_option" value="<?php echo esc_attr($option_id); ?>" 
                           data-quantity="<?php echo esc_attr($option['quantity']); ?>" 
                           data-price="<?php echo esc_attr($option['price']); ?>" <?php echo $checked; ?> />
                    <span class="wc-opc-bundle-description">
                        <span class="wc-opc-bundle-description-text"><?php echo esc_html($option['description']); ?></span>
                        <span class="wc-opc-bundle-price"><?php echo wc_price($option['price']); ?></span>
                    </span>
                </label>
            </div>
        <?php endforeach; ?>
    </div>
</div>