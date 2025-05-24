<?php
/**
 * Template des paramètres généraux dans l'administration
 */

// Sortir si accès direct
if (!defined('ABSPATH')) {
    exit;
}

// Récupérer les paramètres
$enable_for_all = get_option('wc_opc_enable_for_all', 'no');
$form_title = get_option('wc_opc_form_title', __('Commander maintenant', 'wc-one-page-checkout'));
$button_text = get_option('wc_opc_button_text', __('Commander', 'wc-one-page-checkout'));
?>

<div class="wrap wc-opc-admin-settings">
    <h1><?php _e('Paramètres One Page Checkout', 'wc-one-page-checkout'); ?></h1>
    
    <form method="post" action="">
        <?php wp_nonce_field('wc_opc_save_settings', 'wc_opc_nonce'); ?>
        
        <table class="form-table">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="wc_opc_enable_for_all"><?php _e('Activer pour tous les produits', 'wc-one-page-checkout'); ?></label>
                    </th>
                    <td>
                        <input type="checkbox" name="wc_opc_enable_for_all" id="wc_opc_enable_for_all" value="yes" <?php checked($enable_for_all, 'yes'); ?> />
                        <p class="description"><?php _e('Si cette option est activée, le formulaire de checkout sera affiché sur toutes les pages produit. Sinon, vous devrez l\'activer individuellement pour chaque produit.', 'wc-one-page-checkout'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="wc_opc_form_title"><?php _e('Titre du formulaire', 'wc-one-page-checkout'); ?></label>
                    </th>
                    <td>
                        <input type="text" name="wc_opc_form_title" id="wc_opc_form_title" value="<?php echo esc_attr($form_title); ?>" class="regular-text" />
                        <p class="description"><?php _e('Le titre qui sera affiché au-dessus du formulaire de checkout.', 'wc-one-page-checkout'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="wc_opc_button_text"><?php _e('Texte du bouton', 'wc-one-page-checkout'); ?></label>
                    </th>
                    <td>
                        <input type="text" name="wc_opc_button_text" id="wc_opc_button_text" value="<?php echo esc_attr($button_text); ?>" class="regular-text" />
                        <p class="description"><?php _e('Le texte qui sera affiché sur le bouton de soumission du formulaire.', 'wc-one-page-checkout'); ?></p>
                    </td>
                </tr>
            </tbody>
        </table>
        
        <h2><?php _e('Informations sur le suivi', 'wc-one-page-checkout'); ?></h2>
        
        <div class="wc-opc-tracking-info">
            <p><?php _e('Ce plugin est compatible avec les pixels de suivi Facebook et TikTok. Voici les événements qui sont déclenchés :', 'wc-one-page-checkout'); ?></p>
            
            <ul>
                <li><strong>AddToCart</strong> : <?php _e('Déclenché lorsqu\'un client saisit un numéro de téléphone valide et qu\'une commande draft est créée.', 'wc-one-page-checkout'); ?></li>
                <li><strong>InitiateCheckout</strong> : <?php _e('Déclenché lorsqu\'un client soumet le formulaire de checkout.', 'wc-one-page-checkout'); ?></li>
                <li><strong>Purchase</strong> : <?php _e('Déclenché lorsqu\'une commande est confirmée.', 'wc-one-page-checkout'); ?></li>
            </ul>
            
            <p><?php _e('Assurez-vous que les pixels Facebook et TikTok sont correctement installés sur votre site pour que le suivi fonctionne.', 'wc-one-page-checkout'); ?></p>
        </div>
        
        <p class="submit">
            <input type="submit" name="wc_opc_save_settings" class="button button-primary" value="<?php _e('Enregistrer les paramètres', 'wc-one-page-checkout'); ?>" />
        </p>
    </form>
</div>