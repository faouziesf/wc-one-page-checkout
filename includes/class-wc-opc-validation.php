<?php
/**
 * Classe de validation des données
 * 
 * Fournit des méthodes pour valider les données saisies par l'utilisateur
 */
class WC_OPC_Validation {
    
    /**
     * Nombre minimum de chiffres pour un numéro de téléphone valide
     */
    private $min_phone_digits = 8;
    
    /**
     * Constructeur
     */
    public function __construct() {
        // Charger les configurations
        $this->min_phone_digits = (int) get_option('wc_opc_min_phone_digits', 8);
    }
    
    /**
     * Vérifier si un numéro de téléphone est valide
     */
    public function is_valid_phone($phone) {
        // Nettoyer le numéro
        $clean_phone = $this->clean_phone_number($phone);
        
        // Vérifier le nombre de chiffres
        return (strlen($clean_phone) >= $this->min_phone_digits);
    }
    
    /**
     * Compter le nombre de chiffres dans un numéro de téléphone
     */
    public function count_phone_digits($phone) {
        $clean_phone = $this->clean_phone_number($phone);
        return strlen($clean_phone);
    }
    
    /**
     * Nettoyer un numéro de téléphone (supprimer tous les caractères non numériques)
     */
    public function clean_phone_number($phone) {
        return preg_replace('/[^0-9]/', '', $phone);
    }
    
    /**
     * Formater un numéro de téléphone pour l'affichage
     */
    public function format_phone_number($phone) {
        $clean_phone = $this->clean_phone_number($phone);
        
        // Format tunisien par défaut
        if (strlen($clean_phone) === 8) {
            return substr($clean_phone, 0, 2) . ' ' . substr($clean_phone, 2, 3) . ' ' . substr($clean_phone, 5, 3);
        }
        
        // Format international
        if (strlen($clean_phone) > 8) {
            if (substr($clean_phone, 0, 3) === '216') {
                // Format tunisien avec indicatif
                $national = substr($clean_phone, 3);
                if (strlen($national) === 8) {
                    return '+216 ' . substr($national, 0, 2) . ' ' . substr($national, 2, 3) . ' ' . substr($national, 5, 3);
                }
            }
        }
        
        // Pour les autres formats, retourner tel quel avec espaces
        return chunk_split($clean_phone, 2, ' ');
    }
    
    /**
     * Valider une adresse email
     */
    public function is_valid_email($email) {
        return is_email($email);
    }
    
    /**
     * Valider un code postal
     */
    public function is_valid_postcode($postcode, $country = 'TN') {
        // Code postal tunisien (4 chiffres)
        if ($country === 'TN') {
            return (bool) preg_match('/^\d{4}$/', $postcode);
        }
        
        // Par défaut, accepter les codes postaux de 3 à 10 caractères
        return (bool) preg_match('/^[\w\d\s-]{3,10}$/', $postcode);
    }
}