<?php
/**
 * Classe de gestion du checkout
 * 
 * Gère le processus de checkout et l'affichage du formulaire sur la page produit
 */
class WC_OPC_Checkout {
    
    /**
     * Constructeur
     */
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
        
        // Vérifier si le one page checkout est activé pour ce produit
        $product_id = get_the_ID();
        $enabled = apply_filters('wc_opc_enable_for_product', $this->is_enabled_for_product($product_id), $product_id);
        
        if (!$enabled) {
            return;
        }
        
        // Supprimer le formulaire d'ajout au panier complet
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
    }
    
    /**
     * Vérifier si le checkout est activé pour un produit
     */
    private function is_enabled_for_product($product_id) {
        // Vérifier si activé globalement
        $global_enabled = get_option('wc_opc_enable_for_all') === 'yes';
        
        // Vérifier si activé spécifiquement pour ce produit
        $product_enabled = get_post_meta($product_id, '_wc_opc_enabled', true) === 'yes';
        
        return $global_enabled || $product_enabled;
    }
    
    /**
     * Ajouter les options de bundle avant le formulaire
     */
    public function add_bundle_options() {
        global $product;
        
        // Vérifier si le produit existe
        if (!$product) {
            return;
        }
        
        // Vérifier si le one page checkout est activé pour ce produit
        $enabled = apply_filters('wc_opc_enable_for_product', $this->is_enabled_for_product($product->get_id()), $product->get_id());
        if (!$enabled) {
            return;
        }
        
        // Vérifier si les bundles sont activés pour ce produit
        $bundle_enabled = get_post_meta($product->get_id(), '_wc_opc_bundle_enabled', true) === 'yes';
       if (!$bundle_enabled) {
           return;
       }
       
       // Récupérer le gestionnaire de bundles
       $bundle_manager = new WC_OPC_Bundle_Manager();
       
       // Récupérer les options de bundle
       $bundle_options = $bundle_manager->get_bundle_options($product->get_id());
       
       // Si des options existent, afficher le template des bundles
       if (!empty($bundle_options)) {
           include WC_OPC_PATH . 'templates/front/bundle-options.php';
       }
   }
   
   /**
    * Ajouter le formulaire de checkout sur la page produit
    */
   public function add_checkout_form() {
       global $product;
       
       // Vérifier si le produit existe
       if (!$product) {
           return;
       }
       
       // Vérifier si le one page checkout est activé pour ce produit
       $enabled = apply_filters('wc_opc_enable_for_product', $this->is_enabled_for_product($product->get_id()), $product->get_id());
       if (!$enabled) {
           return;
       }
       
       // Charger le template du formulaire
       include WC_OPC_PATH . 'templates/front/checkout-form.php';
   }
   
   /**
    * Traiter la soumission du formulaire
    */
   public function process_checkout() {
       // Vérifier le nonce de manière robuste
       if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wc-opc-nonce')) {
           wp_send_json_error(array(
               'message' => __('Erreur de sécurité. Veuillez rafraîchir la page et réessayer.', 'wc-one-page-checkout'),
               'code' => 'security_error'
           ));
           return;
       }
       
       // Récupérer les données du formulaire
       $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
       $quantity = isset($_POST['quantity']) ? absint($_POST['quantity']) : 1;
       $bundle_option = isset($_POST['bundle_option']) ? sanitize_text_field($_POST['bundle_option']) : '';
       $customer_name = isset($_POST['customer_name']) ? sanitize_text_field($_POST['customer_name']) : '';
       $customer_phone = isset($_POST['customer_phone']) ? sanitize_text_field($_POST['customer_phone']) : '';
       $customer_address = isset($_POST['customer_address']) ? sanitize_text_field($_POST['customer_address']) : '';
       $draft_order_id = isset($_POST['draft_order_id']) ? absint($_POST['draft_order_id']) : 0;
       
       // Vérifier les données essentielles
       if (!$product_id || !$customer_phone) {
           wp_send_json_error(array(
               'message' => __('Données manquantes. Veuillez remplir tous les champs obligatoires.', 'wc-one-page-checkout')
           ));
           return;
       }
       
       // Vérifier le produit
       $product = wc_get_product($product_id);
       if (!$product) {
           wp_send_json_error(array(
               'message' => __('Produit invalide.', 'wc-one-page-checkout')
           ));
           return;
       }
       
       // Vérifier le téléphone
       $validator = new WC_OPC_Validation();
       if (!$validator->is_valid_phone($customer_phone)) {
           wp_send_json_error(array(
               'message' => __('Veuillez entrer un numéro de téléphone valide (au moins 8 chiffres).', 'wc-one-page-checkout')
           ));
           return;
       }
       
       try {
           $logger = WC_OPC_Logger::get_instance();
           $logger->info("Démarrage du processus de checkout - Produit: {$product_id}, Commande draft: {$draft_order_id}");
           
           // Si nous avons une commande draft, l'utiliser
           if ($draft_order_id) {
               $order = wc_get_order($draft_order_id);
               
               if (!$order) {
                   throw new Exception(__('Commande draft invalide.', 'wc-one-page-checkout'));
               }
               
               if ($order->get_meta('_wc_opc_draft_order') !== 'yes') {
                   throw new Exception(__('Cette commande n\'est pas une commande draft.', 'wc-one-page-checkout'));
               }
               
               // Convertir la commande draft en commande réelle
               $logger->info("Conversion de la commande draft #{$draft_order_id} en commande réelle");
               
               // Mettre à jour les informations client
               $order->set_billing_first_name($customer_name);
               $order->set_billing_phone($customer_phone);
               $order->set_billing_address_1($customer_address);
               
               // Ajouter une note finale avec toutes les informations de contact
               $order->add_order_note(
                   sprintf(
                       __('Commande finalisée - Nom: %s, Téléphone: %s, Adresse: %s', 'wc-one-page-checkout'),
                       $customer_name,
                       $customer_phone,
                       $customer_address
                   ),
                   false // Note privée
               );
               
               // Mettre à jour le statut
               $order->set_status('processing', __('Commande créée via One Page Checkout', 'wc-one-page-checkout'));
               
               // Supprimer le flag de commande draft
               $order->delete_meta_data('_wc_opc_draft_order');
               $order->add_meta_data('_wc_opc_order', 'yes');
               $order->add_meta_data('_wc_opc_completed_time', time());
               
               // Recalculer les totaux
               $order->calculate_totals();
               
               // Sauvegarder
               $order->save();
               
               $logger->info("Commande #{$order->get_id()} créée avec succès");
               
               // Définir un cookie pour réinitialiser la session à la prochaine visite
               setcookie('wc_opc_order_completed', '1', time() + 86400, COOKIEPATH, COOKIE_DOMAIN);
               
               // Rediriger avec un paramètre pour réinitialiser immédiatement
               $redirect_url = add_query_arg('reset_opc_session', '1', $order->get_checkout_order_received_url());
               
               // Déclencher un événement pour le tracking
               do_action('wc_opc_checkout_success', $order->get_id(), $product_id);
               
               // Envoyer la réponse
               wp_send_json_success(array(
                   'order_id' => $order->get_id(),
                   'message' => __('Commande créée avec succès!', 'wc-one-page-checkout'),
                   'redirect' => $redirect_url,
                   'product_id' => $product_id,
                   'event' => 'checkout_success'
               ));
           } else {
               // Créer une nouvelle commande
               $logger->info("Création d'une nouvelle commande pour le produit {$product_id}");
               
               $order = wc_create_order();
               
               // Définir le statut initial
               $order->set_status('processing', __('Commande créée via One Page Checkout', 'wc-one-page-checkout'));
               
               // Définir les informations client
               $order->set_billing_first_name($customer_name);
               $order->set_billing_phone($customer_phone);
               $order->set_billing_address_1($customer_address);
               
               // Ajouter le produit
               if ($bundle_option) {
                   // Gérer les bundles
                   $bundle_manager = new WC_OPC_Bundle_Manager();
                   $bundle_data = $bundle_manager->get_bundle_option($product_id, $bundle_option);
                   
                   if ($bundle_data) {
                       $item_id = $order->add_product($product, $bundle_data['quantity'], [
                           'subtotal' => $bundle_data['price'],
                           'total' => $bundle_data['price']
                       ]);
                       
                       // Ajouter les métadonnées du bundle
                       wc_add_order_item_meta($item_id, '_bundle_option', $bundle_option);
                       wc_add_order_item_meta($item_id, '_bundle_description', $bundle_data['description']);
                       wc_add_order_item_meta($item_id, '_bundle_price', $bundle_data['price']);
                   } else {
                       $order->add_product($product, $quantity);
                   }
               } else {
                   $order->add_product($product, $quantity);
               }
               
               // Ajouter une note avec les informations de contact
               $order->add_order_note(
                   sprintf(
                       __('Commande créée - Nom: %s, Téléphone: %s, Adresse: %s', 'wc-one-page-checkout'),
                       $customer_name,
                       $customer_phone,
                       $customer_address
                   ),
                   false // Note privée
               );
               
               // Marquer comme une commande créée par notre plugin
               $order->add_meta_data('_wc_opc_order', 'yes');
               $order->add_meta_data('_wc_opc_completed_time', time());
               
               // Recalculer les totaux
               $order->calculate_totals();
               
               // Sauvegarder
               $order->save();
               
               $logger->info("Commande #{$order->get_id()} créée avec succès");
               
               // Définir un cookie pour réinitialiser la session à la prochaine visite
               setcookie('wc_opc_order_completed', '1', time() + 86400, COOKIEPATH, COOKIE_DOMAIN);
               
               // Rediriger avec un paramètre pour réinitialiser immédiatement
               $redirect_url = add_query_arg('reset_opc_session', '1', $order->get_checkout_order_received_url());
               
               // Déclencher un événement pour le tracking
               do_action('wc_opc_checkout_success', $order->get_id(), $product_id);
               
               // Envoyer la réponse
               wp_send_json_success(array(
                   'order_id' => $order->get_id(),
                   'message' => __('Commande créée avec succès!', 'wc-one-page-checkout'),
                   'redirect' => $redirect_url,
                   'product_id' => $product_id,
                   'event' => 'checkout_success'
               ));
           }
       } catch (Exception $e) {
           $logger = WC_OPC_Logger::get_instance();
           $logger->error("Erreur lors du checkout: " . $e->getMessage());
           
           wp_send_json_error(array(
               'message' => $e->getMessage()
           ));
       }
   }
}