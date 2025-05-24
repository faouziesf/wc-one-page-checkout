/**
 * Gestionnaire de tracking
 * 
 * Gère l'envoi d'événements de tracking (Facebook, etc.)
 * avec des mécanismes pour éviter les duplications.
 */
(function($) {
    'use strict';
    
    // Gestionnaire de tracking
    window.TrackingManager = {
        // Configuration
        config: {
            enableFacebook: true,
            enableLocalStorage: true,
            maxQueueSize: 20,
            queueProcessingInterval: 1000
        },
        
        // État interne
        state: {
            eventsSent: {
                addToCart: false,
                initiateCheckout: false,
                purchase: false
            },
            queue: [],
            processing: false,
            fbPixelReady: false
        },
        
        /**
         * Initialiser le gestionnaire
         */
        init: function() {
            console.log('🔧 Initialisation du gestionnaire de tracking');
            
            // Vérifier si Facebook Pixel est disponible
            this.checkPixelAvailability();
            
            // Restaurer les événements envoyés
            this.restoreEventState();
            
            // Attacher les écouteurs d'événements
            this.attachEventListeners();
            
            // Traiter la file d'attente
            this.startQueueProcessor();
            
            console.log('✅ Gestionnaire de tracking initialisé');
            
            return this;
        },
        
        /**
         * Vérifier si Facebook Pixel est disponible
         */
        checkPixelAvailability: function() {
            // Vérifier si fbq est défini
            if (typeof fbq === 'function') {
                this.state.fbPixelReady = true;
                console.log('✅ Facebook Pixel détecté');
            } else {
                console.log('⏳ Facebook Pixel non détecté, en attente...');
                
                // Vérifier périodiquement pendant 10 secondes
                var self = this;
                var attempts = 0;
                var checkInterval = setInterval(function() {
                    attempts++;
                    
                    if (typeof fbq === 'function') {
                        self.state.fbPixelReady = true;
                        console.log('✅ Facebook Pixel détecté après ' + attempts + ' tentatives');
                        clearInterval(checkInterval);
                        
                        // Traiter la file d'attente
                        self.processQueue();
                    } else if (attempts >= 10) {
                        console.warn('⚠️ Facebook Pixel non détecté après 10 tentatives');
                        clearInterval(checkInterval);
                    }
                }, 1000);
            }
        },
        
        /**
         * Restaurer l'état des événements envoyés
         */
        restoreEventState: function() {
            if (this.config.enableLocalStorage && typeof(Storage) !== 'undefined') {
                try {
                    var storedState = localStorage.getItem('wc_opc_tracking_state');
                    if (storedState) {
                        var parsedState = JSON.parse(storedState);
                        
                        if (parsedState && parsedState.eventsSent) {
                            this.state.eventsSent = parsedState.eventsSent;
                            console.log('📋 État des événements restauré:', this.state.eventsSent);
                        }
                    }
                } catch (e) {
                    console.error('❌ Erreur lors de la restauration de l\'état des événements:', e);
                }
            }
        },
        
        /**
         * Sauvegarder l'état des événements envoyés
         */
        saveEventState: function() {
            if (this.config.enableLocalStorage && typeof(Storage) !== 'undefined') {
                try {
                    localStorage.setItem('wc_opc_tracking_state', JSON.stringify({
                        eventsSent: this.state.eventsSent,
                        timestamp: new Date().getTime()
                    }));
                } catch (e) {
                    console.error('❌ Erreur lors de la sauvegarde de l\'état des événements:', e);
                }
            }
        },
        
        /**
         * Attacher les écouteurs d'événements
         */
        attachEventListeners: function() {
            var self = this;
            
            // Écouter l'événement de création de commande draft
            $(document).on('wc_opc_draft_order_created', function(e, data) {
                self.sendAddToCartEvent(data);
            });
            
            // Écouter l'événement de succès de checkout
            $(document).on('wc_opc_checkout_success', function(e, data) {
                self.sendCheckoutEvents(data);
            });
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
        },
        
        /**
         * Ajouter un événement à la file d'attente
         */
        addToQueue: function(eventType, eventData) {
            // Éviter les doublons dans la file d'attente
            var isDuplicate = this.state.queue.some(function(item) {
                return item.type === eventType;
            });
            
            if (isDuplicate) {
                console.log('⚠️ Événement déjà dans la file d\'attente:', eventType);
                return;
            }
            
            // Ajouter à la file d'attente
            this.state.queue.push({
                type: eventType,
                data: eventData,
                timestamp: new Date().getTime(),
                attempts: 0
            });
            
            console.log('📤 Événement ajouté à la file d\'attente:', eventType);
            
            // Limiter la taille de la file d'attente
            if (this.state.queue.length > this.config.maxQueueSize) {
                this.state.queue = this.state.queue.slice(-this.config.maxQueueSize);
            }
            
            // Essayer de traiter la file d'attente immédiatement
            this.processQueue();
        },
        
        /**
         * Traiter la file d'attente d'événements
         */
        processQueue: function() {
            // Si pas d'événements ou déjà en cours de traitement
            if (this.state.queue.length === 0 || this.state.processing) {
                return;
            }
            
            // Si Pixel n'est pas prêt
            if (!this.state.fbPixelReady) {
                console.log('⏳ Pixel non prêt, traitement reporté');
                return;
            }
            
            this.state.processing = true;
            
            // Récupérer le premier événement de la file
            var event = this.state.queue.shift();
            
            // Envoyer l'événement
            this.sendEvent(event.type, event.data, function(success) {
                this.state.processing = false;
                
                if (!success) {
                    // En cas d'échec, remettre dans la file d'attente si pas trop de tentatives
                    event.attempts++;
                    
                    if (event.attempts < 3) {
                        this.state.queue.unshift(event);
                    } else {
                        console.error('❌ Abandon de l\'événement après 3 tentatives:', event.type);
                    }
                }
                
                // Traiter l'événement suivant s'il y en a
                if (this.state.queue.length > 0) {
                    setTimeout(function() {
                        this.processQueue();
                    }.bind(this), 500); // Petite pause entre les événements
                }
            }.bind(this));
        },
        
        /**
         * Envoyer un événement de tracking
         */
        sendEvent: function(eventType, eventData, callback) {
            // Si Facebook Pixel est disponible
            if (this.config.enableFacebook && typeof fbq === 'function') {
                try {
                    // Envoyer l'événement à Facebook
                    fbq('track', eventType, eventData);
                    
                    console.log('✅ Événement ' + eventType + ' envoyé à Facebook');
                    
                    // Marquer comme envoyé
                    if (eventType === 'AddToCart') this.state.eventsSent.addToCart = true;
                    if (eventType === 'InitiateCheckout') this.state.eventsSent.initiateCheckout = true;
                    if (eventType === 'Purchase') this.state.eventsSent.purchase = true;
                    
                    // Sauvegarder l'état
                    this.saveEventState();
                    
                    if (callback) callback(true);
                } catch (e) {
                    console.error('❌ Erreur lors de l\'envoi de l\'événement à Facebook:', e);
                    if (callback) callback(false);
                }
            } else {
                console.warn('⚠️ Facebook Pixel non disponible');
                if (callback) callback(false);
            }
        },
        
        /**
        * Envoyer l'événement AddToCart
        */
       sendAddToCartEvent: function(data) {
           // Éviter les doublons
           if (this.state.eventsSent.addToCart) {
               console.log('⚠️ Événement AddToCart déjà envoyé, ignoré');
               return;
           }
           
           // Préparer les données
           var eventData = this.prepareEventData(data);
           
           // Ajouter à la file d'attente
           this.addToQueue('AddToCart', eventData);
       },
       
       /**
        * Envoyer les événements de checkout (InitiateCheckout et Purchase)
        */
       sendCheckoutEvents: function(data) {
           // Préparer les données (les mêmes pour les deux événements)
           var eventData = this.prepareEventData(data);
           
           // Envoyer InitiateCheckout si pas déjà envoyé
           if (!this.state.eventsSent.initiateCheckout) {
               this.addToQueue('InitiateCheckout', eventData);
           }
           
           // Envoyer Purchase si pas déjà envoyé (après un petit délai)
           if (!this.state.eventsSent.purchase) {
               var self = this;
               setTimeout(function() {
                   self.addToQueue('Purchase', eventData);
               }, 500);
           }
       },
       
       /**
        * Préparer les données d'événement
        */
       prepareEventData: function(data) {
           // Récupérer le produit et le prix
           var productId = data.product_id || $('input[name="product_id"]').val();
           var price = 0;
           
           // Essayer d'obtenir le prix du bundle si applicable
           var bundleOption = $('input[name="bundle_option"]:checked');
           if (bundleOption.length && bundleOption.data('price')) {
               price = parseFloat(bundleOption.data('price'));
           } else {
               // Sinon, récupérer depuis les données produit de wc_opc_params
               if (typeof wc_opc_params !== 'undefined' && wc_opc_params.product) {
                   price = parseFloat(wc_opc_params.product.price) || 0;
               }
               
               // Multiplier par la quantité
               var quantity = parseInt($('#wc_opc_quantity').val()) || 1;
               price = price * quantity;
           }
           
           // Utiliser la moitié du prix pour les événements Facebook (comme dans l'ancienne version)
           var halfPrice = price / 2;
           
           // Préparer les données pour Facebook
           return {
               value: halfPrice,
               currency: (typeof wc_opc_params !== 'undefined' && wc_opc_params.product) ? wc_opc_params.product.currency : 'TND',
               content_ids: ['product_' + productId],
               content_type: 'product',
               content_name: (typeof wc_opc_params !== 'undefined' && wc_opc_params.product) ? wc_opc_params.product.name : document.title
           };
       },
       
       /**
        * Réinitialiser l'état des événements envoyés
        */
       resetEventState: function() {
           this.state.eventsSent = {
               addToCart: false,
               initiateCheckout: false,
               purchase: false
           };
           
           this.saveEventState();
           
           console.log('🔄 État des événements réinitialisé');
       }
   };
   
   // Initialiser quand le document est prêt
   $(document).ready(function() {
       // Vérifier si le formulaire existe
       if ($('#wc_opc_checkout_form').length) {
           // Initialiser le gestionnaire de tracking
           TrackingManager.init();
       }
   });
   
})(jQuery);