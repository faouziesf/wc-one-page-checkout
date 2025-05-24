<?php
/**
 * Gestionnaire des commandes draft avec protection anti-duplication
 * 
 * Cette classe gère le cycle de vie des commandes draft avec des mécanismes
 * robustes pour éviter les doublons et les pertes de données.
 */
class WC_OPC_Draft_Manager {

    /**
     * Période d'expiration des commandes draft (en secondes)
     * 24 heures par défaut
     */
    private $draft_expiration = 86400;

    /**
     * Nombre maximum de tentatives pour les opérations
     */
    private $max_retry_attempts = 3;

    /**
     * Délai entre les tentatives (en secondes)
     */
    private $retry_delay = 2;

    /**
     * Instance du gestionnaire de session
     */
    private $session;

    /**
     * Instance du gestionnaire de cache
     */
    private $cache;

    /**
     * Instance du logger
     */
    private $logger;

    /**
     * Constructeur
     */
    public function __construct() {
        // Initialiser les dépendances
        $this->session = new WC_OPC_Session();
        $this->cache = new WC_OPC_Cache();
        $this->logger = WC_OPC_Logger::get_instance();
        
        // Charger la configuration
        $this->load_config();
        
        // Initialiser les hooks
        $this->init_hooks();
        
        // Réinitialiser la session si une commande a été passée
        if (isset($_GET['reset_opc_session']) || isset($_COOKIE['wc_opc_order_completed'])) {
            $this->reset_session_for_new_order();
        }
    }

    /**
     * Charger la configuration
     */
    private function load_config() {
        $this->draft_expiration = (int) get_option('wc_opc_draft_expiration', 86400);
        $this->max_retry_attempts = (int) get_option('wc_opc_max_retry_attempts', 3);
        $this->retry_delay = (int) get_option('wc_opc_retry_delay', 2);
    }

    /**
     * Initialiser les hooks
     */
    private function init_hooks() {
        // Hooks AJAX pour la gestion des commandes draft
        add_action('wp_ajax_wc_opc_create_draft_order', array($this, 'ajax_create_draft_order'));
        add_action('wp_ajax_nopriv_wc_opc_create_draft_order', array($this, 'ajax_create_draft_order'));
        
        add_action('wp_ajax_wc_opc_update_draft_order', array($this, 'ajax_update_draft_order'));
        add_action('wp_ajax_nopriv_wc_opc_update_draft_order', array($this, 'ajax_update_draft_order'));
        
        add_action('wp_ajax_wc_opc_verify_draft_order', array($this, 'ajax_verify_draft_order'));
        add_action('wp_ajax_nopriv_wc_opc_verify_draft_order', array($this, 'ajax_verify_draft_order'));
        
        // Hook pour nettoyer les commandes draft expirées
        add_action('wc_opc_cleanup_expired_drafts', array($this, 'cleanup_expired_drafts'));
    }

    /**
     * Réinitialiser la session pour une nouvelle commande
     */
    public function reset_session_for_new_order() {
        $this->logger->info("Réinitialisation de la session pour une nouvelle commande");
        
        // Récupérer le produit actuel si disponible
        $product_id = 0;
        if (is_product()) {
            global $product;
            if ($product) {
                $product_id = $product->get_id();
            }
        }
        
        // Supprimer l'identifiant de commande stocké
        if ($product_id > 0) {
            $this->cache->delete('draft_order_' . $product_id);
        } else {
            // Si on ne connaît pas le produit, nettoyer tous les caches draft_order
            global $wpdb;
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%_transient_wc_opc_draft_order_%'");
        }
        
        // Régénérer un nouvel ID de session
        $this->session->regenerate_session_id();
        
        $this->logger->info("Session réinitialisée avec succès, nouvel ID: " . $this->session->get_session_id());
    }

