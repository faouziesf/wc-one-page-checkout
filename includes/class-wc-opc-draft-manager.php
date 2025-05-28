<?php
/**
 * Gestionnaire des commandes draft avec protection anti-duplication CORRIGÉ
 */
class WC_OPC_Draft_Manager {

    private $draft_expiration = 86400; // 24 heures
    private $max_retry_attempts = 3;
    private $retry_delay = 2;
    private $session;
    private $cache;
    private $logger;

    public function __construct() {
        $this->session = new WC_OPC_Session();
        $this->cache = new WC_OPC_Cache();
        $this->logger = WC_OPC_Logger::get_instance();
        
        $this->load_config();
        $this->init_hooks();
        
        // Réinitialiser la session si une commande a été passée
        if (isset($_GET['reset_opc_session']) || isset($_COOKIE['wc_opc_order_completed'])) {
            $this->reset_session_for_new_order();
        }
    }

    private function load_config() {
        $this->draft_expiration = (int) get_option('wc_opc_draft_expiration', 86400);
        $this->max_retry_attempts = (int) get_option('wc_opc_max_retry_attempts', 3);
        $this->retry_delay = (int) get_option('wc_opc_retry_delay', 2);
    }

    private function init_hooks() {
        add_action('wp_ajax_wc_opc_create_draft_order', array($this, 'ajax_create_draft_order'));
        add_action('wp_ajax_nopriv_wc_opc_create_draft_order', array($this, 'ajax_create_draft_order'));
        
        add_action('wp_ajax_wc_opc_update_draft_order', array($this, 'ajax_update_draft_order'));
        add_action('wp_ajax_nopriv_wc_opc_update_draft_order', array($this, 'ajax_update_draft_order'));
        
        add_action('wp_ajax_wc_opc_verify_draft_order', array($this, 'ajax_verify_draft_order'));
        add_action('wp_ajax_nopriv_wc_opc_verify_draft_order', array($this, 'ajax_verify_draft_order'));
        
        add_action('wc_opc_cleanup_expired_drafts', array($this, 'cleanup_expired_drafts'));
    }

    public function reset_session_for_new_order() {
        $this->logger->info("Réinitialisation de la session pour une nouvelle commande");
        
        // Supprimer tous les caches de draft
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%_transient_wc_opc_draft_order_%'");
        
        // Régénérer l'ID de session
        $this->session->regenerate_session_id();
        
        $this->logger->info("Session réinitialisée avec succès, nouvel ID: " . $this->session->get_session_id());
    }

