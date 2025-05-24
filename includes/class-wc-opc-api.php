<?php
/**
 * Classe API
 * 
 * Gère les points de terminaison AJAX du plugin
 */
class WC_OPC_API {
    
    /**
     * Constructeur
     */
    public function __construct() {
        // Aucun hook supplémentaire nécessaire car les actions AJAX 
        // sont déjà définies dans les classes spécifiques
    }
    
    /**
     * Vérifier un nonce
     */
    public static function verify_nonce($nonce_name, $action) {
        if (!isset($_REQUEST[$nonce_name]) || !wp_verify_nonce($_REQUEST[$nonce_name], $action)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Envoyer une réponse de succès
     */
    public static function send_success_response($data = array()) {
        wp_send_json_success($data);
    }
    
    /**
     * Envoyer une réponse d'erreur
     */
    public static function send_error_response($code = '', $message = '', $data = array()) {
        $response = array(
            'code' => $code,
            'message' => $message
        );
        
        if (!empty($data)) {
            $response = array_merge($response, $data);
        }
        
        wp_send_json_error($response);
    }
    
    /**
     * Enregistrer un événement
     */
    public static function log_event($event_type, $event_data = array(), $order_id = 0, $product_id = 0) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wc_opc_events';
        
        $session = new WC_OPC_Session();
        $session_id = $session->get_session_id();
        
        $data = array(
            'event_type' => $event_type,
            'event_data' => is_array($event_data) ? json_encode($event_data) : $event_data,
            'session_id' => $session_id,
            'order_id' => $order_id,
            'product_id' => $product_id,
            'user_id' => get_current_user_id(),
            'ip_address' => self::get_client_ip(),
            'created_at' => current_time('mysql')
        );
        
        $wpdb->insert($table_name, $data);
        
        return $wpdb->insert_id;
    }
    
    /**
     * Obtenir l'adresse IP du client
     */
    public static function get_client_ip() {
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
}