    /**
     * Créer une commande draft via AJAX avec mécanismes anti-duplication
     */
    public function ajax_create_draft_order() {
        // Mesurer les performances
        $start_time = microtime(true);
        
        // Vérifier le nonce
        if (!$this->verify_nonce('wc-opc-nonce')) {
            $this->send_error_response('security_error', 'Erreur de sécurité. Veuillez rafraîchir la page et réessayer.');
            return;
        }
        
        // Récupérer et valider les données
        $validation_result = $this->validate_draft_data($_POST);
        if (is_wp_error($validation_result)) {
            $this->send_error_response(
                $validation_result->get_error_code(),
                $validation_result->get_error_message()
            );
            return;
        }
        
        // Extraire les données validées
        extract($validation_result);
        
        // Générer un identifiant unique de transaction
        $transaction_id = $this->generate_transaction_id($product_id);
        
        // Journaliser le début de la transaction
        $this->logger->log("Début de création de commande draft - Transaction: {$transaction_id}, Produit: {$product_id}");
        
        // Vérifier si une commande draft existe déjà pour cette session/produit
        $existing_draft_id = $this->find_existing_draft($product_id);
        
        if ($existing_draft_id) {
            // Utiliser la commande existante
            $this->logger->log("Commande draft existante trouvée: {$existing_draft_id} - Transaction: {$transaction_id}");
            
            $result = $this->update_existing_draft(
                $existing_draft_id, 
                $customer_phone, 
                $product_id
            );
            
            if ($result) {
                $execution_time = round((microtime(true) - $start_time) * 1000);
                
                $this->logger->log("Commande draft mise à jour avec succès - Temps: {$execution_time}ms - Transaction: {$transaction_id}");
                
                // Renvoyer la réponse avec l'ID existant
                $this->send_success_response(array(
                    'draft_order_id' => $existing_draft_id,
                    'message' => __('Commande draft existante utilisée', 'wc-one-page-checkout'),
                    'product_id' => $product_id,
                    'execution_time' => $execution_time,
                    'transaction_id' => $transaction_id,
                    'is_new' => false
                ));
                return;
            }
        }
        
        // Obtenir un verrou pour éviter les créations simultanées
        $lock_key = $this->get_lock_key($product_id);
        if (!$this->acquire_lock($lock_key)) {
            $this->logger->log("Impossible d'acquérir le verrou - Transaction: {$transaction_id}");
            $this->send_error_response(
                'lock_error', 
                __('Une création de commande est déjà en cours. Veuillez réessayer dans quelques instants.', 'wc-one-page-checkout')
            );
            return;
        }
        
        // Commencer une transaction
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        
        try {
            // Vérifier à nouveau s'il existe une commande draft (double vérification pour éviter les conditions de concurrence)
            $existing_draft_id = $this->find_existing_draft($product_id);
            if ($existing_draft_id) {
                $this->logger->log("Double vérification: commande existante trouvée - Transaction: {$transaction_id}");
                
                // Libérer le verrou
                $this->release_lock($lock_key);
                
                // Valider la transaction
                $wpdb->query('COMMIT');
                
                $result = $this->update_existing_draft(
                    $existing_draft_id, 
                    $customer_phone, 
                    $product_id
                );
                
                $execution_time = round((microtime(true) - $start_time) * 1000);
                
                $this->send_success_response(array(
                    'draft_order_id' => $existing_draft_id,
                    'message' => __('Commande draft existante utilisée (double vérification)', 'wc-one-page-checkout'),
                    'product_id' => $product_id,
                    'execution_time' => $execution_time,
                    'transaction_id' => $transaction_id,
                    'is_new' => false
                ));
                return;
            }
            
            // Créer une nouvelle commande draft
            $draft_order_id = $this->create_draft_order($product, $customer_phone, $quantity, $bundle_option);
            
            if (!$draft_order_id) {
                throw new Exception(__('Échec de la création de la commande', 'wc-one-page-checkout'));
            }
            
            // Sauvegarder l'association session/produit/commande
            $this->set_draft_order_for_session($draft_order_id, $product_id);
            
            // Valider la transaction
            $wpdb->query('COMMIT');
            
            // Libérer le verrou
            $this->release_lock($lock_key);
            
            // Calculer le temps d'exécution
            $execution_time = round((microtime(true) - $start_time) * 1000);
            
            $this->logger->log("Commande draft créée avec succès: {$draft_order_id} - Temps: {$execution_time}ms - Transaction: {$transaction_id}");
            
            // Envoyer la réponse
            $this->send_success_response(array(
                'draft_order_id' => $draft_order_id,
                'message' => __('Commande draft créée avec succès', 'wc-one-page-checkout'),
                'product_id' => $product_id,
                'execution_time' => $execution_time,
                'transaction_id' => $transaction_id,
                'is_new' => true
            ));
            
        } catch (Exception $e) {
            // Annuler la transaction
            $wpdb->query('ROLLBACK');
            
            // Libérer le verrou
            $this->release_lock($lock_key);
            
            $this->logger->log("Erreur lors de la création de commande draft: " . $e->getMessage() . " - Transaction: {$transaction_id}");
            
            $this->send_error_response(
                'creation_error',
                $e->getMessage()
            );
        }
    }