    /**
     * AJAX - Créer une commande draft avec protection anti-doublons RENFORCÉE
     */
    public function ajax_create_draft_order() {
        $start_time = microtime(true);
        
        // Vérification du nonce
        if (!$this->verify_nonce('wc-opc-nonce')) {
            $this->send_error_response('security_error', 'Erreur de sécurité');
            return;
        }
        
        // Validation des données
        $validation_result = $this->validate_draft_data($_POST);
        if (is_wp_error($validation_result)) {
            $this->send_error_response(
                $validation_result->get_error_code(),
                $validation_result->get_error_message()
            );
            return;
        }
        
        extract($validation_result);
        
        $session_id = $this->session->get_session_id();
        $client_ip = $this->get_client_ip();
        
        // Clé unique pour ce produit/session
        $unique_key = md5($session_id . '_' . $client_ip . '_' . $product_id);
        
        $this->logger->info("Début création draft - Session: {$session_id}, Produit: {$product_id}, Clé: {$unique_key}");
        
        // ÉTAPE 1: Vérifier s'il existe déjà une draft pour cette session/produit
        $existing_draft_id = $this->find_existing_draft_by_session($product_id, $session_id, $client_ip);
        
        if ($existing_draft_id) {
            $this->logger->info("Draft existante trouvée: {$existing_draft_id}");
            
            // Mettre à jour la draft existante
            $order = wc_get_order($existing_draft_id);
            if ($order && $order->get_meta('_wc_opc_draft_order') === 'yes') {
                
                // Mettre à jour le téléphone et l'expiration
                $current_phone = $order->get_billing_phone();
                if ($current_phone !== $customer_phone) {
                    $order->set_billing_phone($customer_phone);
                    $order->add_order_note(
                        sprintf(__('Téléphone mis à jour: %s', 'wc-one-page-checkout'), $customer_phone),
                        false
                    );
                }
                
                $order->update_meta_data('_wc_opc_expiration', time() + $this->draft_expiration);
                $order->update_meta_data('_wc_opc_last_update', time());
                $order->save();
                
                $execution_time = round((microtime(true) - $start_time) * 1000);
                
                $this->send_success_response(array(
                    'draft_order_id' => $existing_draft_id,
                    'message' => __('Draft existante mise à jour', 'wc-one-page-checkout'),
                    'product_id' => $product_id,
                    'execution_time' => $execution_time,
                    'is_new' => false
                ));
                return;
            }
        }
        
        // ÉTAPE 2: Obtenir un verrou pour éviter les créations simultanées
        $lock_key = 'wc_opc_create_lock_' . $unique_key;
        
        if (!$this->acquire_lock($lock_key, 30)) {
            $this->logger->info("Verrou déjà actif pour: {$unique_key}");
            $this->send_error_response(
                'lock_active',
                __('Création en cours, veuillez patienter...', 'wc-one-page-checkout')
            );
            return;
        }
        
        // ÉTAPE 3: Double vérification après acquisition du verrou
        $existing_draft_id = $this->find_existing_draft_by_session($product_id, $session_id, $client_ip);
        
        if ($existing_draft_id) {
            $this->release_lock($lock_key);
            $this->logger->info("Draft créée entre-temps: {$existing_draft_id}");
            
            $execution_time = round((microtime(true) - $start_time) * 1000);
            
            $this->send_success_response(array(
                'draft_order_id' => $existing_draft_id,
                'message' => __('Draft existante utilisée', 'wc-one-page-checkout'),
                'product_id' => $product_id,
                'execution_time' => $execution_time,
                'is_new' => false
            ));
            return;
        }
        
        // ÉTAPE 4: Créer une nouvelle commande draft
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        
        try {
            $draft_order_id = $this->create_draft_order($product, $customer_phone, $quantity, $bundle_option, $session_id, $client_ip);
            
            if (!$draft_order_id) {
                throw new Exception(__('Échec de la création de la commande', 'wc-one-page-checkout'));
            }
            
            // Sauvegarder l'association dans le cache
            $this->cache->set('draft_order_' . $product_id . '_' . $unique_key, $draft_order_id, $this->draft_expiration);
            
            $wpdb->query('COMMIT');
            $this->release_lock($lock_key);
            
            $execution_time = round((microtime(true) - $start_time) * 1000);
            
            $this->logger->info("Draft créée avec succès: {$draft_order_id} - Temps: {$execution_time}ms");
            
            // Déclencher l'événement pour le tracking
            do_action('wc_opc_draft_order_created', $draft_order_id, $product_id);
            
            $this->send_success_response(array(
                'draft_order_id' => $draft_order_id,
                'message' => __('Draft créée avec succès', 'wc-one-page-checkout'),
                'product_id' => $product_id,
                'execution_time' => $execution_time,
                'is_new' => true
            ));
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            $this->release_lock($lock_key);
            
            $this->logger->error("Erreur création draft: " . $e->getMessage());
            
            $this->send_error_response(
                'creation_error',
                $e->getMessage()
            );
        }
    }

