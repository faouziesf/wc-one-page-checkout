<?php
/**
 * Classe de gestion de session
 * 
 * Gère les sessions utilisateur de manière cohérente
 * avec compatibilité multi-périphériques
 */
class WC_OPC_Session {
    
    /**
     * Nom du cookie de session
     */
    private $cookie_name = 'wc_opc_session';
    
    /**
     * Durée de vie du cookie (en secondes)
     * 30 jours par défaut
     */
    private $cookie_lifetime = 2592000;
    
    /**
     * ID de session courante
     */
    private $session_id = null;
    
    /**
     * Constructeur
     */
    public function __construct() {
        // Initialiser la session
        $this->init_session();
    }
    
    /**
     * Initialiser la session
     */
    private function init_session() {
        // Essayer d'abord de récupérer l'ID de session depuis le cookie
        if (isset($_COOKIE[$this->cookie_name])) {
            $this->session_id = sanitize_text_field($_COOKIE[$this->cookie_name]);
            return;
        }
        
        // Si pas de cookie, essayer de récupérer l'ID depuis la session PHP
        if (!isset($_SESSION) && !headers_sent()) {
            session_start();
        }
        
        if (isset($_SESSION[$this->cookie_name])) {
            $this->session_id = $_SESSION[$this->cookie_name];
            
            // Sauvegarder dans un cookie pour persistance
            $this->set_session_cookie($this->session_id);
            
            return;
        }
        
        // Sinon, créer un nouvel ID de session
        $this->session_id = $this->generate_session_id();
        
        // Sauvegarder dans la session PHP
        if (isset($_SESSION)) {
            $_SESSION[$this->cookie_name] = $this->session_id;
        }
        
        // Sauvegarder dans un cookie
        $this->set_session_cookie($this->session_id);
    }
    
    /**
     * Générer un ID de session unique
     */
     private function generate_session_id() {
       // Générer un ID unique basé sur diverses données
       $data = [
           'ip' => $this->get_client_ip(),
           'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
           'time' => microtime(true),
           'random' => wp_generate_password(32, false)
       ];
       
       return md5(json_encode($data));
   }
   
   /**
    * Définir le cookie de session
    */
   private function set_session_cookie($session_id) {
       if (headers_sent()) {
           return false;
       }
       
       $secure = is_ssl();
       $http_only = true;
       
       setcookie(
           $this->cookie_name,
           $session_id,
           time() + $this->cookie_lifetime,
           COOKIEPATH,
           COOKIE_DOMAIN,
           $secure,
           $http_only
       );
       
       // Également définir dans $_COOKIE pour la session courante
       $_COOKIE[$this->cookie_name] = $session_id;
       
       return true;
   }
   
   /**
    * Obtenir l'ID de session courant
    */
   public function get_session_id() {
       return $this->session_id;
   }
   
   /**
    * Régénérer l'ID de session
    */
   public function regenerate_session_id() {
       $this->session_id = $this->generate_session_id();
       
       // Mettre à jour dans la session PHP
       if (isset($_SESSION)) {
           $_SESSION[$this->cookie_name] = $this->session_id;
       }
       
       // Mettre à jour le cookie
       $this->set_session_cookie($this->session_id);
       
       return $this->session_id;
   }
   
   /**
    * Détruire la session
    */
   public function destroy_session() {
       // Supprimer de la session PHP
       if (isset($_SESSION) && isset($_SESSION[$this->cookie_name])) {
           unset($_SESSION[$this->cookie_name]);
       }
       
       // Supprimer le cookie
       if (isset($_COOKIE[$this->cookie_name])) {
           setcookie(
               $this->cookie_name,
               '',
               time() - 3600,
               COOKIEPATH,
               COOKIE_DOMAIN
           );
           
           unset($_COOKIE[$this->cookie_name]);
       }
       
       // Réinitialiser l'ID de session
       $this->session_id = null;
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
}