    /**
     * Mettre à jour une commande draft via AJAX
     */
    public function ajax_update_draft_order() {
        // Vérifier le nonce
        if (!$this->verify_nonce('wc-opc-nonce')) {
            $this->send_error_response('security_error', 'Erreur de sécurité. Veuillez rafraîchir la page et réessayer.');
            return;
        }
        
        // Récupérer les données essentielles
        $draft_order_id = isset($_POST['draft_order_id']) ? intval($_POST['draft_order_id']) : 0;
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $field_changed = isset($_POST['field_changed']) ? sanitize_text_field($_POST['field_changed']) : '';
        
        if (!$draft_order_id || !$product_id) {
            $this->send_error_response('missing_data', __('Données manquantes', 'wc-one-page-checkout'));
            return;
        }
        
        // Vérifier si la commande existe
        $order = wc_get_order($draft_order_id);
        if (!$order) {
            $this->send_error_response('invalid_order', __('Commande invalide', 'wc-one-page-checkout'));
            return;
        }
        
        // Vérifier que c'est bien une commande draft OPC
        if ($order->get_meta('_wc_opc_draft_order') !== 'yes') {
            $this->send_error_response('not_draft', __('Cette commande n\'est pas une commande draft OPC', 'wc-one-page-checkout'));
            return;
        }
        
        // Vérifier la propriété de la commande
        if (!$this->verify_draft_ownership($draft_order_id)) {
            $this->send_error_response('not_owner', __('Vous n\'êtes pas autorisé à modifier cette commande', 'wc-one-page-checkout'));
            return;
        }
        
        // Si c'est juste une vérification, on renvoie succès
        if ($field_changed === 'verify') {
            $this->send_success_response(array(
                'message' => __('Commande draft valide', 'wc-one-page-checkout'),
                'draft_order_id' => $draft_order_id,
                'product_id' => $product_id
            ));
            return;
        }
        
        // Obtenir un verrou pour éviter les mises à jour simultanées
        $lock_key = $this->get_lock_key($product_id, $draft_order_id);
        if (!$this->acquire_lock($lock_key)) {
            $this->send_error_response(
                'lock_error', 
                __('Une mise à jour est déjà en cours. Veuillez réessayer dans quelques instants.', 'wc-one-page-checkout')
            );
            return;
        }
        
        // Extraire les données du formulaire
        $customer_name = isset($_POST['customer_name']) ? sanitize_text_field($_POST['customer_name']) : '';
        $customer_phone = isset($_POST['customer_phone']) ? sanitize_text_field($_POST['customer_phone']) : '';
        $customer_address = isset($_POST['customer_address']) ? sanitize_text_field($_POST['customer_address']) : '';
        $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;
        $bundle_option = isset($_POST['bundle_option']) ? sanitize_text_field($_POST['bundle_option']) : '';
       
       try {
           // Mettre à jour la commande
           $this->update_draft_order(
               $order,
               $product_id,
               $quantity,
               $bundle_option,
               $customer_name,
               $customer_phone,
               $customer_address,
               $field_changed
           );
           
           // Libérer le verrou
           $this->release_lock($lock_key);
           
           $this->send_success_response(array(
               'draft_order_id' => $draft_order_id,
               'message' => __('Commande draft mise à jour avec succès', 'wc-one-page-checkout'),
               'field_updated' => $field_changed,
               'product_id' => $product_id
           ));
           
       } catch (Exception $e) {
           // Libérer le verrou
           $this->release_lock($lock_key);
           
           $this->logger->log("Erreur lors de la mise à jour de commande draft: " . $e->getMessage());
           
           $this->send_error_response(
               'update_error',
               $e->getMessage()
           );
       }
   }

