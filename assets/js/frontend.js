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
            
            // Show loading state
            submitBtn.text(rb_ajax.loading_text).prop('disabled', true);
            form.addClass('rb-loading');
            
            // Clear previous messages
            $(messageContainer).removeClass('success error').hide();
            
            // AJAX request
            $.ajax({
                url: rb_ajax.ajax_url,
                type: 'POST',
                data: formData + '&action=rb_submit_booking&rb_nonce=' + form.find('[name="rb_nonce"], [name="rb_nonce_inline"]').val(),
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
        $('#rb-booking-form, #rb-booking-form-inline').on('submit', function(e) {
            var phone = $(this).find('input[type="tel"]').val();
            
            // Vietnamese phone number validation
            if (!/^(0[3|5|7|8|9])+([0-9]{8})$/.test(phone)) {
                e.preventDefault();
                
                var messageContainer = $(this).attr('id') === 'rb-booking-form' ? 
                    '#rb-form-message' : '#rb-form-message-inline';
                    
                $(messageContainer)
                    .removeClass('success')
                    .addClass('error')
                    .html('Số điện thoại không hợp lệ. Vui lòng nhập số điện thoại Việt Nam hợp lệ.')
                    .show();
                    
                return false;
            }
        });
        
        // Auto-hide messages after 10 seconds
        $(document).on('rb_booking_success', function() {
            setTimeout(function() {
                $('.rb-form-message').fadeOut();
            }, 10000);
        });
        
    });
    
})(jQuery);