/**
 * Gestionnaire de commandes draft côté client
 * 
 * Gère la création et mise à jour des commandes draft avec des mécanismes
 * pour éviter les doublons et gérer les pertes de connexion.
 */
(function($) {
    'use strict';
    
    // Gestionnaire de commandes draft
    window.DraftOrderManager = {
        // Configuration
        config: {
            minPhoneDigits: 8,
            maxRetryAttempts: 3,
            retryDelay: 2000,
            lockTimeout: 30000 // 30 secondes
        },
        
        // État interne
        state: {
            draftOrderId: null,
            draftProductId: null,
            isCreating: false,
            isUpdating: false,
            retryCount: 0,
            pendingUpdates: {},
            creationLock: false,
            creationLockTimeout: null,
            sessionReset: false
        },
        
        // Éléments du DOM
        elements: {},
        
        /**
         * Initialiser le gestionnaire
         */
        init: function(formElements) {
            console.log('🔧 Initialisation du gestionnaire de commandes draft');
            
            // Stocker les éléments du formulaire
            this.elements = formElements || {};
            
            // Charger la configuration depuis les paramètres
            this.loadConfig();
            
            // Vérifier si la session doit être réinitialisée
            this.checkSessionReset();
            
            // Restaurer l'état depuis le stockage local (seulement si pas de reset)
            if (!this.state.sessionReset) {
                this.restoreState();
            }
            
            // Vérifier s'il existe déjà un ID de commande draft
            this.checkExistingDraftOrder();
            
            console.log('✅ Gestionnaire de commandes draft initialisé');
            
            return this;
        },
        
        /**
         * Charger la configuration depuis les paramètres
         */
        loadConfig: function() {
            if (typeof wc_opc_params !== 'undefined') {
                this.config.minPhoneDigits = parseInt(wc_opc_params.min_phone_length) || 8;
            }
        },
        
        /**
         * Vérifier si la session doit être réinitialisée
         */
        checkSessionReset: function() {
            if (typeof wc_opc_params !== 'undefined') {
                this.state.sessionReset = wc_opc_params.reset_session === 'yes' || wc_opc_params.is_new_session === 'yes';
                
                if (this.state.sessionReset) {
                    console.log('🔄 Réinitialisation de session détectée dans DraftOrderManager');
                    this.resetState();
                }
            }
        },
        
        /**
         * Restaurer l'état depuis le stockage local
         */
        restoreState: function() {
            if (typeof(Storage) !== 'undefined') {
                try {
                    var storedState = localStorage.getItem('wc_opc_draft_state');
                    if (storedState) {
                        var parsedState = JSON.parse(storedState);
                        
                        // Vérifier si l'état stocké est valide et récent (moins de 24h)
                        if (parsedState && parsedState.draftOrderId && parsedState.timestamp) {
                            var now = new Date().getTime();
                            var age = now - parsedState.timestamp;
                            var maxAge = 24 * 60 * 60 * 1000; // 24 heures
                            
                            if (age < maxAge) {
                                this.state.draftOrderId = parsedState.draftOrderId;
                                this.state.draftProductId = parsedState.draftProductId;
                                
                                console.log('📋 État restauré depuis le stockage local:', this.state.draftOrderId);
                                
                                // Mettre à jour le champ caché
                                if (this.elements.draftOrderIdField) {
                                    this.elements.draftOrderIdField.val(this.state.draftOrderId);
                                }
                            } else {
                                console.log('⏰ État stocké trop ancien, ignoré');
                                this.clearStoredState();
                            }
                        }
                    }
                } catch (e) {
                    console.error('❌ Erreur lors de la restauration de l\'état:', e);
                    this.clearStoredState();
                }
            }
        },
        
        /**
         * Sauvegarder l'état dans le stockage local
         */
        saveState: function() {
            if (typeof(Storage) !== 'undefined') {
                try {
                    var stateToSave = {
                        draftOrderId: this.state.draftOrderId,
                        draftProductId: this.state.draftProductId,
                        timestamp: new Date().getTime()
                    };
                    
                    localStorage.setItem('wc_opc_draft_state', JSON.stringify(stateToSave));
                } catch (e) {
                    console.error('❌ Erreur lors de la sauvegarde de l\'état:', e);
                }
            }
        },
        
        /**
         * Nettoyer l'état stocké
         */
        clearStoredState: function() {
            if (typeof(Storage) !== 'undefined') {
                try {
                    localStorage.removeItem('wc_opc_draft_state');
                } catch (e) {
                    console.error('❌ Erreur lors du nettoyage de l\'état stocké:', e);
                }
            }
        },
        
        /**
         * Vérifier s'il existe déjà un ID de commande draft
         */
        checkExistingDraftOrder: function() {
            // Vérifier s'il y a un ID de commande draft dans le champ caché
            if (this.elements.draftOrderIdField && this.elements.draftOrderIdField.val()) {
                var draftId = this.elements.draftOrderIdField.val();
                var productId = $('input[name="product_id"]').val();
                
                if (draftId && productId) {
                    console.log('🔍 Vérification de la commande draft existante:', draftId);
                    
                    this.verifyDraftOrder(draftId, productId);
                }
            }
        },
        
        /**
         * Vérifier si une commande draft est valide
         */
        verifyDraftOrder: function(draftId, productId) {
            var self = this;
            
            // Vérifier la connectivité
            if (!navigator.onLine) {
                console.log('⚠️ Hors ligne, vérification de la commande draft reportée');
                return;
            }
            
            $.ajax({
                type: 'POST',
                url: wc_opc_params.ajax_url,
                data: {
                    action: 'wc_opc_verify_draft_order',
                    nonce: wc_opc_params.nonce,
                    draft_order_id: draftId,
                    product_id: productId
                },
                success: function(response) {
                    if (response.success) {
                        // La commande draft est valide
                        console.log('✅ Commande draft valide:', draftId);
                        
                        self.state.draftOrderId = draftId;
                        self.state.draftProductId = productId;
                        
                        // Mettre à jour le champ caché
                        if (self.elements.draftOrderIdField) {
                            self.elements.draftOrderIdField.val(draftId);
                        }
                        
                        // Sauvegarder l'état
                        self.saveState();
                        
                        // Déclencher un événement personnalisé
                        $(document).trigger('wc_opc_draft_order_verified', {
                            draft_order_id: draftId,
                            product_id: productId
                        });
                    } else {
                        // La commande draft n'est pas valide
                        console.log('❌ Commande draft invalide:', response.data.message);
                        
                        // Réinitialiser l'état
                        self.resetState();
                    }
                },
                error: function(xhr, status, error) {
                    console.error('❌ Erreur lors de la vérification de la commande draft:', error);
                    
                    // En cas d'erreur réseau, considérer la commande comme valide
                    // (elle sera re-vérifiée lors de la soumission)
                }
            });
        },
        
        /**
         * Créer une commande draft
         */
        createDraftOrder: function(phoneNumber, productId, callback) {
            // Éviter les créations multiples
            if (this.state.isCreating || this.state.creationLock) {
                console.log('⚠️ Création de commande déjà en cours, requête ignorée');
                if (callback) callback(false, null);
                return;
            }
            
            // Si nous avons déjà une commande draft pour ce produit, l'utiliser
            if (this.state.draftOrderId && this.state.draftProductId === productId) {
                console.log('📋 Utilisation de la commande draft existante:', this.state.draftOrderId);
                if (callback) callback(true, this.state.draftOrderId);
                return;
            }
            
            // Vérifier la connectivité
            if (!navigator.onLine) {
                console.warn('⚠️ Création de commande reportée - hors ligne');
                if (callback) callback(false, null);
                return;
            }
            
            // Poser un verrou
            this.state.creationLock = true;
            
            // Timeout de sécurité pour libérer le verrou
            if (this.state.creationLockTimeout) {
                clearTimeout(this.state.creationLockTimeout);
            }
            
            this.state.creationLockTimeout = setTimeout(function() {
                this.state.creationLock = false;
            }.bind(this), this.config.lockTimeout);
            
            console.log('🔄 Création d\'une commande draft...');
            this.state.isCreating = true;
            
            var self = this;
            
            // Extraire les données nécessaires
            var quantity = this.elements.quantityField ? this.elements.quantityField.val() : 1;
            var bundleOption = $('input[name="bundle_option"]:checked').val() || '';
            
            // Créer la commande draft via AJAX
            $.ajax({
                type: 'POST',
                url: wc_opc_params.ajax_url,
                data: {
                    action: 'wc_opc_create_draft_order',
                    nonce: wc_opc_params.nonce,
                    product_id: productId,
                    quantity: quantity,
                    bundle_option: bundleOption,
                    customer_phone: phoneNumber,
                    // Ajouter un timestamp pour éviter le cache
                    timestamp: new Date().getTime()
                },
                success: function(response) {
                    self.state.isCreating = false;
                    self.state.creationLock = false;
                    
                    if (response.success) {
                        console.log('✅ Commande draft créée avec succès:', response.data.draft_order_id);
                        
                        // Mettre à jour l'état interne
                        self.state.draftOrderId = response.data.draft_order_id;
                        self.state.draftProductId = productId;
                        self.state.retryCount = 0;
                        
                        // Mettre à jour le champ caché
                        if (self.elements.draftOrderIdField) {
                            self.elements.draftOrderIdField.val(response.data.draft_order_id);
                        }
                        
                        // Sauvegarder l'état
                        self.saveState();
                        
                        // Déclencher un événement personnalisé
                        $(document).trigger('wc_opc_draft_order_created', response.data);
                        
                        // Appeler le callback avec succès
                        if (callback) callback(true, response.data.draft_order_id);
                    } else {
                        console.error('❌ Erreur lors de la création de commande draft:', response.data.message);
                        
                        // Gérer l'erreur avec tentatives de reprise
                        self.handleError('create', phoneNumber, productId, callback);
                    }
                },
                error: function(xhr, status, error) {
                    self.state.isCreating = false;
                    self.state.creationLock = false;
                    
                    console.error('❌ Erreur réseau lors de la création de commande draft:', error);
                    
                    // Gérer l'erreur avec tentatives de reprise
                    self.handleError('create', phoneNumber, productId, callback);
                }
            });
        },
        
        /**
         * Mettre à jour une commande draft
         */
        updateDraftOrder: function(field, value, callback) {
            // Si pas de commande draft, mettre l'update en attente
            if (!this.state.draftOrderId) {
                this.state.pendingUpdates[field] = value;
                
                console.log('⏳ Mise à jour mise en attente pour le champ:', field);
                
                if (callback) callback(false);
                return;
            }
            
            // Si déjà en cours de mise à jour, mettre en attente
            if (this.state.isUpdating) {
                this.state.pendingUpdates[field] = value;
                
                console.log('⏳ Mise à jour mise en attente (update en cours) pour le champ:', field);
                
                if (callback) callback(false);
                return;
            }
            
            // Vérifier la connectivité
            if (!navigator.onLine) {
                this.state.pendingUpdates[field] = value;
                console.warn('⚠️ Mise à jour reportée - hors ligne');
                if (callback) callback(false);
                return;
            }
            
            this.state.isUpdating = true;
            
            var self = this;
            var productId = this.state.draftProductId;
            
            // Collecter les données du formulaire
            var formData = {
                action: 'wc_opc_update_draft_order',
                nonce: wc_opc_params.nonce,
                draft_order_id: this.state.draftOrderId,
                product_id: productId,
                field_changed: field,
                // Récupérer toutes les valeurs actuelles du formulaire
                customer_name: this.elements.nameField ? this.elements.nameField.val() : '',
                customer_phone: this.elements.phoneField ? this.elements.phoneField.val() : '',
                customer_address: this.elements.addressField ? this.elements.addressField.val() : '',
                quantity: this.elements.quantityField ? this.elements.quantityField.val() : 1,
                bundle_option: $('input[name="bundle_option"]:checked').val() || ''
            };
            
            console.log('🔄 Mise à jour de la commande draft pour le champ:', field);
            
            // Envoyer la mise à jour via AJAX
            $.ajax({
                type: 'POST',
                url: wc_opc_params.ajax_url,
                data: formData,
                success: function(response) {
                    self.state.isUpdating = false;
                    
                    if (response.success) {
                        console.log('✅ Commande draft mise à jour avec succès pour:', field);
                        
                        // Déclencher un événement personnalisé
                        $(document).trigger('wc_opc_draft_order_updated', {
                            draft_order_id: self.state.draftOrderId,
                            field_updated: field,
                            value: value
                        });
                        
                        // Traiter les mises à jour en attente
                        self.processPendingUpdates();
                        
                        if (callback) callback(true);
                    } else {
                        console.error('❌ Erreur lors de la mise à jour de commande draft:', response.data.message);
                        
                        // Si la commande n'existe plus, la recréer
                        if (response.data.code === 'invalid_order' || response.data.code === 'not_draft') {
                            console.log('🔄 La commande draft n\'existe plus, réinitialisation');
                            
                            // Réinitialiser l'état
                            self.resetState();
                            
                            // Remettre à jour en attente
                            self.state.pendingUpdates[field] = value;
                            
                            if (callback) callback(false);
                        } else {
                            // Réessayer plus tard
                            setTimeout(function() {
                                self.updateDraftOrder(field, value, callback);
                            }, 2000);
                        }
                    }
                },
                error: function(xhr, status, error) {
                    self.state.isUpdating = false;
                    
                    console.error('❌ Erreur réseau lors de la mise à jour de commande draft:', error);
                    
                    // Mettre à jour en attente
                    self.state.pendingUpdates[field] = value;
                    
                    if (callback) callback(false);
                }
            });
        },
        
        /**
         * Traiter les mises à jour en attente
         */
        processPendingUpdates: function() {
            // Si des mises à jour sont en attente, les traiter une par une
            var pendingFields = Object.keys(this.state.pendingUpdates);
            
            if (pendingFields.length > 0 && !this.state.isUpdating) {
                var field = pendingFields[0];
                var value = this.state.pendingUpdates[field];
                
                // Supprimer de la liste d'attente
                delete this.state.pendingUpdates[field];
                
                // Mettre à jour
                var self = this;
                this.updateDraftOrder(field, value, function() {
                    // Continuer avec les autres mises à jour
                    if (Object.keys(self.state.pendingUpdates).length > 0) {
                        setTimeout(function() {
                            self.processPendingUpdates();
                        }, 500);
                    }
                });
            }
        },
        
        /**
         * Gérer les erreurs avec tentatives de reprise
         */
        handleError: function(operation, phoneNumber, productId, callback) {
            this.state.retryCount++;
            
            if (this.state.retryCount <= this.config.maxRetryAttempts) {
                console.log('🔄 Tentative ' + this.state.retryCount + '/' + this.config.maxRetryAttempts + ' pour ' + operation);
                
                // Calculer le délai exponentiel
                var delay = Math.pow(2, this.state.retryCount - 1) * this.config.retryDelay;
                
                var self = this;
                setTimeout(function() {
                    if (operation === 'create') {
                        self.createDraftOrder(phoneNumber, productId, callback);
                    }
                }, delay);
            } else {
                console.error('❌ Abandon après ' + this.config.maxRetryAttempts + ' tentatives pour ' + operation);
                
                // Réinitialiser le compteur pour les prochaines tentatives
                this.state.retryCount = 0;
                
                if (callback) callback(false, null);
            }
        },
        
        /**
         * Réinitialiser l'état
         */
        resetState: function() {
            this.state.draftOrderId = null;
            this.state.draftProductId = null;
            this.state.retryCount = 0;
            this.state.pendingUpdates = {};
            
            // Effacer le champ caché
            if (this.elements.draftOrderIdField) {
                this.elements.draftOrderIdField.val('');
            }
            
            // Nettoyer le stockage local
            this.clearStoredState();
            
            console.log('🔄 État réinitialisé');
        }
    };
    
    // Initialiser quand le document est prêt
    $(document).ready(function() {
        // Vérifier si le formulaire existe
        if ($('#wc_opc_checkout_form').length) {
            // Collecter les éléments du formulaire
            var formElements = {
                form: $('#wc_opc_checkout_form'),
                phoneField: $('#wc_opc_customer_phone'),
                nameField: $('#wc_opc_customer_name'),
                addressField: $('#wc_opc_customer_address'),
                quantityField: $('#wc_opc_quantity'),
                draftOrderIdField: $('#wc_opc_draft_order_id'),
                submitButton: $('#wc_opc_submit_button')
            };
            
            // Initialiser le gestionnaire
            DraftOrderManager.init(formElements);
        }
    });
    
})(jQuery);