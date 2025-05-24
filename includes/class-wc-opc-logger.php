<?php
/**
 * Classe de journalisation
 * 
 * Gère la journalisation des événements pour faciliter le débogage
 */
class WC_OPC_Logger {
    /**
     * Instance singleton
     */
    private static $instance = null;
    
    /**
     * Activation du mode debug
     */
    private $debug_mode = false;
    
    /**
     * Nom du fichier journal
     */
    private $log_file = 'wc-one-page-checkout.log';
    
    /**
     * Obtenir l'instance singleton
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructeur
     */
    private function __construct() {
        // Vérifier si le mode debug est activé
        $this->debug_mode = (get_option('wc_opc_debug_mode') === 'yes');
    }
    
    /**
     * Activer la journalisation
     */
    public function enable_logging() {
        $this->debug_mode = true;
    }
    
    /**
     * Désactiver la journalisation
     */
    public function disable_logging() {
        $this->debug_mode = false;
    }
    
    /**
     * Ajouter une entrée au journal
     */
    public function log($message, $level = 'info') {
        // Ne rien faire si le mode debug est désactivé
        if (!$this->debug_mode) {
            return;
        }
        
        // Formater le message
        $timestamp = date('Y-m-d H:i:s');
        $formatted_message = sprintf(
            '[%s] [%s] %s' . PHP_EOL,
            $timestamp,
            strtoupper($level),
            $message
        );
        
        // Déterminer le chemin du fichier journal
        $log_dir = WP_CONTENT_DIR . '/logs';
        $log_file = $log_dir . '/' . $this->log_file;
        
        // Créer le répertoire de journaux s'il n'existe pas
        if (!file_exists($log_dir)) {
            @mkdir($log_dir, 0755, true);
        }
        
        // Écrire dans le fichier journal
        @error_log($formatted_message, 3, $log_file);
        
        // Limiter la taille du fichier journal (5 Mo)
        $this->rotate_log_file($log_file);
    }
    
    /**
     * Faire pivoter le fichier journal s'il devient trop grand
     */
    private function rotate_log_file($log_file) {
        if (!file_exists($log_file)) {
            return;
        }
        
        $max_size = 5 * 1024 * 1024; // 5 Mo
        
        if (filesize($log_file) > $max_size) {
            $archive_file = $log_file . '.' . date('Y-m-d-H-i-s') . '.old';
            @rename($log_file, $archive_file);
            
            // Limiter le nombre de fichiers d'archive (garder les 5 plus récents)
            $this->cleanup_old_logs();
        }
    }
    
    /**
     * Nettoyer les anciens fichiers journaux
     */
    private function cleanup_old_logs() {
        $log_dir = WP_CONTENT_DIR . '/logs';
        $pattern = $log_dir . '/' . $this->log_file . '.*.old';
        
        $files = glob($pattern);
        
        if (count($files) <= 5) {
            return;
        }
        
        // Trier par date (du plus ancien au plus récent)
        usort($files, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });
        
        // Supprimer les plus anciens
        $files_to_remove = array_slice($files, 0, count($files) - 5);
        
        foreach ($files_to_remove as $file) {
            @unlink($file);
        }
    }
    
    /**
     * Journaliser une erreur
     */
    public function error($message) {
        $this->log($message, 'error');
    }
    
    /**
     * Journaliser un avertissement
     */
    public function warning($message) {
        $this->log($message, 'warning');
    }
    
    /**
     * Journaliser une information
     */
    public function info($message) {
        $this->log($message, 'info');
    }
    
    /**
     * Journaliser un débogage
     */
    public function debug($message) {
        $this->log($message, 'debug');
    }
}