   /**
    * Vérifier une commande draft via AJAX
    */
   public function ajax_verify_draft_order() {
       // Vérifier le nonce
       if (!$this->verify_nonce('wc-opc-nonce')) {
           $this->send_error_response('security_error', 'Erreur de sécurité. Veuillez rafraîchir la page et réessayer.');
           return;
       }
       
       // Récupérer les données
       $draft_order_id = isset($_POST['draft_order_id']) ? intval($_POST['draft_order_id']) : 0;
       $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
       
       if (!$draft_order_id || !$product_id) {
           $this->send_error_response('missing_data', __('Paramètres manquants', 'wc-one-page-checkout'));
           return;
       }
       
       // Vérifier si la commande existe
       $order = wc_get_order($draft_order_id);
       if (!$order) {
           $this->send_error_response('invalid_order', __('Commande invalide', 'wc-one-page-checkout'));
           return;
       }
       
       // Vérifier que c'est bien une commande draft OPC
       if ($order->get_meta('_wc_opc_draft_order') !== 'yes') {
           $this->send_error_response('not_draft', __('Cette commande n\'est pas une commande draft OPC', 'wc-one-page-checkout'));
           return;
       }
       
       // Vérifier si la commande est expirée
       if ($this->is_draft_expired($order)) {
           $this->send_error_response('expired', __('Cette commande a expiré', 'wc-one-page-checkout'));
           return;
       }
       
       // Vérifier si la commande concerne le bon produit
       $order_product_id = $order->get_meta('_wc_opc_product_id');
       if ($order_product_id != $product_id) {
           $this->send_error_response('wrong_product', __('Cette commande concerne un autre produit', 'wc-one-page-checkout'));
           return;
       }
       
       // Vérifier la propriété de la commande
       if (!$this->verify_draft_ownership($draft_order_id)) {
           $this->send_error_response('not_owner', __('Vous n\'êtes pas autorisé à accéder à cette commande', 'wc-one-page-checkout'));
           return;
       }
       
       // Tout est OK
       $this->send_success_response(array(
           'message' => __('Commande draft valide', 'wc-one-page-checkout'),
           'draft_order_id' => $draft_order_id,
           'product_id' => $product_id
       ));
   }

   /**
    * Créer une commande draft
    */
   private function create_draft_order($product, $customer_phone, $quantity = 1, $bundle_option = '') {
       try {
           // Créer une nouvelle commande
           $order = wc_create_order(array(
               'status' => 'draft'
           ));
           
           // Ajouter les métadonnées
           $order->update_meta_data('_wc_opc_draft_order', 'yes');
           $order->update_meta_data('_wc_opc_product_id', $product->get_id());
           $order->update_meta_data('_wc_opc_creation_time', time());
           $order->update_meta_data('_wc_opc_expiration', time() + $this->draft_expiration);
           
           // Ajouter les infos de session
           $session_id = $this->session->get_session_id();
           $client_ip = $this->get_client_ip();
           $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
           
           $order->update_meta_data('_wc_opc_session_id', $session_id);
           $order->update_meta_data('_wc_opc_ip_address', $client_ip);
           $order->update_meta_data('_wc_opc_user_agent', $user_agent);
           
           // Définir le téléphone
           $order->set_billing_phone($customer_phone);
           
           // Ajouter le produit
           if ($bundle_option) {
               // Gérer les bundles
               $this->add_bundle_to_order($order, $product, $bundle_option);
           } else {
               // Ajouter le produit standard
               $item_id = $order->add_product($product, $quantity);
           }
           
           // Ajouter une note pour le téléphone initial
           $order->add_order_note(
               sprintf(__('Numéro de téléphone initial: %s', 'wc-one-page-checkout'), $customer_phone),
               false // Note privée
           );
           
           // Recalculer les totaux
           $order->calculate_totals();
           
           // Sauvegarder la commande
           $order->save();
           
           // Vérifier que la commande a bien été créée
           if (!$order->get_id()) {
               throw new Exception(__('Échec de la création de la commande', 'wc-one-page-checkout'));
           }
           
           // Déclencher un événement pour les modules externes
           do_action('wc_opc_draft_order_created', $order->get_id(), $product->get_id());
           
           return $order->get_id();
           
       } catch (Exception $e) {
           $this->logger->log("Erreur lors de la création de commande draft: " . $e->getMessage());
           return false;
       }
   }

