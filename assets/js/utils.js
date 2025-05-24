/**
 * Fonctions utilitaires pour le One Page Checkout
 * 
 * Fournit des fonctions communes et des utilitaires pour tous les modules
 */
(function($) {
    'use strict';
    
    // Espace de noms global
    window.WC_OPC_Utils = {
        /**
         * Vérifier si le navigateur est en ligne
         */
        isOnline: function() {
            return navigator.onLine;
        },
        
        /**
         * Stocker des données dans le stockage local
         */
        setLocalStorage: function(key, value, expiration = null) {
            if (typeof(Storage) === 'undefined') {
                return false;
            }
            
            try {
                // Préparer les données avec une éventuelle expiration
                var data = {
                    value: value,
                    timestamp: new Date().getTime()
                };
                
                // Ajouter l'expiration si fournie
                if (expiration !== null) {
                    data.expiration = data.timestamp + (expiration * 1000);
                }
                
                // Stocker les données
                localStorage.setItem('wc_opc_' + key, JSON.stringify(data));
                return true;
            } catch (e) {
                console.error('Erreur lors du stockage local:', e);
                return false;
            }
        },
        
        /**
         * Récupérer des données depuis le stockage local
         */
        getLocalStorage: function(key) {
            if (typeof(Storage) === 'undefined') {
                return null;
            }
            
            try {
                var data = localStorage.getItem('wc_opc_' + key);
                
                if (!data) {
                    return null;
                }
                
                data = JSON.parse(data);
                
                // Vérifier l'expiration si elle existe
                if (data.expiration && data.expiration < new Date().getTime()) {
                    // Données expirées, les supprimer et retourner null
                    localStorage.removeItem('wc_opc_' + key);
                    return null;
                }
                
                return data.value;
            } catch (e) {
                console.error('Erreur lors de la récupération du stockage local:', e);
                return null;
            }
        },
        
        /**
         * Supprimer des données du stockage local
         */
        removeLocalStorage: function(key) {
            if (typeof(Storage) === 'undefined') {
                return false;
            }
            
            try {
                localStorage.removeItem('wc_opc_' + key);
                return true;
            } catch (e) {
                console.error('Erreur lors de la suppression du stockage local:', e);
                return false;
            }
        },
        
        /**
         * Compter le nombre de chiffres dans une chaîne
         */
        countDigits: function(str) {
            return (str.match(/\d/g) || []).length;
        },
        
        /**
         * Nettoyer un numéro de téléphone
         */
        cleanPhoneNumber: function(phone) {
            // Supprimer tous les caractères non numériques
            return phone.replace(/[^0-9]/g, '');
        },
        
        /**
         * Formater un numéro de téléphone pour l'affichage
         */
        formatPhoneNumber: function(phone) {
            // Nettoyer le numéro
            var clean = this.cleanPhoneNumber(phone);
            
            // Format tunisien par défaut
            if (clean.length === 8) {
                return clean.substr(0, 2) + ' ' + clean.substr(2, 3) + ' ' + clean.substr(5, 3);
            }
            
            // Format international
            if (clean.length > 8) {
                if (clean.substr(0, 3) === '216') {
                    // Format tunisien avec indicatif
                    var national = clean.substr(3);
                    if (national.length === 8) {
                        return '+216 ' + national.substr(0, 2) + ' ' + national.substr(2, 3) + ' ' + national.substr(5, 3);
                    }
                }
            }
            
            // Pour les autres formats, retourner tel quel avec des espaces tous les 2 caractères
            var result = '';
            for (var i = 0; i < clean.length; i += 2) {
                result += clean.substr(i, 2) + ' ';
            }
            return result.trim();
        },
        
        /**
         * Débouncer une fonction
         */
        debounce: function(func, wait) {
            var timeout;
            return function() {
                var context = this;
                var args = arguments;
                clearTimeout(timeout);
                timeout = setTimeout(function() {
                    func.apply(context, args);
                }, wait);
            };
        },
        
        /**
         * Générer un ID unique
         */
        generateUniqueId: function() {
            return 'wc_opc_' + Math.random().toString(36).substr(2, 9) + '_' + new Date().getTime();
        },
        
        /**
         * Logger un message de debug
         */
        log: function(message, type = 'info') {
            if (typeof wc_opc_params !== 'undefined' && wc_opc_params.debug_mode) {
                var prefix = '[WC OPC] ';
                
                switch (type) {
                    case 'error':
                        console.error(prefix + message);
                        break;
                    case 'warning':
                        console.warn(prefix + message);
                        break;
                    case 'success':
                        console.log('%c' + prefix + message, 'color: green;');
                        break;
                    default:
                        console.log(prefix + message);
                }
                
                // Si la fonction de debug du panneau est disponible
                if (typeof window.wc_opc_debug === 'function') {
                    window.wc_opc_debug(message);
                }
            }
        },
        
        /**
         * Afficher un message à l'utilisateur
         */
        showMessage: function(message, type = 'info', duration = 5000) {
            // Créer le conteneur de messages s'il n'existe pas
            var $messagesContainer = $('.wc-opc-messages');
            if ($messagesContainer.length === 0) {
                $messagesContainer = $('<div class="wc-opc-messages"></div>');
                $('#wc_opc_checkout_form').prepend($messagesContainer);
            }
            
            // Créer l'élément de message
            var messageId = 'wc-opc-message-' + new Date().getTime();
            var $message = $('<div id="' + messageId + '" class="wc-opc-message wc-opc-message-' + type + '">' + message + '</div>');
            
            // Ajouter le message
            $messagesContainer.append($message);
            
            // Faire disparaître le message après un délai
            setTimeout(function() {
                $('#' + messageId).fadeOut(500, function() {
                    $(this).remove();
                });
            }, duration);
        },
        
        /**
         * Formater un prix avec la devise
         */
        formatPrice: function(price, currencySymbol = '') {
            // Utiliser le symbole monétaire du site si disponible
            if (!currencySymbol && typeof wc_opc_params !== 'undefined' && wc_opc_params.product) {
                currencySymbol = wc_opc_params.product.currency_symbol || '';
            }
            
            // Formater le prix
            var formattedPrice = parseFloat(price).toFixed(2);
            
            return currencySymbol + formattedPrice;
        },
        
        /**
         * Envoyer des données au serveur via AJAX
         */
        sendAjaxRequest: function(action, data, successCallback, errorCallback) {
            // Vérifier si nous sommes en ligne
            if (!this.isOnline()) {
                if (errorCallback) {
                    errorCallback({
                        code: 'offline',
                        message: 'Hors ligne'
                    });
                }
                return;
            }
            
            // Préparer les données
            var ajaxData = $.extend({
                action: action,
                nonce: wc_opc_params.nonce
            }, data);
            
            // Envoyer la requête
            $.ajax({
                type: 'POST',
                url: wc_opc_params.ajax_url,
                data: ajaxData,
                success: function(response) {
                    if (response.success) {
                        if (successCallback) {
                            successCallback(response.data);
                        }
                    } else {
                        if (errorCallback) {
                            errorCallback(response.data);
                        }
                    }
                },
                error: function(xhr, status, error) {
                    if (errorCallback) {
                        errorCallback({
                            code: 'ajax_error',
                            message: error
                        });
                    }
                }
            });
        }
    };
    
})(jQuery);