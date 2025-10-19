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

        self.timelineMeta = self.buildTimelineMeta(data.time_slots || []);

        var $grid = $('<div class="rb-timeline-grid" />');
        var $timesColumn = $('<div class="rb-timeline-times" />');
        var $columnsWrapper = $('<div class="rb-timeline-columns" />');

        $timesColumn.css('--slot-height', self.timelineMeta.slotHeight + 'px');
        $timesColumn.css('height', self.timelineMeta.totalHeight + 'px');
        $columnsWrapper.css('--slot-height', self.timelineMeta.slotHeight + 'px');

        self.timelineMeta.timeSlots.forEach(function (slot) {
            $timesColumn.append($('<div class="rb-timeline-timeslot" />').text(slot));
        });

        data.tables.forEach(function (table) {
            $columnsWrapper.append(self.renderTable(table));
        });

        $grid.append($timesColumn).append($columnsWrapper);
        self.$container.append($grid);
    };

    TimelineApp.prototype.buildTimelineMeta = function (slots) {
        var slotHeight = 56;
        var parsedSlots = [];

        function parseTime(value) {
            if (!value) {
                return null;
            }

            if (typeof value !== 'string') {
                value = String(value);
            }

            var trimmed = value.trim();
            if (!trimmed) {
                return null;
            }

            var clean = trimmed.replace(/[^0-9:apm]/gi, '').toLowerCase();

            if (clean.indexOf('am') !== -1 || clean.indexOf('pm') !== -1) {
                var period = clean.indexOf('pm') !== -1 ? 'pm' : 'am';
                clean = clean.replace(/(am|pm)/g, '');
                var parts12 = clean.split(':');
                var h12 = parseInt(parts12[0], 10);
                var m12 = parts12.length > 1 ? parseInt(parts12[1], 10) : 0;

                if (isNaN(h12) || isNaN(m12)) {
                    return null;
                }

                if (period === 'pm' && h12 < 12) {
                    h12 += 12;
                }

                if (period === 'am' && h12 === 12) {
                    h12 = 0;
                }

                return h12 * 60 + m12;
            }

            var parts = clean.split(':');
            var hours = parseInt(parts[0], 10);
            var minutes = parts.length > 1 ? parseInt(parts[1], 10) : 0;

            if (isNaN(hours) || isNaN(minutes)) {
                return null;
            }

            return hours * 60 + minutes;
        }

        function formatMinutes(minutes) {
            var hours = Math.floor(minutes / 60);
            var mins = minutes % 60;
            var hourText = hours < 10 ? '0' + hours : String(hours);
            var minuteText = mins < 10 ? '0' + mins : String(mins);
            return hourText + ':' + minuteText;
        }

        slots.forEach(function (slot) {
            var minutes = parseTime(slot);
            if (minutes !== null && !isNaN(minutes)) {
                parsedSlots.push({ label: slot, minutes: minutes });
            } else {
                parsedSlots.push({ label: slot, minutes: null });
            }
        });

        if (!parsedSlots.length) {
            for (var hour = 8; hour <= 22; hour++) {
                var text = (hour < 10 ? '0' : '') + hour + ':00';
                parsedSlots.push({ label: text, minutes: hour * 60 });
            }
        }

        var validMinutes = parsedSlots
            .map(function (slot) { return slot.minutes; })
            .filter(function (value) { return value !== null; })
            .sort(function (a, b) { return a - b; });

        var interval = 60;
        if (validMinutes.length > 1) {
            interval = validMinutes[1] - validMinutes[0];
            for (var i = 1; i < validMinutes.length; i++) {
                var delta = validMinutes[i] - validMinutes[i - 1];
                if (delta > 0 && delta < interval) {
                    interval = delta;
                }
            }
            interval = Math.max(15, interval);
        }

        var start = validMinutes.length ? validMinutes[0] : 8 * 60;
        var end = validMinutes.length ? validMinutes[validMinutes.length - 1] + interval : 22 * 60;
        var intervalCount = Math.max(1, Math.round((end - start) / interval));
        var slotCount = Math.max(parsedSlots.length, intervalCount);
        var totalHeight = slotCount * slotHeight;

        if (parsedSlots.length < slotCount) {
            for (var index = parsedSlots.length; index < slotCount; index++) {
                var minutesToAdd = start + index * interval;
                parsedSlots.push({ label: formatMinutes(minutesToAdd), minutes: minutesToAdd });
            }
        }

        return {
            slotHeight: slotHeight,
            slotCount: slotCount,
            totalHeight: totalHeight,
            interval: interval,
            start: start,
            end: end,
            timeSlots: parsedSlots.map(function (slot) { return slot.label; }),
            parseTime: parseTime
        };
    };

    TimelineApp.prototype.renderTable = function (table) {
        var self = this;
        var meta = self.timelineMeta || self.buildTimelineMeta([]);
        var $column = $('<div class="rb-timeline-column" />');
        var title = table.table_number ? (self.strings.tableLabel || 'Table') + ' ' + table.table_number : (self.strings.unassigned || 'Unassigned');
        var statusLabel = table.current_status || 'available';

        var $header = $('<div class="rb-timeline-column-header" />');
        var $title = $('<div class="rb-timeline-column-title" />').text(title);
        var $meta = $('<div class="rb-timeline-column-meta" />');
        $meta.append($('<span class="rb-timeline-column-status" />').text((self.strings.currentStatus || 'Current Status') + ': ' + statusLabel));

        if (self.context === 'admin' && table.table_id) {
            $meta.append(self.renderStatusControls(table));
        }

        $header.append($title).append($meta);

        var $body = $('<div class="rb-timeline-column-body" />');
        $body.css('height', meta.totalHeight + 'px');

        if (table.bookings && table.bookings.length) {
            table.bookings.forEach(function (booking) {
                $body.append(self.renderBooking(booking));
            });
        } else {
            $body.append($('<div class="rb-timeline-column-placeholder" />').text(self.strings.noBookings || 'No bookings for this table.'));
        }

        $column.append($header).append($body);
        return $column;
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
        var meta = this.timelineMeta || this.buildTimelineMeta([]);
        var $card = $('<div class="rb-timeline-booking" />');
        $card.addClass('status-' + (booking.status || 'pending'));

        var startMinutes = meta.parseTime(booking.checkin_time) || meta.start;
        var endMinutes = meta.parseTime(booking.checkout_time);

        if (!endMinutes || endMinutes <= startMinutes) {
            endMinutes = startMinutes + meta.interval;
        }

        var offsetMultiplier = 1 / meta.interval;
        var topOffset = Math.max(0, Math.round((startMinutes - meta.start) * offsetMultiplier * meta.slotHeight));
        var duration = Math.max(meta.interval, endMinutes - startMinutes);
        var height = Math.max(32, Math.round(duration * offsetMultiplier * meta.slotHeight));
        height = Math.min(height, Math.max(meta.totalHeight - topOffset, 32));

        $card.css({
            top: topOffset + 'px',
            height: height + 'px'
        });

        var timeText = (booking.checkin_time || '') + ' – ' + (booking.checkout_time || '');
        var name = booking.customer_name || '';
        var guests = booking.guest_count ? booking.guest_count + ' ' + (this.strings.guestsLabel || 'guests') : '';

        $card.append($('<span class="rb-timeline-time" />').text(timeText));
        $card.append($('<div class="rb-timeline-booking-name" />').text(name));
        if (booking.phone) {
            $card.append($('<div class="rb-timeline-booking-phone" />').text(booking.phone));
        }
        if (guests) {
            $card.append($('<div class="rb-timeline-booking-guests" />').text(guests));
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