   /**
    * Mettre à jour une commande draft existante
    */
   private function update_existing_draft($order_id, $customer_phone, $product_id) {
       $order = wc_get_order($order_id);
       
       if (!$order) {
           return false;
       }
       
       try {
           // Mettre à jour le téléphone si différent et enregistrer dans les notes
           $current_phone = $order->get_billing_phone();
           if ($current_phone !== $customer_phone) {
               $order->set_billing_phone($customer_phone);
               
               // Ajouter une note pour l'historique du téléphone
               $order->add_order_note(
                   sprintf(__('Numéro de téléphone mis à jour: %s', 'wc-one-page-checkout'), $customer_phone),
                   false // Note privée
               );
           }
           
           // Mise à jour de la date d'expiration
           $order->update_meta_data('_wc_opc_expiration', time() + $this->draft_expiration);
           $order->update_meta_data('_wc_opc_last_update', time());
           
           // Sauvegarder les changements
           $order->save();
           
           return true;
           
       } catch (Exception $e) {
           $this->logger->log("Erreur lors de la mise à jour de commande draft existante: " . $e->getMessage());
           return false;
       }
   }

   /**
    * Mettre à jour une commande draft
    */
   private function update_draft_order($order, $product_id, $quantity, $bundle_option, $customer_name, $customer_phone, $customer_address, $field_changed) {
       try {
           // Mettre à jour les informations client
           if (!empty($customer_name)) {
               $order->set_billing_first_name($customer_name);
           }
           
           if (!empty($customer_phone)) {
               $current_phone = $order->get_billing_phone();
               
               // Si le téléphone a changé, l'enregistrer et ajouter une note
               if ($current_phone !== $customer_phone) {
                   $order->set_billing_phone($customer_phone);
                   
                   // Ajouter une note pour l'historique du téléphone
                   $order->add_order_note(
                       sprintf(__('Numéro de téléphone: %s', 'wc-one-page-checkout'), $customer_phone),
                       false // Note privée
                   );
               }
           }
           
           if (!empty($customer_address)) {
               $order->set_billing_address_1($customer_address);
           }
           
           // Mettre à jour la date d'expiration
           $order->update_meta_data('_wc_opc_expiration', time() + $this->draft_expiration);
           $order->update_meta_data('_wc_opc_last_update', time());
           
           // Mettre à jour le produit si nécessaire (quantité ou bundle)
           if ($field_changed === 'quantity' || $field_changed === 'bundle') {
               $this->update_order_product($order, $product_id, $quantity, $bundle_option);
           }
           
           // Recalculer les totaux
           $order->calculate_totals();
           
           // Sauvegarder les changements
           $order->save();
           
           // Déclencher un événement pour les modules externes
           do_action('wc_opc_draft_order_updated', $order->get_id(), $product_id, $field_changed);
           
           return true;
           
       } catch (Exception $e) {
           $this->logger->log("Erreur lors de la mise à jour de commande draft: " . $e->getMessage());
           throw $e;
       }
   }

