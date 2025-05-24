/**
 * Gestionnaire de formulaire
 * 
 * Gère les interactions avec le formulaire de checkout
 * et la validation des champs.
 */
(function($) {
    'use strict';
    
    // Gestionnaire de formulaire
    window.FormHandler = {
        // Configuration
        config: {
            minPhoneDigits: 8,
            debounceDelay: 300
        },
        
        // État interne
        state: {
            isSubmitting: false,
            isValidPhone: false,
            timers: {}
        },
        
        // Éléments du DOM
        elements: {},
        
        /**
         * Initialiser le gestionnaire
         */
        init: function(formElements) {
            console.log('🔧 Initialisation du gestionnaire de formulaire');
            
            // Stocker les éléments du formulaire
            this.elements = formElements || {};
            
            // Charger la configuration depuis les paramètres
            this.loadConfig();
            
            // Attacher les écouteurs d'événements
            this.attachEventListeners();
            
            // Restaurer les données du formulaire si disponibles
            this.restoreFormData();
            
            console.log('✅ Gestionnaire de formulaire initialisé');
            
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
         * Attacher les écouteurs d'événements
         */
        attachEventListeners: function() {
            var self = this;
            
            // Gestionnaire pour le champ téléphone avec debounce
            if (this.elements.phoneField && this.elements.phoneField.length) {
                this.elements.phoneField.on('input', function() {
                    // Annuler le timer précédent
                    if (self.state.timers.phone) {
                        clearTimeout(self.state.timers.phone);
                    }
                    
                    // Définir un nouveau timer
                    self.state.timers.phone = setTimeout(function() {
                        self.handlePhoneInput();
                    }, self.config.debounceDelay);
                });
            }
            
            // Gestionnaire pour les champs qui nécessitent une mise à jour de commande draft
            if (this.elements.nameField && this.elements.nameField.length) {
                this.elements.nameField.on('change', function() {
                    self.updateDraftField('name', $(this).val());
                });
            }
            
            if (this.elements.addressField && this.elements.addressField.length) {
                this.elements.addressField.on('change', function() {
                    self.updateDraftField('address', $(this).val());
                });
            }
            
            if (this.elements.quantityField && this.elements.quantityField.length) {
                this.elements.quantityField.on('change', function() {
                    self.updateDraftField('quantity', $(this).val());
                });
            }
            
            // Écouteur pour les options de bundle
            $('body').on('change', 'input[name="bundle_option"]', function() {
                self.handleBundleOptionChange($(this));
            });
            
            // Gestion de la soumission du formulaire
            if (this.elements.form && this.elements.form.length) {
                this.elements.form.on('submit', function(e) {
                    return self.handleFormSubmit(e);
                });
            }
            
            // Boutons plus/moins pour la quantité
            $('.wc-opc-quantity-plus').on('click', function() {
                var quantityField = self.elements.quantityField;
                if (quantityField) {
                    var currentValue = parseInt(quantityField.val()) || 1;
                    quantityField.val(currentValue + 1).trigger('change');
                }
            });
            
            $('.wc-opc-quantity-minus').on('click', function() {
                var quantityField = self.elements.quantityField;
                if (quantityField) {
                    var currentValue = parseInt(quantityField.val()) || 1;
                    if (currentValue > 1) {
                        quantityField.val(currentValue - 1).trigger('change');
                    }
                }
            });
        },
        
        /**
         * Gérer la saisie du numéro de téléphone
         */
        handlePhoneInput: function() {
            var phoneField = this.elements.phoneField;
            
            if (!phoneField) {
                return;
            }
            
            var phone = phoneField.val();
            var digits = this.countDigits(phone);
            
            // Mettre à jour le compteur si présent
            $('.wc-opc-digits-count').text(digits);
            
            // Vérifier la validité
            var isValid = digits >= this.config.minPhoneDigits;
            this.state.isValidPhone = isValid;
            
            // Mettre à jour le message de validation
            if (isValid) {
                $('.wc-opc-phone-message').html('<span class="valid">Numéro valide</span>');
                
                // Créer une commande draft
                var productId = $('input[name="product_id"]').val();
                if (window.DraftOrderManager && productId) {
                    window.DraftOrderManager.createDraftOrder(phone, productId);
                }
                
                // Sauvegarder dans le stockage local
                this.saveFormData();
            } else {
                $('.wc-opc-phone-message').html('<span class="invalid">Numéro invalide</span>');
            }
        },
        
        /**
         * Gérer le changement d'option de bundle
         */
        handleBundleOptionChange: function(bundleOption) {
            // Mettre à jour la quantité si nécessaire
            var quantity = bundleOption.data('quantity');
            
            if (quantity && this.elements.quantityField) {
                this.elements.quantityField.val(quantity).prop('readonly', true);
                this.updateDraftField('quantity', quantity);
            } else if (this.elements.quantityField) {
                this.elements.quantityField.prop('readonly', false);
            }
            
            // Mettre à jour la commande draft
            this.updateDraftField('bundle', bundleOption.val());
            
            // Sauvegarder dans le stockage local
            this.saveFormData();
        },
        
        /**
         * Mettre à jour un champ dans la commande draft
         */
        updateDraftField: function(field, value) {
            // Si le gestionnaire de commandes draft est disponible
            if (window.DraftOrderManager) {
                window.DraftOrderManager.updateDraftOrder(field, value);
            }
            
            // Sauvegarder dans le stockage local
            this.saveFormData();
        },
        
        /**
         * Gérer la soumission du formulaire
         */
        handleFormSubmit: function(e) {
            // Empêcher la soumission par défaut
            e.preventDefault();
            
            // Éviter les soumissions multiples
            if (this.state.isSubmitting) {
                return false;
            }
            
            // Vérifier le téléphone
            if (!this.state.isValidPhone) {
                alert(wc_opc_params.i18n.phone_invalid);
                this.elements.phoneField.focus();
                return false;
            }
            
            // Marquer comme en cours de soumission
            this.state.isSubmitting = true;
            
            // Afficher l'indicateur de chargement
            this.showLoading(true);
            
            // Récupérer les données du formulaire
            var formData = this.elements.form.serialize();
            
            // Récupérer l'ID de commande draft si disponible
            var draftOrderId = (window.DraftOrderManager && window.DraftOrderManager.state.draftOrderId) ? 
                window.DraftOrderManager.state.draftOrderId : 
                (this.elements.draftOrderIdField ? this.elements.draftOrderIdField.val() : '');
            
            // Ajouter l'ID de commande draft aux données
            if (draftOrderId) {
                formData += '&draft_order_id=' + draftOrderId;
            }
            
            var self = this;
            
            // Envoyer la requête AJAX
            $.ajax({
                type: 'POST',
                url: wc_opc_params.ajax_url,
                data: formData,
                success: function(response) {
                    self.state.isSubmitting = false;
                    self.showLoading(false);
                    
                    if (response.success) {
                        console.log('✅ Commande créée avec succès:', response.data);
                        
                        // Nettoyer le stockage local
                        self.clearFormData();
                        
                        // Déclencher un événement personnalisé
                        $(document).trigger('wc_opc_checkout_success', response.data);
                        
                        // Afficher le message de succès
                        alert(wc_opc_params.i18n.order_success);
                        
                        // Rediriger vers la page de confirmation
                        if (response.data.redirect) {
                            window.location.href = response.data.redirect;
                        } else {
                            window.location.reload();
                        }
                    } else {
                        console.error('❌ Erreur lors de la création de commande:', response.data.message);
                        
                        // Afficher le message d'erreur
                        alert(response.data.message);
                    }
                },
                error: function(xhr, status, error) {
                    self.state.isSubmitting = false;
                    self.showLoading(false);
                    
                    console.error('❌ Erreur AJAX lors de la création de commande:', error);
                    
                    // Afficher un message d'erreur générique
                    alert('Une erreur est survenue. Veuillez réessayer.');
                }
            });
            
            return false;
        },
        
        /**
         * Afficher/masquer l'indicateur de chargement
         */
        showLoading: function(show) {
            if (this.elements.submitButton) {
                this.elements.submitButton.prop('disabled', show);
            }
            
            $('.wc-opc-loading').toggle(show);
        },
        
        /**
         * Sauvegarder les données du formulaire dans le stockage local
         */
        saveFormData: function() {
            if (typeof(Storage) !== 'undefined') {
                try {
                    var formData = {
                        name: this.elements.nameField ? this.elements.nameField.val() : '',
                        phone: this.elements.phoneField ? this.elements.phoneField.val() : '',
                        address: this.elements.addressField ? this.elements.addressField.val() : '',
                        quantity: this.elements.quantityField ? this.elements.quantityField.val() : 1,
                        bundle: $('input[name="bundle_option"]:checked').val() || '',
                        timestamp: new Date().getTime()
                    };
                    
                    localStorage.setItem('wc_opc_form_data', JSON.stringify(formData));
                } catch (e) {
                    console.error('❌ Erreur lors de la sauvegarde des données du formulaire:', e);
                }
            }
        },
        
        /**
         * Restaurer les données du formulaire depuis le stockage local
         */
        restoreFormData: function() {
            if (typeof(Storage) !== 'undefined') {
                try {
                    var storedData = localStorage.getItem('wc_opc_form_data');
                    if (storedData) {
                        var formData = JSON.parse(storedData);
                        
                        // Vérifier si les données ne sont pas trop anciennes (24h)
                        var now = new Date().getTime();
                        if (formData.timestamp && (now - formData.timestamp) < 86400000) {
                            // Remplir les champs
                            if (this.elements.nameField && formData.name) {
                                this.elements.nameField.val(formData.name);
                            }
                            
                            if (this.elements.phoneField && formData.phone) {
                                this.elements.phoneField.val(formData.phone);
                                // Déclencher la validation
                                setTimeout(function() {
                                    this.handlePhoneInput();
                                }.bind(this), 500);
                            }
                            
                            if (this.elements.addressField && formData.address) {
                                this.elements.addressField.val(formData.address);
                            }
                            
                            if (this.elements.quantityField && formData.quantity) {
                                this.elements.quantityField.val(formData.quantity);
                            }
                            
                            if (formData.bundle) {
                                var bundleOption = $('input[name="bundle_option"][value="' + formData.bundle + '"]');
                                if (bundleOption.length) {
                                    bundleOption.prop('checked', true);
                                    this.handleBundleOptionChange(bundleOption);
                                }
                            }
                            
                            console.log('📋 Données du formulaire restaurées depuis le stockage local');
                        } else {
                            // Données trop anciennes, nettoyer
                            this.clearFormData();
                        }
                    }
                } catch (e) {
                    console.error('❌ Erreur lors de la restauration des données du formulaire:', e);
                }
            }
        },
        
        /**
         * Effacer les données du formulaire du stockage local
         */
        clearFormData: function() {
            if (typeof(Storage) !== 'undefined') {
                try {
                    localStorage.removeItem('wc_opc_form_data');
                } catch (e) {
                    console.error('❌ Erreur lors du nettoyage des données du formulaire:', e);
                }
            }
        },
        
        /**
         * Compter le nombre de chiffres dans une chaîne
         */
        countDigits: function(str) {
            return (str.match(/\d/g) || []).length;
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
            FormHandler.init(formElements);
        }
    });
    
})(jQuery);