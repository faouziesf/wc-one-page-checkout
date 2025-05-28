<?php
/**
 * Classe de gestion du tracking Facebook CORRIGÉE - Version finale
 */
class WC_OPC_Tracking {
    
    private $logger;
    private $config = array();
    private $pending_events = array();
    private $sent_events = array();
    
    public function __construct() {
        if (!class_exists('WooCommerce')) {
            return;
        }
        
        if (class_exists('WC_OPC_Logger')) {
            try {
                $this->logger = WC_OPC_Logger::get_instance();
            } catch (Exception $e) {
                // Continuer sans logger
            }
        }
        
        if (get_option('wc_opc_enable_tracking', 'yes') !== 'yes') {
            return;
        }
        
        $this->load_config();
        $this->init_hooks();
        $this->restore_sent_events();
    }
    
    /**
     * Charger la configuration de manière ultra-sécurisée
     */
    private function load_config() {
        $this->config = array(
            'half_price_events' => true, // TOUJOURS 50% comme demandé
            'enable_server_api' => true,
            'enable_pixel_fallback' => true,
            'retry_failed_events' => true,
            'max_retries' => 3,
            'pixel_id' => $this->get_facebook_pixel_id_ultra_safe(),
            'access_token' => $this->get_facebook_access_token_ultra_safe()
        );
        
        if ($this->logger) {
            $this->logger->info("Tracking configuré - Pixel ID: " . ($this->config['pixel_id'] ? 'Oui' : 'Non') . ", Access Token: " . ($this->config['access_token'] ? 'Oui' : 'Non'));
        }
    }
    
