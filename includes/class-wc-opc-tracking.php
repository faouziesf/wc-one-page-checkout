<?php
/**
 * Classe de gestion du tracking
 * 
 * Gère le tracking des événements (Facebook, etc.)
 */
class WC_OPC_Tracking {
    
    /**
     * Constructeur
     */
    public function __construct() {
        // Vérifier si le tracking est activé
        if (get_option('wc_opc_enable_tracking') !== 'yes') {
            return;
        }
        
        // Ajouter les hooks pour les événements
        add_action('wc_opc_draft_order_created', array($this, 'handle_draft_created'), 10, 2);
        add_action('wc_opc_checkout_success', array($this, 'handle_checkout_success'), 10, 2);
        
        // Écouter les actions AJAX pour le tracking
        add_action('wp_ajax_wc_opc_track_event', array($this, 'ajax_track_event'));
        add_action('wp_ajax_nopriv_wc_opc_track_event', array($this, 'ajax_track_event'));
    }
    
    /**
     * Gérer l'événement de création de commande draft
     */
    public function handle_draft_created($order_id, $product_id) {
        // Enregistrer l'événement
        WC_OPC_API::log_event('draft_created', array(
            'order_id' => $order_id,
            'product_id' => $product_id
        ), $order_id, $product_id);
    }
    
    /**
     * Gérer l'événement de succès du checkout
     */
    public function handle_checkout_success($order_id, $product_id) {
        // Enregistrer l'événement
        WC_OPC_API::log_event('checkout_success', array(
            'order_id' => $order_id,
            'product_id' => $product_id
        ), $order_id, $product_id);
    }
    
    /**
     * Gérer les événements de tracking via AJAX
     */
    public function ajax_track_event() {
        // Vérifier le nonce
        if (!WC_OPC_API::verify_nonce('nonce', 'wc-opc-nonce')) {
            WC_OPC_API::send_error_response('security_error', __('Erreur de sécurité.', 'wc-one-page-checkout'));
            return;
        }
        
        // Récupérer les données
        $event_type = isset($_POST['event_type']) ? sanitize_text_field($_POST['event_type']) : '';
        $event_data = isset($_POST['event_data']) ? $_POST['event_data'] : '';
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        
        if (empty($event_type)) {
            WC_OPC_API::send_error_response('missing_data', __('Type d\'événement manquant.', 'wc-one-page-checkout'));
            return;
        }
        
        // Décoder les données si nécessaire
        if (is_string($event_data) && !empty($event_data)) {
            $event_data = json_decode(stripslashes($event_data), true);
        }
        
        // Enregistrer l'événement
        $event_id = WC_OPC_API::log_event($event_type, $event_data, $order_id, $product_id);
        
        // Envoyer la réponse
        WC_OPC_API::send_success_response(array(
            'event_id' => $event_id,
            'message' => __('Événement enregistré avec succès.', 'wc-one-page-checkout')
        ));
    }
}