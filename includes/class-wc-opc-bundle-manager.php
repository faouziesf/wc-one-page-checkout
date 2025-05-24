<?php
/**
 * Classe de gestion des bundles de produits
 * 
 * Gère les options de bundle pour les produits
 */
class WC_OPC_Bundle_Manager {
    
    /**
     * Constructeur
     */
    public function __construct() {
        // Ajouter les champs de configuration des bundles à l'admin des produits
        add_action('woocommerce_product_options_general_product_data', array($this, 'add_bundle_options_admin'));
        
        // Sauvegarder les options de bundle
        add_action('woocommerce_process_product_meta', array($this, 'save_bundle_options_admin'));
    }
    
    /**
     * Récupérer toutes les options de bundle pour un produit
     */
    public function get_bundle_options($product_id) {
        $bundle_options = array();
        
        // Récupérer les options depuis les métadonnées du produit
        $bundle_data = get_post_meta($product_id, '_wc_opc_bundle_options', true);
        if (!empty($bundle_data) && is_array($bundle_data)) {
            $bundle_options = $bundle_data;
        }
        
        return $bundle_options;
    }
    
    /**
     * Récupérer une option de bundle spécifique
     */
    public function get_bundle_option($product_id, $option_id) {
        $bundle_options = $this->get_bundle_options($product_id);
        
        if (!empty($bundle_options) && isset($bundle_options[$option_id])) {
            return $bundle_options[$option_id];
        }
        
        return false;
    }
    
    /**
     * Ajouter les champs de configuration des bundles dans l'admin des produits
     */
    public function add_bundle_options_admin() {
        global $post;
        
        echo '<div class="options_group">';
        
        // Option pour activer/désactiver les bundles
        woocommerce_wp_checkbox(array(
            'id' => '_wc_opc_bundle_enabled',
            'label' => __('Activer les bundles', 'wc-one-page-checkout'),
            'description' => __('Activer les options de bundle pour ce produit', 'wc-one-page-checkout')
        ));
        
        // Récupérer les options de bundle existantes
        $bundle_options = $this->get_bundle_options($post->ID);
        
        echo '<div id="wc_opc_bundle_options" style="padding: 10px; background-color: #f8f8f8; margin: 10px 0;">';
        
        echo '<h4>' . __('Options de bundle', 'wc-one-page-checkout') . '</h4>';
        
        echo '<table class="widefat" id="wc_opc_bundle_table">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>' . __('Description', 'wc-one-page-checkout') . '</th>';
        echo '<th>' . __('Quantité', 'wc-one-page-checkout') . '</th>';
        echo '<th>' . __('Prix', 'wc-one-page-checkout') . '</th>';
        echo '<th>' . __('Actions', 'wc-one-page-checkout') . '</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        // Afficher les options existantes
        if (!empty($bundle_options)) {
            $i = 0;
            foreach ($bundle_options as $option_id => $option) {
                echo '<tr class="bundle_option">';
                echo '<td><input type="text" name="wc_opc_bundle_description[]" value="' . esc_attr($option['description']) . '" placeholder="' . esc_attr__('ex: Achetez 2 et économisez 10%', 'wc-one-page-checkout') . '" /></td>';
                echo '<td><input type="number" name="wc_opc_bundle_quantity[]" value="' . esc_attr($option['quantity']) . '" min="1" step="1" /></td>';
                echo '<td><input type="number" name="wc_opc_bundle_price[]" value="' . esc_attr($option['price']) . '" min="0" step="0.01" /></td>';
                echo '<td><button type="button" class="button remove_bundle_option">' . __('Supprimer', 'wc-one-page-checkout') . '</button></td>';
                echo '</tr>';
                $i++;
            }
        }
        
        echo '</tbody>';
        echo '<tfoot>';
        echo '<tr>';
        echo '<td colspan="4"><button type="button" class="button add_bundle_option">' . __('Ajouter une option', 'wc-one-page-checkout') . '</button></td>';
        echo '</tr>';
        echo '</tfoot>';
        echo '</table>';
        
        echo '</div>';
        
        // JavaScript pour gérer l'ajout/suppression des options de bundle
        ?>
        <script type="text/javascript">
            jQuery(function($) {
                // Afficher/masquer les options de bundle en fonction de la case à cocher
                function toggleBundleOptions() {
                    if ($('#_wc_opc_bundle_enabled').is(':checked')) {
                        $('#wc_opc_bundle_options').show();
                    } else {
                        $('#wc_opc_bundle_options').hide();
                    }
                }
                
                // Initialisation
                toggleBundleOptions();
                
                // Événement de changement de la case à cocher
                $('#_wc_opc_bundle_enabled').change(function() {
                    toggleBundleOptions();
                });
                
                // Ajouter une option de bundle
                $('.add_bundle_option').on('click', function() {
                    var html = '<tr class="bundle_option">';
                    html += '<td><input type="text" name="wc_opc_bundle_description[]" value="" placeholder="<?php echo esc_attr__('ex: Achetez 2 et économisez 10%', 'wc-one-page-checkout'); ?>" /></td>';
                    html += '<td><input type="number" name="wc_opc_bundle_quantity[]" value="1" min="1" step="1" /></td>';
                    html += '<td><input type="number" name="wc_opc_bundle_price[]" value="0" min="0" step="0.01" /></td>';
                    html += '<td><button type="button" class="button remove_bundle_option"><?php echo __('Supprimer', 'wc-one-page-checkout'); ?></button></td>';
                    html += '</tr>';
                    
                    $('#wc_opc_bundle_table tbody').append(html);
                });
                
                // Supprimer une option de bundle
                $('#wc_opc_bundle_table').on('click', '.remove_bundle_option', function() {
                    $(this).closest('tr').remove();
                });
            });
        </script>
        <?php
        
        echo '</div>';
    }
    
