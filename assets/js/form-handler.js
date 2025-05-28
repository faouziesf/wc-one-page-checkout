/**
 * Gestionnaire de formulaire CORRIGÉ
 */
(function($) {
    'use strict';
    
    window.FormHandler = {
        // Configuration
        config: {
            minPhoneDigits: 8,
            debounceDelay: 800 // Augmenté pour éviter trop de requêtes
        },
        
        // État interne
        state: {
            isSubmitting: false,
            isValidPhone: false,
            timers: {},
            lastValidPhone: null
        },
        
        // Éléments du DOM
        elements: {},
        
        /**
         * Initialiser le gestionnaire
         */
        init: function(formElements) {
            console.log('🔧 Initialisation du gestionnaire de formulaire');
            
            this.elements = formElements || {};
            this.loadConfig();
            this.attachEventListeners();
            this.restoreFormData();
            
            console.log('✅ Gestionnaire de formulaire initialisé');
            
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
         * Attacher les écouteurs d'événements
         */
        attachEventListeners: function() {
            var self = this;
            
            // Gestionnaire pour le champ téléphone avec debounce renforcé
            if (this.elements.phoneField && this.elements.phoneField.length) {
                this.elements.phoneField.on('input paste keyup', function() {
                    // Annuler le timer précédent
                    if (self.state.timers.phone) {
                        clearTimeout(self.state.timers.phone);
                    }
                    
                    // Mise à jour immédiate du compteur
                    var phone = $(this).val();
                    var digits = self.countDigits(phone);
                    $('.wc-opc-digits-count').text(digits);
                    
                    // Vérification avec debounce pour la création de draft
                    self.state.timers.phone = setTimeout(function() {
                        self.handlePhoneInput();
                    }, self.config.debounceDelay);
                });
                
                // Validation immédiate quand le champ perd le focus
                this.elements.phoneField.on('blur', function() {
                    if (self.state.timers.phone) {
                        clearTimeout(self.state.timers.phone);
                    }
                    self.handlePhoneInput();
                });
            }
            
            // Gestionnaires pour les autres champs avec debounce
            if (this.elements.nameField && this.elements.nameField.length) {
                this.elements.nameField.on('change blur', function() {
                    self.updateDraftField('name', $(this).val());
                });
            }
            
            if (this.elements.addressField && this.elements.addressField.length) {
                this.elements.addressField.on('change blur', function() {
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
            $('.wc-opc-quantity-plus').on('click', function(e) {
                e.preventDefault();
                var quantityField = self.elements.quantityField;
                if (quantityField) {
                    var currentValue = parseInt(quantityField.val()) || 1;
                    quantityField.val(currentValue + 1).trigger('change');
                }
            });
            
            $('.wc-opc-quantity-minus').on('click', function(e) {
                e.preventDefault();
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
         * Gérer la saisie du numéro de téléphone CORRIGÉ
         */
        handlePhoneInput: function() {
            var phoneField = this.elements.phoneField;
            
            if (!phoneField) {
                return;
            }
            
            var phone = phoneField.val().trim();
            var digits = this.countDigits(phone);
            
            // Mise à jour du compteur
            $('.wc-opc-digits-count').text(digits);
            
            // Vérifier la validité
            var isValid = digits >= this.config.minPhoneDigits;
            this.state.isValidPhone = isValid;
            
            console.log('📞 Téléphone:', phone, '- Chiffres:', digits, '- Valide:', isValid);
            
            // Mettre à jour le message de validation
            if (isValid) {
                $('.wc-opc-phone-message').html('<span class="valid">✓ Numéro valide</span>');
                
                // Créer une draft SEULEMENT si le téléphone a changé
                if (this.state.lastValidPhone !== phone) {
                    this.state.lastValidPhone = phone;
                    
                    var productId = $('input[name="product_id"]').val();
                    if (window.DraftOrderManager && productId) {
                        console.log('🔄 Création/mise à jour draft pour:', phone);
                        
                        window.DraftOrderManager.createDraftOrder(phone, productId, function(success, draftId) {
                            if (success) {
                                console.log('✅ Draft prête:', draftId);
                            } else {
                                console.log('⚠️ Échec draft, continuera sans');
                            }
                        });
                    }
                }
                
                // Sauvegarder dans le stockage local
                this.saveFormData();
            } else {
                $('.wc-opc-phone-message').html('<span class="invalid">❌ Au moins ' + this.config.minPhoneDigits + ' chiffres requis</span>');
                this.state.lastValidPhone = null;
            }
        },
        
        /**
         * Gérer le changement d'option de bundle
         */
        handleBundleOptionChange: function(bundleOption) {
            console.log('🎁 Changement bundle:', bundleOption.val());
            
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
         * Gérer la soumission du formulaire CORRIGÉ
         */
        handleFormSubmit: function(e) {
            e.preventDefault();
            
            // Éviter les soumissions multiples
            if (this.state.isSubmitting) {
                console.log('⚠️ Soumission déjà en cours');
                return false;
            }
            
            // Vérifier le téléphone
            if (!this.state.isValidPhone) {
                this.showError(wc_opc_params.i18n.phone_invalid);
                this.elements.phoneField.focus();
                return false;
            }
            
            // Vérifier les champs obligatoires
            var customerName = this.elements.nameField ? this.elements.nameField.val().trim() : '';
            var customerAddress = this.elements.addressField ? this.elements.addressField.val().trim() : '';
            
            if (!customerName) {
                this.showError('Veuillez entrer votre nom');
                this.elements.nameField.focus();
                return false;
            }
            
            if (!customerAddress) {
                this.showError('Veuillez entrer votre adresse');
                this.elements.addressField.focus();
                return false;
            }
            
            // Marquer comme en cours de soumission
            this.state.isSubmitting = true;
            
            // Afficher l'indicateur de chargement
            this.showLoading(true);
            
            console.log('🚀 Soumission du formulaire...');
            
            // Récupérer les données du formulaire
            var formData = this.elements.form.serialize();
            
            // Récupérer l'ID de commande draft si disponible
            var draftOrderId = (window.DraftOrderManager && window.DraftOrderManager.state.draftOrderId) ? 
                window.DraftOrderManager.state.draftOrderId : 
                (this.elements.draftOrderIdField ? this.elements.draftOrderIdField.val() : '');
            
            // Ajouter l'ID de commande draft aux données
            if (draftOrderId) {
                formData += '&draft_order_id=' + draftOrderId;
                console.log('📋 Utilisation draft:', draftOrderId);
            } else {
                console.log('📝 Création directe sans draft');
            }
            
            var self = this;
            
            // Envoyer la requête AJAX
            $.ajax({
                type: 'POST',
                url: wc_opc_params.ajax_url,
                data: formData,
                timeout: 30000, // 30 secondes
                success: function(response) {
                    self.state.isSubmitting = false;
                    self.showLoading(false);
                    
                    if (response.success) {
                        console.log('✅ Commande créée:', response.data);
                        
                        // Nettoyer le stockage local
                        self.clearFormData();
                        
                        // Déclencher l'événement personnalisé
                        $(document).trigger('wc_opc_checkout_success', response.data);
                        
                        // Afficher le message de succès
                        self.showSuccess(wc_opc_params.i18n.order_success);
                        
                        // Attendre 2 secondes puis rediriger
                        setTimeout(function() {
                            if (response.data.redirect) {
                                window.location.href = response.data.redirect;
                            } else {
                                window.location.reload();
                            }
                        }, 2000);
                        
                    } else {
                        console.error('❌ Erreur création commande:', response.data.message);
                        self.showError(response.data.message || 'Une erreur est survenue');
                    }
                },
                error: function(xhr, status, error) {
                    self.state.isSubmitting = false;
                    self.showLoading(false);
                    
                    console.error('❌ Erreur AJAX:', error, 'Status:', status);
                    
                    var errorMessage = 'Une erreur de connexion est survenue. Veuillez réessayer.';
                    
                    if (status === 'timeout') {
                        errorMessage = 'La requête a pris trop de temps. Veuillez réessayer.';
                    } else if (xhr.status === 0) {
                        errorMessage = 'Problème de connexion internet. Vérifiez votre connexion.';
                    } else if (xhr.status >= 500) {
                        errorMessage = 'Erreur serveur. Veuillez réessayer dans quelques instants.';
                    }
                    
                    self.showError(errorMessage);
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
                
                if (show) {
                    this.elements.submitButton.text('Traitement...');
                } else {
                    this.elements.submitButton.text(wc_opc_params.i18n.button_text || 'Commander');
                }
            }
            
            $('.wc-opc-loading').toggle(show);
        },
        
        /**
         * Afficher un message d'erreur
         */
        showError: function(message) {
            this.showMessage(message, 'error');
        },
        
        /**
         * Afficher un message de succès
         */
        showSuccess: function(message) {
            this.showMessage(message, 'success');
        },
        
        /**
         * Afficher un message
         */
        showMessage: function(message, type) {
            // Nettoyer les anciens messages
            $('.wc-opc-messages').remove();
            
            // Créer le conteneur de messages
            var $messagesContainer = $('<div class="wc-opc-messages"></div>');
            this.elements.form.prepend($messagesContainer);
            
            // Créer le message
            var messageClass = 'wc-opc-message wc-opc-message-' + (type || 'info');
            var $message = $('<div class="' + messageClass + '">' + message + '</div>');
            
            // Ajouter le message
            $messagesContainer.append($message);
            
            // Scroll vers le message
            $('html, body').animate({
                scrollTop: $message.offset().top - 100
            }, 500);
            
            // Faire disparaître après 5 secondes (sauf pour les succès)
            if (type !== 'success') {
                setTimeout(function() {
                    $message.fadeOut(500, function() {
                        $(this).remove();
                        if ($messagesContainer.children().length === 0) {
                            $messagesContainer.remove();
                        }
                    });
                }, 5000);
            }
        },
        
        /**
         * Sauvegarder les données du formulaire
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
                    console.error('❌ Erreur sauvegarde formulaire:', e);
                }
            }
        },
        
        /**
         * Restaurer les données du formulaire
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
                                // Déclencher la validation après un délai
                                setTimeout(function() {
                                    this.handlePhoneInput();
                                }.bind(this), 1000);
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
                            
                            console.log('📋 Données formulaire restaurées');
                        } else {
                            this.clearFormData();
                        }
                    }
                } catch (e) {
                    console.error('❌ Erreur restauration formulaire:', e);
                }
            }
        },
        
        /**
         * Effacer les données du formulaire
         */
        clearFormData: function() {
            if (typeof(Storage) !== 'undefined') {
                try {
                    localStorage.removeItem('wc_opc_form_data');
                } catch (e) {
                    console.error('❌ Erreur nettoyage formulaire:', e);
                }
            }
        },
        
        /**
         * Compter le nombre de chiffres
         */
        countDigits: function(str) {
            return (str.match(/\d/g) || []).length;
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
            
            FormHandler.init(formElements);
        }
    });
    
})(jQuery);