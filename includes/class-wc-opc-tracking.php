<?php
/**
 * Classe de gestion du tracking - VERSION FINALE
 */
class WC_OPC_Tracking {
    
    private $logger;
    private $config = array(
        'enable_facebook_api' => true,
        'enable_fbq_fallback' => true,
        'half_price_events' => true,
        'retry_failed_events' => true,
        'max_retries' => 3
    );
    
    private $pending_facebook_events = array();
    private $pending_fbq_events = array();
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
    }
    
    private function load_config() {
        $this->config = array(
            'enable_facebook_api' => get_option('wc_opc_facebook_api_enabled', 'yes') === 'yes',
            'enable_fbq_fallback' => get_option('wc_opc_fbq_fallback_enabled', 'yes') === 'yes',
            'half_price_events' => get_option('wc_opc_half_price_events', 'yes') === 'yes',
            'retry_failed_events' => get_option('wc_opc_retry_failed_events', 'yes') === 'yes',
            'max_retries' => max(1, min(5, (int) get_option('wc_opc_max_retries', 3)))
        );
    }
    
    private function init_hooks() {
        try {
            if (!function_exists('add_action')) {
                return;
            }
            
            // Événements OPC
            add_action('wc_opc_draft_order_created', array($this, 'handle_draft_created'), 10, 2);
            add_action('wc_opc_checkout_success', array($this, 'handle_checkout_success'), 10, 3);
            
            // AJAX
            add_action('wp_ajax_wc_opc_track_event', array($this, 'ajax_track_event'));
            add_action('wp_ajax_nopriv_wc_opc_track_event', array($this, 'ajax_track_event'));
            
            // Footer (front-end seulement)
            if (!is_admin()) {
                add_action('wp_footer', array($this, 'output_facebook_events'), 999);
                add_action('shutdown', array($this, 'force_send_events'), 999);
            }
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Erreur init hooks tracking: " . $e->getMessage());
            }
        }
    }
    
    public function handle_draft_created($order_id, $product_id) {
        try {
            if ($this->logger) {
                $this->logger->info("AddToCart - Commande: {$order_id}, Produit: {$product_id}");
            }
            
            // Éviter les doublons
            $event_key = "addtocart_{$order_id}_{$product_id}";
            if (isset($this->sent_events[$event_key])) {
                return;
            }
            
            if (!$order_id || !$product_id) {
                return;
            }
            
            $order = wc_get_order($order_id);
            $product = wc_get_product($product_id);
            
            if (!$order || !$product) {
                return;
            }
            
            $event_data = $this->prepare_event_data($order, $product, 'AddToCart');
            if (!$event_data) {
                return;
            }
            
            $this->queue_facebook_event('AddToCart', $event_data, $order_id, $product_id);
            $this->queue_fbq_event('AddToCart', $event_data);
            
            $this->sent_events[$event_key] = time();
            
            $this->log_event('addtocart', array(
                'order_id' => $order_id,
                'product_id' => $product_id,
                'event_value' => $event_data['value'],
                'event_id' => $event_data['event_id']
            ), $order_id, $product_id);
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Erreur handle_draft_created: " . $e->getMessage());
            }
        }
    }
    
    public function handle_checkout_success($order_id, $product_id, $total_price = 0) {
        try {
            if ($this->logger) {
                $this->logger->info("Checkout Success - Commande: {$order_id}, Produit: {$product_id}");
            }
            
            $initiate_key = "initiate_{$order_id}_{$product_id}";
            $purchase_key = "purchase_{$order_id}_{$product_id}";
            
            if (isset($this->sent_events[$initiate_key]) && isset($this->sent_events[$purchase_key])) {
                return;
            }
            
            if (!$order_id || !$product_id) {
                return;
            }
            
            $order = wc_get_order($order_id);
            $product = wc_get_product($product_id);
            
            if (!$order || !$product) {
                return;
            }
            
            $event_data = $this->prepare_event_data($order, $product, 'Purchase', $total_price);
            if (!$event_data) {
                return;
            }
            
            // InitiateCheckout
            if (!isset($this->sent_events[$initiate_key])) {
                $initiate_data = $event_data;
                $initiate_data['event_id'] = $this->generate_event_id('initiate', $order_id, $product_id);
                
                $this->queue_facebook_event('InitiateCheckout', $initiate_data, $order_id, $product_id);
                $this->queue_fbq_event('InitiateCheckout', $initiate_data);
                
                $this->sent_events[$initiate_key] = time();
            }
            
            // Purchase
            if (!isset($this->sent_events[$purchase_key])) {
                $purchase_data = $event_data;
                $purchase_data['event_id'] = $this->generate_event_id('purchase', $order_id, $product_id);
                $purchase_data['contents'] = array(
                    array(
                        'id' => 'wc_post_id_' . $product_id,
                        'quantity' => 1
                    )
                );
                
                $this->queue_facebook_event('Purchase', $purchase_data, $order_id, $product_id);
                $this->queue_fbq_event('Purchase', $purchase_data);
                
                $this->sent_events[$purchase_key] = time();
            }
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Erreur handle_checkout_success: " . $e->getMessage());
            }
        }
    }
    
    private function prepare_event_data($order, $product, $event_type, $override_price = 0) {
        try {
            $total_price = 0;
            
            if ($override_price > 0) {
                $total_price = $override_price;
            } else {
                $total_price = $order->get_meta('_wc_opc_total_price');
                
                if (!$total_price || $total_price <= 0) {
                    foreach ($order->get_items() as $item) {
                        $total_price += floatval($item->get_total());
                    }
                }
                
                if (!$total_price || $total_price <= 0) {
                    $total_price = floatval($product->get_price());
                }
            }
            
            if ($total_price <= 0) {
                $total_price = 1;
            }
            
            // Moitié du prix
            $event_value = $this->config['half_price_events'] ? ($total_price / 2) : $total_price;
            
            $event_data = array(
                'value' => $event_value,
                'currency' => get_woocommerce_currency(),
                'content_ids' => array('wc_post_id_' . $product->get_id()),
                'content_type' => 'product',
                'content_name' => $product->get_name(),
                'content_category' => $this->get_product_categories($product),
                'source' => 'woocommerce-opc',
                'version' => '2.0.0',
                'pluginVersion' => '2.0.0',
                'event_id' => $this->generate_event_id(strtolower($event_type), $order->get_id(), $product->get_id())
            );
            
            if (in_array($event_type, array('AddToCart', 'Purchase'))) {
                $event_data['contents'] = array(
                    array(
                        'id' => 'wc_post_id_' . $product->get_id(),
                        'quantity' => 1
                    )
                );
            }
            
            return $event_data;
            
        } catch (Exception $e) {
            return null;
        }
    }
    
    private function queue_facebook_event($event_name, $event_data, $order_id, $product_id) {
        try {
            $facebook_event = array(
                'event_name' => $event_name,
                'event_time' => time(),
                'event_id' => $event_data['event_id'],
                'user_data' => $this->get_user_data(),
                'custom_data' => $event_data,
                'action_source' => 'website'
            );
            
            $this->pending_facebook_events[] = $facebook_event;
            
        } catch (Exception $e) {
            if ($this->logger) {
               $this->logger->error("Erreur queue_facebook_event: " . $e->getMessage());
           }
       }
   }
   
   private function queue_fbq_event($event_name, $event_data) {
       try {
           $fbq_data = $event_data;
           unset($fbq_data['source']);
           unset($fbq_data['version']);
           unset($fbq_data['pluginVersion']);
           unset($fbq_data['event_id']);
           
           $this->pending_fbq_events[] = array(
               'name' => $event_name,
               'data' => $fbq_data
           );
           
       } catch (Exception $e) {
           if ($this->logger) {
               $this->logger->error("Erreur queue_fbq_event: " . $e->getMessage());
           }
       }
   }
   
   private function generate_event_id($event_type, $order_id, $product_id) {
       try {
           $random_part = function_exists('wp_generate_password') ? 
               wp_generate_password(6, false) : 
               substr(md5(uniqid()), 0, 6);
               
           return 'opc_' . $event_type . '_' . $order_id . '_' . $product_id . '_' . time() . '_' . $random_part;
       } catch (Exception $e) {
           return 'opc_' . $event_type . '_' . time() . '_' . rand(1000, 9999);
       }
   }
   
   private function get_user_data() {
       $user_data = array(
           'client_ip_address' => $this->get_client_ip(),
           'client_user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''
       );
       
       if (isset($_COOKIE['_fbp'])) {
           $user_data['fbp'] = $_COOKIE['_fbp'];
       }
       
       if (isset($_COOKIE['_fbc'])) {
           $user_data['fbc'] = $_COOKIE['_fbc'];
       }
       
       return $user_data;
   }
   
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
   
   public function output_facebook_events() {
       try {
           if (empty($this->pending_facebook_events) && empty($this->pending_fbq_events)) {
               return;
           }
           
           // Envoyer via API Facebook
           if (!empty($this->pending_facebook_events) && $this->config['enable_facebook_api']) {
               $this->send_facebook_api_events();
           }
           
           // Envoyer via fbq JavaScript
           if (!empty($this->pending_fbq_events) && $this->config['enable_fbq_fallback']) {
               $this->output_fbq_events();
           }
           
           // Nettoyer
           $this->pending_facebook_events = array();
           $this->pending_fbq_events = array();
           
       } catch (Exception $e) {
           if ($this->logger) {
               $this->logger->error("Erreur output_facebook_events: " . $e->getMessage());
           }
       }
   }
   
   public function force_send_events() {
       if (!empty($this->pending_facebook_events) || !empty($this->pending_fbq_events)) {
           $this->output_facebook_events();
       }
   }
   
   private function send_facebook_api_events() {
       try {
           if (!class_exists('WC_Facebookcommerce_Integration')) {
               return false;
           }
           
           $facebook_integration = WC_Facebookcommerce_Integration::get_instance();
           
           if (!$facebook_integration || !method_exists($facebook_integration, 'get_facebook_pixel_id')) {
               return false;
           }
           
           $pixel_id = $facebook_integration->get_facebook_pixel_id();
           
           if (!$pixel_id) {
               return false;
           }
           
           return $this->send_to_facebook_api($pixel_id, $this->pending_facebook_events);
           
       } catch (Exception $e) {
           if ($this->logger) {
               $this->logger->error("Erreur send_facebook_api_events: " . $e->getMessage());
           }
           return false;
       }
   }
   
   private function send_to_facebook_api($pixel_id, $events) {
       try {
           $access_token = $this->get_facebook_access_token();
           
           if (!$access_token) {
               return false;
           }
           
           $url = "https://graph.facebook.com/v18.0/{$pixel_id}/events";
           
           $data = array(
               'data' => $events,
               'access_token' => $access_token,
               'partner_agent' => 'woocommerce-opc-2.0.0'
           );
           
           $args = array(
               'body' => json_encode($data),
               'headers' => array(
                   'Content-Type' => 'application/json',
               ),
               'timeout' => 30,
               'method' => 'POST'
           );
           
           $response = wp_remote_post($url, $args);
           
           if (is_wp_error($response)) {
               return false;
           }
           
           $response_code = wp_remote_retrieve_response_code($response);
           $body = wp_remote_retrieve_body($response);
           
           if ($response_code !== 200) {
               return false;
           }
           
           $decoded = json_decode($body, true);
           
           if (isset($decoded['error'])) {
               return false;
           }
           
           return true;
           
       } catch (Exception $e) {
           return false;
       }
   }
   
   private function get_facebook_access_token() {
       try {
           $token_sources = array(
               'facebook_for_woocommerce_access_token',
               'wc_facebook_access_token',
               'facebook_access_token'
           );
           
           foreach ($token_sources as $option_name) {
               $token = get_option($option_name);
               if (!empty($token)) {
                   return $token;
               }
           }
           
           if (class_exists('WC_Facebookcommerce_Integration')) {
               $facebook_integration = WC_Facebookcommerce_Integration::get_instance();
               
               if ($facebook_integration && method_exists($facebook_integration, 'get_access_token')) {
                   $token = $facebook_integration->get_access_token();
                   if (!empty($token)) {
                       return $token;
                   }
               }
           }
           
           return false;
           
       } catch (Exception $e) {
           return false;
       }
   }
   
   private function output_fbq_events() {
       try {
           if (empty($this->pending_fbq_events)) {
               return;
           }
           
           echo '<script type="text/javascript">';
           echo '/* One Page Checkout - Facebook Events */';
           
           foreach ($this->pending_fbq_events as $event) {
               if (isset($event['name']) && isset($event['data'])) {
                   $event_data_json = json_encode($event['data']);
                   if ($event_data_json !== false) {
                       echo "if (typeof fbq !== 'undefined') { fbq('track', '" . esc_js($event['name']) . "', {$event_data_json}); }";
                   }
               }
           }
           
           echo '</script>';
           
       } catch (Exception $e) {
           if ($this->logger) {
               $this->logger->error("Erreur output_fbq_events: " . $e->getMessage());
           }
       }
   }
   
   public function ajax_track_event() {
       try {
           if (!class_exists('WC_OPC_API') || !WC_OPC_API::verify_nonce('nonce', 'wc-opc-nonce')) {
               wp_send_json_error(array('message' => 'Erreur de sécurité'));
               return;
           }
           
           $event_type = isset($_POST['event_type']) ? sanitize_text_field($_POST['event_type']) : '';
           $event_data = isset($_POST['event_data']) ? $_POST['event_data'] : '';
           $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
           $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
           
           if (empty($event_type)) {
               wp_send_json_error(array('message' => 'Type événement manquant'));
               return;
           }
           
           if (is_string($event_data) && !empty($event_data)) {
               $decoded_data = json_decode(stripslashes($event_data), true);
               if ($decoded_data !== null) {
                   $event_data = $decoded_data;
               }
           }
           
           $event_id = $this->log_event($event_type, $event_data, $order_id, $product_id);
           
           wp_send_json_success(array(
               'event_id' => $event_id,
               'message' => 'Événement enregistré'
           ));
           
       } catch (Exception $e) {
           wp_send_json_error(array('message' => 'Erreur: ' . $e->getMessage()));
       }
   }
   
   private function log_event($event_type, $event_data = array(), $order_id = 0, $product_id = 0) {
       try {
           if (class_exists('WC_OPC_API') && method_exists('WC_OPC_API', 'log_event')) {
               return WC_OPC_API::log_event($event_type, $event_data, $order_id, $product_id);
           }
           
           return 0;
       } catch (Exception $e) {
           return 0;
       }
   }
   
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