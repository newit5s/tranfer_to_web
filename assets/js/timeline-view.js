(function ($) {
    'use strict';

    var TimelineApp = function ($container) {
        this.$container = $container;
        var config = window.rbTimelineConfig || {};
        this.context = this.$container.data('context') || config.context || 'admin';
        this.ajaxUrl = this.$container.data('ajaxUrl') || config.ajaxUrl || '';
        this.nonce = this.$container.data('nonce') || config.nonce || '';
        this.strings = (window.rbTimelineConfig && window.rbTimelineConfig.strings) || {};
        this.nowTimer = null;
        this.activeDate = null;
        this.activeLocation = null;
        this.$modal = null;
        this.$modalBody = null;
        this.$modalActions = null;
        this.$modalTitle = null;
        this.$modalSubtitle = null;
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
        this.clearNowTimer();
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

        self.activeDate = data && data.date ? data.date : null;
        self.activeLocation = data && data.location_id ? data.location_id : null;

        if (!data || !data.tables || !data.tables.length) {
            self.clearNowTimer();
            self.$container.append($('<div class="rb-timeline-empty" />').text(self.strings.noTables || 'No tables found for the selected date.'));
            return;
        }

        self.timelineMeta = self.buildTimelineMeta(data.time_slots || []);
        self.nowOffset = self.calculateNowOffset();

        var $grid = $('<div class="rb-timeline-grid" />');
        var $timesColumn = $('<div class="rb-timeline-times" />');
        var $columnsScroll = $('<div class="rb-timeline-columns-scroll" />');
        var $columnsWrapper = $('<div class="rb-timeline-columns" />');

        $timesColumn.css('--slot-height', self.timelineMeta.slotHeight + 'px');
        $timesColumn.css('height', self.timelineMeta.totalHeight + 'px');
        $columnsWrapper.css('--slot-height', self.timelineMeta.slotHeight + 'px');

        self.timelineMeta.timeSlots.forEach(function (slot) {
            $timesColumn.append($('<div class="rb-timeline-timeslot" />').text(slot));
        });

        if (typeof self.nowOffset === 'number') {
            var $railMarker = self.buildNowMarker('rail');
            if ($railMarker) {
                $timesColumn.append($railMarker);
            }
        }

        data.tables.forEach(function (table) {
            $columnsWrapper.append(self.renderTable(table));
        });

        $columnsScroll.append($columnsWrapper);
        $grid.append($timesColumn).append($columnsScroll);
        self.$container.append($grid);
        self.ensureNowTimer();
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
        var statusLabel = self.getStatusLabel(table.current_status || 'available');

        var $header = $('<div class="rb-timeline-column-header" />');
        var $title = $('<div class="rb-timeline-column-title" />').text(title);
        var $meta = $('<div class="rb-timeline-column-meta" />');
        $meta.append($('<span class="rb-timeline-column-status" />').text((self.strings.currentStatus || 'Current Status') + ': ' + statusLabel));

        if (self.context === 'admin') {
            $header.addClass('rb-timeline-column-header--interactive').attr('tabindex', '0');
            $header.on('click', function (event) {
                if ($(event.target).closest('.rb-timeline-column-action').length) {
                    return;
                }
                self.openTableModal(table);
            });
            $header.on('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    self.openTableModal(table);
                }
            });

            if (table.table_id) {
                var $manageButton = $('<button type="button" class="button button-small rb-timeline-column-action" />')
                    .text(self.strings.manageTable || 'Manage table')
                    .on('click', function (event) {
                        event.preventDefault();
                        self.openTableModal(table);
                    });
                $meta.append($manageButton);
            }
        }

        $header.append($title).append($meta);

        var $body = $('<div class="rb-timeline-column-body" />');
        $body.css('height', meta.totalHeight + 'px');

        var bookings = Array.isArray(table.bookings) ? table.bookings.slice() : [];
        if (bookings.length) {
            bookings = self.sortBookings(bookings);
            bookings.forEach(function (booking) {
                $body.append(self.renderBooking(booking));
            });
        } else {
            $body.append($('<div class="rb-timeline-column-placeholder" />').text(self.strings.noBookings || 'No bookings for this table.'));
        }

        if (typeof self.nowOffset === 'number') {
            var $columnMarker = self.buildNowMarker('column');
            if ($columnMarker) {
                $body.append($columnMarker);
            }
        }

        $column.append($header).append($body);
        return $column;
    };

    TimelineApp.prototype.renderStatusControls = function (table) {
        var self = this;
        var statuses = ['available', 'occupied', 'cleaning', 'reserved'];
        var $wrapper = $('<div class="rb-timeline-status-control" />');

        statuses.forEach(function (status) {
            var label = self.getStatusLabel(status);
            var $button = $('<button type="button" class="button button-small" />')
                .text(label)
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

    TimelineApp.prototype.getStatusLabel = function (status) {
        if (!status) {
            return this.strings.available || 'available';
        }

        var key = String(status).toLowerCase();
        if (this.strings && this.strings[key]) {
            return this.strings[key];
        }

        return status;
    };

    TimelineApp.prototype.renderBooking = function (booking) {
        var meta = this.timelineMeta || this.buildTimelineMeta([]);
        var $card = $('<div class="rb-timeline-booking" />');
        $card.addClass('status-' + (booking.status || 'pending'));

        var startMinutes = this.getBookingStartMinutes(booking, meta);
        if (startMinutes === null) {
            startMinutes = meta.start;
        }

        var endMinutes = this.getBookingEndMinutes(booking, meta);

        if (!endMinutes || endMinutes <= startMinutes) {
            endMinutes = startMinutes + meta.interval;
        }

        var minuteHeight = meta.interval > 0 ? (meta.slotHeight / meta.interval) : (meta.slotHeight / 30);
        var topOffset = Math.max(0, Math.round((startMinutes - meta.start) * minuteHeight));
        var duration = Math.max(meta.interval, endMinutes - startMinutes);
        var height = Math.max(32, Math.round(duration * minuteHeight));
        height = Math.min(height, Math.max(meta.totalHeight - topOffset, 32));

        $card.css({
            top: topOffset + 'px',
            height: height + 'px'
        });

        var checkin = booking.checkin_time || booking.start_time || booking.time || '';
        var checkout = booking.checkout_time || booking.end_time || '';
        var timeText = checkin && checkout ? (checkin + ' – ' + checkout) : (checkin || checkout || '');
        var name = booking.customer_name || '';
        var guests = booking.guest_count ? booking.guest_count + ' ' + (this.strings.guestsLabel || 'guests') : '';

        if (timeText) {
            $card.append($('<span class="rb-timeline-time" />').text(timeText));
        }
        $card.append($('<div class="rb-timeline-booking-name" />').text(name));
        if (booking.phone) {
            $card.append($('<div class="rb-timeline-booking-phone" />').text(booking.phone));
        }
        if (guests) {
            $card.append($('<div class="rb-timeline-booking-guests" />').text(guests));
        }

        return $card;
    };

    TimelineApp.prototype.getBookingStatusLabel = function (status) {
        if (!status) {
            return '';
        }

        var normalized = String(status).toLowerCase();
        if (normalized === 'no_show') {
            normalized = 'no-show';
        }

        var map = {
            'pending': this.strings.statusPending,
            'confirmed': this.strings.statusConfirmed,
            'cancelled': this.strings.statusCancelled,
            'completed': this.strings.statusCompleted,
            'no-show': this.strings.statusNoShow
        };

        return map[normalized] || status;
    };

    TimelineApp.prototype.ensureModal = function () {
        if (this.$modal) {
            return this.$modal;
        }

        var self = this;
        var $modal = $('<div class="rb-timeline-modal" />');
        var $backdrop = $('<div class="rb-timeline-modal-backdrop" />');
        var $dialog = $('<div class="rb-timeline-modal-dialog" />');
        var $header = $('<div class="rb-timeline-modal-header" />');
        var $title = $('<h3 class="rb-timeline-modal-title" />');
        var $subtitle = $('<div class="rb-timeline-modal-subtitle" />');
        var $close = $('<button type="button" class="rb-timeline-modal-close" aria-label="Close" />').html('&times;');
        var $body = $('<div class="rb-timeline-modal-body" />');
        var $actions = $('<div class="rb-timeline-modal-actions" />');

        $header.append($title).append($subtitle).append($close);
        $dialog.append($header).append($body).append($actions);
        $modal.append($backdrop).append($dialog);
        $('body').append($modal);

        $backdrop.add($close).on('click', function (event) {
            event.preventDefault();
            self.closeModal();
        });

        $(document).on('keydown.rbTimelineModal', function (event) {
            if (event.key === 'Escape' && $modal.hasClass('is-visible')) {
                self.closeModal();
            }
        });

        this.$modal = $modal;
        this.$modalBody = $body;
        this.$modalActions = $actions;
        this.$modalTitle = $title;
        this.$modalSubtitle = $subtitle;

        return $modal;
    };

    TimelineApp.prototype.openTableModal = function (table) {
        if (!table || !table.table_id) {
            return;
        }

        var self = this;
        var $modal = self.ensureModal();
        var title = table.table_number ? (self.strings.tableLabel || 'Table') + ' ' + table.table_number : (self.strings.unassigned || 'Unassigned');
        self.$modalTitle.text(title);

        var subtitle = '';
        if (self.strings.bookingsTitle && self.activeDate) {
            subtitle = self.strings.bookingsTitle.replace('%s', self.activeDate);
        }
        self.$modalSubtitle.text(subtitle);
        self.$modalSubtitle.toggle(!!subtitle);

        self.$modalBody.empty();

        var statusBadge = $('<span class="rb-timeline-modal-status-badge status-' + (table.current_status || 'available') + '" />').text(self.getStatusLabel(table.current_status || 'available'));
        var $info = $('<div class="rb-timeline-modal-info" />');
        $info.append(statusBadge);
        if (table.capacity) {
            $info.append($('<span class="rb-timeline-modal-capacity" />').text((self.strings.guestsLabel || 'guests') + ': ' + table.capacity));
        }
        self.$modalBody.append($info);

        if (Array.isArray(table.bookings) && table.bookings.length) {
            var $list = $('<div class="rb-timeline-modal-bookings" />');
            table.bookings.forEach(function (booking) {
                var $item = $('<div class="rb-timeline-modal-booking-item" />');
                var timeText = '';
                if (booking.checkin_time && booking.checkout_time) {
                    timeText = booking.checkin_time + ' – ' + booking.checkout_time;
                } else if (booking.checkin_time) {
                    timeText = booking.checkin_time;
                }
                if (timeText) {
                    $item.append($('<div class="rb-timeline-modal-booking-time" />').text(timeText));
                }
                if (booking.customer_name) {
                    $item.append($('<div class="rb-timeline-modal-booking-name" />').text(booking.customer_name));
                }
                var details = [];
                if (booking.phone) {
                    details.push(booking.phone);
                }
                if (booking.guest_count) {
                    details.push(booking.guest_count + ' ' + (self.strings.guestsLabel || 'guests'));
                }
                if (details.length) {
                    $item.append($('<div class="rb-timeline-modal-booking-meta" />').text(details.join(' • ')));
                }
                if (booking.status) {
                    $item.append($('<span class="rb-timeline-modal-booking-status status-' + booking.status + '" />').text(self.getBookingStatusLabel(booking.status)));
                }
                $list.append($item);
            });
            self.$modalBody.append($list);
        } else {
            self.$modalBody.append($('<p class="rb-timeline-modal-empty" />').text(self.strings.noBookings || 'No bookings for this table.'));
        }

        self.$modalActions.empty();

        if (self.context === 'admin' && table.table_id) {
            var $label = $('<div class="rb-timeline-modal-actions-label" />').text((self.strings.currentStatus || 'Current Status') + ':');
            var $controls = self.renderStatusControls(table).addClass('rb-timeline-modal-status-control');
            self.$modalActions.append($label).append($controls);
        }

        $modal.addClass('is-visible');
        $('body').addClass('rb-timeline-modal-open');
    };

    TimelineApp.prototype.closeModal = function () {
        if (this.$modal) {
            this.$modal.removeClass('is-visible');
        }
        $('body').removeClass('rb-timeline-modal-open');
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
                self.closeModal();
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

    TimelineApp.prototype.sortBookings = function (bookings) {
        var self = this;
        var meta = self.timelineMeta || self.buildTimelineMeta([]);

        return bookings.sort(function (a, b) {
            var aStart = self.getBookingStartMinutes(a, meta);
            var bStart = self.getBookingStartMinutes(b, meta);

            if (aStart === bStart) {
                return 0;
            }

            if (aStart === null) {
                return 1;
            }

            if (bStart === null) {
                return -1;
            }

            return aStart - bStart;
        });
    };

    TimelineApp.prototype.getBookingStartMinutes = function (booking, meta) {
        meta = meta || this.timelineMeta || this.buildTimelineMeta([]);
        return meta.parseTime(booking.checkin_time || booking.start_time || booking.time || '');
    };

    TimelineApp.prototype.getBookingEndMinutes = function (booking, meta) {
        meta = meta || this.timelineMeta || this.buildTimelineMeta([]);
        return meta.parseTime(booking.checkout_time || booking.end_time || '');
    };

    TimelineApp.prototype.calculateNowOffset = function () {
        var meta = this.timelineMeta;

        if (!meta) {
            return null;
        }

        var now = new Date();
        var minutes = now.getHours() * 60 + now.getMinutes();

        if (minutes < meta.start || minutes > meta.end) {
            return null;
        }

        var minuteHeight = meta.interval > 0 ? (meta.slotHeight / meta.interval) : (meta.slotHeight / 30);
        return Math.round((minutes - meta.start) * minuteHeight);
    };

    TimelineApp.prototype.buildNowMarker = function (variant) {
        if (typeof this.nowOffset !== 'number') {
            return null;
        }

        var $marker = $('<div class="rb-timeline-now" />').css('top', this.nowOffset + 'px');

        if (variant === 'rail') {
            $marker.addClass('rb-timeline-now--rail');
        }

        return $marker;
    };

    TimelineApp.prototype.ensureNowTimer = function () {
        var self = this;

        if (self.nowTimer) {
            clearInterval(self.nowTimer);
            self.nowTimer = null;
        }

        self.updateNowMarker();

        self.nowTimer = setInterval(function () {
            self.updateNowMarker();
        }, 60000);
    };

    TimelineApp.prototype.clearNowTimer = function () {
        if (this.nowTimer) {
            clearInterval(this.nowTimer);
            this.nowTimer = null;
        }
    };

    TimelineApp.prototype.updateNowMarker = function () {
        if (!this.timelineMeta) {
            return;
        }

        var offset = this.calculateNowOffset();
        this.nowOffset = offset;

        var $markers = this.$container.find('.rb-timeline-now');

        if (typeof offset !== 'number') {
            $markers.remove();
            return;
        }

        if (!$markers.length) {
            var $times = this.$container.find('.rb-timeline-times');
            var $railMarker = this.buildNowMarker('rail');
            if ($times.length && $railMarker) {
                $times.append($railMarker);
            }

            var self = this;
            this.$container.find('.rb-timeline-column-body').each(function () {
                var $columnMarker = self.buildNowMarker('column');
                if ($columnMarker) {
                    $(this).append($columnMarker);
                }
            });

            return;
        }

        $markers.css('top', offset + 'px');
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
