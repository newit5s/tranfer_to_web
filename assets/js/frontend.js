/**
 * Restaurant Booking - Frontend JavaScript
 */

(function($) {
    'use strict';
    
    $(document).ready(function() {
        
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
                            .html(response.data.message)
                            .show();
                    }
                },
                error: function(xhr, status, error) {
                    $(messageContainer)
                        .removeClass('success')
                        .addClass('error')
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
                showPortalStep('1');
            });

            $('#rb-portal-back-to-start').on('click', function() {
                showPortalStep('start');
            });

            $('#rb-portal-language-form').on('submit', function(e) {
                e.preventDefault();
                var language = $(this).find('input[name="language"]:checked').val();

                if (!language) {
                    alert(rb_ajax.choose_language_text || 'Please select a language.');
                    return;
                }

                var willReload = setLanguage(language, { persist: true, resumeStep: '2' });

                if (willReload) {
                    return;
                }

                resetAvailabilityState();

                var currentLocation = $('#rb-portal-location').val();
                if (!currentLocation && locationsData.length) {
                    currentLocation = locationsData[0].id;
                    $('#rb-portal-location').val(currentLocation);
                }

                applyLocationConstraints(currentLocation);
                showPortalStep('2');
            });

            $('#rb-portal-back-to-language').on('click', function() {
                resetAvailabilityState();
                showPortalStep('1');
            });

            $('#rb-portal-location').on('change', function() {
                applyLocationConstraints($(this).val());
                resetAvailabilityState();
                $('#rb-portal-time').val('');
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
                applyLocationConstraints($('#rb-portal-location').val() || locationsData[0].id);
            }
            setLanguage(selectedLanguage || $('#rb-portal-language-selected').val());

            if (supportsSessionStorage()) {
                try {
                    var resumeStep = sessionStorage.getItem('rbPortalResumeStep');
                    var resumeLanguage = sessionStorage.getItem('rbPortalSelectedLanguage');

                    if (resumeStep && resumeLanguage) {
                        if (resumeLanguage === rb_ajax.current_language) {
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
            function showManagerFeedback(container, type, message) {
                container
                    .removeClass('success error')
                    .addClass(type)
                    .text(message)
                    .show();
            }

            function updateManagerRow(row, newStatus) {
                var statusLabel = row.find('.rb-status');
                statusLabel.removeClass(function(index, className) {
                    return (className.match(/rb-status-[^\s]+/g) || []).join(' ');
                });
                statusLabel.addClass('rb-status-' + newStatus);
                statusLabel.text(newStatus.replace(/-/g, ' ').replace(/\b\w/g, function(l) { return l.toUpperCase(); }));

                var bookingId = row.data('booking-id');
                var actionsCell = row.find('.rb-manager-actions');

                if (newStatus === 'pending') {
                    actionsCell.html('<button class="rb-btn-success rb-manager-action" data-action="confirm" data-id="' + bookingId + '">' + (rb_ajax.confirm_text || 'Confirm') + '</button>' +
                        '<button class="rb-btn-danger rb-manager-action" data-action="cancel" data-id="' + bookingId + '">' + (rb_ajax.cancel_text || 'Cancel') + '</button>');
                } else if (newStatus === 'confirmed') {
                    actionsCell.html('<button class="rb-btn-info rb-manager-action" data-action="complete" data-id="' + bookingId + '">' + (rb_ajax.complete_text || 'Complete') + '</button>' +
                        '<button class="rb-btn-danger rb-manager-action" data-action="cancel" data-id="' + bookingId + '">' + (rb_ajax.cancel_text || 'Cancel') + '</button>');
                } else {
                    actionsCell.html('<em>' + (rb_ajax.no_actions_text || 'No actions available') + '</em>');
                }
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

                button.prop('disabled', true);

                $.ajax({
                    url: rb_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'rb_manager_update_booking',
                        booking_id: bookingId,
                        manager_action: action,
                        nonce: rb_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            feedback.removeClass('error').addClass('success').text(response.data.message).show();

                            if (action === 'confirm') {
                                updateManagerRow(row, 'confirmed');
                            } else if (action === 'cancel') {
                                updateManagerRow(row, 'cancelled');
                            } else if (action === 'complete') {
                                updateManagerRow(row, 'completed');
                            }
                        } else {
                            feedback.removeClass('success').addClass('error').text(response.data.message).show();
                        }
                    },
                    error: function() {
                        feedback.removeClass('success').addClass('error').text(rb_ajax.error_text).show();
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
                                showManagerFeedback(feedback, 'success', response.data.message);
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
                                showManagerFeedback(feedback, 'success', response.data.message);
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
        }

        // Auto-hide messages after 10 seconds
        $(document).on('rb_booking_success', function() {
            setTimeout(function() {
                $('.rb-form-message').fadeOut();
            }, 10000);
        });
        
    });
    
})(jQuery);