    /**
     * AJAX - Mettre à jour une commande draft
     */
    public function ajax_update_draft_order() {
        if (!$this->verify_nonce('wc-opc-nonce')) {
            $this->send_error_response('security_error', 'Erreur de sécurité');
            return;
        }
        
        $draft_order_id = isset($_POST['draft_order_id']) ? intval($_POST['draft_order_id']) : 0;
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $field_changed = isset($_POST['field_changed']) ? sanitize_text_field($_POST['field_changed']) : '';
        
        if (!$draft_order_id || !$product_id) {
            $this->send_error_response('missing_data', __('Données manquantes', 'wc-one-page-checkout'));
            return;
        }
        
        $order = wc_get_order($draft_order_id);
        if (!$order || $order->get_meta('_wc_opc_draft_order') !== 'yes') {
            $this->send_error_response('invalid_order', __('Commande draft invalide', 'wc-one-page-checkout'));
            return;
        }
        
        // Vérifier la propriété
        if (!$this->verify_draft_ownership($draft_order_id)) {
            $this->send_error_response('not_owner', __('Accès non autorisé', 'wc-one-page-checkout'));
            return;
        }
        
        // Si c'est juste une vérification
        if ($field_changed === 'verify') {
            $this->send_success_response(array(
                'message' => __('Draft valide', 'wc-one-page-checkout'),
                'draft_order_id' => $draft_order_id,
                'product_id' => $product_id
            ));
            return;
        }
        
        try {
            $this->update_draft_order(
                $order,
                $product_id,
                isset($_POST['quantity']) ? intval($_POST['quantity']) : 1,
                isset($_POST['bundle_option']) ? sanitize_text_field($_POST['bundle_option']) : '',
                isset($_POST['customer_name']) ? sanitize_text_field($_POST['customer_name']) : '',
                isset($_POST['customer_phone']) ? sanitize_text_field($_POST['customer_phone']) : '',
                isset($_POST['customer_address']) ? sanitize_text_field($_POST['customer_address']) : '',
                $field_changed
            );
            
            $this->send_success_response(array(
                'draft_order_id' => $draft_order_id,
                'message' => __('Draft mise à jour', 'wc-one-page-checkout'),
                'field_updated' => $field_changed,
                'product_id' => $product_id
            ));
            
        } catch (Exception $e) {
            $this->logger->error("Erreur mise à jour draft: " . $e->getMessage());
            $this->send_error_response('update_error', $e->getMessage());
        }
    }

    /**
     * AJAX - Vérifier une commande draft
     */
    public function ajax_verify_draft_order() {
        if (!$this->verify_nonce('wc-opc-nonce')) {
            $this->send_error_response('security_error', 'Erreur de sécurité');
            return;
        }
        
        $draft_order_id = isset($_POST['draft_order_id']) ? intval($_POST['draft_order_id']) : 0;
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        
        if (!$draft_order_id || !$product_id) {
            $this->send_error_response('missing_data', __('Paramètres manquants', 'wc-one-page-checkout'));
            return;
        }
        
        $order = wc_get_order($draft_order_id);
        if (!$order || $order->get_meta('_wc_opc_draft_order') !== 'yes') {
            $this->send_error_response('invalid_order', __('Draft invalide', 'wc-one-page-checkout'));
            return;
        }
        
        if ($this->is_draft_expired($order)) {
            $this->send_error_response('expired', __('Draft expirée', 'wc-one-page-checkout'));
            return;
        }
        
        $order_product_id = $order->get_meta('_wc_opc_product_id');
        if ($order_product_id != $product_id) {
            $this->send_error_response('wrong_product', __('Produit incorrect', 'wc-one-page-checkout'));
            return;
        }
        
        if (!$this->verify_draft_ownership($draft_order_id)) {
            $this->send_error_response('not_owner', __('Accès non autorisé', 'wc-one-page-checkout'));
            return;
        }
        
        $this->send_success_response(array(
            'message' => __('Draft valide', 'wc-one-page-checkout'),
            'draft_order_id' => $draft_order_id,
            'product_id' => $product_id
        ));
    }