   /**
    * Mettre à jour le produit dans une commande
    */
   private function update_order_product($order, $product_id, $quantity, $bundle_option) {
       // Récupérer le produit
       $product = wc_get_product($product_id);
       if (!$product) {
           throw new Exception(__('Produit invalide', 'wc-one-page-checkout'));
       }
       
       // Supprimer tous les articles existants
       foreach ($order->get_items() as $item_id => $item) {
           $order->remove_item($item_id);
       }
       
       // Ajouter le produit avec les nouvelles informations
       if ($bundle_option) {
           // Gérer les bundles
           $this->add_bundle_to_order($order, $product, $bundle_option);
       } else {
           // Ajouter le produit standard
           $item_id = $order->add_product($product, $quantity);
       }
   }

   /**
    * Ajouter un bundle à une commande
    */
   private function add_bundle_to_order($order, $product, $bundle_option) {
       // Récupérer le gestionnaire de bundles
       $bundle_manager = new WC_OPC_Bundle_Manager();
       
       // Récupérer les détails du bundle
       $bundle_data = $bundle_manager->get_bundle_option($product->get_id(), $bundle_option);
       
       if ($bundle_data) {
           // Ajouter le produit avec les détails du bundle
           $item_id = $order->add_product($product, $bundle_data['quantity'], [
               'subtotal' => $bundle_data['price'],
               'total' => $bundle_data['price']
           ]);
           
           // Ajouter les métadonnées du bundle
           wc_add_order_item_meta($item_id, '_bundle_option', $bundle_option);
           wc_add_order_item_meta($item_id, '_bundle_description', $bundle_data['description']);
           wc_add_order_item_meta($item_id, '_bundle_price', $bundle_data['price']);
       } else {
           // Si le bundle n'existe pas, ajouter le produit standard
           $item_id = $order->add_product($product, 1);
       }
   }

   /**
    * Valider les données pour une commande draft
    */
   private function validate_draft_data($data) {
       // Récupérer les données essentielles
       $product_id = isset($data['product_id']) ? intval($data['product_id']) : 0;
       $customer_phone = isset($data['customer_phone']) ? sanitize_text_field($data['customer_phone']) : '';
       $quantity = isset($data['quantity']) ? intval($data['quantity']) : 1;
       $bundle_option = isset($data['bundle_option']) ? sanitize_text_field($data['bundle_option']) : '';
       
       // Vérifier le produit
       $product = wc_get_product($product_id);
       if (!$product) {
           return new WP_Error('invalid_product', __('Produit invalide', 'wc-one-page-checkout'));
       }
       
       // Vérifier le téléphone
       $validator = new WC_OPC_Validation();
       
       if (!$validator->is_valid_phone($customer_phone)) {
           return new WP_Error('invalid_phone', __('Numéro de téléphone invalide', 'wc-one-page-checkout'));
       }
       
       // Vérifier la quantité
       if ($quantity < 1) {
           $quantity = 1;
       }
       
       // Retourner les données validées
       return array(
           'product_id' => $product_id,
           'product' => $product,
           'customer_phone' => $customer_phone,
           'quantity' => $quantity,
           'bundle_option' => $bundle_option
       );
   }

   /**
    * Vérifier si une commande draft existe pour la session courante et le produit spécifié
    */
   private function find_existing_draft($product_id) {
       // Essayer d'abord de récupérer depuis le cache
       $cached_id = $this->cache->get('draft_order_' . $product_id);
       if ($cached_id) {
           // Vérifier rapidement si la commande existe toujours
           $order = wc_get_order($cached_id);
           if ($order && $order->get_meta('_wc_opc_draft_order') === 'yes' && !$this->is_draft_expired($order)) {
               return $cached_id;
           }
       }
       
       // Rechercher par session ID et produit
       $session_id = $this->session->get_session_id();
       $client_ip = $this->get_client_ip();
       
       global $wpdb;
       
       // Recherche optimisée par session ID
       $query = $wpdb->prepare(
           "SELECT post_id FROM {$wpdb->postmeta} 
           WHERE meta_key = '_wc_opc_session_id' 
           AND meta_value = %s 
           AND post_id IN (
               SELECT post_id FROM {$wpdb->postmeta} 
               WHERE meta_key = '_wc_opc_product_id' 
               AND meta_value = %d
           )
           AND post_id IN (
               SELECT post_id FROM {$wpdb->postmeta} 
               WHERE meta_key = '_wc_opc_draft_order' 
               AND meta_value = 'yes'
           )
           AND post_id IN (
               SELECT ID FROM {$wpdb->posts} 
               WHERE post_type = 'shop_order' 
               AND post_status = 'draft'
           )
           ORDER BY post_id DESC LIMIT 1",
           $session_id,
           $product_id
       );
       
       $draft_id = $wpdb->get_var($query);
       
       if ($draft_id) {
           // Vérifier si la commande n'est pas expirée
           $order = wc_get_order($draft_id);
           if ($order && !$this->is_draft_expired($order)) {
               // Mettre en cache pour les futures requêtes
               $this->cache->set('draft_order_' . $product_id, $draft_id, $this->draft_expiration);
               return $draft_id;
           }
       }
       
       return false;
   }

