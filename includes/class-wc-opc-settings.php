<?php
/**
 * Classe de gestion des paramètres
 * 
 * Gère les paramètres du plugin
 */
class WC_OPC_Settings {
    
    /**
     * Constructeur
     */
    public function __construct() {
        // Ajouter la page de configuration dans le menu WooCommerce
        add_action('admin_menu', array($this, 'add_settings_page'), 99);
        
        // Enregistrer les paramètres
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    /**
     * Ajouter la page de configuration
     */
    public function add_settings_page() {
        add_submenu_page(
            'woocommerce',
            __('One Page Checkout', 'wc-one-page-checkout'),
            __('One Page Checkout', 'wc-one-page-checkout'),
            'manage_woocommerce',
            'wc-one-page-checkout',
            array($this, 'render_settings_page')
        );
    }
    
    /**
     * Enregistrer les paramètres
     */
    public function register_settings() {
        register_setting('wc_opc_settings', 'wc_opc_enable_for_all');
        register_setting('wc_opc_settings', 'wc_opc_form_title');
        register_setting('wc_opc_settings', 'wc_opc_button_text');
        register_setting('wc_opc_settings', 'wc_opc_draft_expiration', 'intval');
        register_setting('wc_opc_settings', 'wc_opc_enable_tracking');
        register_setting('wc_opc_settings', 'wc_opc_debug_mode');
    }
    
    /**
     * Afficher la page de configuration
     */
    public function render_settings_page() {
        // Vérifier les permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Vous n\'avez pas les permissions suffisantes pour accéder à cette page.', 'wc-one-page-checkout'));
        }
        
        // Afficher les messages de notification
        if (isset($_GET['settings-updated']) && $_GET['settings-updated']) {
            echo '<div class="updated"><p>' . __('Paramètres enregistrés avec succès.', 'wc-one-page-checkout') . '</p></div>';
        }
        
        // Récupérer les valeurs actuelles
        $enable_for_all = get_option('wc_opc_enable_for_all', 'yes');
        $form_title = get_option('wc_opc_form_title', __('Commander maintenant', 'wc-one-page-checkout'));
        $button_text = get_option('wc_opc_button_text', __('Commander', 'wc-one-page-checkout'));
        $draft_expiration = get_option('wc_opc_draft_expiration', 86400);
        $enable_tracking = get_option('wc_opc_enable_tracking', 'yes');
        $debug_mode = get_option('wc_opc_debug_mode', 'no');
        
        // Afficher le formulaire
        ?>
        <div class="wrap">
            <h1><?php _e('Paramètres de One Page Checkout', 'wc-one-page-checkout'); ?></h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('wc_opc_settings'); ?>
                
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php _e('Activer pour tous les produits', 'wc-one-page-checkout'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wc_opc_enable_for_all" value="yes" <?php checked($enable_for_all, 'yes'); ?> />
                                <?php _e('Afficher le formulaire de checkout sur toutes les pages produit', 'wc-one-page-checkout'); ?>
                            </label>
                            <p class="description"><?php _e('Si désactivé, vous devrez activer manuellement le checkout pour chaque produit dans ses paramètres.', 'wc-one-page-checkout'); ?></p>
                        </td>
                    </tr>
                    
                    <tr valign="top">
                        <th scope="row"><?php _e('Titre du formulaire', 'wc-one-page-checkout'); ?></th>
                        <td>
                            <input type="text" name="wc_opc_form_title" value="<?php echo esc_attr($form_title); ?>" class="regular-text" />
                            <p class="description"><?php _e('Le titre qui sera affiché au-dessus du formulaire de checkout.', 'wc-one-page-checkout'); ?></p>
                        </td>
                    </tr>
                    
                    <tr valign="top">
                        <th scope="row"><?php _e('Texte du bouton', 'wc-one-page-checkout'); ?></th>
                        <td>
                            <input type="text" name="wc_opc_button_text" value="<?php echo esc_attr($button_text); ?>" class="regular-text" />
                            <p class="description"><?php _e('Le texte du bouton de soumission du formulaire.', 'wc-one-page-checkout'); ?></p>
                        </td>
                    </tr>
                    
                    <tr valign="top">
                        <th scope="row"><?php _e('Expiration des commandes draft', 'wc-one-page-checkout'); ?></th>
                        <td>
                            <select name="wc_opc_draft_expiration">
                                <option value="3600" <?php selected($draft_expiration, 3600); ?>><?php _e('1 heure', 'wc-one-page-checkout'); ?></option>
                                <option value="7200" <?php selected($draft_expiration, 7200); ?>><?php _e('2 heures', 'wc-one-page-checkout'); ?></option>
                                <option value="14400" <?php selected($draft_expiration, 14400); ?>><?php _e('4 heures', 'wc-one-page-checkout'); ?></option>
                                <option value="28800" <?php selected($draft_expiration, 28800); ?>><?php _e('8 heures', 'wc-one-page-checkout'); ?></option>
                                <option value="43200" <?php selected($draft_expiration, 43200); ?>><?php _e('12 heures', 'wc-one-page-checkout'); ?></option>
                                <option value="86400" <?php selected($draft_expiration, 86400); ?>><?php _e('24 heures', 'wc-one-page-checkout'); ?></option>
                                <option value="172800" <?php selected($draft_expiration, 172800); ?>><?php _e('48 heures', 'wc-one-page-checkout'); ?></option>
                                <option value="259200" <?php selected($draft_expiration, 259200); ?>><?php _e('3 jours', 'wc-one-page-checkout'); ?></option>
                                <option value="604800" <?php selected($draft_expiration, 604800); ?>><?php _e('7 jours', 'wc-one-page-checkout'); ?></option>
                            </select>
                            <p class="description"><?php _e('Durée avant que les commandes draft soient automatiquement supprimées.', 'wc-one-page-checkout'); ?></p>
                        </td>
                    </tr>
                    
                    <tr valign="top">
                        <th scope="row"><?php _e('Activer le tracking', 'wc-one-page-checkout'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wc_opc_enable_tracking" value="yes" <?php checked($enable_tracking, 'yes'); ?> />
                                <?php _e('Envoyer des événements de tracking à Facebook', 'wc-one-page-checkout'); ?>
                            </label>
                            <p class="description"><?php _e('Nécessite que le pixel Facebook soit configuré sur votre site.', 'wc-one-page-checkout'); ?></p>
                        </td>
                    </tr>
                    
                    <tr valign="top">
                        <th scope="row"><?php _e('Mode debug', 'wc-one-page-checkout'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wc_opc_debug_mode" value="yes" <?php checked($debug_mode, 'yes'); ?> />
                                <?php _e('Activer le mode debug', 'wc-one-page-checkout'); ?>
                            </label>
                            <p class="description"><?php _e('Affiche des informations de débogage et journalise les événements pour le dépannage.', 'wc-one-page-checkout'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <div class="wc-opc-admin-info">
                <h2><?php _e('Informations sur le plugin', 'wc-one-page-checkout'); ?></h2>
                
                <p><?php _e('Ce plugin ajoute un formulaire de checkout directement sur la page produit, permettant aux clients de commander plus rapidement.', 'wc-one-page-checkout'); ?></p>
                
                <h3><?php _e('Événements de tracking', 'wc-one-page-checkout'); ?></h3>
                
                <ul>
                    <li><strong>AddToCart</strong>: <?php _e('Déclenché lorsqu\'un client saisit un numéro de téléphone valide et qu\'une commande draft est créée.', 'wc-one-page-checkout'); ?></li>
                    <li><strong>InitiateCheckout</strong>: <?php _e('Déclenché lorsqu\'un client soumet le formulaire de checkout.', 'wc-one-page-checkout'); ?></li>
                    <li><strong>Purchase</strong>: <?php _e('Déclenché lorsqu\'une commande est confirmée.', 'wc-one-page-checkout'); ?></li>
                </ul>
                
                <h3><?php _e('Outils de diagnostic', 'wc-one-page-checkout'); ?></h3>
                
                <p>
                    <a href="<?php echo admin_url('admin.php?page=wc-one-page-checkout&action=view_logs'); ?>" class="button"><?php _e('Voir les journaux', 'wc-one-page-checkout'); ?></a>
                    <a href="<?php echo admin_url('admin.php?page=wc-one-page-checkout&action=cleanup_drafts'); ?>" class="button"><?php _e('Nettoyer les commandes draft', 'wc-one-page-checkout'); ?></a>
                </p>
            </div>
        </div>
        <?php
    }
}