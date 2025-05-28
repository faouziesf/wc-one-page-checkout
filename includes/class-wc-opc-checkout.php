<?php
/**
 * Classe de gestion du checkout CORRIGÉE
 */
class WC_OPC_Checkout {
    
    public function __construct() {
        // Ajouter les bundles avant le formulaire
        add_action('woocommerce_before_add_to_cart_form', array($this, 'add_bundle_options'), 5);
        
        // Ajouter le formulaire de checkout sur la page produit 
        add_action('woocommerce_before_add_to_cart_form', array($this, 'add_checkout_form'), 10);
        
        // Masquer complètement le formulaire d'ajout au panier standard
        add_action('woocommerce_before_single_product', array($this, 'remove_add_to_cart_form'));
        
        // Traiter la soumission du formulaire
        add_action('wp_ajax_wc_opc_process_checkout', array($this, 'process_checkout'));
        add_action('wp_ajax_nopriv_wc_opc_process_checkout', array($this, 'process_checkout'));
    }
    
    /**
     * Supprimer complètement le formulaire d'ajout au panier
     */
    public function remove_add_to_cart_form() {
        if (!is_product()) {
            return;
        }
        
        $product_id = get_the_ID();
        $enabled = apply_filters('wc_opc_enable_for_product', $this->is_enabled_for_product($product_id), $product_id);
        
        if (!$enabled) {
            return;
        }
        
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
    }
    
    /**
     * Vérifier si le checkout est activé pour un produit
     */
    private function is_enabled_for_product($product_id) {
        $global_enabled = get_option('wc_opc_enable_for_all') === 'yes';
        $product_enabled = get_post_meta($product_id, '_wc_opc_enabled', true) === 'yes';
        
        return $global_enabled || $product_enabled;
    }
    
    /**
     * Ajouter les options de bundle avant le formulaire
     */
    public function add_bundle_options() {
        global $product;
        
        if (!$product) {
            return;
        }
        
        $enabled = apply_filters('wc_opc_enable_for_product', $this->is_enabled_for_product($product->get_id()), $product->get_id());
        if (!$enabled) {
            return;
        }
        
        $bundle_enabled = get_post_meta($product->get_id(), '_wc_opc_bundle_enabled', true) === 'yes';
        if (!$bundle_enabled) {
            return;
        }
        
        $bundle_manager = new WC_OPC_Bundle_Manager();
        $bundle_options = $bundle_manager->get_bundle_options($product->get_id());
        
        if (!empty($bundle_options)) {
            include WC_OPC_PATH . 'templates/front/bundle-options.php';
        }
    }
    
    /**
     * Ajouter le formulaire de checkout sur la page produit
     */
    public function add_checkout_form() {
        global $product;
        
        if (!$product) {
            return;
        }
        
        $enabled = apply_filters('wc_opc_enable_for_product', $this->is_enabled_for_product($product->get_id()), $product->get_id());
        if (!$enabled) {
            return;
        }
        
        include WC_OPC_PATH . 'templates/front/checkout-form.php';
    }
    
    /**
     * Traiter la soumission du formulaire CORRIGÉ
     */
    public function process_checkout() {
        // Vérification du nonce renforcée
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wc-opc-nonce')) {
            wp_send_json_error(array(
                'message' => __('Erreur de sécurité. Veuillez rafraîchir la page et réessayer.', 'wc-one-page-checkout'),
                'code' => 'security_error'
            ));
            return;
        }
        
        // Récupération et validation des données
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $quantity = isset($_POST['quantity']) ? absint($_POST['quantity']) : 1;
        $bundle_option = isset($_POST['bundle_option']) ? sanitize_text_field($_POST['bundle_option']) : '';
        $customer_name = isset($_POST['customer_name']) ? sanitize_text_field($_POST['customer_name']) : '';
        $customer_phone = isset($_POST['customer_phone']) ? sanitize_text_field($_POST['customer_phone']) : '';
        $customer_address = isset($_POST['customer_address']) ? sanitize_text_field($_POST['customer_address']) : '';
        $draft_order_id = isset($_POST['draft_order_id']) ? absint($_POST['draft_order_id']) : 0;
        
        // Validation des données essentielles
        if (!$product_id || !$customer_phone) {
            wp_send_json_error(array(
                'message' => __('Données manquantes. Veuillez remplir tous les champs obligatoires.', 'wc-one-page-checkout'),
                'code' => 'missing_data'
            ));
            return;
        }
        
