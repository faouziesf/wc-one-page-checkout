<?php
/**
 * Classe de gestion du cache
 * 
 * Fournit une couche d'abstraction pour le stockage et la récupération
 * de données temporaires, avec une redondance pour la résilience.
 */
class WC_OPC_Cache {
    
    /**
     * Préfixe pour les clés de cache
     */
    private $prefix = 'wc_opc_';
    
    /**
     * Constructeur
     */
    public function __construct() {
        // Rien à faire pour l'instant
    }
    
    /**
     * Préfixer une clé de cache
     */
    private function prefix_key($key) {
        return $this->prefix . $key;
    }
    
    /**
     * Définir une valeur dans le cache
     */
    public function set($key, $value, $expiration = 0) {
        $prefixed_key = $this->prefix_key($key);
        
        // Définir dans le cache WordPress (transient)
        $transient_result = set_transient($prefixed_key, $value, $expiration);
        
        // Sauvegarder aussi dans le stockage local JavaScript
        $this->enqueue_local_storage_script($key, $value, $expiration);
        
        return $transient_result;
    }
    
    /**
     * Obtenir une valeur depuis le cache
     */
    public function get($key) {
        $prefixed_key = $this->prefix_key($key);
        
        // Essayer d'abord depuis le cache WordPress
        $value = get_transient($prefixed_key);
        
        if (false !== $value) {
            return $value;
        }
        
        // Si pas trouvé, essayer de récupérer depuis le stockage local via AJAX
        // Note: cela ne fonctionne que si la requête est une requête AJAX
        if (wp_doing_ajax() && isset($_POST['local_storage_data'])) {
            $local_storage_data = json_decode(stripslashes($_POST['local_storage_data']), true);
            
            if (isset($local_storage_data[$key])) {
                $stored_data = $local_storage_data[$key];
                
                // Vérifier si les données ne sont pas expirées
                if (!isset($stored_data['expiration']) || $stored_data['expiration'] > time()) {
                    $value = $stored_data['value'];
                    
                    // Restaurer dans le cache WordPress
                    $expiration = isset($stored_data['expiration']) ? $stored_data['expiration'] - time() : 0;
                    set_transient($prefixed_key, $value, $expiration);
                    
                    return $value;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Supprimer une valeur du cache
     */
    public function delete($key) {
        $prefixed_key = $this->prefix_key($key);
        
        // Supprimer du cache WordPress
        $result = delete_transient($prefixed_key);
        
        // Envoyer un script pour supprimer du stockage local
        $this->enqueue_local_storage_removal_script($key);
        
        return $result;
    }
    
    /**
     * Enqueuer un script pour mettre à jour le stockage local
     */
    private function enqueue_local_storage_script($key, $value, $expiration) {
        // Ne fonctionner que sur le front-end
        if (is_admin() && !wp_doing_ajax()) {
            return;
        }
        
        // Calculer la date d'expiration en timestamp
        $expiration_time = ($expiration > 0) ? time() + $expiration : 0;
        
        // Préparer les données à stocker
        $data = [
            'value' => $value,
            'expiration' => $expiration_time
        ];
        
        // Encoder en JSON pour JavaScript
        $json_data = json_encode($data);
        
        // Script pour mettre à jour le stockage local
        $script = "
            if (typeof(Storage) !== 'undefined') {
                try {
                    localStorage.setItem('$this->prefix$key', '$json_data');
                } catch (e) {
                    console.error('Erreur lors du stockage local:', e);
                }
            }
        ";
        
        // Ajouter à la file d'attente des scripts
        if (!wp_doing_ajax()) {
            // Ajouter le script dans le pied de page
            wp_add_inline_script('wc-opc-utils', $script);
        } else {
            // Pour les requêtes AJAX, ajouter le script à la réponse
            add_action('wp_ajax_nopriv_wc_opc_create_draft_order', function() use ($script) {
                echo '<script>' . $script . '</script>';
            }, 999);
            
            add_action('wp_ajax_wc_opc_create_draft_order', function() use ($script) {
                echo '<script>' . $script . '</script>';
            }, 999);
        }
    }
    
    /**
     * Enqueuer un script pour supprimer du stockage local
     */
    private function enqueue_local_storage_removal_script($key) {
        // Ne fonctionner que sur le front-end
        if (is_admin() && !wp_doing_ajax()) {
            return;
        }
        
        // Script pour supprimer du stockage local
        $script = "
            if (typeof(Storage) !== 'undefined') {
                try {
                    localStorage.removeItem('$this->prefix$key');
                } catch (e) {
                    console.error('Erreur lors de la suppression du stockage local:', e);
                }
            }
        ";
        
        // Ajouter à la file d'attente des scripts
        if (!wp_doing_ajax()) {
            // Ajouter le script dans le pied de page
            wp_add_inline_script('wc-opc-utils', $script);
        }
    }
}