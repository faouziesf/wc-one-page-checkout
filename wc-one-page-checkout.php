<?php
/**
 * Plugin Name: WooCommerce One Page Checkout Premium
 * Plugin URI: https://votre-site.com/plugins/wc-one-page-checkout
 * Description: Système de checkout directement sur la page produit avec gestion efficace des commandes draft et suivi en temps réel
 * Version: 2.0.0
 * Author: Faouzi
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 */

// Si ce fichier est appelé directement, on sort.
if (!defined('ABSPATH')) {
    exit;
}

// Définir les constantes
define('WC_OPC_VERSION', '2.0.0');
define('WC_OPC_PATH', plugin_dir_path(__FILE__));
define('WC_OPC_URL', plugin_dir_url(__FILE__));
define('WC_OPC_BASENAME', plugin_basename(__FILE__));

/**
 * Classe principale du plugin
 */
class WC_One_Page_Checkout {

    /**
     * Instance singleton
     */
    private static $instance = null;

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
        // Vérifier si WooCommerce est actif
        add_action('plugins_loaded', array($this, 'check_woocommerce'));
        
        // Charger les traductions
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        
        // Initialiser le plugin
        add_action('plugins_loaded', array($this, 'init'), 20);
        
        // Ajouter hooks d'activation/désactivation
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    /**
     * Vérifier si WooCommerce est actif
     */
    public function check_woocommerce() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }
    }

    /**
     * Message d'erreur si WooCommerce n'est pas actif
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="error">
            <p><?php _e('WooCommerce One Page Checkout Premium nécessite que WooCommerce soit installé et activé.', 'wc-one-page-checkout'); ?></p>
        </div>
        <?php
    }

    /**
     * Charger le domaine de texte
     */
    public function load_textdomain() {
        load_plugin_textdomain('wc-one-page-checkout', false, dirname(WC_OPC_BASENAME) . '/languages');
    }

    /**
     * Initialiser le plugin
     */
    public function init() {
        // Vérifier si WooCommerce est actif
        if (!class_exists('WooCommerce')) {
            return;
        }
    
        // Charger les classes
        $this->load_dependencies();
    
        // Initialiser les composants principaux
        $this->initialize_components();
    
        // Ajouter les scripts et styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
    }

    /**
     * Lors de l'activation du plugin
     */
    public function activate() {
        // Créer les tables personnalisées si nécessaire
        require_once WC_OPC_PATH . 'includes/class-wc-opc-installer.php';
        $installer = new WC_OPC_Installer();
        $installer->install();
        
        // Planifier la tâche cron pour nettoyer les commandes draft expirées
        if (!wp_next_scheduled('wc_opc_cleanup_expired_drafts')) {
            wp_schedule_event(time(), 'daily', 'wc_opc_cleanup_expired_drafts');
        }
        
        // Mettre en place les paramètres par défaut
        $this->set_default_options();
        
        // Créer les dossiers de journalisation si nécessaires
        $log_dir = WP_CONTENT_DIR . '/logs';
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }
        
        // Essayer d'importer des données depuis l'ancienne version
        if (get_option('wc_opc_version') !== WC_OPC_VERSION) {
            // Marquer que la migration est nécessaire
            update_option('wc_opc_needs_migration', 'yes');
        }
    }

    /**
     * Lors de la désactivation du plugin
     */
    public function deactivate() {
        // Supprimer la tâche cron
        wp_clear_scheduled_hook('wc_opc_cleanup_expired_drafts');
    }

    /**
     * Mettre en place les paramètres par défaut
     */
    private function set_default_options() {
        $options = array(
            'wc_opc_enable_for_all' => 'yes',
            'wc_opc_form_title' => __('Commander maintenant', 'wc-one-page-checkout'),
            'wc_opc_button_text' => __('Commander', 'wc-one-page-checkout'),
            'wc_opc_draft_expiration' => 86400, // 24 heures par défaut
            'wc_opc_enable_tracking' => 'yes',
            'wc_opc_debug_mode' => 'no',
            'wc_opc_version' => WC_OPC_VERSION
        );
        
        foreach ($options as $option_name => $option_value) {
            if (get_option($option_name) === false) {
                update_option($option_name, $option_value);
            }
        }
    }

    /**
     * Charger les dépendances
     */
    private function load_dependencies() {
        // Classes utilitaires (doivent être chargées en premier)
        require_once WC_OPC_PATH . 'includes/utilities/class-wc-opc-cache.php';
        require_once WC_OPC_PATH . 'includes/utilities/class-wc-opc-session.php';
        
        // Classes principales
        require_once WC_OPC_PATH . 'includes/class-wc-opc-logger.php';
        require_once WC_OPC_PATH . 'includes/class-wc-opc-settings.php';
        require_once WC_OPC_PATH . 'includes/class-wc-opc-admin.php';
        require_once WC_OPC_PATH . 'includes/class-wc-opc-draft-manager.php';
        require_once WC_OPC_PATH . 'includes/class-wc-opc-checkout.php';
        require_once WC_OPC_PATH . 'includes/class-wc-opc-bundle-manager.php';
        require_once WC_OPC_PATH . 'includes/class-wc-opc-tracking.php';
        require_once WC_OPC_PATH . 'includes/class-wc-opc-validation.php';
        require_once WC_OPC_PATH . 'includes/class-wc-opc-api.php';
    }

    /**
     * Initialiser les composants principaux
     */
    private function initialize_components() {
        // Initialiser les paramètres
        $settings = new WC_OPC_Settings();
        
        // Initialiser l'administration
        $admin = new WC_OPC_Admin();
        
        // Initialiser le gestionnaire de commandes draft
        $draft_manager = new WC_OPC_Draft_Manager();
        
        // Initialiser le checkout
        $checkout = new WC_OPC_Checkout();
        
        // Initialiser la gestion des bundles
        $bundle_manager = new WC_OPC_Bundle_Manager();
        
        // Initialiser le tracking
        $tracking = new WC_OPC_Tracking();
        
        // Initialiser l'API
        $api = new WC_OPC_API();
        
        // Démarrer la journalisation si le mode debug est activé
        if (get_option('wc_opc_debug_mode') === 'yes') {
            WC_OPC_Logger::get_instance()->enable_logging();
        }
    }

    /**
     * Enregistrer les scripts et styles front-end
     */
    public function enqueue_scripts() {
        // Ne charger que sur les pages produit
        if (!is_product()) {
            return;
        }

        // Styles
        wp_enqueue_style(
            'wc-opc-style', 
            WC_OPC_URL . 'assets/css/front-style.css', 
            array(), 
            WC_OPC_VERSION
        );
        
        // Scripts de base
        wp_enqueue_script(
            'wc-opc-utils', 
            WC_OPC_URL . 'assets/js/utils.js', 
            array('jquery'), 
            WC_OPC_VERSION, 
            true
        );
        
        wp_enqueue_script(
            'wc-opc-draft-manager', 
            WC_OPC_URL . 'assets/js/draft-manager.js', 
            array('jquery', 'wc-opc-utils'), 
            WC_OPC_VERSION, 
            true
        );
        
        wp_enqueue_script(
            'wc-opc-form-handler', 
            WC_OPC_URL . 'assets/js/form-handler.js', 
            array('jquery', 'wc-opc-draft-manager'), 
            WC_OPC_VERSION, 
            true
        );
        
        // Script de tracking uniquement si activé
        if (get_option('wc_opc_enable_tracking') === 'yes') {
            wp_enqueue_script(
                'wc-opc-tracking', 
                WC_OPC_URL . 'assets/js/tracking.js', 
                array('jquery', 'wc-opc-draft-manager'), 
                WC_OPC_VERSION, 
                true
            );
        }
        
        // Script principal
        wp_enqueue_script(
            'wc-opc-script', 
            WC_OPC_URL . 'assets/js/front-script.js', 
            array('jquery', 'wc-opc-form-handler', 'wc-opc-draft-manager'), 
            WC_OPC_VERSION, 
            true
        );
        
        // Ajouter les variables JS
        global $product;
        
        wp_localize_script('wc-opc-script', 'wc_opc_params', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc-opc-nonce'),
            'min_phone_length' => 8,
            'version' => WC_OPC_VERSION,
            'product' => array(
                'id' => $product ? $product->get_id() : 0,
                'name' => $product ? $product->get_name() : '',
                'price' => $product ? $product->get_price() : 0,
                'currency' => get_woocommerce_currency(),
                'currency_symbol' => get_woocommerce_currency_symbol(),
            ),
            'debug_mode' => get_option('wc_opc_debug_mode') === 'yes',
            'reset_session' => isset($_GET['reset_opc_session']) ? 'yes' : 'no',
            'is_new_session' => isset($_COOKIE['wc_opc_order_completed']) ? 'yes' : 'no',
            'i18n' => array(
                'phone_invalid' => __('Veuillez entrer un numéro de téléphone valide (au moins 8 chiffres)', 'wc-one-page-checkout'),
                'processing' => __('Traitement en cours...', 'wc-one-page-checkout'),
                'order_success' => __('Commande enregistrée avec succès!', 'wc-one-page-checkout'),
                'offline_mode' => __('Vous êtes hors ligne. Vos données seront enregistrées localement.', 'wc-one-page-checkout'),
                'online_mode' => __('Connexion rétablie. Synchronisation en cours...', 'wc-one-page-checkout'),
                'session_reset' => __('Nouvelle session démarrée.', 'wc-one-page-checkout')
            )
        ));
        
        // Nettoyer le stockage local après une commande réussie
        if (isset($_GET['reset_opc_session']) || isset($_COOKIE['wc_opc_order_completed'])) {
            add_action('wp_footer', function() {
                ?>
                <script type="text/javascript">
                jQuery(document).ready(function($) {
                    // Nettoyer les données de commande en cours
                    if (typeof(Storage) !== 'undefined') {
                        try {
                            localStorage.removeItem('wc_opc_draft_state');
                            localStorage.removeItem('wc_opc_form_data');
                            localStorage.removeItem('wc_opc_tracking_state');
                            
                            console.log('✅ Session nettoyée pour une nouvelle commande');
                            
                            // Afficher un message à l'utilisateur si besoin
                            if (typeof WC_OPC_Utils !== 'undefined') {
                                WC_OPC_Utils.showMessage(wc_opc_params.i18n.session_reset, 'info');
                            }
                        } catch (e) {
                            console.error('❌ Erreur lors du nettoyage de la session:', e);
                        }
                    }
                });
                </script>
                <?php
                
                // Supprimer le cookie après utilisation
                if (isset($_COOKIE['wc_opc_order_completed'])) {
                    setcookie('wc_opc_order_completed', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN);
                }
            }, 5);
        }
    }

    /**
     * Enregistrer les scripts et styles admin
     */
    public function admin_enqueue_scripts($hook) {
        // Ne charger que sur les pages d'administration du plugin
        if ('woocommerce_page_wc-one-page-checkout' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'wc-opc-admin-style', 
            WC_OPC_URL . 'assets/css/admin-style.css', 
            array(), 
            WC_OPC_VERSION
        );
        
        wp_enqueue_script(
            'wc-opc-admin-script', 
            WC_OPC_URL . 'assets/js/admin-script.js', 
            array('jquery'), 
            WC_OPC_VERSION, 
            true
        );
        
        // Localiser le script admin
        wp_localize_script('wc-opc-admin-script', 'wc_opc_admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc-opc-admin-nonce'),
            'version' => WC_OPC_VERSION
        ));
    }
}

/**
 * Fonction principale pour initialiser le plugin
 */
function wc_one_page_checkout() {
    return WC_One_Page_Checkout::get_instance();
}

// Démarrer le plugin
wc_one_page_checkout();