        // Vérification du produit
        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(array(
                'message' => __('Produit invalide.', 'wc-one-page-checkout'),
                'code' => 'invalid_product'
            ));
            return;
        }
        
        // Validation du téléphone
        $validator = new WC_OPC_Validation();
        if (!$validator->is_valid_phone($customer_phone)) {
            wp_send_json_error(array(
                'message' => __('Veuillez entrer un numéro de téléphone valide (au moins 8 chiffres).', 'wc-one-page-checkout'),
                'code' => 'invalid_phone'
            ));
            return;
        }
        
        try {
            $logger = WC_OPC_Logger::get_instance();
            $logger->info("Démarrage checkout - Produit: {$product_id}, Draft: {$draft_order_id}");
            
            // LOGIQUE CORRIGÉE: Conversion de draft OU création directe
            if ($draft_order_id) {
                // OPTION 1: Convertir la draft existante
                $order = $this->convert_draft_to_final_order($draft_order_id, $customer_name, $customer_phone, $customer_address, $product_id);
                
                if (!$order) {
                    // Si la conversion échoue, créer une nouvelle commande
                    $logger->info("Conversion draft échouée, création d'une nouvelle commande");
                    $order = $this->create_new_final_order($product, $quantity, $bundle_option, $customer_name, $customer_phone, $customer_address);
                }
            } else {
                // OPTION 2: Créer directement une nouvelle commande finale
                $logger->info("Aucune draft fournie, création directe d'une commande finale");
                $order = $this->create_new_final_order($product, $quantity, $bundle_option, $customer_name, $customer_phone, $customer_address);
            }
            
            if (!$order) {
                throw new Exception(__('Échec de la création de la commande finale.', 'wc-one-page-checkout'));
            }
            
            $logger->info("Commande finale créée avec succès: #{$order->get_id()}");
            
            // Définir le cookie de reset pour la prochaine visite
            setcookie('wc_opc_order_completed', '1', time() + 86400, COOKIEPATH, COOKIE_DOMAIN);
            
            // URL de redirection avec reset
            $redirect_url = add_query_arg('reset_opc_session', '1', $order->get_checkout_order_received_url());
            
            // Calculer le total pour le tracking
            $total_price = $order->get_total();
            
            // Déclencher l'événement de tracking
            do_action('wc_opc_checkout_success', $order->get_id(), $product_id, $total_price);
            
            // Réponse de succès
            wp_send_json_success(array(
                'order_id' => $order->get_id(),
                'message' => __('Commande créée avec succès!', 'wc-one-page-checkout'),
                'redirect' => $redirect_url,
                'product_id' => $product_id,
                'total_price' => $total_price,
                'event' => 'checkout_success'
            ));
            
        } catch (Exception $e) {
            $logger = WC_OPC_Logger::get_instance();
            $logger->error("Erreur checkout: " . $e->getMessage());
            
            wp_send_json_error(array(
                'message' => $e->getMessage(),
                'code' => 'checkout_error'
            ));
        }
    }
    
    /**
     * Convertir une draft en commande finale
     */
    private function convert_draft_to_final_order($draft_order_id, $customer_name, $customer_phone, $customer_address, $product_id) {
        try {
            $order = wc_get_order($draft_order_id);
            
            // Vérifications de sécurité
            if (!$order) {
                throw new Exception(__('Draft introuvable.', 'wc-one-page-checkout'));
            }
            
            if ($order->get_meta('_wc_opc_draft_order') !== 'yes') {
                throw new Exception(__('Cette commande n\'est pas une draft OPC.', 'wc-one-page-checkout'));
            }
            
            // Vérifier que c'est le bon produit
            $order_product_id = $order->get_meta('_wc_opc_product_id');
            if ($order_product_id != $product_id) {
                throw new Exception(__('La draft ne correspond pas au produit actuel.', 'wc-one-page-checkout'));
            }
            
            // Vérifier la propriété (session/IP)
            if (!$this->verify_draft_ownership($order)) {
                throw new Exception(__('Vous n\'êtes pas autorisé à finaliser cette commande.', 'wc-one-page-checkout'));
            }
            
            // Mettre à jour les informations client
            $order->set_billing_first_name($customer_name);
            $order->set_billing_phone($customer_phone);
            $order->set_billing_address_1($customer_address);
            
            // Note de finalisation
            $order->add_order_note(
                sprintf(
                    __('Commande finalisée - Nom: %s, Téléphone: %s, Adresse: %s', 'wc-one-page-checkout'),
                    $customer_name,
                    $customer_phone,
                    $customer_address
                ),
                false
            );
            
            // Changer le statut de draft à processing
            $order->set_status('processing', __('Commande finalisée via One Page Checkout', 'wc-one-page-checkout'));
            
            // Supprimer le flag draft et ajouter le flag final
            $order->delete_meta_data('_wc_opc_draft_order');
            $order->add_meta_data('_wc_opc_order', 'yes');
            $order->add_meta_data('_wc_opc_completed_time', time());
            
            // Recalculer et sauvegarder
            $order->calculate_totals();
            $order->save();
            
            return $order;
            
        } catch (Exception $e) {
            $logger = WC_OPC_Logger::get_instance();
            $logger->error("Erreur conversion draft: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Créer une nouvelle commande finale directement
     */
    private function create_new_final_order($product, $quantity, $bundle_option, $customer_name, $customer_phone, $customer_address) {
        try {
            // Vérification anti-doublon par téléphone récent
            if ($this->has_recent_order_with_phone($customer_phone, $product->get_id())) {
                throw new Exception(__('Une commande récente existe déjà avec ce numéro de téléphone.', 'wc-one-page-checkout'));
            }
            
            $order = wc_create_order();
            
            // Définir le statut final directement
            $order->set_status('processing', __('Commande créée via One Page Checkout', 'wc-one-page-checkout'));
            
            // Informations client
            $order->set_billing_first_name($customer_name);
            $order->set_billing_phone($customer_phone);
            $order->set_billing_address_1($customer_address);
            
            // Ajouter le produit
            if ($bundle_option) {
                $this->add_bundle_to_new_order($order, $product, $bundle_option);
            } else {
                $order->add_product($product, $quantity);
            }
            
            // Note de création
            $order->add_order_note(
                sprintf(
                    __('Commande créée directement - Nom: %s, Téléphone: %s, Adresse: %s', 'wc-one-page-checkout'),
                    $customer_name,
                    $customer_phone,
                    $customer_address
                ),
                false
            );
            
            // Marquer comme commande OPC
            $order->add_meta_data('_wc_opc_order', 'yes');
            $order->add_meta_data('_wc_opc_completed_time', time());
            $order->add_meta_data('_wc_opc_product_id', $product->get_id());
            
            // Recalculer et sauvegarder
            $order->calculate_totals();
            $order->save();
            
            if (!$order->get_id()) {
                throw new Exception(__('Échec de la sauvegarde de la commande.', 'wc-one-page-checkout'));
            }
            
            return $order;
            
        } catch (Exception $e) {
            $logger = WC_OPC_Logger::get_instance();
            $logger->error("Erreur création commande directe: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Ajouter un bundle à une nouvelle commande
     */
    private function add_bundle_to_new_order($order, $product, $bundle_option) {
        $bundle_manager = new WC_OPC_Bundle_Manager();
        $bundle_data = $bundle_manager->get_bundle_option($product->get_id(), $bundle_option);
        
        if ($bundle_data) {
            $item_id = $order->add_product($product, $bundle_data['quantity'], [
                'subtotal' => $bundle_data['price'],
                'total' => $bundle_data['price']
            ]);
            
            wc_add_order_item_meta($item_id, '_bundle_option', $bundle_option);
            wc_add_order_item_meta($item_id, '_bundle_description', $bundle_data['description']);
            wc_add_order_item_meta($item_id, '_bundle_price', $bundle_data['price']);
        } else {
            // Si le bundle n'existe plus, ajouter le produit standard
            $order->add_product($product, 1);
        }
    }
    
    /**
     * Vérifier s'il existe une commande récente avec ce téléphone
     */
    private function has_recent_order_with_phone($phone, $product_id) {
        global $wpdb;
        
        // Rechercher dans les 5 dernières minutes pour éviter les vrais doublons
        $recent_time = date('Y-m-d H:i:s', strtotime('-5 minutes'));
        
        $query = $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_billing_phone' AND pm1.meta_value = %s
            INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_wc_opc_product_id' AND pm2.meta_value = %d
            WHERE p.post_type = 'shop_order' 
            AND p.post_status IN ('processing', 'completed', 'on-hold')
            AND p.post_date > %s
            LIMIT 1",
            $phone,
            $product_id,
            $recent_time
        );
        
        $existing_order = $wpdb->get_var($query);
        
        return !empty($existing_order);
    }
    
    /**
     * Vérifier la propriété d'une draft
     */
    private function verify_draft_ownership($order) {
        $session = new WC_OPC_Session();
        
        $order_session_id = $order->get_meta('_wc_opc_session_id');
        $order_ip = $order->get_meta('_wc_opc_ip_address');
        
        $current_session_id = $session->get_session_id();
        $current_ip = $this->get_client_ip();
        
        // Vérifier par session ID (prioritaire)
        if ($order_session_id && $order_session_id === $current_session_id) {
            return true;
        }
        
        // Vérifier par IP (secondaire)
        if ($order_ip && $order_ip === $current_ip) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Obtenir l'IP du client
     */
    private function get_client_ip() {
        if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }
        
        if (isset($_SERVER['REMOTE_ADDR'])) {
            return $_SERVER['REMOTE_ADDR'];
        }
        
        return '';
    }
}