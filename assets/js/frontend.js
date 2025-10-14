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
            $.ajax({
                url: rb_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'rb_get_time_slots',
                    date: date,
                    guest_count: guestCount,
                    nonce: rb_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var slots = response.data.slots;
                        var currentValue = timeSelect.val();
                        
                        // Clear and rebuild options
                        timeSelect.empty();
                        timeSelect.append('<option value="">Chọn giờ</option>');
                        
                        if (slots.length > 0) {
                            $.each(slots, function(i, slot) {
                                var selected = (slot === currentValue) ? ' selected' : '';
                                timeSelect.append('<option value="' + slot + '"' + selected + '>' + slot + '</option>');
                            });
                        } else {
                            timeSelect.append('<option value="">Không có giờ trống</option>');
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
            var resultDiv = $('#rb-availability-result');
            
            if (!date || !time || !guests) {
                resultDiv
                    .removeClass('success')
                    .addClass('error')
                    .html('Vui lòng chọn đầy đủ ngày, giờ và số khách')
                    .show();
                return;
            }
            
            // Show loading
            resultDiv
                .removeClass('success error')
                .html('Đang kiểm tra...')
                .show();
            
            $.ajax({
                url: rb_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'rb_check_availability',
                    date: date,
                    time: time,
                    guests: guests,
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
                        .html('Có lỗi xảy ra. Vui lòng thử lại.');
                }
            });
        }
        
        // Reset form
        function resetForm() {
            $('#rb-booking-form')[0].reset();
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

            $('#rb-portal-go-to-availability').on('click', function() {
                var selectedLocation = $('#rb-portal-location-form').find('input[name="location_id"]:checked').val();
                var language = $('#rb-portal-language-select').val();

                if (!selectedLocation) {
                    alert(rb_ajax.choose_location_text || 'Please select a location.');
                    return;
                }

                var location = findLocation(selectedLocation);
                $('#rb-portal-availability-form [name="location_id"]').val(selectedLocation);
                $('#rb-portal-availability-form [name="language"]').val(language);
                $('#rb-portal-location-hidden').val(selectedLocation);
                $('#rb-portal-language-hidden').val(language);

                if (location) {
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

                showPortalStep(2);
            });

            $('#rb-portal-back-to-location').on('click', function() {
                showPortalStep(1);
            });

            $('#rb-portal-availability-form').on('submit', function(e) {
                e.preventDefault();

                var form = $(this);
                var resultDiv = $('#rb-portal-availability-result');
                var suggestionsWrap = $('#rb-portal-suggestions');
                var suggestionList = suggestionsWrap.find('.rb-portal-suggestion-list');
                var continueWrap = $('#rb-portal-availability-continue');

                resultDiv.removeClass('success error').text(rb_ajax.loading_text).show();
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
                                continueWrap.removeAttr('hidden');
                            } else {
                                resultDiv.removeClass('success').addClass('error').text(response.data.message);
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
                        }
                    },
                    error: function() {
                        resultDiv.removeClass('success').addClass('error').text(rb_ajax.error_text);
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
                var location = findLocation($('#rb-portal-location-hidden').val());

                $('#rb-portal-date-hidden').val(dateValue);
                $('#rb-portal-time-hidden').val(timeValue);
                $('#rb-portal-guests-hidden').val(guestsValue);

                updatePortalLocationSummary(location);

                showPortalStep(3);
            });

            $('#rb-portal-back-to-availability').on('click', function() {
                showPortalStep(2);
            });

            $('#rb-portal-details-form').on('submit', function(e) {
                e.preventDefault();
                submitBookingForm($(this), '#rb-portal-details-message');
            });
        }

        // Manager dashboard actions
        var managerWrapper = $('.rb-manager');
        if (managerWrapper.length) {
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
        }

        // Auto-hide messages after 10 seconds
        $(document).on('rb_booking_success', function() {
            setTimeout(function() {
                $('.rb-form-message').fadeOut();
            }, 10000);
        });
        
    });
    
})(jQuery);