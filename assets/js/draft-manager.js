/**
 * Gestionnaire de commandes draft côté client CORRIGÉ
 */
(function($) {
    'use strict';
    
    window.DraftOrderManager = {
        // Configuration
        config: {
            minPhoneDigits: 8,
            maxRetryAttempts: 3,
            retryDelay: 2000,
            lockTimeout: 30000,
            debounceDelay: 500
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
            sessionReset: false,
            lastPhoneCheck: null
        },
        
        // Éléments du DOM
        elements: {},
        
        /**
         * Initialiser le gestionnaire
         */
        init: function(formElements) {
            console.log('🔧 Initialisation du gestionnaire de commandes draft');
            
            this.elements = formElements || {};
            this.loadConfig();
            this.checkSessionReset();
            
            if (!this.state.sessionReset) {
                this.restoreState();
            }
            
            this.checkExistingDraftOrder();
            
            console.log('✅ Gestionnaire de commandes draft initialisé');
            
            return this;
        },
        
        /**
         * Charger la configuration
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
                        
                        if (parsedState && parsedState.draftOrderId && parsedState.timestamp) {
                            var now = new Date().getTime();
                            var age = now - parsedState.timestamp;
                            var maxAge = 24 * 60 * 60 * 1000; // 24 heures
                            
                            if (age < maxAge) {
                                this.state.draftOrderId = parsedState.draftOrderId;
                                this.state.draftProductId = parsedState.draftProductId;
                                
                                console.log('📋 État restauré:', this.state.draftOrderId);
                                
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
                    console.error('❌ Erreur restauration état:', e);
                    this.clearStoredState();
                }
            }
        },
        
        /**
         * Sauvegarder l'état
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
                    console.error('❌ Erreur sauvegarde état:', e);
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
                    console.error('❌ Erreur nettoyage état:', e);
                }
            }
        },
        
        /**
         * Vérifier s'il existe déjà un ID de commande draft
         */
        checkExistingDraftOrder: function() {
            if (this.elements.draftOrderIdField && this.elements.draftOrderIdField.val()) {
                var draftId = this.elements.draftOrderIdField.val();
                var productId = $('input[name="product_id"]').val();
                
                if (draftId && productId) {
                    console.log('🔍 Vérification draft existante:', draftId);
                    this.verifyDraftOrder(draftId, productId);
                }
            }
        },
        
        /**
         * Vérifier si une commande draft est valide
         */
        verifyDraftOrder: function(draftId, productId) {
            var self = this;
            
            if (!navigator.onLine) {
                console.log('⚠️ Hors ligne, vérification reportée');
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
                        console.log('✅ Draft valide:', draftId);
                        
                        self.state.draftOrderId = draftId;
                        self.state.draftProductId = productId;
                        
                        if (self.elements.draftOrderIdField) {
                            self.elements.draftOrderIdField.val(draftId);
                        }
                        
                        self.saveState();
                        
                        $(document).trigger('wc_opc_draft_order_verified', {
                            draft_order_id: draftId,
                            product_id: productId
                        });
                    } else {
                        console.log('❌ Draft invalide:', response.data.message);
                        self.resetState();
                    }
                },
                error: function(xhr, status, error) {
                    console.error('❌ Erreur vérification draft:', error);
                }
            });
        },
        
        /**
         * Créer une commande draft avec protection anti-doublons RENFORCÉE
         */
        createDraftOrder: function(phoneNumber, productId, callback) {
            // Protection anti-création multiple
            if (this.state.isCreating || this.state.creationLock) {
                console.log('⚠️ Création déjà en cours, requête ignorée');
                if (callback) callback(false, null);
                return;
            }
            
            // Si nous avons déjà une draft pour ce produit, l'utiliser
            if (this.state.draftOrderId && this.state.draftProductId === productId) {
                console.log('📋 Draft existante utilisée:', this.state.draftOrderId);
                if (callback) callback(true, this.state.draftOrderId);
                return;
            }
            
            // Vérifier la connectivité
            if (!navigator.onLine) {
                console.warn('⚠️ Création reportée - hors ligne');
                if (callback) callback(false, null);
                return;
            }
            
            // Débounce pour éviter trop de requêtes
            var now = Date.now();
            if (this.state.lastPhoneCheck && (now - this.state.lastPhoneCheck) < this.config.debounceDelay) {
                console.log('⚠️ Requête trop rapide, ignorée');
                if (callback) callback(false, null);
                return;
            }
            this.state.lastPhoneCheck = now;
            
            // Poser un verrou
            this.state.creationLock = true;
            
            if (this.state.creationLockTimeout) {
                clearTimeout(this.state.creationLockTimeout);
            }
            
            this.state.creationLockTimeout = setTimeout(function() {
                this.state.creationLock = false;
            }.bind(this), this.config.lockTimeout);
            
            console.log('🔄 Création draft pour produit:', productId);
            this.state.isCreating = true;
            
            var self = this;
            
            // Extraire les données nécessaires
            var quantity = this.elements.quantityField ? this.elements.quantityField.val() : 1;
            var bundleOption = $('input[name="bundle_option"]:checked').val() || '';
            
            // Créer la draft via AJAX
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
                    timestamp: new Date().getTime()
                },
                timeout: 15000, // 15 secondes max
                success: function(response) {
                    self.state.isCreating = false;
                    self.state.creationLock = false;
                    
                    if (response.success) {
                        console.log('✅ Draft créée/mise à jour:', response.data.draft_order_id);
                        
                        // Mettre à jour l'état
                        self.state.draftOrderId = response.data.draft_order_id;
                        self.state.draftProductId = productId;
                        self.state.retryCount = 0;
                        
                        // Mettre à jour le champ caché
                        if (self.elements.draftOrderIdField) {
                            self.elements.draftOrderIdField.val(response.data.draft_order_id);
                        }
                        
                        // Sauvegarder l'état
                        self.saveState();
                        
                        // Déclencher l'événement
                        $(document).trigger('wc_opc_draft_order_created', {
                            draft_order_id: response.data.draft_order_id,
                            product_id: productId,
                            is_new: response.data.is_new || false
                        });
                        
                        if (callback) callback(true, response.data.draft_order_id);
                    } else {
                        console.error('❌ Erreur création draft:', response.data.message);
                        self.handleError('create', phoneNumber, productId, callback);
                    }
                },
                error: function(xhr, status, error) {
                    self.state.isCreating = false;
                    self.state.creationLock = false;
                    
                    console.error('❌ Erreur réseau création draft:', error);
                    
                    // Gestion spéciale pour les timeouts et erreurs réseau
                    if (status === 'timeout' || xhr.status === 0) {
                        console.log('🔄 Timeout/Réseau - Retry dans 3s');
                        setTimeout(function() {
                            self.createDraftOrder(phoneNumber, productId, callback);
                        }, 3000);
                    } else {
                        self.handleError('create', phoneNumber, productId, callback);
                    }
                }
            });
        },
        
        /**
         * Mettre à jour une commande draft
         */
        updateDraftOrder: function(field, value, callback) {
            // Si pas de draft, mettre en attente
            if (!this.state.draftOrderId) {
                this.state.pendingUpdates[field] = value;
                console.log('⏳ Update en attente pour:', field);
                if (callback) callback(false);
                return;
            }
            
            // Si déjà en cours, mettre en attente
            if (this.state.isUpdating) {
                this.state.pendingUpdates[field] = value;
                console.log('⏳ Update en attente (en cours) pour:', field);
                if (callback) callback(false);
                return;
            }
            
            // Vérifier la connectivité
            if (!navigator.onLine) {
                this.state.pendingUpdates[field] = value;
                console.warn('⚠️ Update reportée - hors ligne');
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
                customer_name: this.elements.nameField ? this.elements.nameField.val() : '',
                customer_phone: this.elements.phoneField ? this.elements.phoneField.val() : '',
                customer_address: this.elements.addressField ? this.elements.addressField.val() : '',
                quantity: this.elements.quantityField ? this.elements.quantityField.val() : 1,
                bundle_option: $('input[name="bundle_option"]:checked').val() || ''
            };
            
            console.log('🔄 Mise à jour draft pour:', field);
            
            // Envoyer la mise à jour
            $.ajax({
                type: 'POST',
                url: wc_opc_params.ajax_url,
                data: formData,
                timeout: 10000,
                success: function(response) {
                    self.state.isUpdating = false;
                    
                    if (response.success) {
                        console.log('✅ Draft mise à jour pour:', field);
                        
                        $(document).trigger('wc_opc_draft_order_updated', {
                            draft_order_id: self.state.draftOrderId,
                            field_updated: field,
                            value: value
                        });
                        
                        // Traiter les updates en attente
                        self.processPendingUpdates();
                        
                        if (callback) callback(true);
                    } else {
                        console.error('❌ Erreur update draft:', response.data.message);
                        
                        // Si la draft n'existe plus, la recréer
                        if (response.data.code === 'invalid_order' || response.data.code === 'not_draft') {
                            console.log('🔄 Draft n\'existe plus, réinitialisation');
                            self.resetState();
                            self.state.pendingUpdates[field] = value;
                        } else {
                            // Retry plus tard
                            setTimeout(function() {
                                self.updateDraftOrder(field, value, callback);
                            }, 2000);
                        }
                        
                        if (callback) callback(false);
                    }
                },
                error: function(xhr, status, error) {
                    self.state.isUpdating = false;
                    
                    console.error('❌ Erreur réseau update draft:', error);
                    
                    // Mettre en attente
                    self.state.pendingUpdates[field] = value;
                    
                    if (callback) callback(false);
                }
            });
        },
        
        /**
         * Traiter les mises à jour en attente
         */
        processPendingUpdates: function() {
            var pendingFields = Object.keys(this.state.pendingUpdates);
            
            if (pendingFields.length > 0 && !this.state.isUpdating) {
                var field = pendingFields[0];
                var value = this.state.pendingUpdates[field];
                
                // Supprimer de la liste d'attente
                delete this.state.pendingUpdates[field];
                
                // Mettre à jour
                var self = this;
                this.updateDraftOrder(field, value, function() {
                    // Continuer avec les autres updates
                    if (Object.keys(self.state.pendingUpdates).length > 0) {
                        setTimeout(function() {
                            self.processPendingUpdates();
                        }, 500);
                    }
                });
            }
        },
        
        /**
         * Gérer les erreurs avec retry
         */
        handleError: function(operation, phoneNumber, productId, callback) {
            this.state.retryCount++;
            
            if (this.state.retryCount <= this.config.maxRetryAttempts) {
                console.log('🔄 Tentative ' + this.state.retryCount + '/' + this.config.maxRetryAttempts + ' pour ' + operation);
                
                var delay = Math.pow(2, this.state.retryCount - 1) * this.config.retryDelay;
                
                var self = this;
                setTimeout(function() {
                    if (operation === 'create') {
                        self.createDraftOrder(phoneNumber, productId, callback);
                    }
                }, delay);
            } else {
                console.error('❌ Abandon après ' + this.config.maxRetryAttempts + ' tentatives pour ' + operation);
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
            this.state.lastPhoneCheck = null;
            
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
        if ($('#wc_opc_checkout_form').length) {
            var formElements = {
                form: $('#wc_opc_checkout_form'),
                phoneField: $('#wc_opc_customer_phone'),
                nameField: $('#wc_opc_customer_name'),
                addressField: $('#wc_opc_customer_address'),
                quantityField: $('#wc_opc_quantity'),
                draftOrderIdField: $('#wc_opc_draft_order_id'),
                submitButton: $('#wc_opc_submit_button')
            };
            
            DraftOrderManager.init(formElements);
        }
    });
    
})(jQuery);