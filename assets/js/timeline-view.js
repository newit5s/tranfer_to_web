(function ($) {
    'use strict';

    var TimelineApp = function ($container) {
        this.$container = $container;
        var config = window.rbTimelineConfig || {};
        this.context = this.$container.data('context') || config.context || 'admin';
        this.ajaxUrl = this.$container.data('ajaxUrl') || config.ajaxUrl || '';
        this.nonce = this.$container.data('nonce') || config.nonce || '';
        this.strings = (window.rbTimelineConfig && window.rbTimelineConfig.strings) || {};
        this.init();
    };

    TimelineApp.prototype.init = function () {
        var self = this;
        if (!self.ajaxUrl || !self.nonce) {
            self.renderError(self.strings.statusUpdateFailed || 'Missing AJAX configuration.');
            return;
        }

        self.fetchData();
    };

    TimelineApp.prototype.renderError = function (message) {
        this.$container.empty().append(
            $('<div class="rb-timeline-empty" />').text(message || 'An unexpected error occurred.')
        );
    };

    TimelineApp.prototype.fetchData = function () {
        var self = this;
        self.$container.addClass('rb-timeline-loading-state');

        $.post(self.ajaxUrl, {
            action: 'rb_get_timeline_data',
            nonce: self.nonce,
            date: self.$container.data('date'),
            location_id: self.$container.data('location')
        }).done(function (response) {
            self.$container.removeClass('rb-timeline-loading-state');

            if (response && response.success && response.data) {
                self.render(response.data);
            } else {
                var message = (response && response.data && response.data.message)
                    ? response.data.message
                    : (self.strings.loadingError || 'Unable to load timeline data.');
                self.renderError(message);
            }
        }).fail(function () {
            self.$container.removeClass('rb-timeline-loading-state');
            self.renderError(self.strings.loadingError || 'Unable to load timeline data.');
        });
    };

    TimelineApp.prototype.render = function (data) {
        var self = this;
        self.$container.empty();

        if (!data || !data.tables || !data.tables.length) {
            self.$container.append($('<div class="rb-timeline-empty" />').text(self.strings.noTables || 'No tables found for the selected date.'));
            return;
        }

        var $grid = $('<div class="rb-timeline-grid" />');
        var $timeslots = $('<div class="rb-timeline-timeslots" />');
        var $tablesWrapper = $('<div class="rb-timeline-tables" />');

        if (data.time_slots && data.time_slots.length) {
            data.time_slots.forEach(function (slot) {
                $timeslots.append($('<div />').text(slot));
            });
        }

        data.tables.forEach(function (table) {
            $tablesWrapper.append(self.renderTable(table));
        });

        $grid.append($timeslots).append($tablesWrapper);
        self.$container.append($grid);
    };

    TimelineApp.prototype.renderTable = function (table) {
        var self = this;
        var $table = $('<div class="rb-timeline-table" />');
        var title = table.table_number ? 'Table ' + table.table_number : 'Unassigned';
        var statusLabel = table.current_status || 'available';
        var headerText = title + ' · ' + (self.strings.currentStatus || 'Current Status') + ': ' + statusLabel;

        var $header = $('<div class="rb-timeline-table-header" />');
        $header.append($('<div />').text(headerText));

        if (self.context === 'admin' && table.table_id) {
            $header.append(self.renderStatusControls(table));
        }

        var $bookings = $('<div class="rb-timeline-bookings" />');
        if (table.bookings && table.bookings.length) {
            table.bookings.forEach(function (booking) {
                $bookings.append(self.renderBooking(booking));
            });
        } else {
            $bookings.append($('<div class="rb-timeline-empty" />').text(self.strings.noBookings || 'No bookings for this table.'));
        }

        $table.append($header).append($bookings);
        return $table;
    };

    TimelineApp.prototype.renderStatusControls = function (table) {
        var self = this;
        var statuses = ['available', 'occupied', 'cleaning', 'reserved'];
        var $wrapper = $('<div class="rb-timeline-status-control" />');

        statuses.forEach(function (status) {
            var $button = $('<button type="button" class="button button-small" />')
                .text(status)
                .attr('data-status', status)
                .attr('data-table-id', table.table_id)
                .attr('data-booking-id', table.last_booking_id || '')
                .on('click', function (event) {
                    event.preventDefault();
                    self.updateStatus($(this));
                });

            if (status === table.current_status) {
                $button.addClass('button-primary');
            }

            $wrapper.append($button);
        });

        return $wrapper;
    };

    TimelineApp.prototype.renderBooking = function (booking) {
        var $card = $('<div class="rb-timeline-booking" />');
        $card.addClass('status-' + (booking.status || 'pending'));

        var timeText = (booking.checkin_time || '') + ' – ' + (booking.checkout_time || '');
        var name = booking.customer_name || '';
        var guests = booking.guest_count ? booking.guest_count + ' ' + (this.strings.guestsLabel || 'guests') : '';

        $card.append($('<span class="rb-timeline-time" />').text(timeText));
        $card.append($('<div />').text(name));
        if (booking.phone) {
            $card.append($('<div />').text(booking.phone));
        }
        if (guests) {
            $card.append($('<div />').text(guests));
        }

        return $card;
    };

    TimelineApp.prototype.updateStatus = function ($button) {
        var self = this;
        var status = $button.data('status');
        var tableId = $button.data('table-id');
        var bookingId = $button.data('booking-id');

        if (!tableId || !status) {
            return;
        }

        $button.prop('disabled', true).addClass('updating');

        $.post(self.ajaxUrl, {
            action: 'rb_update_table_status',
            nonce: self.nonce,
            table_id: tableId,
            status: status,
            booking_id: bookingId
        }).done(function (response) {
            if (response && response.success) {
                self.fetchData();
            } else {
                alert((self.strings.statusUpdateFailed) || 'Unable to update status.');
            }
        }).fail(function () {
            alert((self.strings.statusUpdateFailed) || 'Unable to update status.');
        }).always(function () {
            $button.prop('disabled', false).removeClass('updating');
        });
    };

    function bootstrapTimeline() {
        $('.rb-timeline-app').each(function () {
            var $container = $(this);
            if (!$container.data('rb-timeline-init')) {
                $container.data('rb-timeline-init', true);
                new TimelineApp($container);
            }
        });
    }

    $(document).ready(function () {
        bootstrapTimeline();
    });
})(jQuery);
