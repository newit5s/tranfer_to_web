/**
 * Restaurant Booking - Modern Frontend JavaScript
 * Handles the new unified booking experience
 */

(function($) {
    'use strict';

    const RBBookingNew = {
        modal: null,
        isInline: false,
        focusTrapNamespace: '.rbBookingFocusTrap',
        focusableSelectors: 'a[href], area[href], input:not([disabled]):not([type="hidden"]):not([tabindex="-1"]), select:not([disabled]):not([tabindex="-1"]), textarea:not([disabled]):not([tabindex="-1"]), button:not([disabled]):not([tabindex="-1"]), [tabindex]:not([tabindex="-1"])',
        lastActiveElement: null,
        boundFocusinHandler: null,
        currentStep: 1,
        selectedData: {},
        currentLanguage: '',
        availableSlots: [],
        defaultDate: '',
        minDate: '',
        maxDate: '',
        $dateInput: null,
        $availabilityForm: null,
        isInitializingSlots: false,
        
        // Localized strings (will be populated by WordPress)
        strings: window.rbBookingStrings || {
            checking: 'Checking...',
            processing: 'Processing...',
            selectTime: 'Select time',
            people: 'people',
            connectionError: 'Connection error. Please try again.',
            securityError: 'Security check failed',
            fillRequired: 'Please fill in all required fields',
            suggestedTimes: 'Suggested Times',
            back: 'Back',
            continue: 'Continue',
            checkingAvailability: 'Check Availability',
            confirmBooking: 'Confirm Booking',
            invalidEmail: 'Please enter a valid email address',
            invalidPhone: 'Please enter a valid phone number',
            languageSwitching: 'Switching language…',
            languageSwitched: 'Language switched',
            languageSwitchFailed: 'Unable to switch language. Please try again.',
            advanceNoteGeneric: 'If you would like to book within 2 hours or cannot select a time slot, please contact the restaurant hotline or email for assistance.',
            advanceNoteHotlineOnly: 'If you would like to book within 2 hours or cannot select a time slot, please call %1$s for assistance.',
            advanceNoteEmailOnly: 'If you would like to book within 2 hours or cannot select a time slot, please email %1$s for assistance.',
            advanceNoteHotlineEmail: 'If you would like to book within 2 hours or cannot select a time slot, please contact us at %1$s or %2$s for assistance.'
        },

        init: function() {
            this.modal = $('#rb-new-booking-modal');

            if (!this.modal.length) {
                return;
            }

            this.$dateInput = $('#rb-new-date');
            this.$availabilityForm = $('#rb-new-availability-form');

            if (this.$dateInput && this.$dateInput.length) {
                this.defaultDate = this.$dateInput.data('defaultDate')
                    || this.$dateInput.val()
                    || this.$dateInput.attr('value')
                    || this.$dateInput.attr('min')
                    || '';
                this.minDate = this.$dateInput.data('minDate') || this.$dateInput.attr('min') || '';
                this.maxDate = this.$dateInput.data('maxDate') || this.$dateInput.attr('max') || '';

                if (this.defaultDate) {
                    this.$dateInput.val(this.defaultDate);
                    this.$dateInput.attr('value', this.defaultDate);
                }
            }

            this.isInline = this.modal.data('inline-mode') === 1 || this.modal.hasClass('rb-new-modal-inline');
            this.currentLanguage = $('#rb-new-hidden-language').val() || (window.rbBookingAjax && window.rbBookingAjax.currentLanguage) || '';
            if (!this.currentLanguage) {
                this.currentLanguage = $('.rb-new-lang-select').first().val() || '';
            }
            this.stepperItems = this.modal.find('.rb-new-stepper__item');
            const $successMessage = this.modal.find('#rb-new-success-message');
            if ($successMessage.length) {
                $successMessage.data('default-message', $successMessage.text());
            }
            this.bindEvents();
            this.setInitialValues();
            this.updateLocationMeta();
            this.preventBodyScroll();
            this.updateStepper(1);
        },

        bindEvents: function() {
            // Open modal
            $(document).on('click', '.rb-new-open-modal-btn', this.openModal.bind(this));
            
            // Close modal
            $(document).on('click', '.rb-new-close', this.closeModal.bind(this));
            $(document).on('click', '.rb-new-modal', this.handleModalClick.bind(this));

            // Language switcher
            $(document).on('change', '.rb-new-lang-select', this.changeLanguage.bind(this));

            // Availability form
            $(document).on('submit', '#rb-new-availability-form', this.checkAvailability.bind(this));

            // Booking form
            $(document).on('submit', '#rb-new-booking-form', this.submitBooking.bind(this));

            // Back button
            $(document).on('click', '#rb-new-back-btn', this.goBackToStep1.bind(this));

            // Date/location change - update time slots
            $(document).on('change', '#rb-new-date, #rb-new-guests', this.updateTimeSlots.bind(this));
            $(document).on('change', '#rb-new-location', () => {
                this.updateLocationMeta();
                this.updateTimeSlots();
            });

            $(document).on('click', '.rb-new-date-trigger', this.handleDateTriggerClick.bind(this));

            // Check-in change - update checkout options
            $(document).on('change', '#rb-new-time', this.updateCheckoutSelect.bind(this));

            // Suggestion buttons
            $(document).on('click', '.rb-new-suggestion-btn', this.selectSuggestedTime.bind(this));

            // Keyboard navigation
            $(document).on('keydown', this.handleKeydown.bind(this));

            // Restart booking
            $(document).on('click', '.rb-new-restart-btn', this.handleRestart.bind(this));

            $(document).on('input', '#rb-new-customer-phone', this.handlePhoneInput.bind(this));
        },

        setInitialValues: function(isReset) {
            if (isReset && this.$availabilityForm && this.$availabilityForm.length) {
                const formElement = this.$availabilityForm[0];
                if (formElement) {
                    formElement.reset();
                }
            }

            if (this.$dateInput && this.$dateInput.length) {
                const defaultDate = this.defaultDate || this.$dateInput.attr('value') || this.$dateInput.attr('min');
                if (defaultDate) {
                    this.$dateInput.val(defaultDate);
                    this.$dateInput.attr('value', defaultDate);
                }
            }

            if (isReset) {
                const $timeSelect = $('#rb-new-time');
                if ($timeSelect.length) {
                    $timeSelect.empty().append(`<option value="">${this.strings.selectTime}</option>`);
                }
            }

            this.refreshGuestSelect();
            this.isInitializingSlots = true;
            this.updateTimeSlots();
        },

        preventBodyScroll: function() {
            if (this.isInline) {
                return;
            }

            // Prevent body scroll when modal is open
            this.modal.on('show', function() {
                $('body').addClass('rb-modal-open');
            });

            this.modal.on('hide', function() {
                $('body').removeClass('rb-modal-open');
            });
        },

        handleDateTriggerClick: function(event) {
            event.preventDefault();

            const $button = $(event.currentTarget);
            const $input = $button.siblings('input[type="date"]').first();

            if (!$input.length) {
                return;
            }

            const inputElement = $input[0];

            if (typeof inputElement.showPicker === 'function') {
                try {
                    inputElement.showPicker();
                    return;
                } catch (error) {
                    // Fallback to focusing if showPicker throws
                }
            }

            $input.trigger('focus');

            // Attempt to hint browsers without showPicker support
            try {
                inputElement.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
                inputElement.dispatchEvent(new MouseEvent('mouseup', { bubbles: true }));
                inputElement.dispatchEvent(new MouseEvent('click', { bubbles: true }));
            } catch (error) {
                // If dispatching fails, the focused input still allows manual date entry
            }
        },

        getFocusableElements: function() {
            if (!this.modal || !this.modal.length) {
                return $();
            }

            return this.modal
                .find(this.focusableSelectors)
                .filter(':visible');
        },

        focusFirstElement: function() {
            const focusable = this.getFocusableElements();
            if (focusable.length) {
                focusable.first().trigger('focus');
            }
        },

        enableFocusTrap: function() {
            if (this.isInline || !this.modal.length) {
                return;
            }

            this.disableFocusTrap();

            this.boundFocusinHandler = (event) => {
                if (!this.modal.hasClass('show')) {
                    return;
                }

                if (!this.modal[0].contains(event.target)) {
                    this.focusFirstElement();
                }
            };

            $(document).on('focusin' + this.focusTrapNamespace, this.boundFocusinHandler);

            this.boundKeydownHandler = (event) => {
                if (!this.modal.hasClass('show') || event.key !== 'Tab') {
                    return;
                }

                const focusable = this.getFocusableElements();
                if (!focusable.length) {
                    return;
                }

                const first = focusable.first()[0];
                const last = focusable.last()[0];
                const active = document.activeElement;

                if (event.shiftKey) {
                    if (active === first || !this.modal[0].contains(active)) {
                        event.preventDefault();
                        last.focus();
                    }
                } else if (active === last) {
                    event.preventDefault();
                    first.focus();
                }
            };

            this.modal.on('keydown' + this.focusTrapNamespace, this.boundKeydownHandler);
        },

        disableFocusTrap: function() {
            if (this.isInline || !this.modal.length) {
                return;
            }

            if (this.boundFocusinHandler) {
                $(document).off('focusin' + this.focusTrapNamespace, this.boundFocusinHandler);
                this.boundFocusinHandler = null;
            }

            if (this.boundKeydownHandler) {
                this.modal.off('keydown' + this.focusTrapNamespace, this.boundKeydownHandler);
                this.boundKeydownHandler = null;
            }
        },

        openModal: function(e) {
            if (this.isInline) {
                return;
            }

            if (e) {
                e.preventDefault();
                this.lastActiveElement = e.currentTarget || document.activeElement;
            } else {
                this.lastActiveElement = document.activeElement;
            }
            this.modal.addClass('show').attr('aria-hidden', 'false');
            this.modal.trigger('show');

            this.enableFocusTrap();

            // Focus first input for accessibility
            setTimeout(() => {
                this.focusFirstElement();
            }, 300);
        },

        closeModal: function(e) {
            if (this.isInline) {
                return;
            }

            if (e) {
                e.preventDefault();
            }
            this.modal.removeClass('show').attr('aria-hidden', 'true');
            this.modal.trigger('hide');
            this.disableFocusTrap();
            this.resetToStep1();

            if (this.lastActiveElement && typeof this.lastActiveElement.focus === 'function') {
                this.lastActiveElement.focus();
            }
            this.lastActiveElement = null;
        },

        handleModalClick: function(e) {
            if (this.isInline) {
                return;
            }

            if (e.target === e.currentTarget) {
                this.closeModal();
            }
        },

        handleKeydown: function(e) {
            if (this.isInline || !this.modal || !this.modal.length) {
                return;
            }

            if (e.key === 'Escape' && this.modal.hasClass('show')) {
                this.closeModal();
                return;
            }

            if (e.key === 'Tab' && this.modal.hasClass('show')) {
                const focusable = this.getFocusableElements();

                if (!focusable.length) {
                    e.preventDefault();
                    return;
                }

                const first = focusable.get(0);
                const last = focusable.get(focusable.length - 1);
                const activeElement = document.activeElement;

                if (e.shiftKey) {
                    if (activeElement === first || !this.modal[0].contains(activeElement)) {
                        e.preventDefault();
                        $(last).trigger('focus');
                    }
                } else if (activeElement === last) {
                    e.preventDefault();
                    $(first).trigger('focus');
                }
            }
        },

        changeLanguage: function(e) {
            const $select = $(e.target);
            const newLang = $select.val();
            const previousLanguage = this.currentLanguage || (window.rbBookingAjax && window.rbBookingAjax.currentLanguage) || '';

            if (!newLang || newLang === previousLanguage) {
                return;
            }

            const $status = this.getLanguageStatusElement($select);
            this.updateLanguageStatus($status, this.strings.languageSwitching || 'Switching language…', 'loading');

            if (!window.rbBookingAjax || !rbBookingAjax.languageAction || !rbBookingAjax.languageNonce) {
                this.currentLanguage = newLang;
                $('#rb-new-hidden-language').val(newLang);
                $('.rb-new-lang-select').val(newLang);
                this.updateLanguageStatus($status, this.strings.languageSwitched || 'Language switched', 'success');
                setTimeout(() => this.updateLanguageStatus($status, '', ''), 2500);
                return;
            }

            this.toggleLanguageLoading(true);

            $.ajax({
                url: rbBookingAjax.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: rbBookingAjax.languageAction,
                    language: newLang,
                    nonce: rbBookingAjax.languageNonce
                }
            }).done((response) => {
                if (response && response.success) {
                    this.currentLanguage = newLang;
                    $('#rb-new-hidden-language').val(newLang);
                    $('.rb-new-lang-select').val(newLang);

                    if (rbBookingAjax.shouldReloadOnLanguageChange) {
                        window.location.reload();
                        return;
                    }

                    const successMessage = this.strings.languageSwitched || 'Language switched';
                    this.updateLanguageStatus($status, successMessage, 'success');
                    setTimeout(() => this.updateLanguageStatus($status, '', ''), 2500);
                } else {
                    this.currentLanguage = previousLanguage;
                    $('#rb-new-hidden-language').val(previousLanguage);
                    $('.rb-new-lang-select').val(previousLanguage);

                    const errorMessage = response && response.data && response.data.message;
                    this.updateLanguageStatus($status, errorMessage || this.strings.languageSwitchFailed || this.strings.connectionError, 'error');
                }
            }).fail(() => {
                this.currentLanguage = previousLanguage;
                $('#rb-new-hidden-language').val(previousLanguage);
                $('.rb-new-lang-select').val(previousLanguage);
                this.updateLanguageStatus($status, this.strings.languageSwitchFailed || this.strings.connectionError, 'error');
            }).always(() => {
                this.toggleLanguageLoading(false);
            });
        },

        getLanguageStatusElement: function($select) {
            const $container = $select.closest('.rb-new-language-switcher');
            if ($container.length) {
                const $status = $container.find('.rb-new-language-status');
                if ($status.length) {
                    return $status;
                }
            }
            return $('.rb-new-language-status').first();
        },

        toggleLanguageLoading: function(isLoading) {
            const $selects = $('.rb-new-lang-select');
            if (isLoading) {
                $selects.prop('disabled', true).addClass('rb-new-lang-loading');
            } else {
                $selects.prop('disabled', false).removeClass('rb-new-lang-loading');
            }
        },

        updateLanguageStatus: function($status, message, state) {
            if (!$status || !$status.length) {
                return;
            }

            $status.removeClass('is-success is-error is-loading');

            if (!message) {
                $status.text('').attr('hidden', true);
                return;
            }

            if (state === 'success') {
                $status.addClass('is-success');
            } else if (state === 'error') {
                $status.addClass('is-error');
            } else if (state === 'loading') {
                $status.addClass('is-loading');
            }

            $status.text(message).attr('hidden', false);
        },

        checkAvailability: function(e) {
            e.preventDefault();
            
            if (!this.validateAvailabilityForm()) {
                return;
            }

            const formData = this.getAvailabilityFormData();
            const $button = $('#rb-new-availability-form button[type="submit"]');
            
            this.setButtonLoading($button, this.strings.checking);

            $.ajax({
                url: window.rbBookingAjax.ajaxUrl,
                type: 'POST',
                data: formData,
                success: this.handleAvailabilityResponse.bind(this),
                error: this.handleAjaxError.bind(this),
                complete: () => this.resetButton($button, this.strings.checkingAvailability || 'Check Availability')
            });
        },

        validateAvailabilityForm: function() {
            const requiredFields = ['#rb-new-location', '#rb-new-date', '#rb-new-time', '#rb-new-checkout', '#rb-new-guests'];
            let isValid = true;

            requiredFields.forEach(selector => {
                const $field = $(selector);
                if (!$field.val()) {
                    this.highlightField($field);
                    isValid = false;
                } else {
                    this.unhighlightField($field);
                }
            });

            if (!isValid) {
                this.showError(this.strings.fillRequired);
            }

            return isValid;
        },

        getAvailabilityFormData: function() {
            return {
                action: 'rb_check_availability_extended',
                nonce: window.rbBookingAjax.nonce,
                location_id: $('#rb-new-location').val(),
                date: $('#rb-new-date').val(),
                checkin_time: $('#rb-new-time').val(),
                checkout_time: $('#rb-new-checkout').val(),
                guest_count: $('#rb-new-guests').val()
            };
        },

        handleAvailabilityResponse: function(response) {
            const data = response && response.data ? response.data : {};

            if (response && response.success && data.available) {
                this.showAvailabilitySuccess(data);
                this.storeSelectedData();
                this.goToStep2();
                return;
            }

            if (data.suggestions && data.suggestions.length) {
                this.showSuggestions(data);
                return;
            }

            if (data.message) {
                this.showError(data.message);
            } else {
                this.showError(this.strings.connectionError || 'Connection error. Please try again.');
            }
        },

        showAvailabilitySuccess: function(data) {
            $('#rb-new-availability-result')
                .removeClass('error')
                .addClass('success')
                .html('<p>✓ ' + (data.message || this.strings.processing || 'Available') + '</p>')
                .removeAttr('hidden')
                .show();
            $('#rb-new-suggestions').attr('hidden', true).hide();
        },

        showSuggestions: function(data) {
            $('#rb-new-availability-result')
                .removeClass('success')
                .addClass('error')
                .html('<p>' + (data.message || this.strings.connectionError || 'Unavailable') + '</p>')
                .removeAttr('hidden')
                .show();

            if (data.suggestions && data.suggestions.length > 0) {
                this.renderSuggestions(data.suggestions);
                $('#rb-new-suggestions').removeAttr('hidden').show();
            } else {
                $('#rb-new-suggestions').attr('hidden', true).hide();
            }
        },

        renderSuggestions: function(suggestions) {
            let suggestionsHtml = '';
            suggestions.forEach(suggestion => {
                suggestionsHtml += `<button type="button" class="rb-new-suggestion-btn" data-time="${suggestion}">${suggestion}</button>`;
            });
            
            $('#rb-new-suggestions .rb-new-suggestion-list').html(suggestionsHtml);
        },

        selectSuggestedTime: function(e) {
            const suggestedTime = $(e.target).data('time');
            $('#rb-new-time').val(suggestedTime);
            this.updateCheckoutSelect();

            // Highlight the selected suggestion
            $('.rb-new-suggestion-btn').removeClass('selected');
            $(e.target).addClass('selected');

            // Auto-submit after selection
            setTimeout(() => {
                $('#rb-new-availability-form').submit();
            }, 500);
        },

        storeSelectedData: function() {
            this.selectedData = {
                locationId: $('#rb-new-location').val(),
                locationName: $('#rb-new-location option:selected').text(),
                date: $('#rb-new-date').val(),
                checkin: $('#rb-new-time').val(),
                checkout: $('#rb-new-checkout').val(),
                guests: $('#rb-new-guests').val()
            };
        },

        goToStep2: function() {
            this.updateBookingSummary();
            this.setHiddenFields();

            const $step1 = $('.rb-new-step[data-step="1"]');
            const $step2 = $('.rb-new-step[data-step="2"]');
            const $step3 = $('.rb-new-step[data-step="3"]');

            // Ensure the second step can be displayed by removing the hidden attribute
            $step2.stop(true, true).hide().removeAttr('hidden');
            $step3.stop(true, true).hide().attr('hidden', true).removeClass('active');

            $step1.stop(true, true).fadeOut(300, () => {
                $step1.attr('hidden', true).removeClass('active');
                $step2.fadeIn(300).addClass('active');
            });

            this.currentStep = 2;
            this.updateStepper(2);

            // Focus first input in step 2
            setTimeout(() => {
                $('#rb-new-customer-name').trigger('focus');
            }, 350);
        },

        updateBookingSummary: function() {
            const { locationName, date, checkin, checkout, guests } = this.selectedData;

            $('#rb-new-summary-location').text(locationName);
            $('#rb-new-summary-datetime').text(`${date} ${checkin} – ${checkout}`);
            $('#rb-new-summary-guests').text(`${guests} ${this.strings.people}`);
        },

        setHiddenFields: function() {
            const { locationId, date, checkin, checkout, guests } = this.selectedData;

            $('#rb-new-hidden-location').val(locationId);
            $('#rb-new-hidden-date').val(date);
            $('#rb-new-hidden-time').val(checkin);
            $('#rb-new-hidden-checkout').val(checkout);
            $('#rb-new-hidden-guests').val(guests);
            if (this.currentLanguage) {
                $('#rb-new-hidden-language').val(this.currentLanguage);
            }
        },

        goBackToStep1: function(e) {
            e.preventDefault();

            const $step1 = $('.rb-new-step[data-step="1"]');
            const $step2 = $('.rb-new-step[data-step="2"]');
            const $step3 = $('.rb-new-step[data-step="3"]');

            $step1.stop(true, true).hide().removeAttr('hidden');

            $step2.stop(true, true).fadeOut(300, () => {
                $step2.attr('hidden', true).removeClass('active');
                $step3.stop(true, true).hide().attr('hidden', true).removeClass('active');
                $step1.fadeIn(300).addClass('active');
            });

            this.currentStep = 1;
            this.updateStepper(1);

            // Clear any result messages
            $('#rb-new-booking-result').attr('hidden', true).hide();
        },

        resetToStep1: function() {
            const $step1 = $('.rb-new-step[data-step="1"]');
            const $step2 = $('.rb-new-step[data-step="2"]');
            const $step3 = $('.rb-new-step[data-step="3"]');

            $step2.stop(true, true).hide().attr('hidden', true).removeClass('active');
            $step3.stop(true, true).hide().attr('hidden', true).removeClass('active');
            $step1.stop(true, true).show().removeAttr('hidden').addClass('active');
            this.currentStep = 1;
            this.updateStepper(1);
            this.selectedData = {};
            this.clearAllMessages();
            this.clearForm();
            this.setInitialValues(true);
            this.updateLocationMeta();
            const $successMessage = $('#rb-new-success-message');
            if ($successMessage.length) {
                const defaultMessage = $successMessage.data('default-message') || $successMessage.text();
                $successMessage.text(defaultMessage);
            }
        },

        submitBooking: function(e) {
            e.preventDefault();
            
            if (!this.validateBookingForm()) {
                return;
            }

            const formData = $('#rb-new-booking-form').serialize() + '&action=rb_submit_booking';
            const $button = $('#rb-new-booking-form button[type="submit"]');
            
            this.setButtonLoading($button, this.strings.processing);

            $.ajax({
                url: window.rbBookingAjax.ajaxUrl,
                type: 'POST',
                data: formData,
                success: this.handleBookingResponse.bind(this),
                error: this.handleAjaxError.bind(this),
                complete: () => this.resetButton($button, this.strings.confirmBooking || 'Confirm Booking')
            });
        },

        validateBookingForm: function() {
            const requiredFields = [
                '#rb-new-customer-name',
                '#rb-new-customer-phone', 
                '#rb-new-customer-email'
            ];
            
            let isValid = true;
            let errorMessage = '';

            requiredFields.forEach(selector => {
                const $field = $(selector);
                if (!$field.val().trim()) {
                    this.highlightField($field);
                    isValid = false;
                    errorMessage = this.strings.fillRequired;
                } else {
                    this.unhighlightField($field);
                }
            });

            // Email validation
            const email = $('#rb-new-customer-email').val();
            if (email && !this.isValidEmail(email)) {
                this.highlightField($('#rb-new-customer-email'));
                isValid = false;
                errorMessage = this.strings.invalidEmail;
            }

            // Phone validation
            const $phoneField = $('#rb-new-customer-phone');
            let phone = $phoneField.val();
            if (window.rbPhoneUtils && typeof window.rbPhoneUtils.sanitize === 'function') {
                const sanitizedPhone = window.rbPhoneUtils.sanitize(phone || '');
                if (sanitizedPhone !== phone) {
                    $phoneField.val(sanitizedPhone);
                }
                phone = sanitizedPhone;
            }

            if (phone && !this.isValidPhone(phone)) {
                this.highlightField($phoneField);
                isValid = false;
                errorMessage = this.strings.invalidPhone;
            }

            if (!isValid) {
                this.showBookingError(errorMessage || this.strings.fillRequired);
            }

            return isValid;
        },

        isValidEmail: function(email) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return emailRegex.test(email);
        },

        isValidPhone: function(phone) {
            const utils = window.rbPhoneUtils;
            if (utils && typeof utils.isValid === 'function') {
                return utils.isValid(phone);
            }

            if (typeof phone !== 'string') {
                return false;
            }

            const trimmed = phone.trim();
            if (!trimmed) {
                return false;
            }

            const digits = trimmed.replace(/\D/g, '');
            if (digits.length < 8 || digits.length > 20) {
                return false;
            }

            return /^\+?[0-9][0-9\s-]{6,19}$/.test(trimmed);
        },

        handleBookingResponse: function(response) {
            if (response && response.success) {
                const successMessage = response.data && response.data.message ? response.data.message : (this.strings.processing || 'Success');
                this.showBookingSuccess(successMessage);
                this.goToStep3();

                // Auto-close modal after success
                setTimeout(() => {
                    if (!this.isInline) {
                        this.closeModal();
                    }
                    this.resetToStep1();
                    const bookingForm = $('#rb-new-booking-form')[0];
                    if (bookingForm) {
                        bookingForm.reset();
                    }
                }, 4000);
            } else {
                const errorMessage = response && response.data && response.data.message ? response.data.message : (this.strings.connectionError || 'Connection error. Please try again.');
                this.showBookingError(errorMessage);
            }
        },

        showBookingSuccess: function(message) {
            const $successMessage = $('#rb-new-success-message');
            if ($successMessage.length) {
                if (!$successMessage.data('default-message')) {
                    $successMessage.data('default-message', $successMessage.text());
                }
                $successMessage.text(message);
            }

            $('#rb-new-booking-result').attr('hidden', true).hide();
        },

        showBookingError: function(message) {
            $('#rb-new-booking-result')
                .removeClass('success')
                .addClass('error')
                .html('<p>' + message + '</p>')
                .removeAttr('hidden')
                .show();

            // Scroll to message
            this.scrollToElement('#rb-new-booking-result');
        },

        goToStep3: function() {
            const $step2 = $('.rb-new-step[data-step="2"]');
            const $step3 = $('.rb-new-step[data-step="3"]');

            $step3.stop(true, true).hide().removeAttr('hidden');

            $step2.stop(true, true).fadeOut(300, () => {
                $step2.attr('hidden', true).removeClass('active');
                $step3.fadeIn(300).addClass('active');
                this.scrollToElement('.rb-new-step[data-step="3"]');
            });

            this.currentStep = 3;
            this.updateStepper(3);
        },

        handleRestart: function(e) {
            e.preventDefault();
            this.resetToStep1();
            if (this.isInline) {
                $('#rb-new-date').trigger('focus');
            } else {
                setTimeout(() => {
                    this.modal.find('input, select').first().focus();
                }, 200);
            }
        },

        handlePhoneInput: function(e) {
            const $input = $(e.target);
            if (!$input.length) {
                return;
            }

            const utils = window.rbPhoneUtils;
            if (utils && typeof utils.sanitize === 'function') {
                const sanitized = utils.sanitize($input.val());
                if ($input.val() !== sanitized) {
                    $input.val(sanitized);
                }
            }
        },

        updateTimeSlots: function() {
            const date = this.$dateInput && this.$dateInput.length ? this.$dateInput.val() : $('#rb-new-date').val();
            const locationId = $('#rb-new-location').val();
            const guestCount = $('#rb-new-guests').val();

            if (!date || !locationId || !guestCount || !window.rbBookingAjax || !window.rbBookingAjax.ajaxUrl) {
                this.isInitializingSlots = false;
                return;
            }

            const request = $.ajax({
                url: window.rbBookingAjax.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'rb_get_time_slots',
                    nonce: window.rbBookingAjax.nonce,
                    date: date,
                    location_id: locationId,
                    guest_count: guestCount
                }
            });

            request.done(this.handleTimeSlotsResponse.bind(this));
            request.fail(() => {
                this.availableSlots = [];
                $('#rb-new-time').val('');
                $('#rb-new-checkout').empty().append(`<option value="">${this.strings.selectTime}</option>`);
            });
            request.always(() => {
                this.isInitializingSlots = false;
            });
        },

        handleTimeSlotsResponse: function(response) {
            if (response.success && response.data.slots) {
                const $timeSelect = $('#rb-new-time');
                const currentTime = $timeSelect.val();

                $timeSelect.empty().append(`<option value="">${this.strings.selectTime}</option>`);

                this.availableSlots = response.data.slots;

                this.availableSlots.forEach(slot => {
                    $timeSelect.append(`<option value="${slot}">${slot}</option>`);
                });

                let nextSelection = '';

                if (currentTime && this.availableSlots.includes(currentTime) && !this.isInitializingSlots) {
                    nextSelection = currentTime;
                } else if (this.availableSlots.length) {
                    nextSelection = this.availableSlots[0];
                }

                if (nextSelection) {
                    $timeSelect.val(nextSelection);
                }

                this.updateCheckoutSelect();
            } else {
                this.availableSlots = [];
                $('#rb-new-time').val('');
                $('#rb-new-checkout').empty().append(`<option value="">${this.strings.selectTime}</option>`);
            }
        },

        updateCheckoutSelect: function() {
            const $checkout = $('#rb-new-checkout');
            if (!$checkout.length) {
                return;
            }

            const checkin = $('#rb-new-time').val();
            $checkout.empty().append(`<option value="">${this.strings.selectTime}</option>`);

            if (!checkin || !Array.isArray(this.availableSlots) || !this.availableSlots.length) {
                return;
            }

            const checkinSeconds = this.timeStringToSeconds(checkin);
            if (checkinSeconds === null) {
                return;
            }

            const minSeconds = checkinSeconds + 3600; // 1 hour minimum duration
            const maxSeconds = checkinSeconds + (6 * 3600); // 6 hours maximum duration
            let fallbackValue = null;
            let preferredValue = null;

            this.availableSlots.forEach(slot => {
                const slotSeconds = this.timeStringToSeconds(slot);
                if (slotSeconds === null) {
                    return;
                }

                if (slotSeconds >= minSeconds && slotSeconds <= maxSeconds) {
                    $checkout.append(`<option value="${slot}">${slot}</option>`);

                    if (!fallbackValue) {
                        fallbackValue = slot;
                    }

                    if (!preferredValue && slotSeconds >= checkinSeconds + (2 * 3600)) {
                        preferredValue = slot;
                    }
                }
            });

            const defaultValue = preferredValue || fallbackValue;
            if (defaultValue) {
                $checkout.val(defaultValue);
            }
        },

        timeStringToSeconds: function(time) {
            if (!time || typeof time !== 'string') {
                return null;
            }

            const segments = time.split(':');
            if (segments.length < 2) {
                return null;
            }

            const hours = parseInt(segments[0], 10);
            const minutes = parseInt(segments[1], 10);
            const seconds = segments.length > 2 ? parseInt(segments[2], 10) : 0;

            if (Number.isNaN(hours) || Number.isNaN(minutes) || Number.isNaN(seconds)) {
                return null;
            }

            return (hours * 3600) + (minutes * 60) + seconds;
        },

        escapeHtml: function(value) {
            if (value === null || value === undefined) {
                return '';
            }

            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        },

        formatPhoneHref: function(phone) {
            if (!phone) {
                return '';
            }

            const trimmed = String(phone).trim();
            if (!trimmed) {
                return '';
            }

            const hasPlus = trimmed.charAt(0) === '+';
            const digits = trimmed.replace(/[^0-9]/g, '');

            if (!digits) {
                return '';
            }

            return hasPlus ? `+${digits}` : digits;
        },

        formatEmailHref: function(email) {
            if (!email) {
                return '';
            }

            const sanitized = String(email).trim();
            if (!sanitized) {
                return '';
            }

            return sanitized.replace(/\s+/g, '');
        },

        replacePlaceholders: function(template, replacements) {
            if (!template) {
                return '';
            }

            let result = template;
            replacements.forEach((replacement, index) => {
                const token = `%${index + 1}$s`;
                result = result.split(token).join(replacement);
            });

            return result;
        },

        formatContactNote: function(hotline, email) {
            const hasHotline = typeof hotline === 'string' && hotline.trim() !== '';
            const hasEmail = typeof email === 'string' && email.trim() !== '';

            if (!hasHotline && !hasEmail) {
                return '';
            }

            const templates = this.strings || {};
            const generic = templates.advanceNoteGeneric || '';
            const hotlineTemplate = templates.advanceNoteHotlineOnly || generic;
            const emailTemplate = templates.advanceNoteEmailOnly || generic;
            const bothTemplate = templates.advanceNoteHotlineEmail || generic;

            const hotlineText = hasHotline ? this.escapeHtml(hotline.trim()) : '';
            const emailText = hasEmail ? this.escapeHtml(email.trim()) : '';

            const hotlineHref = hasHotline ? this.formatPhoneHref(hotline) : '';
            const emailHref = hasEmail ? this.formatEmailHref(email) : '';

            const hotlineMarkup = hasHotline
                ? (hotlineHref ? `<a href="tel:${hotlineHref}" class="rb-new-location-info__note-link rb-new-location-info__note-link--hotline">${hotlineText}</a>` : hotlineText)
                : '';
            const emailMarkup = hasEmail
                ? (emailHref ? `<a href="mailto:${emailHref}" class="rb-new-location-info__note-link rb-new-location-info__note-link--email">${emailText}</a>` : emailText)
                : '';

            if (hasHotline && hasEmail) {
                if (bothTemplate && bothTemplate.indexOf('%') !== -1) {
                    return this.replacePlaceholders(bothTemplate, [hotlineMarkup, emailMarkup]);
                }

                return `${bothTemplate} ${hotlineMarkup} ${emailMarkup}`.trim();
            }

            if (hasHotline) {
                if (hotlineTemplate && hotlineTemplate.indexOf('%') !== -1) {
                    return this.replacePlaceholders(hotlineTemplate, [hotlineMarkup]);
                }

                return `${hotlineTemplate} ${hotlineMarkup}`.trim();
            }

            if (hasEmail) {
                if (emailTemplate && emailTemplate.indexOf('%') !== -1) {
                    return this.replacePlaceholders(emailTemplate, [emailMarkup]);
                }

                return `${emailTemplate} ${emailMarkup}`.trim();
            }

            return generic;
        },

        updateLocationMeta: function() {
            const $container = $('.rb-new-location-info');
            if (!$container.length) {
                return;
            }

            const $select = $('#rb-new-location');
            const $selected = $select.length ? $select.find('option:selected') : null;

            const address = $selected && $selected.length ? ($selected.data('address') || '') : '';
            const hotline = $selected && $selected.length ? ($selected.data('hotline') || '') : '';
            const email = $selected && $selected.length ? ($selected.data('email') || '') : '';

            const updateField = (field, value, targetSelector) => {
                const $item = $container.find(`.rb-new-location-info__item[data-field="${field}"]`);
                if (!$item.length) {
                    return;
                }

                if (targetSelector) {
                    $(targetSelector).text(value || '');
                }

                if (value) {
                    $item.removeClass('is-hidden');
                } else {
                    $item.addClass('is-hidden');
                }
            };

            updateField('address', address, '#rb-new-location-address');
            updateField('hotline', hotline, '#rb-new-location-hotline');
            updateField('email', email, '#rb-new-location-email');

            const $note = $container.find('.rb-new-location-info__note');
            if ($note.length) {
                const noteHtml = this.formatContactNote(hotline, email);
                const $noteContent = $note.find('.rb-new-location-info__note-content');

                if (noteHtml) {
                    $note.removeClass('is-hidden');
                    if ($noteContent.length) {
                        $noteContent.html(noteHtml);
                    } else {
                        $note.html(noteHtml);
                    }
                } else {
                    if ($noteContent.length) {
                        $noteContent.empty();
                    } else {
                        $note.empty();
                    }
                    $note.addClass('is-hidden');
                }
            }

            if (address || hotline || email) {
                $container.removeClass('is-empty');
            } else {
                $container.addClass('is-empty');
            }

            this.refreshGuestSelect();
        },

        refreshGuestSelect: function() {
            const $guestSelect = $('#rb-new-guests');
            if (!$guestSelect.length) {
                return;
            }

            const defaultMax = parseInt($guestSelect.data('defaultMax'), 10);
            const fallbackMax = Number.isFinite(defaultMax) && defaultMax > 0 ? defaultMax : 20;

            const $locationSelect = $('#rb-new-location');
            const $selected = $locationSelect.length ? $locationSelect.find('option:selected') : null;
            let locationMax = $selected && $selected.length ? parseInt($selected.data('maxGuests'), 10) : NaN;

            if (!Number.isFinite(locationMax) || locationMax <= 0) {
                locationMax = fallbackMax;
            }

            let currentValue = parseInt($guestSelect.val(), 10);
            if (!Number.isFinite(currentValue) || currentValue < 1) {
                currentValue = 1;
            }

            if (currentValue > locationMax) {
                currentValue = locationMax;
            }

            const label = this.strings.people || 'people';
            let optionsHtml = '';
            for (let i = 1; i <= locationMax; i++) {
                const selectedAttr = i === currentValue ? ' selected' : '';
                optionsHtml += `<option value="${i}"${selectedAttr}>${i} ${label}</option>`;
            }

            $guestSelect.html(optionsHtml);
            $guestSelect.val(String(currentValue));

            const $hiddenGuests = $('#rb-new-hidden-guests');
            if ($hiddenGuests.length) {
                $hiddenGuests.val(String(currentValue));
            }
        },

        handleAjaxError: function() {
            if (this.currentStep === 2) {
                this.showBookingError(this.strings.connectionError);
            } else {
                this.showError(this.strings.connectionError);
            }
        },

        showError: function(message) {
            $('#rb-new-availability-result')
                .removeClass('success')
                .addClass('error')
                .html('<p>' + message + '</p>')
                .removeAttr('hidden')
                .show();
            $('#rb-new-suggestions').attr('hidden', true).hide();
        },

        setButtonLoading: function($button, text) {
            $button.text(text).prop('disabled', true).addClass('rb-new-loading');
        },

        resetButton: function($button, text) {
            $button.text(text).prop('disabled', false).removeClass('rb-new-loading');
        },

        highlightField: function($field) {
            $field.addClass('error');
        },

        unhighlightField: function($field) {
            $field.removeClass('error').css('border-color', '');
        },

        scrollToElement: function(selector) {
            const $element = $(selector);
            if ($element.length) {
                $element[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        },

        clearAllMessages: function() {
            $('#rb-new-availability-result, #rb-new-suggestions, #rb-new-booking-result')
                .attr('hidden', true)
                .hide();
        },

        clearForm: function() {
            const bookingForm = $('#rb-new-booking-form')[0];
            if (bookingForm) {
                bookingForm.reset();
            }
            $('.rb-new-form-group input, .rb-new-form-group select, .rb-new-form-group textarea')
                .removeClass('error')
                .css('border-color', '');
            this.availableSlots = [];
            $('#rb-new-time').empty().append(`<option value="">${this.strings.selectTime}</option>`);
            $('#rb-new-checkout').empty().append(`<option value="">${this.strings.selectTime}</option>`);
        },

        updateStepper: function(step) {
            if (!this.stepperItems || !this.stepperItems.length) {
                return;
            }

            this.stepperItems.each((index, element) => {
                const $item = $(element);
                const itemStep = parseInt($item.data('step'), 10);

                $item.removeClass('is-active is-complete');

                if (itemStep < step) {
                    $item.addClass('is-complete');
                } else if (itemStep === step) {
                    $item.addClass('is-active');
                }
            });
        }
    };

    // Initialize when DOM is ready
    $(document).ready(function() {
        RBBookingNew.init();
    });

    // Expose to global scope for external access
    window.RBBookingNew = RBBookingNew;

})(jQuery);