    /**
     * Créer une commande draft avec identifiants de session
     */
    private function create_draft_order($product, $customer_phone, $quantity = 1, $bundle_option = '', $session_id = '', $client_ip = '') {
        try {
            $order = wc_create_order(array('status' => 'draft'));
            
            // Métadonnées essentielles
            $order->update_meta_data('_wc_opc_draft_order', 'yes');
            $order->update_meta_data('_wc_opc_product_id', $product->get_id());
            $order->update_meta_data('_wc_opc_creation_time', time());
            $order->update_meta_data('_wc_opc_expiration', time() + $this->draft_expiration);
            
            // Identifiants de session pour éviter les doublons
            $order->update_meta_data('_wc_opc_session_id', $session_id);
            $order->update_meta_data('_wc_opc_ip_address', $client_ip);
            $order->update_meta_data('_wc_opc_user_agent', isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '');
            
            // Informations client
            $order->set_billing_phone($customer_phone);
            
            // Ajouter le produit
            if ($bundle_option) {
                $this->add_bundle_to_order($order, $product, $bundle_option);
            } else {
                $order->add_product($product, $quantity);
            }
            
            // Note initiale
            $order->add_order_note(
                sprintf(__('Draft créée - Téléphone: %s', 'wc-one-page-checkout'), $customer_phone),
                false
            );
            
            // Calculer les totaux et sauvegarder
            $order->calculate_totals();
            $order->save();
            
            if (!$order->get_id()) {
                throw new Exception(__('Échec de la sauvegarde', 'wc-one-page-checkout'));
            }
            
            return $order->get_id();
            
        } catch (Exception $e) {
            $this->logger->error("Erreur création draft: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Rechercher une draft existante par session ET produit
     */
    private function find_existing_draft_by_session($product_id, $session_id, $client_ip) {
        global $wpdb;
        
        // Recherche par session ID prioritaire
        $query = $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_wc_opc_session_id' AND pm1.meta_value = %s
            INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_wc_opc_product_id' AND pm2.meta_value = %d
            INNER JOIN {$wpdb->postmeta} pm3 ON p.ID = pm3.post_id AND pm3.meta_key = '_wc_opc_draft_order' AND pm3.meta_value = 'yes'
            WHERE p.post_type = 'shop_order' AND p.post_status = 'draft'
            ORDER BY p.ID DESC LIMIT 1",
            $session_id,
            $product_id
        );
        
        $draft_id = $wpdb->get_var($query);
        
        if ($draft_id) {
            $order = wc_get_order($draft_id);
            if ($order && !$this->is_draft_expired($order)) {
                return $draft_id;
            }
        }
        
        // Recherche de secours par IP
        if (!$draft_id && $client_ip) {
            $query = $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_wc_opc_ip_address' AND pm1.meta_value = %s
                INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_wc_opc_product_id' AND pm2.meta_value = %d
                INNER JOIN {$wpdb->postmeta} pm3 ON p.ID = pm3.post_id AND pm3.meta_key = '_wc_opc_draft_order' AND pm3.meta_value = 'yes'
                WHERE p.post_type = 'shop_order' AND p.post_status = 'draft'
                AND p.post_date > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                ORDER BY p.ID DESC LIMIT 1",
                $client_ip,
                $product_id
            );
            
            $draft_id = $wpdb->get_var($query);
            
            if ($draft_id) {
                $order = wc_get_order($draft_id);
                if ($order && !$this->is_draft_expired($order)) {
                    return $draft_id;
                }
            }
        }
        
        return false;
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
                if ($current_phone !== $customer_phone) {
                    $order->set_billing_phone($customer_phone);
                    $order->add_order_note(
                        sprintf(__('Téléphone: %s', 'wc-one-page-checkout'), $customer_phone),
                        false
                    );
                }
            }
            
            if (!empty($customer_address)) {
                $order->set_billing_address_1($customer_address);
            }
            
            // Mettre à jour l'expiration
            $order->update_meta_data('_wc_opc_expiration', time() + $this->draft_expiration);
            $order->update_meta_data('_wc_opc_last_update', time());
            
            // Mettre à jour le produit si nécessaire
            if ($field_changed === 'quantity' || $field_changed === 'bundle') {
                $this->update_order_product($order, $product_id, $quantity, $bundle_option);
            }
            
            $order->calculate_totals();
            $order->save();
            
            do_action('wc_opc_draft_order_updated', $order->get_id(), $product_id, $field_changed);
            
            return true;
            
        } catch (Exception $e) {
            $this->logger->error("Erreur mise à jour draft: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Mettre à jour le produit dans la commande
     */
    private function update_order_product($order, $product_id, $quantity, $bundle_option) {
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
            $this->add_bundle_to_order($order, $product, $bundle_option);
        } else {
            $order->add_product($product, $quantity);
        }
    }

    /**
     * Ajouter un bundle à la commande
     */
    private function add_bundle_to_order($order, $product, $bundle_option) {
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
            $order->add_product($product, 1);
        }
    }