    /**
     * Sauvegarder les options de bundle
     */
    public function save_bundle_options_admin($product_id) {
        // Sauvegarder l'état activé/désactivé
        $bundle_enabled = isset($_POST['_wc_opc_bundle_enabled']) ? 'yes' : 'no';
        update_post_meta($product_id, '_wc_opc_bundle_enabled', $bundle_enabled);
        
        // Sauvegarder les options de bundle
        $bundle_options = array();
        
        if (isset($_POST['wc_opc_bundle_description']) && is_array($_POST['wc_opc_bundle_description'])) {
            $descriptions = $_POST['wc_opc_bundle_description'];
            $quantities = isset($_POST['wc_opc_bundle_quantity']) ? $_POST['wc_opc_bundle_quantity'] : array();
            $prices = isset($_POST['wc_opc_bundle_price']) ? $_POST['wc_opc_bundle_price'] : array();
            
            for ($i = 0; $i < count($descriptions); $i++) {
                $description = sanitize_text_field($descriptions[$i]);
                $quantity = isset($quantities[$i]) ? absint($quantities[$i]) : 1;
                $price = isset($prices[$i]) ? wc_format_decimal($prices[$i]) : 0;
                
                // Vérifier que les champs requis sont remplis
                if (!empty($description) && $quantity > 0) {
                    $option_id = 'bundle_' . $i;
                    $bundle_options[$option_id] = array(
                        'description' => $description,
                        'quantity' => $quantity,
                        'price' => $price
                    );
                }
            }
        }
        
        update_post_meta($product_id, '_wc_opc_bundle_options', $bundle_options);
    }
    
    /**
     * Calculer le prix d'un bundle
     */
    public function calculate_bundle_price($bundle_option, $product_price, $quantity) {
        // Si c'est un bundle existant avec un prix défini
        if (isset($bundle_option['price']) && $bundle_option['price'] > 0) {
            return $bundle_option['price'];
        }
        
        // Sinon, calculer le prix en fonction du produit et de la quantité
        $bundle_quantity = isset($bundle_option['quantity']) ? $bundle_option['quantity'] : $quantity;
        return $product_price * $bundle_quantity;
    }
}