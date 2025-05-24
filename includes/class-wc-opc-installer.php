<?php
/**
 * Classe d'installation du plugin
 * 
 * Gère l'installation et la mise à jour du plugin
 */
class WC_OPC_Installer {
    
    /**
     * Version actuelle du schéma de la base de données
     */
    private $db_version = '2.0.0';
    
    /**
     * Constructeur
     */
    public function __construct() {
        // Rien à faire
    }
    
    /**
     * Installer le plugin
     */
    public function install() {
        // Créer les tables personnalisées
        $this->create_tables();
        
        // Mettre à jour la version de la base de données
        update_option('wc_opc_db_version', $this->db_version);
    }
    
    /**
     * Créer les tables de la base de données
     */
    private function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Table de suivi des événements
        $table_events = $wpdb->prefix . 'wc_opc_events';
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_events (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            event_type varchar(50) NOT NULL,
            event_data longtext NOT NULL,
            session_id varchar(100) NOT NULL,
            order_id bigint(20) NOT NULL DEFAULT 0,
            product_id bigint(20) NOT NULL DEFAULT 0,
            user_id bigint(20) NOT NULL DEFAULT 0,
            ip_address varchar(45) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY event_type (event_type),
            KEY session_id (session_id),
            KEY order_id (order_id),
            KEY product_id (product_id),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        // Table de journalisation
        $table_logs = $wpdb->prefix . 'wc_opc_logs';
        
        $sql .= "CREATE TABLE IF NOT EXISTS $table_logs (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            log_type varchar(50) NOT NULL,
            log_message text NOT NULL,
            log_data longtext,
            session_id varchar(100),
            order_id bigint(20) NOT NULL DEFAULT 0,
            ip_address varchar(45) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY log_type (log_type),
            KEY session_id (session_id),
            KEY order_id (order_id),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        // Exécuter les requêtes SQL
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Désinstaller le plugin
     */
    public static function uninstall() {
        // Supprimer les options
        delete_option('wc_opc_db_version');
        delete_option('wc_opc_enable_for_all');
        delete_option('wc_opc_form_title');
        delete_option('wc_opc_button_text');
        delete_option('wc_opc_draft_expiration');
        delete_option('wc_opc_enable_tracking');
        delete_option('wc_opc_debug_mode');
        
        // Ne pas supprimer les tables pour préserver les données
        // Si vous souhaitez les supprimer, décommentez ci-dessous
        /*
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wc_opc_events");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wc_opc_logs");
        */
    }
}