    /**
     * Valider les données pour une commande draft
     */
    private function validate_draft_data($data) {
        $product_id = isset($data['product_id']) ? intval($data['product_id']) : 0;
        $customer_phone = isset($data['customer_phone']) ? sanitize_text_field($data['customer_phone']) : '';
        $quantity = isset($data['quantity']) ? intval($data['quantity']) : 1;
        $bundle_option = isset($data['bundle_option']) ? sanitize_text_field($data['bundle_option']) : '';
        
        $product = wc_get_product($product_id);
        if (!$product) {
            return new WP_Error('invalid_product', __('Produit invalide', 'wc-one-page-checkout'));
        }
        
        $validator = new WC_OPC_Validation();
        if (!$validator->is_valid_phone($customer_phone)) {
            return new WP_Error('invalid_phone', __('Numéro de téléphone invalide', 'wc-one-page-checkout'));
        }
        
        if ($quantity < 1) {
            $quantity = 1;
        }
        
        return array(
            'product_id' => $product_id,
            'product' => $product,
            'customer_phone' => $customer_phone,
            'quantity' => $quantity,
            'bundle_option' => $bundle_option
        );
    }

    /**
     * Vérifier si une draft est expirée
     */
    private function is_draft_expired($order) {
        $expiration = $order->get_meta('_wc_opc_expiration');
        
        if (!$expiration) {
            $creation_time = $order->get_meta('_wc_opc_creation_time');
            if (!$creation_time) {
                $creation_time = strtotime($order->get_date_created());
            }
            return (time() > ($creation_time + $this->draft_expiration));
        }
        
        return (time() > $expiration);
    }

    /**
     * Vérifier la propriété d'une draft
     */
    private function verify_draft_ownership($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }
        
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
     * Nettoyage des drafts expirées
     */
    public function cleanup_expired_drafts() {
        $this->logger->info("Début du nettoyage des drafts expirées");
        
        global $wpdb;
        $expiration_time = time() - $this->draft_expiration;
        
        $query = $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_wc_opc_draft_order' AND pm1.meta_value = 'yes'
            LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_wc_opc_expiration'
            LEFT JOIN {$wpdb->postmeta} pm3 ON p.ID = pm3.post_id AND pm3.meta_key = '_wc_opc_creation_time'
            WHERE p.post_type = 'shop_order' 
            AND p.post_status = 'draft'
            AND (
                (pm2.meta_value IS NOT NULL AND pm2.meta_value < %d)
                OR 
                (pm2.meta_value IS NULL AND pm3.meta_value IS NOT NULL AND pm3.meta_value < %d)
                OR
                (pm2.meta_value IS NULL AND pm3.meta_value IS NULL AND p.post_date < DATE_SUB(NOW(), INTERVAL %d SECOND))
            )",
            time(),
            $expiration_time,
            $this->draft_expiration
        );
        
        $expired_drafts = $wpdb->get_col($query);
        
        if (empty($expired_drafts)) {
            $this->logger->info("Aucune draft expirée trouvée");
            return;
        }
        
        $count = count($expired_drafts);
        $this->logger->info("Trouvé {$count} drafts expirées à nettoyer");
        
        foreach ($expired_drafts as $draft_id) {
            $order = wc_get_order($draft_id);
            if ($order) {
                $order->add_order_note(__('Draft expirée - Supprimée automatiquement', 'wc-one-page-checkout'), false);
                $order->update_status('trash', __('Expirée', 'wc-one-page-checkout'));
                $this->logger->info("Draft #{$draft_id} déplacée vers la corbeille");
            }
        }
        
        $this->logger->info("Nettoyage terminé - {$count} drafts traitées");
    }

    /**
     * Obtenir une clé de verrou
     */
    private function get_lock_key($identifier) {
        return 'wc_opc_lock_' . md5($identifier);
    }

    /**
     * Acquérir un verrou
     */
    private function acquire_lock($lock_key, $timeout = 30) {
        if (get_transient($lock_key)) {
            return false;
        }
        
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
     * Vérifier un nonce
     */
    private function verify_nonce($action) {
        if (!isset($_POST['nonce'])) {
            return false;
        }
        
        $nonce = $_POST['nonce'];
        $result = wp_verify_nonce($nonce, $action);
        
        return ($result !== false && $result !== 0);
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