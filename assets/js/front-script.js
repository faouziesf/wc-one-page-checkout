/**
 * Script principal
 * 
 * Coordonne les différents gestionnaires et fournit des fonctionnalités
 * générales pour le One Page Checkout.
 */
(function($) {
    'use strict';
    
    // Gestionnaire principal
    window.WC_OPC = {
        // Configuration
        config: {
            debugMode: false,
            networkCheckInterval: 5000
        },
        
        // État interne
        state: {
            isOnline: navigator.onLine,
            offlineMode: false,
            components: {
                draftManager: false,
                formHandler: false,
                trackingManager: false
            },
            sessionReset: false
        },
        
        /**
         * Initialiser le gestionnaire principal
         */
        init: function() {
            console.log('🚀 Initialisation du One Page Checkout v' + (wc_opc_params.version || '2.0.0'));
            
            // Charger la configuration
            this.loadConfig();
            
            // Vérifier si c'est une nouvelle session
            this.checkSessionReset();
            
            // Initialiser la surveillance du réseau
            this.initNetworkMonitoring();
            
            // Configurer les gestionnaires d'événements globaux
            this.setupGlobalHandlers();
            
            // Démarrer en mode debug si activé
            if (this.config.debugMode) {
                this.setupDebugMode();
            }
            
            // Vérifier les composants chargés
            this.checkComponentsLoaded();
            
            console.log('✅ One Page Checkout initialisé');
            
            return this;
        },
        
        /**
         * Charger la configuration depuis les paramètres
         */
        loadConfig: function() {
            if (typeof wc_opc_params !== 'undefined') {
                this.config.debugMode = wc_opc_params.debug_mode === true || wc_opc_params.debug_mode === 'yes';
                this.state.sessionReset = wc_opc_params.reset_session === 'yes' || wc_opc_params.is_new_session === 'yes';
            }
        },
        
        /**
         * Vérifier si la session doit être réinitialisée
         */
        checkSessionReset: function() {
            if (this.state.sessionReset) {
                console.log('🔄 Réinitialisation de session détectée');
                
                // Nettoyer le stockage local
                if (typeof(Storage) !== 'undefined') {
                    try {
                        var keysToRemove = [
                            'wc_opc_draft_state',
                            'wc_opc_form_data',
                            'wc_opc_tracking_state'
                        ];
                        
                        keysToRemove.forEach(function(key) {
                            localStorage.removeItem(key);
                        });
                        
                        console.log('✅ Stockage local nettoyé pour nouvelle session');
                        
                        // Afficher un message discret
                        setTimeout(function() {
                            if (typeof WC_OPC_Utils !== 'undefined') {
                                WC_OPC_Utils.showMessage(wc_opc_params.i18n.session_reset, 'info', 3000);
                            }
                        }, 1000);
                        
                    } catch (e) {
                        console.error('❌ Erreur lors du nettoyage de la session:', e);
                    }
                }
            }
        },
        
        /**
         * Initialiser la surveillance du réseau
         */
        initNetworkMonitoring: function() {
            var self = this;
            
            // Vérifier l'état initial
            this.state.isOnline = navigator.onLine;
            
            // Configurer les gestionnaires d'événements
            window.addEventListener('online', function() {
                self.handleOnlineStatus(true);
            });
            
            window.addEventListener('offline', function() {
                self.handleOnlineStatus(false);
            });
            
            // Vérifier périodiquement la connexion
            setInterval(function() {
                // Tester la connexion en faisant une requête ping
                self.checkNetworkConnection();
            }, this.config.networkCheckInterval);
        },
        
        /**
         * Gérer les changements d'état de connexion
         */
        handleOnlineStatus: function(isOnline) {
            this.state.isOnline = isOnline;
            
            if (isOnline) {
                console.log('🌐 Connexion internet rétablie');
                
                // Afficher un message si précédemment hors ligne
                if (this.state.offlineMode) {
                    this.showMessage(wc_opc_params.i18n.online_mode, 'success');
                    this.state.offlineMode = false;
                    
                    // Synchroniser les données
                    this.synchronizeOfflineData();
                }
            } else {
                console.log('⚠️ Connexion internet perdue');
                
                // Afficher un message
                this.showMessage(wc_opc_params.i18n.offline_mode, 'warning');
                this.state.offlineMode = true;
            }
            
            // Mettre à jour l'interface
            this.updateUIForConnectivity();
        },
        
        /**
         * Vérifier la connexion réseau avec une requête ping
         */
        checkNetworkConnection: function() {
            // Cette fonction pourrait envoyer une requête ping au serveur
            // pour vérifier la connexion réelle (au-delà de navigator.onLine)
            // Mais pour l'instant, utilisons simplement navigator.onLine
        },
        
        /**
         * Synchroniser les données hors ligne
         */
        synchronizeOfflineData: function() {
            // Si le gestionnaire de commandes draft est disponible
            if (window.DraftOrderManager && DraftOrderManager.state.pendingUpdates) {
                // Traiter les mises à jour en attente
                DraftOrderManager.processPendingUpdates();
            }
            
            // Si le gestionnaire de tracking est disponible
            if (window.TrackingManager) {
                // Traiter la file d'attente d'événements
                TrackingManager.processQueue();
            }
        },
        
        /**
         * Mettre à jour l'interface pour l'état de connexion
         */
        updateUIForConnectivity: function() {
            if (this.state.isOnline) {
                // Enlever les indicateurs hors ligne
                $('.wc-opc-offline-indicator').remove();
                $('#wc_opc_submit_button').prop('disabled', false).removeClass('offline-mode');
            } else {
                // Ajouter des indicateurs hors ligne
                if ($('.wc-opc-offline-indicator').length === 0) {
                    $('<div class="wc-opc-offline-indicator">' + wc_opc_params.i18n.offline_mode + '</div>')
                        .prependTo('#wc_opc_checkout_form');
                }
                
                // Désactiver le bouton de soumission
                $('#wc_opc_submit_button').prop('disabled', true).addClass('offline-mode');
            }
        },
        
        /**
         * Afficher un message temporaire
         */
        showMessage: function(message, type, duration) {
            // Utiliser la fonction utilitaire si disponible
            if (typeof WC_OPC_Utils !== 'undefined' && WC_OPC_Utils.showMessage) {
                WC_OPC_Utils.showMessage(message, type, duration);
                return;
            }
            
            // Fallback simple
            console.log('[' + (type || 'info').toUpperCase() + '] ' + message);
            
            // Créer l'élément de message s'il n'existe pas
            var messageId = 'wc-opc-message-' + new Date().getTime();
            
            var messageClass = 'wc-opc-message';
            if (type) {
                messageClass += ' wc-opc-message-' + type;
            }
            
            var messageHtml = '<div id="' + messageId + '" class="' + messageClass + '">' + message + '</div>';
            
            // Ajouter au DOM
            if ($('.wc-opc-messages').length === 0) {
                $('<div class="wc-opc-messages"></div>').prependTo('#wc_opc_checkout_form');
            }
            
            $('.wc-opc-messages').append(messageHtml);
            
            // Faire disparaître après un délai
            setTimeout(function() {
                $('#' + messageId).fadeOut(500, function() {
                    $(this).remove();
                });
            }, duration || 5000);
        },
        
        /**
         * Configurer les gestionnaires d'événements globaux
         */
        setupGlobalHandlers: function() {
            var self = this;
            
            // Gestionnaire global d'erreurs AJAX
            $(document).ajaxError(function(event, jqXHR, ajaxSettings, thrownError) {
                // Ne traiter que les requêtes AJAX de notre plugin
                if (ajaxSettings.url && ajaxSettings.url.indexOf('wc_opc') !== -1) {
                    console.error('❌ Erreur AJAX globale:', thrownError);
                    
                    // Journaliser l'erreur
                    self.logError('ajax_error', {
                        url: ajaxSettings.url,
                        data: ajaxSettings.data,
                        status: jqXHR.status,
                        responseText: jqXHR.responseText,
                        error: thrownError
                    });
                    
                    // Si c'est un problème de réseau, passer en mode hors ligne
                    if (jqXHR.status === 0) {
                        self.handleOnlineStatus(false);
                    }
                }
            });
            
            // Vérifier les événements click pour le débogage
            if (this.config.debugMode) {
                $(document).on('click', '*', function(e) {
                    var $el = $(this);
                    var id = $el.attr('id') || '';
                    var classes = $el.attr('class') || '';
                    
                    console.log('🖱️ Click sur élément:', id, classes);
                });
            }
            
            // Écouter l'événement beforeunload pour sauvegarder les données
            window.addEventListener('beforeunload', function() {
                // Sauvegarder le formulaire
                if (window.FormHandler) {
                    FormHandler.saveFormData();
                }
                
                // Sauvegarder l'état des commandes draft
                if (window.DraftOrderManager) {
                    DraftOrderManager.saveState();
                }
                
                // Sauvegarder l'état du tracking
                if (window.TrackingManager) {
                    TrackingManager.saveEventState();
                }
            });
        },
        
        /**
         * Configurer le mode debug
         */
        setupDebugMode: function() {
            console.log('🐞 Mode debug activé');
            
            // Créer un panneau de debug
            var debugPanel = $('<div id="wc-opc-debug-panel"></div>')
                .css({
                    position: 'fixed',
                    bottom: '10px',
                    right: '10px',
                    background: 'rgba(0, 0, 0, 0.8)',
                    color: '#fff',
                    padding: '10px',
                    borderRadius: '5px',
                    zIndex: 9999,
                    maxWidth: '300px',
                    maxHeight: '200px',
                    overflow: 'auto',
                    fontSize: '12px'
                })
                .appendTo('body');
            
            // Ajouter un en-tête
            $('<h4 style="margin:0 0 5px 0;">WC OPC Debug v' + (wc_opc_params.version || '2.0.0') + '</h4>').appendTo(debugPanel);
            
            // Ajouter le contenu
            $('<div id="wc-opc-debug-content"></div>').appendTo(debugPanel);
            
            // Fonction pour ajouter des messages de debug
            window.wc_opc_debug = function(message) {
                var time = new Date().toLocaleTimeString();
                $('#wc-opc-debug-content').prepend('<div>[' + time + '] ' + message + '</div>');
                
                // Limiter à 20 messages
                if ($('#wc-opc-debug-content').children().length > 20) {
                    $('#wc-opc-debug-content').children().last().remove();
                }
            };
            
            // Journaliser les événements AJAX
            $(document).ajaxSend(function(event, jqxhr, settings) {
                if (settings.url && settings.url.indexOf('wc_opc') !== -1) {
                    wc_opc_debug('📤 AJAX: ' + settings.data ? settings.data.action || 'inconnu' : 'no action');
                }
            });
            
            $(document).ajaxComplete(function(event, jqxhr, settings) {
               if (settings.url && settings.url.indexOf('wc_opc') !== -1) {
                   if (jqxhr.responseJSON && jqxhr.responseJSON.success) {
                       wc_opc_debug('✅ Success: ' + (jqxhr.responseJSON.data ? JSON.stringify(jqxhr.responseJSON.data).substr(0, 30) + '...' : 'no data'));
                   } else {
                       wc_opc_debug('❌ Error: ' + (jqxhr.responseText ? jqxhr.responseText.substr(0, 30) + '...' : 'no response'));
                   }
               }
           });
           
           // Log de l'état initial
           wc_opc_debug('🚀 Démarrage - Session reset: ' + this.state.sessionReset);
           
           // Observer les événements personnalisés
           $(document).on('wc_opc_draft_order_created', function(e, data) {
               wc_opc_debug('📝 Draft créé: #' + data.draft_order_id);
               $('#wc-opc-debug-panel').css('background', 'rgba(0,128,0,0.8)');
               setTimeout(function() {
                   $('#wc-opc-debug-panel').css('background', 'rgba(0,0,0,0.8)');
               }, 3000);
           });
           
           $(document).on('wc_opc_checkout_success', function(e, data) {
               wc_opc_debug('🛒 Commande finalisée: #' + data.order_id);
               $('#wc-opc-debug-panel').css('background', 'rgba(0,255,0,0.8)');
               setTimeout(function() {
                   $('#wc-opc-debug-panel').css('background', 'rgba(0,0,0,0.8)');
               }, 5000);
           });
       },
       
       /**
        * Journaliser une erreur
        */
       logError: function(type, data) {
           if (!this.config.debugMode) {
               return;
           }
           
           console.error('🐞 [' + type + ']', data);
           
           if (window.wc_opc_debug) {
               wc_opc_debug('❌ Erreur ' + type + ': ' + JSON.stringify(data).substr(0, 50) + '...');
           }
           
           // On pourrait également envoyer l'erreur au serveur pour journalisation
       },
       
       /**
        * Vérifier que tous les composants sont chargés
        */
       checkComponentsLoaded: function() {
           // Vérifier DraftOrderManager
           if (window.DraftOrderManager) {
               this.state.components.draftManager = true;
           } else {
               console.warn('⚠️ DraftOrderManager non chargé');
           }
           
           // Vérifier FormHandler
           if (window.FormHandler) {
               this.state.components.formHandler = true;
           } else {
               console.warn('⚠️ FormHandler non chargé');
           }
           
           // Vérifier TrackingManager
           if (window.TrackingManager) {
               this.state.components.trackingManager = true;
           } else {
               console.warn('⚠️ TrackingManager non chargé');
           }
           
           // Journaliser l'état des composants en mode debug
           if (this.config.debugMode) {
               console.log('🔍 État des composants:', this.state.components);
           }
       }
   };
   
   // Initialiser quand le document est prêt
   $(document).ready(function() {
       // Vérifier que nous sommes sur une page avec le formulaire
       if ($('#wc_opc_checkout_form').length) {
           // Initialiser le gestionnaire principal
           WC_OPC.init();
       }
   });
   
})(jQuery);