    /**
     * Initialiser les hooks
     */
    private function init_hooks() {
        try {
            // Événements OPC
            add_action('wc_opc_draft_order_created', array($this, 'handle_draft_created'), 10, 2);
            add_action('wc_opc_checkout_success', array($this, 'handle_checkout_success'), 10, 3);
            
            // Footer pour envoyer les événements
            if (!is_admin()) {
                add_action('wp_footer', array($this, 'output_pending_events'), 999);
                add_action('shutdown', array($this, 'force_send_events'), 1);
            }
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Erreur init hooks tracking: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Gérer la création d'une draft (AddToCart event)
     */
    public function handle_draft_created($draft_order_id, $product_id) {
        try {
            if ($this->logger) {
                $this->logger->info("Tracking AddToCart - Draft: {$draft_order_id}, Produit: {$product_id}");
            }
            
            // Éviter les doublons
            $event_key = "addtocart_{$draft_order_id}_{$product_id}";
            if (isset($this->sent_events[$event_key])) {
                if ($this->logger) {
                    $this->logger->info("AddToCart déjà envoyé pour: {$event_key}");
                }
                return;
            }
            
            if (!$draft_order_id || !$product_id) {
                return;
            }
            
            $order = wc_get_order($draft_order_id);
            $product = wc_get_product($product_id);
            
            if (!$order || !$product) {
                return;
            }
            
            // Préparer les données d'événement
            $event_data = $this->prepare_event_data($order, $product, 'AddToCart');
            if (!$event_data) {
                return;
            }
            
            // Ajouter à la file d'attente
            $this->queue_event('AddToCart', $event_data, $draft_order_id, $product_id);
            
            // Marquer comme envoyé
            $this->sent_events[$event_key] = time();
            $this->save_sent_events();
            
            if ($this->logger) {
                $this->logger->info("AddToCart mis en file - Valeur: {$event_data['value']} (50% de " . ($event_data['value'] * 2) . ")");
            }
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Erreur handle_draft_created: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Gérer le succès du checkout (InitiateCheckout + Purchase)
     */
    public function handle_checkout_success($order_id, $product_id, $total_price = 0) {
        try {
            if ($this->logger) {
                $this->logger->info("Tracking Checkout Success - Commande: {$order_id}, Produit: {$product_id}, Total: {$total_price}");
            }
            
            $initiate_key = "initiate_{$order_id}_{$product_id}";
            $purchase_key = "purchase_{$order_id}_{$product_id}";
            
            if (!$order_id || !$product_id) {
                return;
            }
            
            $order = wc_get_order($order_id);
            $product = wc_get_product($product_id);
            
            if (!$order || !$product) {
                return;
            }
            
            // Utiliser le total fourni ou calculer depuis la commande
            if (!$total_price) {
                $total_price = $order->get_total();
            }
            
            // Préparer les données d'événement
            $event_data = $this->prepare_event_data($order, $product, 'Purchase', $total_price);
            if (!$event_data) {
                return;
            }
            
            // InitiateCheckout
            if (!isset($this->sent_events[$initiate_key])) {
                $initiate_data = $event_data;
                $initiate_data['event_id'] = $this->generate_event_id('initiate', $order_id, $product_id);
                
                $this->queue_event('InitiateCheckout', $initiate_data, $order_id, $product_id);
                $this->sent_events[$initiate_key] = time();
                
                if ($this->logger) {
                    $this->logger->info("InitiateCheckout mis en file - Valeur: {$initiate_data['value']}");
                }
            }
            
            // Purchase (avec un léger délai)
            if (!isset($this->sent_events[$purchase_key])) {
                $purchase_data = $event_data;
                $purchase_data['event_id'] = $this->generate_event_id('purchase', $order_id, $product_id);
                $purchase_data['contents'] = array(
                    array(
                        'id' => 'wc_post_id_' . $product_id,
                        'quantity' => 1
                    )
                );
                
                // Ajouter un délai de 1 seconde pour Purchase
                $purchase_data['_delay'] = 1000; // millisecondes
                
                $this->queue_event('Purchase', $purchase_data, $order_id, $product_id);
                $this->sent_events[$purchase_key] = time();
                
                if ($this->logger) {
                    $this->logger->info("Purchase mis en file - Valeur: {$purchase_data['value']}");
                }
            }
            
            $this->save_sent_events();
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Erreur handle_checkout_success: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Préparer les données d'événement avec 50% du prix
     */
    private function prepare_event_data($order, $product, $event_type, $override_price = 0) {
        try {
            $total_price = 0;
            
            // Calculer le prix total
            if ($override_price > 0) {
                $total_price = floatval($override_price);
            } else {
                // Essayer de récupérer depuis la commande
                $total_price = $order->get_total();
                
                // Si pas de total, calculer depuis les items
                if (!$total_price || $total_price <= 0) {
                    foreach ($order->get_items() as $item) {
                        $total_price += floatval($item->get_total());
                    }
                }
                
                // Fallback sur le prix du produit
                if (!$total_price || $total_price <= 0) {
                    $total_price = floatval($product->get_price());
                    
                    // Si c'est une draft, vérifier s'il y a un bundle
                    if ($order->get_meta('_wc_opc_draft_order') === 'yes') {
                        foreach ($order->get_items() as $item) {
                            $bundle_price = $item->get_meta('_bundle_price');
                            if ($bundle_price) {
                                $total_price = floatval($bundle_price);
                                break;
                            }
                        }
                    }
                }
            }
            
            if ($total_price <= 0) {
                $total_price = 1; // Prix minimum pour éviter les erreurs
            }
            
            // APPLIQUER 50% DU PRIX COMME DEMANDÉ
            $event_value = $this->config['half_price_events'] ? ($total_price / 2) : $total_price;
            
            // Données de base de l'événement
            $event_data = array(
                'value' => round($event_value, 2),
                'currency' => get_woocommerce_currency(),
                'content_ids' => array('wc_post_id_' . $product->get_id()),
                'content_type' => 'product',
                'content_name' => $product->get_name(),
                'content_category' => $this->get_product_categories($product),
                'source' => 'woocommerce-opc',
                'event_source_url' => home_url($_SERVER['REQUEST_URI']),
                'action_source' => 'website',
                'event_id' => $this->generate_event_id(strtolower($event_type), $order->get_id(), $product->get_id())
            );
            
            // Ajouter contents pour AddToCart et Purchase
            if (in_array($event_type, array('AddToCart', 'Purchase'))) {
                $event_data['contents'] = array(
                    array(
                        'id' => 'wc_post_id_' . $product->get_id(),
                        'quantity' => 1,
                        'item_price' => $event_value
                    )
                );
            }
            
            return $event_data;
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Erreur prepare_event_data: " . $e->getMessage());
            }
            return null;
        }
    }
    
    /**
     * Ajouter un événement à la file d'attente
     */
    private function queue_event($event_name, $event_data, $order_id, $product_id) {
        try {
            $event = array(
                'event_name' => $event_name,
                'event_data' => $event_data,
                'order_id' => $order_id,
                'product_id' => $product_id,
                'timestamp' => time(),
                'attempts' => 0
            );
            
            $this->pending_events[] = $event;
            
            if ($this->logger) {
                $this->logger->info("Événement {$event_name} ajouté à la file - Valeur: {$event_data['value']}");
            }
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Erreur queue_event: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Envoyer tous les événements en attente
     */
    public function output_pending_events() {
        try {
            if (empty($this->pending_events)) {
                return;
            }
            
            if ($this->logger) {
                $this->logger->info("Envoi de " . count($this->pending_events) . " événements en attente");
            }
            
            echo "\n<!-- One Page Checkout - Facebook Events -->\n";
            echo "<script type='text/javascript'>\n";
            echo "document.addEventListener('DOMContentLoaded', function() {\n";
            
            foreach ($this->pending_events as $event) {
                $this->output_single_event($event);
            }
            
            echo "});\n";
            echo "</script>\n";
            echo "<!-- End OPC Facebook Events -->\n\n";
            
            // Vider la file après envoi
            $this->pending_events = array();
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Erreur output_pending_events: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Envoyer un événement unique
     */
    private function output_single_event($event) {
        try {
            $event_name = $event['event_name'];
            $event_data = $event['event_data'];
            $delay = isset($event_data['_delay']) ? intval($event_data['_delay']) : 0;
            
            // Nettoyer les données pour le JavaScript
            $clean_data = $event_data;
            unset($clean_data['source']);
            unset($clean_data['action_source']);
            unset($clean_data['event_source_url']);
            unset($clean_data['event_id']);
            unset($clean_data['_delay']);
            
            $json_data = json_encode($clean_data, JSON_UNESCAPED_UNICODE);
            
            if ($delay > 0) {
                echo "  setTimeout(function() {\n";
            }
            
            // Envoyer via fbq (Pixel JavaScript)
            echo "    if (typeof fbq !== 'undefined') {\n";
            echo "      fbq('track', '{$event_name}', {$json_data});\n";
            echo "      console.log('OPC: {$event_name} envoyé via fbq - Valeur: {$event_data['value']}');\n";
            echo "    }\n";
            
            // Envoyer via l'API Server si configurée
            if ($this->config['pixel_id'] && $this->config['access_token']) {
                $this->send_server_event($event_name, $event_data);
            }
            
            if ($delay > 0) {
                echo "  }, {$delay});\n";
            }
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Erreur output_single_event: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Envoyer un événement via l'API Server Facebook
     */
    private function send_server_event($event_name, $event_data) {
        try {
            if (!$this->config['pixel_id'] || !$this->config['access_token']) {
                return false;
            }
            
            // Préparer les données pour l'API Server
            $server_event = array(
                'event_name' => $event_name,
                'event_time' => time(),
                'event_id' => $event_data['event_id'],
                'event_source_url' => $event_data['event_source_url'],
                'action_source' => 'website',
                'user_data' => $this->get_user_data(),
                'custom_data' => array(
                    'value' => $event_data['value'],
                    'currency' => $event_data['currency'],
                    'content_ids' => $event_data['content_ids'],
                    'content_type' => $event_data['content_type'],
                    'content_name' => $event_data['content_name'],
                    'content_category' => $event_data['content_category']
                )
            );
            
            // Ajouter contents si présent
            if (isset($event_data['contents'])) {
                $server_event['custom_data']['contents'] = $event_data['contents'];
            }
            
            // Envoyer la requête de manière asynchrone
            wp_remote_post('https://graph.facebook.com/v18.0/' . $this->config['pixel_id'] . '/events', array(
                'timeout' => 5,
                'blocking' => false, // Non-bloquant
                'headers' => array(
                    'Content-Type' => 'application/json',
                ),
                'body' => json_encode(array(
                    'data' => array($server_event),
                    'access_token' => $this->config['access_token'],
                    'partner_agent' => 'woocommerce-opc-2.0.1'
                ))
            ));
            
            if ($this->logger) {
                $this->logger->info("Événement {$event_name} envoyé via API Server - Valeur: {$event_data['value']}");
            }
            
            return true;
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Erreur send_server_event: " . $e->getMessage());
            }
            return false;
        }
    }
    
    /**
     * Forcer l'envoi des événements (fallback)
     */
    public function force_send_events() {
        if (!empty($this->pending_events)) {
            $this->output_pending_events();
        }
    }
    
    /**
     * Obtenir les données utilisateur pour l'API Server
     */
    private function get_user_data() {
        $user_data = array(
            'client_ip_address' => $this->get_client_ip(),
            'client_user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''
        );
        
        // Ajouter les cookies Facebook si disponibles
        if (isset($_COOKIE['_fbp'])) {
            $user_data['fbp'] = $_COOKIE['_fbp'];
        }
        
        if (isset($_COOKIE['_fbc'])) {
            $user_data['fbc'] = $_COOKIE['_fbc'];
        }
        
        return $user_data;
    }
    
    /**
     * Obtenir les catégories du produit
     */
    private function get_product_categories($product) {
        try {
            $categories = wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'names'));
            
            if (empty($categories) || is_wp_error($categories)) {
                return 'Tous les produits';
            }
            
            return implode(', ', $categories);
        } catch (Exception $e) {
            return 'Tous les produits';
        }
    }
    
    /**
     * Générer un ID d'événement unique
     */
    private function generate_event_id($event_type, $order_id, $product_id) {
        try {
            $session = new WC_OPC_Session();
            $session_id = $session->get_session_id();
            
            return 'opc_' . $event_type . '_' . $order_id . '_' . $product_id . '_' . substr($session_id, 0, 8) . '_' . time();
        } catch (Exception $e) {
            return 'opc_' . $event_type . '_' . $order_id . '_' . $product_id . '_' . time() . '_' . rand(1000, 9999);
        }
    }
    
    /**
     * Obtenir le Pixel ID Facebook ultra-sécurisé (JAMAIS d'instanciation directe)
     */
    private function get_facebook_pixel_id_ultra_safe() {
        try {
            // MÉTHODE 1: Seulement via les options WordPress (le plus sûr)
            $pixel_options = array(
                'facebook_for_woocommerce',
                'wc_facebook_options',
                'facebook_for_woocommerce_pixel_id',
                'wc_facebook_pixel_id',
                'facebook_pixel_id'
            );
            
            foreach ($pixel_options as $option_name) {
                $option_value = get_option($option_name, array());
                
                if (!empty($option_value)) {
                    // Si c'est un array, chercher le pixel ID dedans
                    if (is_array($option_value)) {
                        // Différentes clés possibles
                        $possible_keys = array('pixel_id', 'facebook_pixel_id', 'fb_pixel_id', 'id');
                        
                        foreach ($possible_keys as $key) {
                            if (isset($option_value[$key]) && !empty($option_value[$key])) {
                                $pixel_id = $option_value[$key];
                                // Vérifier que ça ressemble à un pixel ID (15-16 chiffres)
                                if (is_string($pixel_id) && preg_match('/^\d{15,16}$/', $pixel_id)) {
                                    return $pixel_id;
                                }
                            }
                        }
                    } else {
                        // Si c'est une string et ça ressemble à un pixel ID
                        if (is_string($option_value) && preg_match('/^\d{15,16}$/', $option_value)) {
                            return $option_value;
                        }
                    }
                }
            }
            
            // MÉTHODE 2: Essayer via les hooks WordPress (pas d'instanciation)
            if (function_exists('facebook_for_woocommerce')) {
                try {
                    $fb_settings = facebook_for_woocommerce()->get_integration();
                    if ($fb_settings && method_exists($fb_settings, 'get_facebook_pixel_id')) {
                        $pixel_id = $fb_settings->get_facebook_pixel_id();
                        if (!empty($pixel_id)) {
                            return $pixel_id;
                        }
                    }
                } catch (Exception $e) {
                    // Ignorer et continuer
                }
            }
            
            // MÉTHODE 3: Recherche dans toutes les options pour un pattern de pixel ID
            global $wpdb;
            $pixel_pattern_query = $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} 
                WHERE (option_name LIKE %s OR option_name LIKE %s OR option_value REGEXP %s) 
                AND option_value REGEXP %s 
                LIMIT 1",
                '%facebook%',
                '%pixel%',
                '[0-9]{15,16}',
                '^[0-9]{15,16}$'
            );
            
            $potential_pixel = $wpdb->get_var($pixel_pattern_query);
            if ($potential_pixel && preg_match('/^\d{15,16}$/', $potential_pixel)) {
                return $potential_pixel;
            }
            
            return false;
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Erreur get_facebook_pixel_id_ultra_safe: " . $e->getMessage());
            }
            return false;
        }
    }
    
    /**
     * Obtenir l'Access Token Facebook ultra-sécurisé (JAMAIS d'instanciation directe)
     */
    private function get_facebook_access_token_ultra_safe() {
        try {
            // MÉTHODE 1: Seulement via les options WordPress
            $token_options = array(
                'facebook_for_woocommerce',
                'wc_facebook_options',
                'facebook_for_woocommerce_access_token',
                'wc_facebook_access_token',
                'facebook_access_token'
            );
            
            foreach ($token_options as $option_name) {
                $option_value = get_option($option_name, array());
                
                if (!empty($option_value)) {
                    // Si c'est un array, chercher le token dedans
                    if (is_array($option_value)) {
                        $possible_keys = array('access_token', 'facebook_access_token', 'fb_access_token', 'token');
                        
                        foreach ($possible_keys as $key) {
                            if (isset($option_value[$key]) && !empty($option_value[$key])) {
                                $token = $option_value[$key];
                                // Vérifier que ça ressemble à un token Facebook
                                if (is_string($token) && strlen($token) > 50) {
                                    return $token;
                                }
                            }
                        }
                    } else {
                        // Si c'est une string et ça ressemble à un token
                        if (is_string($option_value) && strlen($option_value) > 50) {
                            return $option_value;
                        }
                    }
                }
            }
            
            // MÉTHODE 2: Via les fonctions globales (pas d'instanciation)
            if (function_exists('facebook_for_woocommerce')) {
                try {
                    $fb_settings = facebook_for_woocommerce()->get_integration();
                    if ($fb_settings && method_exists($fb_settings, 'get_access_token')) {
                        $token = $fb_settings->get_access_token();
                        if (!empty($token)) {
                            return $token;
                        }
                    }
                } catch (Exception $e) {
                    // Ignorer et continuer
                }
            }
            
            return false;
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Erreur get_facebook_access_token_ultra_safe: " . $e->getMessage());
            }
            return false;
        }
    }
    
    /**
     * Sauvegarder les événements envoyés
     */
    private function save_sent_events() {
        if (sizeof($this->sent_events) > 100) {
            // Garder seulement les 50 plus récents
            $this->sent_events = array_slice($this->sent_events, -50, null, true);
        }
        
        try {
            set_transient('wc_opc_sent_events', $this->sent_events, DAY_IN_SECONDS);
        } catch (Exception $e) {
            // Ignorer les erreurs de cache
        }
    }
    
    /**
     * Restaurer les événements envoyés
     */
    private function restore_sent_events() {
        try {
            $saved_events = get_transient('wc_opc_sent_events');
            if (is_array($saved_events)) {
                $this->sent_events = $saved_events;
            }
        } catch (Exception $e) {
            $this->sent_events = array();
        }
    }
    
    /**
     * Obtenir l'IP du client
     */
    private function get_client_ip() {
        try {
            if (isset($_SERVER['HTTP_CF_CONNECTING_IP']) && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
                return $_SERVER['HTTP_CF_CONNECTING_IP'];
            }
            
            if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                $ip = trim($ips[0]);
                if (!empty($ip)) {
                    return $ip;
                }
            }
            
            if (isset($_SERVER['REMOTE_ADDR']) && !empty($_SERVER['REMOTE_ADDR'])) {
                return $_SERVER['REMOTE_ADDR'];
            }
            
            return '';
        } catch (Exception $e) {
            return '';
        }
    }
}