/**
 * Restaurant Booking - Frontend JavaScript
 */

(function($) {
    'use strict';
    
    $(document).ready(function() {

        if (typeof window.rb_ajax === 'undefined') {
            window.rb_ajax = {};
        }

        rb_ajax.error_text = rb_ajax.error_text || 'Something went wrong. Please try again.';
        rb_ajax.success_text = rb_ajax.success_text || 'Saved successfully.';
        rb_ajax.delete_confirm_text = rb_ajax.delete_confirm_text || 'Are you sure you want to delete this booking?';
        rb_ajax.confirm_text = rb_ajax.confirm_text || 'Confirm';
        rb_ajax.cancel_text = rb_ajax.cancel_text || 'Cancel';
        rb_ajax.complete_text = rb_ajax.complete_text || 'Complete';
        rb_ajax.edit_text = rb_ajax.edit_text || 'Edit';
        rb_ajax.delete_text = rb_ajax.delete_text || 'Delete';
        rb_ajax.confirm_delete_table = rb_ajax.confirm_delete_table || 'Are you sure you want to delete this table?';
        rb_ajax.confirm_set_vip = rb_ajax.confirm_set_vip || 'Upgrade this customer to VIP?';
        rb_ajax.confirm_blacklist = rb_ajax.confirm_blacklist || 'Blacklist this customer?';
        rb_ajax.confirm_unblacklist = rb_ajax.confirm_unblacklist || 'Remove this customer from blacklist?';
        
        // Modal handling
        var modal = $('#rb-booking-modal');
        var openBtn = $('.rb-open-modal-btn');
        var closeBtn = $('.rb-close, .rb-close-modal');
        
        // Open modal
        openBtn.on('click', function(e) {
            e.preventDefault();
            modal.addClass('show');
            $('body').css('overflow', 'hidden');
        });
        
        // Close modal
        closeBtn.on('click', function(e) {
            e.preventDefault();
            modal.removeClass('show');
            $('body').css('overflow', 'auto');
            resetForm();
        });
        
        // Close modal when clicking outside
        $(window).on('click', function(e) {
            if ($(e.target).is(modal)) {
                modal.removeClass('show');
                $('body').css('overflow', 'auto');
                resetForm();
            }
        });
        
        // Handle booking form submission (modal)
        $('#rb-booking-form').on('submit', function(e) {
            e.preventDefault();
            submitBookingForm($(this), '#rb-form-message');
        });
        
        // Handle inline form submission
        $('#rb-booking-form-inline').on('submit', function(e) {
            e.preventDefault();
            submitBookingForm($(this), '#rb-form-message-inline');
        });
        
        // Date change - update available times
        $('#rb_booking_date, #rb_date_inline').on('change', function() {
            var date = $(this).val();
            var guestCount = $(this).closest('form').find('[name="guest_count"]').val();
            var timeSelect = $(this).closest('form').find('[name="booking_time"]');

            if (date && guestCount) {
                updateAvailableTimeSlots(date, guestCount, timeSelect);
            }
        });

        // Guest count change - update available times
        $('#rb_guest_count, #rb_guests_inline').on('change', function() {
            var guestCount = $(this).val();
            var date = $(this).closest('form').find('[name="booking_date"]').val();
            var timeSelect = $(this).closest('form').find('[name="booking_time"]');

            if (date && guestCount) {
                updateAvailableTimeSlots(date, guestCount, timeSelect);
            }
        });
        
        // Check availability button
        $('#rb-check-availability').on('click', function(e) {
            e.preventDefault();
            checkAvailability();
        });
        
        // Submit booking form
        function submitBookingForm(form, messageContainer) {
            var formData = form.serialize();
            var submitBtn = form.find('[type="submit"]');
            var originalText = submitBtn.text();
            var nonceField = form.find('[name="rb_nonce"], [name="rb_nonce_inline"], [name="rb_nonce_portal"]');
            var nonceValue = nonceField.length ? nonceField.val() : '';

            // Show loading state
            submitBtn.text(rb_ajax.loading_text).prop('disabled', true);
            form.addClass('rb-loading');

            // Clear previous messages
            $(messageContainer).removeClass('success error').hide();

            // AJAX request
            $.ajax({
                url: rb_ajax.ajax_url,
                type: 'POST',
                data: formData + '&action=rb_submit_booking&rb_nonce=' + nonceValue,
                success: function(response) {
                    if (response.success) {
                        // Show success message
                        $(messageContainer)
                            .removeClass('error')
                            .addClass('success')
                            .removeAttr('hidden')
                            .html(response.data.message)
                            .show();
                        
                        // Reset form
                        form[0].reset();
                        
                        // Close modal after 3 seconds if it's open
                        if (modal.hasClass('show')) {
                            setTimeout(function() {
                                modal.removeClass('show');
                                $('body').css('overflow', 'auto');
                                resetForm();
                            }, 3000);
                        }
                        
                        // Trigger custom event
                        $(document).trigger('rb_booking_success', [response.data]);
                        
                    } else {
                        // Show error message
                        $(messageContainer)
                            .removeClass('success')
                            .addClass('error')
                            .removeAttr('hidden')
                            .html(response.data.message)
                            .show();
                    }
                },
                error: function(xhr, status, error) {
                    $(messageContainer)
                        .removeClass('success')
                        .addClass('error')
                        .removeAttr('hidden')
                        .html(rb_ajax.error_text)
                        .show();
                },
                complete: function() {
                    // Remove loading state
                    submitBtn.text(originalText).prop('disabled', false);
                    form.removeClass('rb-loading');
                }
            });
        }
        
        // Update available time slots
        function updateAvailableTimeSlots(date, guestCount, timeSelect) {
            if (!timeSelect || !timeSelect.length) {
                return;
            }

            var form = timeSelect.closest('form');
            var locationField = form.find('[name="location_id"]');
            var locationId = locationField.length ? locationField.val() : '';

            if (!locationId && rb_ajax.default_location_id) {
                locationId = rb_ajax.default_location_id;
            }

            if (!locationId) {
                return;
            }

            $.ajax({
                url: rb_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'rb_get_time_slots',
                    date: date,
                    guest_count: guestCount,
                    location_id: locationId,
                    nonce: rb_ajax.nonce
                },
                success: function(response) {
                    if (response.success && response.data) {
                        var slots = response.data.slots;
                        var currentValue = timeSelect.val();

                        // Clear and rebuild options
                        timeSelect.empty();
                        timeSelect.append('<option value="">' + (rb_ajax.select_time_text || 'Select time') + '</option>');
                        
                        if (slots.length > 0) {
                            $.each(slots, function(i, slot) {
                                var selected = (slot === currentValue) ? ' selected' : '';
                                timeSelect.append('<option value="' + slot + '"' + selected + '>' + slot + '</option>');
                            });
                        } else {
                            timeSelect.append('<option value="">' + (rb_ajax.no_slots_text || 'No available times') + '</option>');
                        }
                    }
                }
            });
        }

        // Check availability
        function checkAvailability() {
            var date = $('#rb_booking_date').val();
            var time = $('#rb_booking_time').val();
            var guests = $('#rb_guest_count').val();
            var locationId = $('#rb-booking-form').find('[name="location_id"]').val() || rb_ajax.default_location_id || '';
            var resultDiv = $('#rb-availability-result');

            if (!date || !time || !guests || !locationId) {
                resultDiv
                    .removeClass('success')
                    .addClass('error')
                    .html(rb_ajax.missing_fields_text || 'Please select date, time, guests and location before checking availability.')
                    .show();
                return;
            }

            // Show loading
            resultDiv
                .removeClass('success error')
                .html(rb_ajax.loading_text || 'Checking...')
                .show();
            
            $.ajax({
                url: rb_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'rb_check_availability',
                    date: date,
                    time: time,
                    guests: guests,
                    location_id: locationId,
                    nonce: rb_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        if (response.data.available) {
                            resultDiv
                                .removeClass('error')
                                .addClass('success')
                                .html(response.data.message);
                        } else {
                            resultDiv
                                .removeClass('success')
                                .addClass('error')
                                .html(response.data.message);
                        }
                    } else {
                        resultDiv
                            .removeClass('success')
                            .addClass('error')
                            .html(response.data.message);
                    }
                },
                error: function() {
                    resultDiv
                        .removeClass('success')
                        .addClass('error')
                        .html(rb_ajax.error_text || 'Something went wrong. Please try again.');
                }
            });
        }

        // Reset form
        function resetForm() {
            var modalForm = $('#rb-booking-form');
            if (modalForm.length) {
                modalForm[0].reset();
            }
            $('#rb-form-message').removeClass('success error').hide();
            $('#rb-availability-result').removeClass('success error').hide();
        }
        
        // Form validation
        $('#rb-booking-form, #rb-booking-form-inline').find('input[type="tel"]').on('input', function() {
            // Only allow numbers
            this.value = this.value.replace(/[^0-9]/g, '');
        });
        
        // Set minimum date to today
        var today = new Date();
        var dd = String(today.getDate()).padStart(2, '0');
        var mm = String(today.getMonth() + 1).padStart(2, '0');
        var yyyy = today.getFullYear();
        today = yyyy + '-' + mm + '-' + dd;
        
        $('#rb_booking_date, #rb_date_inline').attr('min', today);
        
        // Set maximum date to 30 days from now
        var maxDate = new Date();
        maxDate.setDate(maxDate.getDate() + 30);
        var maxDd = String(maxDate.getDate()).padStart(2, '0');
        var maxMm = String(maxDate.getMonth() + 1).padStart(2, '0');
        var maxYyyy = maxDate.getFullYear();
        maxDate = maxYyyy + '-' + maxMm + '-' + maxDd;
        
        $('#rb_booking_date, #rb_date_inline').attr('max', maxDate);
        
        // Enhanced phone validation
        $('#rb-booking-form, #rb-booking-form-inline, #rb-portal-details-form').on('submit', function(e) {
            var phone = $(this).find('input[type="tel"]').val().replace(/\D+/g, '');

            if (phone.length < 8 || phone.length > 15) {
                e.preventDefault();

                var messageContainer = '#rb-form-message';
                if ($(this).attr('id') === 'rb-booking-form-inline') {
                    messageContainer = '#rb-form-message-inline';
                } else if ($(this).attr('id') === 'rb-portal-details-form') {
                    messageContainer = '#rb-portal-details-message';
                }

                $(messageContainer)
                    .removeClass('success')
                    .addClass('error')
                    .html(rb_ajax.invalid_phone_text || 'Invalid phone number. Please enter a valid phone number.')
                    .show();

                return false;
            }
        });

        // Multi-step portal handling
        var portalWrapper = $('.rb-portal');
        if (portalWrapper.length) {
            var locationsData = rb_ajax.locations || [];
            var selectedLanguage = rb_ajax.current_language || '';

            function supportsSessionStorage() {
                try {
                    return typeof window.sessionStorage !== 'undefined';
                } catch (error) {
                    return false;
                }
            }

            function findLocation(id) {
                id = parseInt(id, 10);
                for (var i = 0; i < locationsData.length; i++) {
                    if (parseInt(locationsData[i].id, 10) === id) {
                        return locationsData[i];
                    }
                }
                return null;
            }

            function parseTimeToMinutes(timeStr) {
                if (!timeStr) {
                    return null;
                }

                var parts = String(timeStr).split(':');
                if (parts.length < 2) {
                    return null;
                }

                var hours = parseInt(parts[0], 10);
                var minutes = parseInt(parts[1], 10);

                if (isNaN(hours) || isNaN(minutes)) {
                    return null;
                }

                return (hours * 60) + minutes;
            }

            function generateTimeSlotsForLocation(location) {
                if (!location) {
                    return [];
                }

                var startMinutes = parseTimeToMinutes(location.opening_time);
                var endMinutes = parseTimeToMinutes(location.closing_time);
                var interval = parseInt(location.time_slot_interval, 10);

                if (isNaN(interval) || interval <= 0) {
                    interval = 30;
                }

                if (startMinutes === null || endMinutes === null) {
                    return [];
                }

                var slots = [];
                for (var current = startMinutes; current <= endMinutes; current += interval) {
                    var hours = Math.floor(current / 60);
                    var mins = current % 60;
                    var hoursStr = hours < 10 ? '0' + hours : String(hours);
                    var minsStr = mins < 10 ? '0' + mins : String(mins);
                    slots.push(hoursStr + ':' + minsStr);
                }

                return slots;
            }

            function populateTimeOptions(locationId, selectedTime) {
                var timeSelect = $('#rb-portal-time');
                if (!timeSelect.length) {
                    return;
                }

                var placeholder = rb_ajax.select_time_text || 'Select time';
                var slots = generateTimeSlotsForLocation(findLocation(locationId));

                timeSelect.empty();
                timeSelect.append('<option value="">' + placeholder + '</option>');

                if (!slots.length) {
                    timeSelect.append('<option value="" disabled>' + (rb_ajax.no_slots_text || 'No available times') + '</option>');
                    return;
                }

                slots.forEach(function(slot) {
                    var selectedAttr = slot === selectedTime ? ' selected' : '';
                    timeSelect.append('<option value="' + slot + '"' + selectedAttr + '>' + slot + '</option>');
                });
            }

            function formatDate(dateObj) {
                var year = dateObj.getFullYear();
                var month = String(dateObj.getMonth() + 1).padStart(2, '0');
                var day = String(dateObj.getDate()).padStart(2, '0');
                return year + '-' + month + '-' + day;
            }

            function showPortalStep(step) {
                portalWrapper.find('.rb-portal-step').attr('hidden', true);
                portalWrapper.find('.rb-portal-step[data-step="' + step + '"]').removeAttr('hidden');
            }

            function updatePortalLocationSummary(location) {
                if (!location) {
                    return;
                }
                $('#rb-portal-location-hotline').text(location.hotline || '');
                $('#rb-portal-hotline-note').text(location.hotline ? ' ' + location.hotline : '');
                $('#rb-portal-location-address').text(location.address || '');
            }

            function setLanguage(language, options) {
                options = options || {};

                var availabilityLanguageField = $('#rb-portal-language-selected');
                var detailsLanguageField = $('#rb-portal-language-hidden');
                var currentLanguage = rb_ajax.current_language || '';
                var targetLanguage = language || selectedLanguage ||
                    (availabilityLanguageField.length ? availabilityLanguageField.val() : '') ||
                    (detailsLanguageField.length ? detailsLanguageField.val() : '') ||
                    currentLanguage;

                if (!targetLanguage) {
                    return false;
                }

                selectedLanguage = targetLanguage;

                if (availabilityLanguageField.length) {
                    availabilityLanguageField.val(targetLanguage);
                }

                if (detailsLanguageField.length) {
                    detailsLanguageField.val(targetLanguage);
                }

                var languageSelector = $('#rb-portal-language-selector');
                if (languageSelector.length) {
                    languageSelector.val(targetLanguage);
                }

                var needsPersist = !!options.persist;
                var resumeStep = options.resumeStep || null;

                if (needsPersist && targetLanguage !== currentLanguage) {
                    var storeState = function() {
                        if (!supportsSessionStorage()) {
                            return;
                        }

                        try {
                            sessionStorage.setItem('rbPortalSelectedLanguage', targetLanguage);

                            if (resumeStep) {
                                sessionStorage.setItem('rbPortalResumeStep', resumeStep);
                            } else {
                                sessionStorage.removeItem('rbPortalResumeStep');
                            }
                        } catch (storageError) {
                            // Ignore storage errors (e.g. private mode)
                        }
                    };

                    var fallbackNavigation = function() {
                        storeState();

                        try {
                            var fallbackUrl = new URL(window.location.href);
                            fallbackUrl.searchParams.set('rb_lang', targetLanguage);
                            window.location.href = fallbackUrl.toString();
                        } catch (urlError) {
                            var baseUrl = window.location.href.split('?')[0];
                            window.location.href = baseUrl + '?rb_lang=' + encodeURIComponent(targetLanguage);
                        }
                    };

                    storeState();

                    if (rb_ajax.language_nonce) {
                        $.ajax({
                            url: rb_ajax.ajax_url,
                            type: 'POST',
                            data: {
                                action: 'rb_switch_language',
                                language: targetLanguage,
                                nonce: rb_ajax.language_nonce
                            },
                            success: function(response) {
                                if (response && response.success) {
                                    window.location.reload();
                                } else {
                                    fallbackNavigation();
                                }
                            },
                            error: fallbackNavigation
                        });
                    } else {
                        fallbackNavigation();
                    }

                    return true;
                }

                if (needsPersist && supportsSessionStorage()) {
                    try {
                        sessionStorage.removeItem('rbPortalResumeStep');
                        sessionStorage.removeItem('rbPortalSelectedLanguage');
                    } catch (clearError) {
                        // Ignore storage errors
                    }
                }

                rb_ajax.current_language = targetLanguage;

                return false;
            }

            function applyLocationConstraints(locationId) {
                var location = findLocation(locationId);
                if (!location) {
                    return;
                }

                var minAdvance = parseInt(location.min_advance_booking, 10) || 2;
                var maxAdvance = parseInt(location.max_advance_booking, 10) || 30;

                var minDate = new Date();
                minDate.setHours(minDate.getHours() + minAdvance);
                var maxDate = new Date();
                maxDate.setDate(maxDate.getDate() + maxAdvance);

                $('#rb-portal-date').attr('min', formatDate(minDate));
                $('#rb-portal-date').attr('max', formatDate(maxDate));

                updatePortalLocationSummary(location);
                populateTimeOptions(locationId);
            }

            function resetAvailabilityState() {
                var resultDiv = $('#rb-portal-availability-result');
                var suggestionsWrap = $('#rb-portal-suggestions');
                var suggestionList = suggestionsWrap.find('.rb-portal-suggestion-list');
                var continueWrap = $('#rb-portal-availability-continue');

                resultDiv.removeClass('success error').text('').attr('hidden', true);
                suggestionsWrap.attr('hidden', true);
                suggestionList.empty();
                continueWrap.attr('hidden', true);
            }

            $('#rb-portal-start').on('click', function() {
                resetAvailabilityState();

                var currentLocation = $('#rb-portal-location').val();
                if (!currentLocation && locationsData.length) {
                    currentLocation = locationsData[0].id;
                    $('#rb-portal-location').val(currentLocation);
                }

                applyLocationConstraints(currentLocation);
                populateTimeOptions(currentLocation);
                showPortalStep('2');
            });

            $('#rb-portal-back-to-start').on('click', function() {
                resetAvailabilityState();
                showPortalStep('start');
            });

            $('#rb-portal-language-selector').on('change', function() {
                var language = $(this).val();
                if (!language) {
                    return;
                }

                var willReload = setLanguage(language, { persist: true, resumeStep: '2' });
                if (!willReload) {
                    resetAvailabilityState();
                }
            });

            $('#rb-portal-location').on('change', function() {
                var selectedLocation = $(this).val();
                applyLocationConstraints(selectedLocation);
                resetAvailabilityState();
                populateTimeOptions(selectedLocation);
            });

            $('#rb-portal-availability-form').on('submit', function(e) {
                e.preventDefault();

                var form = $(this);
                var resultDiv = $('#rb-portal-availability-result');
                var suggestionsWrap = $('#rb-portal-suggestions');
                var suggestionList = suggestionsWrap.find('.rb-portal-suggestion-list');
                var continueWrap = $('#rb-portal-availability-continue');

                resultDiv.removeClass('success error').text(rb_ajax.loading_text).removeAttr('hidden').show();
                suggestionsWrap.attr('hidden', true);
                suggestionList.empty();
                continueWrap.attr('hidden', true);

                $.ajax({
                    url: rb_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'rb_check_availability',
                        nonce: rb_ajax.nonce,
                        date: form.find('[name="booking_date"]').val(),
                        time: form.find('[name="booking_time"]').val(),
                        guests: form.find('[name="guest_count"]').val(),
                        location_id: form.find('[name="location_id"]').val()
                    },
                    success: function(response) {
                        if (response.success) {
                            if (response.data.available) {
                                resultDiv.removeClass('error').addClass('success').text(response.data.message);
                                resultDiv.removeAttr('hidden');
                                continueWrap.removeAttr('hidden');
                            } else {
                                resultDiv.removeClass('success').addClass('error').text(response.data.message);
                                resultDiv.removeAttr('hidden');
                                if (response.data.suggestions && response.data.suggestions.length) {
                                    suggestionsWrap.removeAttr('hidden');
                                    suggestionList.empty();
                                    response.data.suggestions.forEach(function(time) {
                                        suggestionList.append('<button type="button" class="rb-btn-secondary rb-portal-suggestion" data-time="' + time + '">' + time + '</button>');
                                    });
                                }
                            }
                        } else {
                            resultDiv.removeClass('success').addClass('error').text(response.data.message);
                            resultDiv.removeAttr('hidden');
                        }
                    },
                    error: function() {
                        resultDiv.removeClass('success').addClass('error').text(rb_ajax.error_text);
                        resultDiv.removeAttr('hidden');
                    }
                });
            });

            $(document).on('click', '.rb-portal-suggestion', function() {
                $('#rb-portal-time').val($(this).data('time'));
                $('#rb-portal-availability-form').trigger('submit');
            });

            $('#rb-portal-go-to-details').on('click', function() {
                var dateValue = $('#rb-portal-date').val();
                var timeValue = $('#rb-portal-time').val();
                var guestsValue = $('#rb-portal-guests').val();
                var locationId = $('#rb-portal-location').val();
                var location = findLocation(locationId);

                $('#rb-portal-location-hidden').val(locationId);
                $('#rb-portal-date-hidden').val(dateValue);
                $('#rb-portal-time-hidden').val(timeValue);
                $('#rb-portal-guests-hidden').val(guestsValue);

                updatePortalLocationSummary(location);

                showPortalStep('3');
            });

            $('#rb-portal-back-to-availability').on('click', function() {
                showPortalStep('2');
            });

            $('#rb-portal-details-form').on('submit', function(e) {
                e.preventDefault();
                submitBookingForm($(this), '#rb-portal-details-message');
            });

            // Initialize default state
            if (locationsData.length) {
                var initialLocation = $('#rb-portal-location').val() || locationsData[0].id;
                $('#rb-portal-location').val(initialLocation);
                applyLocationConstraints(initialLocation);
                populateTimeOptions(initialLocation, $('#rb-portal-time').val());
            }
            setLanguage(selectedLanguage || $('#rb-portal-language-selected').val());

            if (supportsSessionStorage()) {
                try {
                    var resumeStep = sessionStorage.getItem('rbPortalResumeStep');
                    var resumeLanguage = sessionStorage.getItem('rbPortalSelectedLanguage');

                        if (resumeStep && resumeLanguage) {
                            if (resumeLanguage === rb_ajax.current_language) {
                                if (resumeStep === '2') {
                                    resetAvailabilityState();
                                    populateTimeOptions($('#rb-portal-location').val());
                                }
                                showPortalStep(resumeStep);
                            }

                            sessionStorage.removeItem('rbPortalResumeStep');
                            sessionStorage.removeItem('rbPortalSelectedLanguage');
                        }
                } catch (resumeError) {
                    // Ignore storage errors
                }
            }
        }

        // Manager dashboard actions
        var managerWrapper = $('.rb-manager');
        if (managerWrapper.length) {
            function escapeAttribute(value) {
                return String(value == null ? '' : value)
                    .replace(/&/g, '&amp;')
                    .replace(/"/g, '&quot;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;');
            }

            function showManagerFeedback(container, type, message) {
                container
                    .removeClass('success error warning')
                    .addClass(type)
                    .text(message)
                    .removeAttr('hidden')
                    .show();
            }

            function getManagerNonce() {
                var feedback = $('#rb-manager-feedback');
                var nonce = feedback.data('nonce');
                return nonce ? nonce : rb_ajax.nonce;
            }

            function buildActionButtons(status, bookingId) {
                var label = escapeAttribute(rb_ajax.booking_actions_label || 'Booking actions');
                var buttons = ['<div class="rb-manager-action-stack" role="group" aria-label="' + label + '">'];
                if (status === 'pending') {
                    buttons.push('<button class="rb-btn-success rb-manager-action" data-action="confirm" data-id="' + bookingId + '">' + (rb_ajax.confirm_text || 'Confirm') + '</button>');
                    buttons.push('<button class="rb-btn-danger rb-manager-action" data-action="cancel" data-id="' + bookingId + '">' + (rb_ajax.cancel_text || 'Cancel') + '</button>');
                }
                if (status === 'confirmed') {
                    buttons.push('<button class="rb-btn-danger rb-manager-action" data-action="cancel" data-id="' + bookingId + '">' + (rb_ajax.cancel_text || 'Cancel') + '</button>');
                    buttons.push('<button class="rb-btn-secondary rb-manager-action" data-action="complete" data-id="' + bookingId + '">' + (rb_ajax.complete_text || 'Complete') + '</button>');
                }
                buttons.push('<button class="rb-btn-secondary rb-manager-edit-booking" data-id="' + bookingId + '">' + (rb_ajax.edit_text || 'Edit') + '</button>');
                buttons.push('<button class="rb-btn-danger rb-manager-action" data-action="delete" data-id="' + bookingId + '">' + (rb_ajax.delete_text || 'Delete') + '</button>');
                buttons.push('</div>');
                return buttons.join('');
            }

            function updateBookingRow(row, booking) {
                if (!booking) {
                    return;
                }

                row.attr('data-booking-id', booking.id || '');
                row.attr('data-customer-name', booking.customer_name || '');
                row.attr('data-customer-phone', booking.customer_phone || '');
                row.attr('data-customer-email', booking.customer_email || '');
                row.attr('data-booking-date', booking.booking_date || '');
                row.attr('data-booking-time', booking.booking_time || '');
                row.attr('data-guest-count', booking.guest_count || '');
                row.attr('data-booking-source', booking.booking_source || '');
                row.attr('data-special-requests', booking.special_requests || '');
                row.attr('data-admin-notes', booking.admin_notes || '');
                row.attr('data-status', booking.status || '');
                row.attr('data-table-number', booking.table_number || '');

                row.data('bookingId', booking.id || '');
                row.data('customerName', booking.customer_name || '');
                row.data('customerPhone', booking.customer_phone || '');
                row.data('customerEmail', booking.customer_email || '');
                row.data('bookingDate', booking.booking_date || '');
                row.data('bookingTime', booking.booking_time || '');
                row.data('guestCount', booking.guest_count || '');
                row.data('bookingSource', booking.booking_source || '');
                row.data('specialRequests', booking.special_requests || '');
                row.data('adminNotes', booking.admin_notes || '');
                row.data('status', booking.status || '');
                row.data('tableNumber', booking.table_number || '');

                var cells = row.find('td');
                cells.eq(0).text('#' + (booking.padded_id || booking.id));

                var guestHtml = '<strong>' + booking.customer_name + '</strong>';
                if (booking.special_requests) {
                    guestHtml += '<div class="rb-manager-note">' + booking.special_requests + '</div>';
                }
                if (booking.admin_notes) {
                    guestHtml += '<div class="rb-manager-note rb-manager-note-internal">' + booking.admin_notes + '</div>';
                }
                cells.eq(1).html(guestHtml);

                cells.eq(2).html('<div>' + (booking.customer_phone || '') + '</div><div>' + (booking.customer_email || '') + '</div>');
                cells.eq(3).text(booking.date_display || booking.booking_date || '');
                cells.eq(4).text(booking.booking_time || '');
                cells.eq(5).text(booking.guest_count || '');
                cells.eq(6).text(booking.source_label || booking.booking_source || '');

                var statusBadge = cells.eq(7).find('.rb-status');
                statusBadge.removeClass(function(index, className) {
                    return (className.match(/rb-status-[^\s]+/g) || []).join(' ');
                });
                statusBadge.addClass('rb-status-' + booking.status);
                statusBadge.text(booking.status_label || booking.status || '');

                cells.eq(8).text(booking.table_number || '—');
                cells.eq(9).text(booking.created_display || booking.created_at || '');
                cells.eq(10).html(buildActionButtons(booking.status, booking.id));
            }

            managerWrapper.on('click', '.rb-manager-action', function(e) {
                e.preventDefault();
                var button = $(this);
                if (button.prop('disabled')) {
                    return;
                }

                var bookingId = button.data('id');
                var action = button.data('action');
                var row = button.closest('tr');
                var feedback = $('#rb-manager-feedback');

                if (action === 'delete' && !window.confirm(rb_ajax.delete_confirm_text || 'Are you sure you want to delete this booking?')) {
                    return;
                }

                button.prop('disabled', true);

                $.ajax({
                    url: rb_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'rb_manager_update_booking',
                        booking_id: bookingId,
                        manager_action: action,
                        nonce: getManagerNonce()
                    },
                    success: function(response) {
                        if (response.success) {
                            var successMessage = response.data && response.data.message ? response.data.message : rb_ajax.success_text;
                            showManagerFeedback(feedback, 'success', successMessage);

                            if (action === 'delete') {
                                row.remove();
                            } else if (response.data && response.data.booking) {
                                updateBookingRow(row, response.data.booking);
                            }
                        } else {
                            var message = response.data && response.data.message ? response.data.message : rb_ajax.error_text;
                            showManagerFeedback(feedback, 'error', message);
                        }
                    },
                    error: function() {
                        showManagerFeedback(feedback, 'error', rb_ajax.error_text);
                    },
                    complete: function() {
                        button.prop('disabled', false);
                    }
                });
            });

            var editModal = $('#rb-manager-edit-modal');
            var editForm = $('#rb-manager-edit-booking-form');
            var editFeedback = $('#rb-manager-edit-feedback');

            function closeEditModal() {
                editModal.attr('hidden', true);
                editForm.removeData('row');
                editFeedback.hide().removeClass('success error');
            }

            managerWrapper.on('click', '.rb-manager-edit-booking', function(e) {
                e.preventDefault();
                var button = $(this);
                var row = button.closest('tr');
                editForm.find('[name="booking_id"]').val(row.data('bookingId') || row.data('booking-id'));
                editForm.find('[name="customer_name"]').val(row.data('customerName'));
                editForm.find('[name="customer_phone"]').val(row.data('customerPhone'));
                editForm.find('[name="customer_email"]').val(row.data('customerEmail'));
                editForm.find('[name="guest_count"]').val(row.data('guestCount'));
                editForm.find('[name="booking_date"]').val(row.data('bookingDate'));
                editForm.find('[name="booking_time"]').val(row.data('bookingTime'));
                editForm.find('[name="booking_source"]').val(row.data('bookingSource'));
                editForm.find('[name="special_requests"]').val(row.data('specialRequests'));
                editForm.find('[name="admin_notes"]').val(row.data('adminNotes'));
                editForm.data('row', row);
                editModal.removeAttr('hidden');
            });

            managerWrapper.on('click', '.rb-manager-modal-close, .rb-manager-modal-cancel', function() {
                closeEditModal();
            });

            editModal.on('click', function(e) {
                if ($(e.target).is('#rb-manager-edit-modal')) {
                    closeEditModal();
                }
            });

            if (editForm.length) {
                editForm.on('submit', function(e) {
                    e.preventDefault();
                    var form = $(this);
                    var submitBtn = form.find('button[type="submit"]');
                    var row = form.data('row');

                    if (!row || !row.length) {
                        closeEditModal();
                        return;
                    }

                    submitBtn.prop('disabled', true);
                    editFeedback.hide();

                    $.ajax({
                        url: rb_ajax.ajax_url,
                        type: 'POST',
                        data: form.serialize() + '&action=rb_manager_save_booking',
                        success: function(response) {
                            if (response.success && response.data && response.data.booking) {
                                showManagerFeedback(editFeedback, 'success', response.data.message || (rb_ajax.success_text || 'Saved successfully.'));
                                updateBookingRow(row, response.data.booking);
                                setTimeout(function() {
                                    closeEditModal();
                                }, 600);
                            } else {
                                var message = response.data && response.data.message ? response.data.message : rb_ajax.error_text;
                                showManagerFeedback(editFeedback, 'error', message);
                            }
                        },
                        error: function() {
                            showManagerFeedback(editFeedback, 'error', rb_ajax.error_text);
                        },
                        complete: function() {
                            submitBtn.prop('disabled', false);
                        }
                    });
                });
            }

            managerWrapper.on('click', '.rb-manager-save-note', function(e) {
                e.preventDefault();
                var button = $(this);
                var customerId = button.data('customerId');
                var noteField = button.closest('td').find('.rb-manager-customer-note');
                var noteValue = noteField.length ? noteField.val() : '';
                var feedback = $('#rb-manager-customers-feedback');

                if (button.prop('disabled')) {
                    return;
                }

                button.prop('disabled', true);
                showManagerFeedback(feedback, 'success', rb_ajax.loading_text || 'Saving...');

                $.ajax({
                    url: rb_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'rb_manager_update_customer_note',
                        customer_id: customerId,
                        note: noteValue,
                        nonce: getManagerNonce()
                    },
                    success: function(response) {
                        if (response.success) {
                            showManagerFeedback(feedback, 'success', response.data.message || (rb_ajax.success_text || 'Saved successfully.'));
                        } else {
                            var message = response.data && response.data.message ? response.data.message : rb_ajax.error_text;
                            showManagerFeedback(feedback, 'error', message);
                        }
                    },
                    error: function() {
                        showManagerFeedback(feedback, 'error', rb_ajax.error_text);
                    },
                    complete: function() {
                        button.prop('disabled', false);
                    }
                });
            });

            var createForm = $('#rb-manager-create-booking');
            if (createForm.length) {
                createForm.on('submit', function(e) {
                    e.preventDefault();
                    var form = $(this);
                    var submitBtn = form.find('button[type="submit"]');
                    var feedback = $('#rb-manager-create-feedback');

                    submitBtn.prop('disabled', true);
                    feedback.removeClass('success error').hide();

                    $.ajax({
                        url: rb_ajax.ajax_url,
                        type: 'POST',
                        data: form.serialize() + '&action=rb_manager_create_booking',
                        success: function(response) {
                            if (response.success) {
                                form[0].reset();
                                var successMessage = response.data && response.data.message ? response.data.message : rb_ajax.success_text;
                                showManagerFeedback(feedback, 'success', successMessage);
                            } else if (response.data && response.data.message) {
                                showManagerFeedback(feedback, 'error', response.data.message);
                            } else {
                                showManagerFeedback(feedback, 'error', rb_ajax.error_text);
                            }
                        },
                        error: function() {
                            showManagerFeedback(feedback, 'error', rb_ajax.error_text);
                        },
                        complete: function() {
                            submitBtn.prop('disabled', false);
                        }
                    });
                });
            }

            var addTableForm = $('#rb-manager-add-table');
            if (addTableForm.length) {
                addTableForm.on('submit', function(e) {
                    e.preventDefault();
                    var form = $(this);
                    var feedback = $('#rb-manager-table-feedback');
                    var submitBtn = form.find('button[type="submit"]');

                    submitBtn.prop('disabled', true);
                    feedback.hide();

                    $.ajax({
                        url: rb_ajax.ajax_url,
                        type: 'POST',
                        data: form.serialize() + '&action=rb_manager_add_table',
                        success: function(response) {
                            if (response.success) {
                                location.reload();
                            } else if (response.data && response.data.message) {
                                showManagerFeedback(feedback, 'error', response.data.message);
                            } else {
                                showManagerFeedback(feedback, 'error', rb_ajax.error_text);
                            }
                        },
                        error: function() {
                            showManagerFeedback(feedback, 'error', rb_ajax.error_text);
                        },
                        complete: function() {
                            submitBtn.prop('disabled', false);
                        }
                    });
                });
            }

            managerWrapper.on('click', '.rb-manager-toggle-table', function(e) {
                e.preventDefault();
                var button = $(this);
                if (button.prop('disabled')) {
                    return;
                }

                button.prop('disabled', true);

                $.ajax({
                    url: rb_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'rb_manager_toggle_table',
                        table_id: button.data('table-id'),
                        is_available: button.data('next-status'),
                        nonce: rb_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else if (response.data && response.data.message) {
                            alert(response.data.message);
                        } else {
                            alert(rb_ajax.error_text);
                        }
                    },
                    error: function() {
                        alert(rb_ajax.error_text);
                    },
                    complete: function() {
                        button.prop('disabled', false);
                    }
                });
            });

            managerWrapper.on('click', '.rb-manager-delete-table', function(e) {
                e.preventDefault();
                if (!confirm(rb_ajax.confirm_delete_table || 'Are you sure you want to delete this table?')) {
                    return;
                }

                var button = $(this);

                $.ajax({
                    url: rb_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'rb_manager_delete_table',
                        table_id: button.data('table-id'),
                        nonce: rb_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else if (response.data && response.data.message) {
                            alert(response.data.message);
                        } else {
                            alert(rb_ajax.error_text);
                        }
                    },
                    error: function() {
                        alert(rb_ajax.error_text);
                    }
                });
            });

            managerWrapper.on('click', '.rb-manager-set-vip', function(e) {
                e.preventDefault();
                if (!confirm(rb_ajax.confirm_set_vip || 'Upgrade this customer to VIP?')) {
                    return;
                }

                var button = $(this);
                $.ajax({
                    url: rb_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'rb_manager_set_customer_vip',
                        customer_id: button.data('customer-id'),
                        status: 1,
                        nonce: rb_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else if (response.data && response.data.message) {
                            alert(response.data.message);
                        } else {
                            alert(rb_ajax.error_text);
                        }
                    },
                    error: function() {
                        alert(rb_ajax.error_text);
                    }
                });
            });

            managerWrapper.on('click', '.rb-manager-blacklist, .rb-manager-unblacklist', function(e) {
                e.preventDefault();
                var button = $(this);
                var status = button.hasClass('rb-manager-unblacklist') ? 0 : 1;
                var confirmMessage = status ? (rb_ajax.confirm_blacklist || 'Blacklist this customer?') : (rb_ajax.confirm_unblacklist || 'Remove this customer from blacklist?');
                if (!confirm(confirmMessage)) {
                    return;
                }

                $.ajax({
                    url: rb_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'rb_manager_set_customer_blacklist',
                        customer_id: button.data('customer-id'),
                        status: status,
                        nonce: rb_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else if (response.data && response.data.message) {
                            alert(response.data.message);
                        } else {
                            alert(rb_ajax.error_text);
                        }
                    },
                    error: function() {
                        alert(rb_ajax.error_text);
                    }
                });
            });

            managerWrapper.on('click', '.rb-manager-view-history', function(e) {
                e.preventDefault();
                var phone = $(this).data('phone');
                var historyModal = $('#rb-manager-history');
                var historyContent = $('#rb-manager-history-content');
                var nonceField = $('#rb-manager-customers-nonce');
                var nonceValue = nonceField.length ? nonceField.val() : rb_ajax.nonce;

                historyContent.html('<p>' + (rb_ajax.loading_text || 'Loading...') + '</p>');
                historyModal.removeAttr('hidden');

                $.ajax({
                    url: rb_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'rb_manager_customer_history',
                        phone: phone,
                        nonce: nonceValue
                    },
                    success: function(response) {
                        if (response.success && response.data.history) {
                            if (!response.data.history.length) {
                                historyContent.html('<p>' + (rb_ajax.no_history_text || 'No history found.') + '</p>');
                                return;
                            }

                            var table = $('<table class="rb-manager-history-table"></table>');
                            var thead = $('<thead><tr><th>Date</th><th>Time</th><th>Guests</th><th>Table</th><th>Status</th></tr></thead>');
                            table.append(thead);
                            var tbody = $('<tbody></tbody>');
                            response.data.history.forEach(function(item) {
                                var row = $('<tr></tr>');
                                row.append('<td>' + item.booking_date + '</td>');
                                row.append('<td>' + item.booking_time + '</td>');
                                row.append('<td>' + item.guest_count + '</td>');
                                row.append('<td>' + (item.table_number || '-') + '</td>');
                                row.append('<td>' + item.status + '</td>');
                                tbody.append(row);
                            });
                            table.append(tbody);
                            historyContent.html(table);
                        } else if (response.data && response.data.message) {
                            historyContent.html('<p>' + response.data.message + '</p>');
                        } else {
                            historyContent.html('<p>' + rb_ajax.error_text + '</p>');
                        }
                    },
                    error: function() {
                        historyContent.html('<p>' + rb_ajax.error_text + '</p>');
                    }
                });
            });

            managerWrapper.on('click', '.rb-manager-history-close', function() {
                $('#rb-manager-history').attr('hidden', true);
            });

            $('#rb-manager-history').on('click', function(e) {
                if ($(e.target).is('#rb-manager-history')) {
                    $('#rb-manager-history').attr('hidden', true);
                }
            });

            var settingsForm = $('#rb-manager-settings-form');
            if (settingsForm.length) {
                settingsForm.on('submit', function(e) {
                    e.preventDefault();
                    var form = $(this);
                    var feedback = $('#rb-manager-settings-feedback');
                    var submitBtn = form.find('button[type="submit"]');

                    submitBtn.prop('disabled', true);
                    feedback.hide();

                    $.ajax({
                        url: rb_ajax.ajax_url,
                        type: 'POST',
                        data: form.serialize() + '&action=rb_manager_update_settings',
                        success: function(response) {
                            if (response.success) {
                                var successMessage = response.data && response.data.message ? response.data.message : rb_ajax.success_text;
                                showManagerFeedback(feedback, 'success', successMessage);
                            } else if (response.data && response.data.message) {
                                showManagerFeedback(feedback, 'error', response.data.message);
                            } else {
                                showManagerFeedback(feedback, 'error', rb_ajax.error_text);
                            }
                        },
                        error: function() {
                            showManagerFeedback(feedback, 'error', rb_ajax.error_text);
                        },
                        complete: function() {
                            submitBtn.prop('disabled', false);
                        }
                    });
                });
            }

            var settingsTabs = $('.rb-manager-settings-tab');
            if (settingsTabs.length) {
                var panels = $('.rb-manager-settings-panel');
                settingsTabs.on('click', function() {
                    var tab = $(this).data('tab');
                    settingsTabs.removeClass('active');
                    $(this).addClass('active');
                    panels.attr('hidden', true);
                    panels.filter('[data-tab="' + tab + '"]').removeAttr('hidden');

                    if (window.history && window.history.replaceState) {
                        var url = new URL(window.location.href);
                        url.searchParams.set('rb_settings_tab', tab);
                        window.history.replaceState({}, '', url.toString());
                    }
                });
            }
        }

        // Auto-hide messages after 10 seconds
        $(document).on('rb_booking_success', function() {
            setTimeout(function() {
                $('.rb-form-message').fadeOut();
            }, 10000);
        });
        
    });
    
})(jQuery);