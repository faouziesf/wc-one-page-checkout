<?php
/**
 * Classe d'administration du plugin
 */
class WC_OPC_Admin {

    /**
     * Constructeur
     */
    public function __construct() {
        // Ajouter le menu dans l'administration
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Ajouter une colonne dans la liste des commandes
        add_filter('manage_edit-shop_order_columns', array($this, 'add_order_columns'));
        add_action('manage_shop_order_posts_custom_column', array($this, 'render_order_columns'), 10, 2);
        
        // Ajouter un filtre dans la liste des commandes
        add_action('restrict_manage_posts', array($this, 'add_order_filters'));
        add_filter('request', array($this, 'filter_orders'));
    }

    /**
     * Ajouter le menu dans l'administration
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('One Page Checkout', 'wc-one-page-checkout'),
            __('One Page Checkout', 'wc-one-page-checkout'),
            'manage_woocommerce',
            'wc-one-page-checkout',
            array($this, 'render_admin_page')
        );
    }

    /**
     * Afficher la page d'administration
     */
    public function render_admin_page() {
        // Vérifier si l'utilisateur a les permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Vous n\'avez pas les permissions suffisantes pour accéder à cette page.', 'wc-one-page-checkout'));
        }
        
        // Enregistrer les paramètres si le formulaire est soumis
        if (isset($_POST['wc_opc_save_settings']) && isset($_POST['wc_opc_nonce']) && wp_verify_nonce($_POST['wc_opc_nonce'], 'wc_opc_save_settings')) {
            // Sauvegarder les paramètres
            $this->save_settings();
            
            // Afficher le message de succès
            echo '<div class="updated"><p>' . __('Paramètres sauvegardés avec succès.', 'wc-one-page-checkout') . '</p></div>';
        }
        
        // Afficher le formulaire des paramètres
        include WC_OPC_PATH . 'templates/admin/general-settings.php';
    }

    /**
     * Sauvegarder les paramètres
     */
    private function save_settings() {
        // Récupérer les paramètres
        $enable_for_all = isset($_POST['wc_opc_enable_for_all']) ? 'yes' : 'no';
        $form_title = isset($_POST['wc_opc_form_title']) ? sanitize_text_field($_POST['wc_opc_form_title']) : '';
        $button_text = isset($_POST['wc_opc_button_text']) ? sanitize_text_field($_POST['wc_opc_button_text']) : '';
        
        // Sauvegarder les paramètres
        update_option('wc_opc_enable_for_all', $enable_for_all);
        update_option('wc_opc_form_title', $form_title);
        update_option('wc_opc_button_text', $button_text);
    }

    /**
     * Ajouter des colonnes dans la liste des commandes
     */
    public function add_order_columns($columns) {
        $new_columns = array();
        
        // Insérer la colonne après la colonne "Order"
        foreach ($columns as $column_name => $column_info) {
            $new_columns[$column_name] = $column_info;
            
            if ('order_number' === $column_name) {
                $new_columns['one_page_checkout'] = __('One Page Checkout', 'wc-one-page-checkout');
            }
        }
        
        return $new_columns;
    }

    /**
     * Afficher le contenu des colonnes personnalisées
     */
    public function render_order_columns($column, $post_id) {
        if ('one_page_checkout' === $column) {
            // Vérifier si la commande a été créée via le One Page Checkout
            $is_opc = get_post_meta($post_id, '_wc_opc_order', true);
            
            if ($is_opc) {
                echo '<span class="dashicons dashicons-yes" style="color: green;"></span>';
                
                // Afficher l'historique des numéros de téléphone
                $order = wc_get_order($post_id);
                if ($order) {
                    $notes = wc_get_order_notes(array(
                        'order_id' => $post_id,
                        'type' => 'internal'
                    ));
                    
                    $phone_notes = array();
                    foreach ($notes as $note) {
                        if (strpos($note->content, 'Numéro de téléphone saisi') !== false) {
                            $phone_notes[] = $note->content;
                        }
                    }
                    
                    if (!empty($phone_notes)) {
                        echo '<div class="wc-opc-phone-history">';
                        echo '<p><strong>' . __('Historique des numéros:', 'wc-one-page-checkout') . '</strong></p>';
                        echo '<ul>';
                        foreach ($phone_notes as $note) {
                            echo '<li>' . esc_html($note) . '</li>';
                        }
                        echo '</ul>';
                        echo '</div>';
                    }
                }
            } else {
                echo '<span class="dashicons dashicons-no" style="color: gray;"></span>';
            }
        }
    }

    /**
     * Ajouter des filtres dans la liste des commandes
     */
    public function add_order_filters() {
        global $typenow;
        
        if ('shop_order' === $typenow) {
            $current = isset($_GET['one_page_checkout']) ? $_GET['one_page_checkout'] : '';
            ?>
            <select name="one_page_checkout">
                <option value=""><?php _e('Tous les types de commande', 'wc-one-page-checkout'); ?></option>
                <option value="yes" <?php selected($current, 'yes'); ?>><?php _e('One Page Checkout uniquement', 'wc-one-page-checkout'); ?></option>
                <option value="no" <?php selected($current, 'no'); ?>><?php _e('Commandes standard uniquement', 'wc-one-page-checkout'); ?></option>
            </select>
            <?php
        }
    }

    /**
     * Filtrer les commandes
     */
    public function filter_orders($vars) {
        global $typenow;
        
        if ('shop_order' === $typenow && isset($_GET['one_page_checkout']) && $_GET['one_page_checkout']) {
            $meta_query = isset($vars['meta_query']) ? $vars['meta_query'] : array();
            
            if ('yes' === $_GET['one_page_checkout']) {
                $meta_query[] = array(
                    'key' => '_wc_opc_order',
                    'value' => 'yes',
                    'compare' => '='
                );
            } elseif ('no' === $_GET['one_page_checkout']) {
                $meta_query[] = array(
                    'key' => '_wc_opc_order',
                    'compare' => 'NOT EXISTS'
                );
            }
            
            $vars['meta_query'] = $meta_query;
        }
        
        return $vars;
    }
}