/**
 * Gestionnaire de tracking JavaScript CORRIGÉ
 */
(function($) {
    'use strict';
    
    window.TrackingManager = {
        config: {
            enableTracking: true,
            debugMode: false,
            maxQueueSize: 20,
            queueProcessingInterval: 1000,
            retryAttempts: 3,
            retryDelay: 2000
        },
        
        state: {
            eventsSent: {
                addToCart: {},
                initiateCheckout: {},
                purchase: {}
            },
            queue: [],
            processing: false,
            fbPixelReady: false,
            sessionId: null,
            initialized: false
        },
        
        init: function() {
            if (this.state.initialized) {
                return this;
            }
            
            console.log('🔧 Initialisation tracking manager');
            
            this.loadConfig();
            this.state.sessionId = this.generateSessionId();
            this.checkPixelAvailability();
            this.restoreEventState();
            this.attachEventListeners();
            this.startQueueProcessor();
            
            this.state.initialized = true;
            
            console.log('✅ Tracking manager initialisé');
            
            return this;
        },
        
        loadConfig: function() {
            if (typeof wc_opc_params !== 'undefined') {
                this.config.debugMode = wc_opc_params.debug_mode === true || wc_opc_params.debug_mode === 'yes';
                this.config.enableTracking = wc_opc_params.enable_tracking !== 'no';
            }
        },
        
        generateSessionId: function() {
            return 'opc_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        },
        
        checkPixelAvailability: function() {
            var self = this;
            
            if (typeof fbq === 'function') {
                this.state.fbPixelReady = true;
                console.log('✅ Facebook Pixel détecté immédiatement');
                return;
            }
            
            var attempts = 0;
            var maxAttempts = 30; // Augmenté pour les connexions lentes
            
            var checkInterval = setInterval(function() {
                attempts++;
                
                if (typeof fbq === 'function') {
                    self.state.fbPixelReady = true;
                    console.log('✅ Facebook Pixel détecté après ' + attempts + ' tentatives');
                    clearInterval(checkInterval);
                    self.processQueue();
                } else if (attempts >= maxAttempts) {
                    console.warn('⚠️ Facebook Pixel non détecté après ' + maxAttempts + ' tentatives');
                    clearInterval(checkInterval);
                }
            }, 1000);
        },
        
        attachEventListeners: function() {
            var self = this;
            
            // Écouter la création de draft (AddToCart)
            $(document).on('wc_opc_draft_order_created', function(e, data) {
                if (self.config.debugMode) {
                    console.log('🎯 Draft order created:', data);
                }
                self.sendAddToCartEvent(data);
            });
            
            // Écouter le succès du checkout (InitiateCheckout + Purchase)
            $(document).on('wc_opc_checkout_success', function(e, data) {
                if (self.config.debugMode) {
                    console.log('🎯 Checkout success:', data);
                }
                self.sendCheckoutEvents(data);
            });
        },
        
        /**
         * Envoyer l'événement AddToCart
         */
        sendAddToCartEvent: function(data) {
            try {
                var productId = data.product_id || data.draft_product_id;
                var orderId = data.order_id || data.draft_order_id;
                
                if (!productId) {
                    console.error('❌ Product ID manquant pour AddToCart');
                    return;
                }
                
                var eventKey = productId + '_' + (orderId || 'draft') + '_addtocart';
                if (this.state.eventsSent.addToCart[eventKey]) {
                    console.log('⚠️ AddToCart déjà envoyé pour ' + eventKey);
                    return;
                }
                
                var eventData = this.prepareEventData(data, 'AddToCart');
                if (!eventData) {
                    console.error('❌ Impossible de préparer les données AddToCart');
                    return;
                }
                
                // ID unique pour l'événement
                eventData.event_id = 'opc_addtocart_' + productId + '_' + this.state.sessionId + '_' + Date.now();
                
                if (this.config.debugMode) {
                    console.log('📤 Préparation AddToCart:', eventData);
                }
                
                this.addToQueue('AddToCart', eventData, eventKey);
                
                this.state.eventsSent.addToCart[eventKey] = Date.now();
                this.saveEventState();
                
                console.log('✅ AddToCart préparé pour le produit ' + productId + ' - Valeur: ' + eventData.value);
                
            } catch (error) {
                console.error('❌ Erreur sendAddToCartEvent:', error);
            }
        },
        
        /**
         * Envoyer les événements de checkout
         */
        sendCheckoutEvents: function(data) {
            try {
                var productId = data.product_id;
                var orderId = data.order_id;
                
                if (!productId || !orderId) {
                    console.error('❌ Product ID ou Order ID manquant');
                    return;
                }
                
                var initiateKey = 'initiate_' + orderId + '_' + productId;
                var purchaseKey = 'purchase_' + orderId + '_' + productId;
                
                var eventData = this.prepareEventData(data, 'Purchase');
                if (!eventData) {
                    console.error('❌ Impossible de préparer les données checkout');
                    return;
                }
                
                if (this.config.debugMode) {
                    console.log('📤 Préparation checkout events:', eventData);
                }
                
                // InitiateCheckout
                if (!this.state.eventsSent.initiateCheckout[initiateKey]) {
                    var initiateData = Object.assign({}, eventData);
                    initiateData.event_id = 'opc_initiate_' + orderId + '_' + this.state.sessionId + '_' + Date.now();
                    
                    this.addToQueue('InitiateCheckout', initiateData, initiateKey);
                    this.state.eventsSent.initiateCheckout[initiateKey] = Date.now();
                    
                    console.log('✅ InitiateCheckout préparé pour ' + orderId + ' - Valeur: ' + initiateData.value);
                }
                
                // Purchase (avec délai)
                if (!this.state.eventsSent.purchase[purchaseKey]) {
                    var self = this;
                    setTimeout(function() {
                        var purchaseData = Object.assign({}, eventData);
                        purchaseData.event_id = 'opc_purchase_' + orderId + '_' + self.state.sessionId + '_' + Date.now();
                        
                        purchaseData.contents = [{
                            id: 'wc_post_id_' + productId,
                            quantity: 1
                        }];
                        
                        self.addToQueue('Purchase', purchaseData, purchaseKey);
                        self.state.eventsSent.purchase[purchaseKey] = Date.now();
                        self.saveEventState();
                        
                        console.log('✅ Purchase préparé pour ' + orderId + ' - Valeur: ' + purchaseData.value);
                    }, 1000);
                }
                
                this.saveEventState();
                
            } catch (error) {
                console.error('❌ Erreur sendCheckoutEvents:', error);
            }
        },
        
        /**
         * Préparer les données d'événement avec 50% du prix
         */
        prepareEventData: function(data, eventType) {
            try {
                var productId = data.product_id || data.draft_product_id;
                
                if (!productId) {
                    return null;
                }
                
                var price = 0;
                
                // Essayer de récupérer le prix total
                if (data.total_price) {
                    price = parseFloat(data.total_price);
                } else if (data.order_total) {
                    price = parseFloat(data.order_total);
                } else {
                    // Calcul depuis les options de bundle
                    var bundleOption = $('input[name="bundle_option"]:checked');
                    if (bundleOption.length && bundleOption.data('price')) {
                        price = parseFloat(bundleOption.data('price'));
                    } else if (typeof wc_opc_params !== 'undefined' && wc_opc_params.product) {
                        price = parseFloat(wc_opc_params.product.price) || 0;
                        var quantity = parseInt($('#wc_opc_quantity').val()) || 1;
                        price = price * quantity;
                    }
                }
                
                if (price <= 0) {
                    price = 1; // Prix minimum
                }
                
                // APPLIQUER 50% DU PRIX COMME DEMANDÉ
                var eventValue = price / 4;
                
                var eventData = {
                    value: Math.round(eventValue * 100) / 100, // Arrondir à 2 décimales
                    currency: (typeof wc_opc_params !== 'undefined' && wc_opc_params.product) ? 
                        wc_opc_params.product.currency : 'TND',
                    content_ids: ['wc_post_id_' + productId],
                    content_type: 'product',
                    content_name: (typeof wc_opc_params !== 'undefined' && wc_opc_params.product) ? 
                        wc_opc_params.product.name : document.title,
                    source: 'woocommerce-opc',
                    version: (typeof wc_opc_params !== 'undefined') ? wc_opc_params.version : '2.0.0',
                    pluginVersion: '2.0.0'
                };
                
                // Ajouter contents pour AddToCart et Purchase
                if (eventType === 'AddToCart' || eventType === 'Purchase') {
                    eventData.contents = [{
                        id: 'wc_post_id_' + productId,
                        quantity: 1
                    }];
                }
                
                // Catégories du produit
                if (typeof wc_opc_params !== 'undefined' && wc_opc_params.product && wc_opc_params.product.categories) {
                    eventData.content_category = wc_opc_params.product.categories;
                } else {
                    eventData.content_category = 'Tous les produits';
                }
                
                return eventData;
                
            } catch (error) {
                console.error('❌ Erreur prepareEventData:', error);
                return null;
            }
        },
        
        /**
         * Ajouter à la file d'attente
         */
        addToQueue: function(eventType, eventData, identifier) {
            try {
                // Vérifier les doublons
                var isDuplicate = this.state.queue.some(function(item) {
                    return item.type === eventType && item.identifier === identifier;
                });
                
                if (isDuplicate) {
                    console.log('⚠️ Événement déjà en file:', eventType, identifier);
                    return;
                }
                
                this.state.queue.push({
                    type: eventType,
                    data: eventData,
                    identifier: identifier,
                    timestamp: Date.now(),
                    attempts: 0
                });
                
                console.log('📤 Ajouté à la file:', eventType, identifier, '- Valeur:', eventData.value);
                
                // Limiter la taille de la file
                if (this.state.queue.length > this.config.maxQueueSize) {
                    this.state.queue = this.state.queue.slice(-this.config.maxQueueSize);
                }
                
                // Traiter immédiatement si possible
                this.processQueue();
                
            } catch (error) {
                console.error('❌ Erreur addToQueue:', error);
            }
        },
        
        /**
         * Traiter la file d'attente
         */
        processQueue: function() {
            if (this.state.queue.length === 0 || this.state.processing) {
                return;
            }
            
            if (!this.state.fbPixelReady) {
                console.log('⏳ Pixel non prêt, événements en attente');
                return;
            }
            
            this.state.processing = true;
            
            var event = this.state.queue.shift();
            var self = this;
            
            this.sendEvent(event.type, event.data, function(success) {
                self.state.processing = false;
                
                if (!success) {
                    event.attempts++;
                    
                    if (event.attempts < self.config.retryAttempts) {
                        console.log('🔄 Retry ' + event.type + ' (tentative ' + event.attempts + ')');
                        self.state.queue.unshift(event);
                        
                        setTimeout(function() {
                            self.processQueue();
                        }, self.config.retryDelay * event.attempts);
                    } else {
                        console.error('❌ Abandon ' + event.type + ' après ' + self.config.retryAttempts + ' tentatives');
                    }
                } else {
                    console.log('✅ ' + event.type + ' envoyé avec succès - Valeur: ' + event.data.value);
                }
                
                // Continuer avec les autres événements
                if (self.state.queue.length > 0) {
                    setTimeout(function() {
                        self.processQueue();
                    }, 500);
                }
            });
        },
        
        /**
         * Envoyer un événement
         */
        sendEvent: function(eventType, eventData, callback) {
            var self = this;
            var fbqSuccess = false;
            
            try {
                if (typeof fbq === 'function') {
                    // Nettoyer les données pour fbq
                    var fbqData = Object.assign({}, eventData);
                    delete fbqData.source;
                    delete fbqData.version;
                    delete fbqData.pluginVersion;
                    delete fbqData.event_id;
                    
                    // Envoyer via fbq
                    fbq('track', eventType, fbqData);
                    fbqSuccess = true;
                    
                    console.log('✅ ' + eventType + ' envoyé via fbq - Valeur: ' + fbqData.value + ' (50% de ' + (fbqData.value * 2) + ')');
                } else {
                    console.warn('⚠️ fbq non disponible pour ' + eventType);
                }
            } catch (e) {
                console.error('❌ Erreur fbq ' + eventType + ':', e);
            }
            
            if (callback) {
                callback(fbqSuccess);
            }
        },
        
        /**
         * Sauvegarder l'état des événements
         */
        saveEventState: function() {
            if (typeof(Storage) !== 'undefined') {
                try {
                    var stateToSave = {
                        eventsSent: this.state.eventsSent,
                        sessionId: this.state.sessionId,
                        timestamp: Date.now()
                    };
                    
                    // Limiter la taille du stockage
                    for (var eventType in stateToSave.eventsSent) {
                        var events = stateToSave.eventsSent[eventType];
                        var keys = Object.keys(events);
                        if (keys.length > 50) {
                            // Garder seulement les 30 plus récents
                            var sortedKeys = keys.sort(function(a, b) {
                                return events[b] - events[a];
                            }).slice(0, 30);
                            
                            var newEvents = {};
                            sortedKeys.forEach(function(key) {
                                newEvents[key] = events[key];
                            });
                            stateToSave.eventsSent[eventType] = newEvents;
                        }
                    }
                    
                    localStorage.setItem('wc_opc_tracking_state', JSON.stringify(stateToSave));
                } catch (e) {
                    console.error('❌ Erreur sauvegarde état tracking:', e);
                }
            }
        },
        
        /**
         * Restaurer l'état des événements
         */
        restoreEventState: function() {
            if (typeof(Storage) !== 'undefined') {
                try {
                    var storedState = localStorage.getItem('wc_opc_tracking_state');
                    if (storedState) {
                        var parsedState = JSON.parse(storedState);
                        
                        if (parsedState && parsedState.timestamp) {
                            var age = Date.now() - parsedState.timestamp;
                            var maxAge = 24 * 60 * 60 * 1000; // 24 heures
                            
                            if (age < maxAge && parsedState.eventsSent) {
                                this.state.eventsSent = parsedState.eventsSent;
                                console.log('📋 État des événements restauré');
                            } else {
                                this.resetEventState();
                            }
                        }
                    }
                } catch (e) {
                    console.error('❌ Erreur restauration état tracking:', e);
                    this.resetEventState();
                }
            }
        },
        
        /**
         * Réinitialiser l'état des événements
         */
        resetEventState: function() {
            this.state.eventsSent = {
                addToCart: {},
                initiateCheckout: {},
                purchase: {}
            };
            
            if (typeof(Storage) !== 'undefined') {
                try {
                    localStorage.removeItem('wc_opc_tracking_state');
                } catch (e) {
                    console.error('❌ Erreur nettoyage état tracking:', e);
                }
            }
            
            console.log('🔄 État des événements réinitialisé');
        },
        
        /**
         * Démarrer le processeur de file d'attente
         */
        startQueueProcessor: function() {
            var self = this;
            
            setInterval(function() {
                if (self.state.queue.length > 0 && !self.state.processing) {
                    self.processQueue();
                }
            }, this.config.queueProcessingInterval);
        }
    };
    
    // Initialiser automatiquement
    $(document).ready(function() {
        if ($('#wc_opc_checkout_form').length) {
            TrackingManager.init();
            
            // Vérification supplémentaire du pixel Facebook
            var fbqCheckCount = 0;
            var fbqCheckInterval = setInterval(function() {
                fbqCheckCount++;
                
                if (typeof fbq === 'function' && !TrackingManager.state.fbPixelReady) {
                    TrackingManager.state.fbPixelReady = true;
                    console.log('✅ Facebook Pixel détecté après init');
                    TrackingManager.processQueue();
                }
                
                if (fbqCheckCount >= 30) {
                    clearInterval(fbqCheckInterval);
                    if (!TrackingManager.state.fbPixelReady) {
                        console.warn('⚠️ Facebook Pixel non détecté après 30 secondes');
                    }
                }
            }, 1000);
        }
    });
    
})(jQuery);