   /**
    * Vérifier si une commande draft est expirée
    */
   private function is_draft_expired($order) {
       $expiration = $order->get_meta('_wc_opc_expiration');
       
       if (!$expiration) {
           // Si pas de date d'expiration, utiliser la date de création + durée d'expiration
           $creation_time = $order->get_meta('_wc_opc_creation_time');
           
           if (!$creation_time) {
               // Utiliser la date de la commande comme fallback
               $creation_time = strtotime($order->get_date_created());
           }
           
           return (time() > ($creation_time + $this->draft_expiration));
       }
       
       return (time() > $expiration);
   }

   /**
    * Associer une commande draft à une session/produit
    */
   private function set_draft_order_for_session($order_id, $product_id) {
       // Mettre en cache localement
       $this->cache->set('draft_order_' . $product_id, $order_id, $this->draft_expiration);
   }

   /**
    * Vérifier si l'utilisateur est propriétaire de la commande draft
    */
   private function verify_draft_ownership($order_id) {
       $order = wc_get_order($order_id);
       
       if (!$order) {
           return false;
       }
       
       // Récupérer les informations de session
       $order_session_id = $order->get_meta('_wc_opc_session_id');
       $order_ip = $order->get_meta('_wc_opc_ip_address');
       
       $current_session_id = $this->session->get_session_id();
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
    * Nettoyage des commandes draft expirées
    */
   public function cleanup_expired_drafts() {
       $this->logger->log("Début du nettoyage des commandes draft expirées");
       
       global $wpdb;
       
       // Récupérer les commandes draft créées il y a plus de X secondes
       $expiration_time = time() - $this->draft_expiration;
       
       // Recherche par date d'expiration explicite
       $query = $wpdb->prepare(
           "SELECT post_id FROM {$wpdb->postmeta} 
           WHERE meta_key = '_wc_opc_expiration' 
           AND meta_value < %d 
           AND post_id IN (
               SELECT post_id FROM {$wpdb->postmeta} 
               WHERE meta_key = '_wc_opc_draft_order' 
               AND meta_value = 'yes'
           )
           AND post_id IN (
               SELECT ID FROM {$wpdb->posts} 
               WHERE post_type = 'shop_order' 
               AND post_status = 'draft'
           )",
           $expiration_time
       );
       
       $expired_drafts = $wpdb->get_col($query);
       
       // Recherche par date de création (pour les commandes sans date d'expiration explicite)
       $query = $wpdb->prepare(
           "SELECT post_id FROM {$wpdb->postmeta} 
           WHERE meta_key = '_wc_opc_creation_time' 
           AND meta_value < %d 
           AND post_id IN (
               SELECT post_id FROM {$wpdb->postmeta} 
               WHERE meta_key = '_wc_opc_draft_order' 
               AND meta_value = 'yes'
           )
           AND post_id IN (
               SELECT ID FROM {$wpdb->posts} 
               WHERE post_type = 'shop_order' 
               AND post_status = 'draft'
           )
           AND post_id NOT IN (
               SELECT post_id FROM {$wpdb->postmeta} 
               WHERE meta_key = '_wc_opc_expiration'
           )",
           $expiration_time
       );
       
       $expired_by_creation = $wpdb->get_col($query);
       
       // Fusionner les résultats
       $expired_drafts = array_merge($expired_drafts, $expired_by_creation);
       
       // Dédupliquer
       $expired_drafts = array_unique($expired_drafts);
       
       if (empty($expired_drafts)) {
           $this->logger->log("Aucune commande draft expirée trouvée");
           return;
       }
       
       $count = count($expired_drafts);
       $this->logger->log("Trouvé {$count} commandes draft expirées à nettoyer");
       
       // Traiter les commandes expirées
       foreach ($expired_drafts as $draft_id) {
           $order = wc_get_order($draft_id);
           
           if ($order) {
               // Ajouter une note avant de supprimer
               $order->add_order_note(
                   __('Commande draft expirée - Supprimée automatiquement', 'wc-one-page-checkout'),
                   false // Note privée
               );
               
               // Mettre dans la corbeille
               $order->update_status('trash', __('Expirée', 'wc-one-page-checkout'));
               
               $this->logger->log("Commande draft #{$draft_id} déplacée vers la corbeille");
           }
       }
       
       $this->logger->log("Nettoyage des commandes draft terminé - {$count} commandes traitées");
   }

   /**
    * Générer un ID de transaction unique
    */
   private function generate_transaction_id($product_id) {
       return md5($this->session->get_session_id() . '_' . $product_id . '_' . microtime(true));
   }

   /**
    * Obtenir une clé de verrou pour un produit/commande
    */
   private function get_lock_key($product_id, $order_id = 0) {
       $session_id = $this->session->get_session_id();
       $client_ip = $this->get_client_ip();
       
       if ($order_id) {
           return 'wc_opc_lock_' . md5("{$session_id}_{$client_ip}_{$product_id}_{$order_id}");
       } else {
           return 'wc_opc_lock_' . md5("{$session_id}_{$client_ip}_{$product_id}");
       }
   }

   /**
    * Acquérir un verrou pour éviter les opérations simultanées
    */
   private function acquire_lock($lock_key, $timeout = 30) {
       // Vérifier si un verrou existe déjà
       if (get_transient($lock_key)) {
           return false;
       }
       
       // Poser un verrou avec un délai d'expiration
       set_transient($lock_key, time(), $timeout);
       
       return true;
   }

   /**
    * Libérer un verrou
    */
   private function release_lock($lock_key) {
       delete_transient($lock_key);
       return true;
   }

   /**
    * Vérifier un nonce de manière robuste
    */
   private function verify_nonce($action) {
       // S'assurer que le nonce est bien transmis
       if (!isset($_POST['nonce'])) {
           $this->logger->log("Nonce manquant dans la requête");
           return false;
       }
       
       // Récupérer le nonce
       $nonce = $_POST['nonce'];
       
       // Vérifier le nonce avec wp_verify_nonce
       $result = wp_verify_nonce($nonce, $action);
       
       // Journaliser pour le debug
       $this->logger->log("Vérification du nonce - Action: {$action}, Nonce: {$nonce}, Résultat: " . var_export($result, true));
       
       // wp_verify_nonce renvoie 1 ou 2 si valide, false ou 0 sinon
       if ($result === false || $result === 0) {
           $this->logger->log("Échec de la vérification du nonce");
           return false;
       }
       
       return true;
   }

   /**
    * Obtenir l'adresse IP du client
    */
   private function get_client_ip() {
       // CloudFlare
       if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
           return $_SERVER['HTTP_CF_CONNECTING_IP'];
       }
       
       // Proxy
       if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
           $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
           return trim($ips[0]);
       }
       
       // Direct
       if (isset($_SERVER['REMOTE_ADDR'])) {
           return $_SERVER['REMOTE_ADDR'];
       }
       
       return '';
   }

   /**
    * Envoyer une réponse d'erreur
    */
   private function send_error_response($code, $message) {
       wp_send_json_error(array(
           'code' => $code,
           'message' => $message
       ));
   }

   /**
    * Envoyer une réponse de succès
    */
   private function send_success_response($data) {
       wp_send_json_success($data);
   }
}