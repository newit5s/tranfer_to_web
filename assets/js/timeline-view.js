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
        this.$sidebar = null;
        this.$main = null;
        this.tableSelectionState = {};
        this.currentData = null;
        this.hasTables = false;
        this.defaultViewMode = this.normalizeViewMode(config.defaultView || 'day');
        this.viewMode = this.defaultViewMode || 'day';
        this.availableViews = ['day'];
        this.viewHistory = [];
        this.isMobile = false;
        this.drawerOpen = false;
        this.mobileMediaQuery = null;
        this.responsiveInitialized = false;
        this.$drawerOverlay = null;
        this.$toolbar = null;
        this.$content = null;
        this.$viewButtons = {};
        this.$backButton = null;
        this.$drawerToggle = null;
        this.hasRendered = false;
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

    TimelineApp.prototype.fetchData = function (options) {
        var self = this;
        options = options || {};

        var targetDate = options.date || self.$container.data('date');
        var targetLocation = options.location || self.$container.data('location');

        self.$container.addClass('rb-timeline-loading-state');

        $.post(self.ajaxUrl, {
            action: 'rb_get_timeline_data',
            nonce: self.nonce,
            date: targetDate,
            location_id: targetLocation
        }).done(function (response) {
            self.$container.removeClass('rb-timeline-loading-state');

            if (response && response.success && response.data) {
                if (options.date) {
                    self.$container.attr('data-date', options.date);
                }
                if (options.location) {
                    self.$container.attr('data-location', options.location);
                }
                self.render(response.data);
                if (typeof options.onSuccess === 'function') {
                    options.onSuccess(response.data);
                }
            } else {
                var message = (response && response.data && response.data.message)
                    ? response.data.message
                    : (self.strings.loadingError || 'Unable to load timeline data.');
                self.renderError(message);
                if (typeof options.onError === 'function') {
                    options.onError(response);
                }
            }
        }).fail(function () {
            self.$container.removeClass('rb-timeline-loading-state');
            self.renderError(self.strings.loadingError || 'Unable to load timeline data.');
            if (typeof options.onError === 'function') {
                options.onError();
            }
        });
    };

    TimelineApp.prototype.render = function (data) {
        var self = this;
        self.clearNowTimer();
        self.$container.find('.rb-timeline-now').remove();
        self.$container.empty();

        self.currentData = data || {};
        self.activeDate = self.currentData && self.currentData.date ? self.currentData.date : null;
        self.activeLocation = self.currentData && self.currentData.location_id ? self.currentData.location_id : null;

        var tables = Array.isArray(self.currentData.tables) ? self.currentData.tables : [];
        self.hasTables = tables.length > 0;

        var keys = tables.map(function (table, index) {
            return self.getTableKey(table, index);
        });

        self.cleanupTableSelectionState(keys);

        var hasSelected = false;
        keys.forEach(function (key) {
            if (typeof self.tableSelectionState[key] === 'undefined') {
                self.tableSelectionState[key] = true;
            }
            if (self.tableSelectionState[key]) {
                hasSelected = true;
            }
        });

        if (!hasSelected && keys.length) {
            keys.forEach(function (key) {
                self.tableSelectionState[key] = true;
            });
        }

        self.timelineMeta = self.buildTimelineMeta(self.currentData.time_slots || []);
        self.nowOffset = self.calculateNowOffset();

        self.availableViews = self.getAvailableViews();

        if (!self.hasRendered) {
            var initialMode = self.normalizeViewMode(self.currentData.default_view || self.currentData.view_mode || self.viewMode);
            if (initialMode) {
                self.viewMode = initialMode;
            }
        }

        var $layout = $('<div class="rb-timeline-layout" />');
        self.$sidebar = self.renderSidebar(self.currentData);
        self.$main = $('<div class="rb-timeline-main" />');
        self.$toolbar = self.renderToolbar();
        self.$content = $('<div class="rb-timeline-main-content" />');

        self.$main.append(self.$toolbar).append(self.$content);
        $layout.append(self.$sidebar).append(self.$main);
        self.$container.append($layout);

        self.$drawerOverlay = $('<div class="rb-timeline-drawer-overlay" aria-hidden="true" />');
        self.$drawerOverlay.on('click', function (event) {
            event.preventDefault();
            self.closeDrawer();
        });
        self.$container.append(self.$drawerOverlay);

        self.ensureResponsiveHandlers();
        self.renderMain();
        self.hasRendered = true;
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

    TimelineApp.prototype.cleanupTableSelectionState = function (keys) {
        if (!this.tableSelectionState) {
            this.tableSelectionState = {};
            return;
        }

        var stateKeys = Object.keys(this.tableSelectionState);
        for (var i = 0; i < stateKeys.length; i++) {
            if (keys.indexOf(stateKeys[i]) === -1) {
                delete this.tableSelectionState[stateKeys[i]];
            }
        }
    };

    TimelineApp.prototype.getTableKey = function (table, index) {
        if (!table) {
            return 'table-' + index;
        }

        if (table.table_id) {
            return 'table-' + table.table_id;
        }

        if (table.id) {
            return 'table-' + table.id;
        }

        if (table.uuid) {
            return 'table-' + table.uuid;
        }

        if (table.table_number) {
            return 'number-' + table.table_number;
        }

        if (table.slug) {
            return 'slug-' + table.slug;
        }

        if (table.name) {
            return 'name-' + table.name;
        }

        return 'table-index-' + index;
    };

    TimelineApp.prototype.getTableLabel = function (table) {
        if (!table) {
            return this.strings.tableLabel || 'Table';
        }

        if (table.table_name) {
            return table.table_name;
        }

        if (table.name) {
            return table.name;
        }

        if (table.label) {
            return table.label;
        }

        if (table.table_number) {
            return (this.strings.tableLabel || 'Table') + ' ' + table.table_number;
        }

        return this.strings.unassigned || 'Unassigned';
    };

    TimelineApp.prototype.getTableMeta = function (table) {
        if (!table) {
            return '';
        }

        var details = [];

        if (table.capacity) {
            details.push((this.strings.guestsLabel || 'guests') + ': ' + table.capacity);
        }

        if (table.current_status) {
            details.push(this.getStatusLabel(table.current_status));
        }

        return details.join(' • ');
    };

    TimelineApp.prototype.normalizeStatus = function (status) {
        if (!status) {
            return 'available';
        }

        var normalized = String(status).toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9_-]/g, '');

        if (!normalized) {
            return 'available';
        }

        return normalized;
    };

    TimelineApp.prototype.renderSidebar = function (data) {
        var self = this;
        var tables = Array.isArray(data.tables) ? data.tables : [];
        var title = self.strings.tablesSidebarTitle || 'Tables';
        var toggleLabel = self.strings.sidebarToggleLabel || title;
        var allLabel = self.strings.allTablesLabel || 'All tables';

        var $sidebar = $('<aside class="rb-timeline-sidebar is-open" />');
        var $header = $('<div class="rb-timeline-sidebar-header" />');
        var $title = $('<h3 class="rb-timeline-sidebar-title" />').text(title);
        var $toggle = $('<button type="button" class="rb-timeline-sidebar-toggle" aria-expanded="true" />')
            .text(toggleLabel)
            .on('click', function (event) {
                event.preventDefault();
                if (self.isMobile) {
                    self.toggleDrawer();
                    return;
                }
                var expanded = !$sidebar.hasClass('is-open');
                $sidebar.toggleClass('is-open', expanded);
                $toggle.attr('aria-expanded', expanded ? 'true' : 'false');
            });

        var closeLabel = self.strings.closeLabel || self.strings.backLabel || 'Close';
        var $closeButton = $('<button type="button" class="rb-timeline-sidebar-close" />')
            .attr('aria-label', closeLabel)
            .text(closeLabel)
            .on('click', function (event) {
                event.preventDefault();
                self.closeDrawer();
            });

        $header.append($title).append($toggle).append($closeButton);

        var $content = $('<div class="rb-timeline-sidebar-content" />');

        if (!tables.length) {
            $content.append(
                $('<p class="rb-timeline-sidebar-empty" />').text(self.strings.noTables || 'No tables found for the selected date.')
            );
        } else {
            var $allItem = $('<label class="rb-timeline-sidebar-item rb-timeline-sidebar-item--all" />').attr('data-table-key', 'all');
            var $allCheckbox = $('<input type="checkbox" class="rb-timeline-sidebar-checkbox" />')
                .attr('data-table-key', 'all')
                .on('change', function () {
                    self.handleSelectAllToggle($(this));
                });
            var $allIndicator = $('<span class="rb-timeline-sidebar-indicator status-all" aria-hidden="true" />');
            var $allText = $('<span class="rb-timeline-sidebar-text" />')
                .append($('<span class="rb-timeline-sidebar-label" />').text(allLabel));
            $allItem.append($allCheckbox).append($allIndicator).append($allText);
            $content.append($allItem);

            var $list = $('<div class="rb-timeline-sidebar-list" />');
            tables.forEach(function (table, index) {
                var key = self.getTableKey(table, index);
                var $item = $('<label class="rb-timeline-sidebar-item" />').attr('data-table-key', key);
                var $checkbox = $('<input type="checkbox" class="rb-timeline-sidebar-checkbox" />')
                    .attr('data-table-key', key)
                    .on('change', function () {
                        self.handleTableToggle($(this));
                    });
                var statusClass = 'status-' + self.normalizeStatus(table.current_status || 'available');
                var $indicator = $('<span class="rb-timeline-sidebar-indicator" aria-hidden="true" />').addClass(statusClass);
                var $text = $('<span class="rb-timeline-sidebar-text" />');
                $text.append($('<span class="rb-timeline-sidebar-label" />').text(self.getTableLabel(table)));
                var meta = self.getTableMeta(table);
                if (meta) {
                    $text.append($('<span class="rb-timeline-sidebar-meta" />').text(meta));
                }
                $item.append($checkbox).append($indicator).append($text);
                $list.append($item);
            });
            $content.append($list);
        }

        $sidebar.append($header).append($content);
        return $sidebar;
    };

    TimelineApp.prototype.normalizeViewMode = function (mode) {
        if (!mode) {
            return null;
        }

        var value = String(mode).toLowerCase();
        if (value === 'month' || value === 'week' || value === 'day') {
            return value;
        }

        if (value === 'weekly') {
            return 'week';
        }

        if (value === 'monthly') {
            return 'month';
        }

        return null;
    };

    TimelineApp.prototype.getAvailableViews = function () {
        var data = this.currentData || {};
        var views = [];

        if (Array.isArray(data.available_views) && data.available_views.length) {
            views = data.available_views.slice();
        } else if (Array.isArray(data.views) && data.views.length) {
            views = data.views.slice();
        } else {
            views = ['month', 'week', 'day'];
        }

        var normalized = [];
        for (var i = 0; i < views.length; i++) {
            var mode = this.normalizeViewMode(views[i]);
            if (!mode) {
                continue;
            }
            if (normalized.indexOf(mode) === -1) {
                normalized.push(mode);
            }
        }

        if (normalized.indexOf('day') === -1) {
            normalized.push('day');
        }

        return normalized;
    };

    TimelineApp.prototype.getViewLabel = function (mode) {
        var map = {
            'month': this.strings.viewMonth || 'Month',
            'week': this.strings.viewWeek || 'Week',
            'day': this.strings.viewDay || 'Day'
        };

        var normalized = this.normalizeViewMode(mode) || 'day';
        return map[normalized] || map.day;
    };

    TimelineApp.prototype.renderToolbar = function () {
        var self = this;
        var $toolbar = $('<div class="rb-timeline-toolbar" />');

        var $leftGroup = $('<div class="rb-timeline-toolbar-group rb-timeline-toolbar-group--left" />');
        var backLabel = self.strings.backLabel || 'Back';
        self.$backButton = $('<button type="button" class="rb-timeline-back-button" />')
            .text(backLabel)
            .attr('aria-label', backLabel)
            .on('click', function (event) {
                event.preventDefault();
                self.handleBackNavigation();
            });
        $leftGroup.append(self.$backButton);

        var $toggleGroup = $('<div class="rb-timeline-view-toggle" role="tablist" />');
        self.$viewButtons = {};

        var views = self.availableViews && self.availableViews.length ? self.availableViews : ['day'];
        views.forEach(function (view) {
            var normalized = self.normalizeViewMode(view) || 'day';
            if (self.$viewButtons[normalized]) {
                return;
            }
            var label = self.getViewLabel(normalized);
            var $button = $('<button type="button" class="rb-timeline-view-button" role="tab" />')
                .attr('data-view', normalized)
                .text(label)
                .on('click', function (event) {
                    event.preventDefault();
                    self.onViewToggleClick(normalized);
                });
            self.$viewButtons[normalized] = $button;
            $toggleGroup.append($button);
        });

        if (!$toggleGroup.children().length) {
            var $fallback = $('<button type="button" class="rb-timeline-view-button" role="tab" data-view="day" />')
                .text(self.getViewLabel('day'))
                .on('click', function (event) {
                    event.preventDefault();
                    self.onViewToggleClick('day');
                });
            self.$viewButtons.day = $fallback;
            $toggleGroup.append($fallback);
        }

        var $rightGroup = $('<div class="rb-timeline-toolbar-group rb-timeline-toolbar-group--right" />');
        self.$drawerToggle = $('<button type="button" class="rb-timeline-drawer-toggle" aria-expanded="false" />')
            .text(self.getDrawerLabel(false))
            .on('click', function (event) {
                event.preventDefault();
                self.toggleDrawer();
            });
        $rightGroup.append(self.$drawerToggle);

        $toolbar.append($leftGroup).append($toggleGroup).append($rightGroup);

        return $toolbar;
    };

    TimelineApp.prototype.getDrawerLabel = function (isOpen) {
        if (isOpen) {
            return this.strings.hideTablesLabel || this.strings.closeLabel || 'Hide tables';
        }
        return this.strings.openTablesLabel || this.strings.tablesSidebarTitle || 'Tables';
    };

    TimelineApp.prototype.onViewToggleClick = function (mode) {
        var normalized = this.normalizeViewMode(mode) || 'day';
        var options = {};
        if (normalized === 'day') {
            options.resetHistory = true;
        }
        this.setViewMode(normalized, options);
    };

    TimelineApp.prototype.setViewMode = function (mode, options) {
        options = options || {};
        var normalized = this.normalizeViewMode(mode) || 'day';
        var previous = this.normalizeViewMode(this.viewMode) || 'day';
        var changed = previous !== normalized;

        if (options.resetHistory) {
            this.viewHistory = [];
        } else if (options.pushHistory && changed) {
            this.viewHistory.push({ mode: previous, date: this.activeDate });
        }

        this.viewMode = normalized;

        if (options.skipRender) {
            this.updateToolbarState();
            return;
        }

        this.renderMain();
    };

    TimelineApp.prototype.handleBackNavigation = function () {
        if (!this.viewHistory || !this.viewHistory.length) {
            if (this.viewMode !== (this.defaultViewMode || 'day')) {
                this.viewMode = this.defaultViewMode || 'day';
                this.renderMain();
            }
            return;
        }

        var previous = this.viewHistory.pop();
        if (!previous) {
            this.renderMain();
            return;
        }

        var targetMode = this.normalizeViewMode(previous.mode) || 'day';
        var targetDate = previous.date || this.activeDate;

        this.viewMode = targetMode;

        if (targetDate && targetDate !== this.activeDate) {
            var self = this;
            this.renderMain();
            this.fetchData({
                date: targetDate,
                onSuccess: function () {
                    self.viewMode = targetMode;
                    self.renderMain();
                }
            });
        } else {
            this.renderMain();
        }
    };

    TimelineApp.prototype.handleDaySelection = function (date, options) {
        if (!date) {
            return;
        }

        options = options || {};

        if (options.pushHistory !== false) {
            this.viewHistory = this.viewHistory || [];
            this.viewHistory.push({ mode: this.viewMode, date: this.activeDate });
        }

        this.viewMode = 'day';

        if (this.isMobile) {
            this.closeDrawer(true);
        }

        if (date === this.activeDate) {
            this.renderMain();
            return;
        }

        var self = this;
        this.fetchData({
            date: date,
            onSuccess: function () {
                self.viewMode = 'day';
                self.renderMain();
            },
            onError: function () {
                if (self.viewHistory && self.viewHistory.length) {
                    self.viewHistory.pop();
                }
            }
        });
    };

    TimelineApp.prototype.updateToolbarState = function () {
        var self = this;
        var currentView = self.normalizeViewMode(self.viewMode) || 'day';

        if (self.$viewButtons) {
            Object.keys(self.$viewButtons).forEach(function (key) {
                var $button = self.$viewButtons[key];
                if (!$button || !$button.length) {
                    return;
                }
                var isActive = key === currentView;
                $button.toggleClass('is-active', isActive);
                $button.attr('aria-pressed', isActive ? 'true' : 'false');
                if (isActive) {
                    $button.attr('aria-current', 'page');
                } else {
                    $button.removeAttr('aria-current');
                }
            });
        }

        if (self.$backButton) {
            var hasHistory = self.viewHistory && self.viewHistory.length > 0;
            self.$backButton.toggle(hasHistory);
        }

        if (self.$drawerToggle) {
            var isMobile = !!self.isMobile;
            self.$drawerToggle.css('display', isMobile ? 'inline-flex' : 'none');
            self.$drawerToggle.attr('aria-expanded', self.drawerOpen ? 'true' : 'false');
            self.$drawerToggle.text(self.getDrawerLabel(self.drawerOpen));
        }

        if (self.$sidebar) {
            if (self.isMobile) {
                self.$sidebar.attr('aria-hidden', self.drawerOpen ? 'false' : 'true');
            } else {
                self.$sidebar.removeAttr('aria-hidden');
            }
        }

        if (self.$container) {
            self.$container.toggleClass('rb-timeline-view-month', currentView === 'month');
            self.$container.toggleClass('rb-timeline-view-week', currentView === 'week');
            self.$container.toggleClass('rb-timeline-view-day', currentView === 'day');
            self.$container.toggleClass('rb-timeline-drawer-open', !!self.drawerOpen);
        }
    };

    TimelineApp.prototype.ensureResponsiveHandlers = function () {
        var self = this;

        if (self.responsiveInitialized) {
            if (self.mobileMediaQuery) {
                self.onResponsiveChange(self.mobileMediaQuery.matches);
            } else {
                self.onResponsiveChange(false);
            }
            return;
        }

        if (typeof window === 'undefined' || !window.matchMedia) {
            self.onResponsiveChange(false);
            self.responsiveInitialized = true;
            return;
        }

        var mql = window.matchMedia('(max-width: 767px)');
        self.mobileMediaQuery = mql;

        var listener = function (event) {
            self.onResponsiveChange(event.matches);
        };

        if (typeof mql.addEventListener === 'function') {
            mql.addEventListener('change', listener);
        } else if (typeof mql.addListener === 'function') {
            mql.addListener(listener);
        }

        self.onResponsiveChange(mql.matches);
        self.responsiveInitialized = true;
    };

    TimelineApp.prototype.onResponsiveChange = function (isMobile) {
        this.isMobile = !!isMobile;

        if (this.$container) {
            this.$container.toggleClass('rb-timeline-is-mobile', this.isMobile);
        }

        if (!this.isMobile) {
            this.drawerOpen = false;
        }

        this.updateSidebarResponsiveState();
        this.updateToolbarState();
    };

    TimelineApp.prototype.updateSidebarResponsiveState = function () {
        if (!this.$sidebar || !this.$sidebar.length) {
            return;
        }

        if (this.isMobile) {
            this.$sidebar.addClass('rb-timeline-sidebar--drawer');
            if (this.drawerOpen) {
                this.$sidebar.addClass('is-open');
            } else {
                this.$sidebar.removeClass('is-open');
            }
            if (this.$drawerOverlay) {
                this.$drawerOverlay.toggleClass('is-visible', this.drawerOpen);
            }
            $('body').toggleClass('rb-timeline-drawer-open', this.drawerOpen);
        } else {
            this.$sidebar.removeClass('rb-timeline-sidebar--drawer');
            if (this.$drawerOverlay) {
                this.$drawerOverlay.removeClass('is-visible');
            }
            $('body').removeClass('rb-timeline-drawer-open');
        }

        var $toggle = this.$sidebar.find('.rb-timeline-sidebar-toggle');
        if ($toggle.length) {
            if (this.isMobile) {
                $toggle.attr('aria-expanded', this.drawerOpen ? 'true' : 'false');
            } else {
                $toggle.attr('aria-expanded', this.$sidebar.hasClass('is-open') ? 'true' : 'false');
            }
        }
    };

    TimelineApp.prototype.openDrawer = function () {
        if (!this.isMobile || !this.$sidebar) {
            return;
        }

        this.drawerOpen = true;
        this.updateSidebarResponsiveState();
        this.updateToolbarState();
    };

    TimelineApp.prototype.closeDrawer = function (skipFocus) {
        if (!this.$sidebar) {
            return;
        }

        this.drawerOpen = false;
        this.updateSidebarResponsiveState();
        this.updateToolbarState();

        if (!skipFocus && this.isMobile && this.$drawerToggle && this.$drawerToggle.length) {
            this.$drawerToggle.trigger('focus');
        }
    };

    TimelineApp.prototype.toggleDrawer = function () {
        if (!this.isMobile) {
            return;
        }

        if (this.drawerOpen) {
            this.closeDrawer();
        } else {
            this.openDrawer();
        }
    };

    TimelineApp.prototype.areAllTablesSelected = function () {
        var data = this.currentData || {};
        var tables = Array.isArray(data.tables) ? data.tables : [];

        if (!tables.length) {
            return false;
        }

        var state = this.tableSelectionState || {};
        for (var i = 0; i < tables.length; i++) {
            var key = this.getTableKey(tables[i], i);
            if (!state[key]) {
                return false;
            }
        }

        return true;
    };

    TimelineApp.prototype.hasAnyTableSelected = function () {
        var data = this.currentData || {};
        var tables = Array.isArray(data.tables) ? data.tables : [];
        var state = this.tableSelectionState || {};

        for (var i = 0; i < tables.length; i++) {
            var key = this.getTableKey(tables[i], i);
            if (state[key]) {
                return true;
            }
        }

        return false;
    };

    TimelineApp.prototype.isTableSelected = function (key) {
        if (!key) {
            return false;
        }

        if (!this.tableSelectionState) {
            this.tableSelectionState = {};
        }

        if (typeof this.tableSelectionState[key] === 'undefined') {
            return true;
        }

        return !!this.tableSelectionState[key];
    };

    TimelineApp.prototype.updateSidebarSelectionStates = function () {
        var self = this;

        if (!self.$sidebar || !self.$sidebar.length) {
            return;
        }

        var allSelected = self.areAllTablesSelected();
        var hasAny = self.hasAnyTableSelected();
        var $allCheckbox = self.$sidebar.find('.rb-timeline-sidebar-item--all input[type="checkbox"]');
        if ($allCheckbox.length) {
            $allCheckbox.prop('checked', allSelected);
            $allCheckbox.prop('indeterminate', hasAny && !allSelected);
            $allCheckbox.prop('disabled', !self.hasTables);
        }

        self.$sidebar.find('.rb-timeline-sidebar-item').each(function () {
            var $item = $(this);
            var key = $item.data('tableKey');
            if (!key || key === 'all') {
                return;
            }
            var checked = self.isTableSelected(key);
            $item.toggleClass('is-unchecked', !checked);
            $item.find('input[type="checkbox"]').prop('checked', checked);
        });
    };

    TimelineApp.prototype.selectAllTables = function () {
        var self = this;
        var data = self.currentData || {};
        var tables = Array.isArray(data.tables) ? data.tables : [];

        if (!tables.length) {
            return;
        }

        if (!self.tableSelectionState) {
            self.tableSelectionState = {};
        }

        tables.forEach(function (table, index) {
            var key = self.getTableKey(table, index);
            self.tableSelectionState[key] = true;
        });

        self.renderMain();
    };

    TimelineApp.prototype.handleSelectAllToggle = function ($checkbox) {
        if (!$checkbox || !$checkbox.length) {
            return;
        }

        var self = this;
        var isChecked = $checkbox.is(':checked');
        var data = self.currentData || {};
        var tables = Array.isArray(data.tables) ? data.tables : [];

        if (!self.tableSelectionState) {
            self.tableSelectionState = {};
        }

        if (isChecked) {
            self.selectAllTables();
            return;
        }

        tables.forEach(function (table, index) {
            var key = self.getTableKey(table, index);
            self.tableSelectionState[key] = false;
        });

        self.renderMain();
    };

    TimelineApp.prototype.handleTableToggle = function ($checkbox) {
        if (!$checkbox || !$checkbox.length) {
            return;
        }

        if (!this.tableSelectionState) {
            this.tableSelectionState = {};
        }

        var key = $checkbox.data('tableKey');
        if (!key) {
            return;
        }

        this.tableSelectionState[key] = $checkbox.is(':checked');
        this.renderMain();
    };

    TimelineApp.prototype.getVisibleTables = function () {
        var self = this;
        var data = self.currentData || {};
        var tables = Array.isArray(data.tables) ? data.tables : [];
        var visible = [];

        tables.forEach(function (table, index) {
            var key = self.getTableKey(table, index);
            var selected = self.tableSelectionState && typeof self.tableSelectionState[key] !== 'undefined'
                ? self.tableSelectionState[key]
                : true;
            if (selected) {
                visible.push(table);
            }
        });

        return visible;
    };

    TimelineApp.prototype.renderMain = function () {
        var self = this;

        if (!self.$main || !self.$main.length) {
            return;
        }

        if (!self.$content || !self.$content.length) {
            self.$content = $('<div class="rb-timeline-main-content" />');
            self.$main.append(self.$content);
        }

        self.$content.empty();
        self.$container.find('.rb-timeline-now').remove();
        self.clearNowTimer();

        var mode = self.normalizeViewMode(self.viewMode) || 'day';

        if (mode === 'month') {
            self.renderMonthView();
        } else if (mode === 'week') {
            self.renderWeekView();
        } else {
            self.renderDayView();
        }

        self.updateSidebarSelectionStates();
        self.updateToolbarState();
    };

    TimelineApp.prototype.renderDayView = function () {
        var self = this;

        if (!self.$content || !self.$content.length) {
            return;
        }

        if (!self.hasTables) {
            var noTablesMessage = self.strings.noTables || 'No tables found for the selected date.';
            var $emptyCard = $('<div class="rb-timeline-main-card rb-timeline-main-card--empty" />')
                .append($('<div class="rb-timeline-empty" />').text(noTablesMessage));
            self.$content.append($emptyCard);
            self.updateSidebarSelectionStates();
            return;
        }

        var visibleTables = self.getVisibleTables();

        if (!visibleTables.length) {
            var selectMessage = self.strings.noTablesSelected || 'Select tables from the list to display bookings.';
            var showAllLabel = self.strings.showAllTables || 'Show all tables';
            var $card = $('<div class="rb-timeline-main-card rb-timeline-main-card--empty" />');
            $card.append($('<div class="rb-timeline-empty rb-timeline-empty--selection" />').text(selectMessage));

            var $actions = $('<div class="rb-timeline-selection-actions" />');
            var $button = $('<button type="button" class="button button-primary rb-timeline-reset-selection" />')
                .text(showAllLabel)
                .on('click', function (event) {
                    event.preventDefault();
                    self.selectAllTables();
                });
            $actions.append($button);
            $card.append($actions);
            self.$content.append($card);
            self.updateSidebarSelectionStates();
            return;
        }

        self.timelineMeta = self.buildTimelineMeta(self.currentData.time_slots || []);
        self.nowOffset = self.calculateNowOffset();

        var $cardContainer = $('<div class="rb-timeline-main-card" />');
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

        visibleTables.forEach(function (table) {
            $columnsWrapper.append(self.renderTable(table));
        });

        $columnsScroll.append($columnsWrapper);
        $grid.append($timesColumn).append($columnsScroll);
        $cardContainer.append($grid);
        self.$content.append($cardContainer);

        self.updateSidebarSelectionStates();
        self.ensureNowTimer();
    };

    TimelineApp.prototype.renderWeekView = function () {
        var self = this;

        if (!self.$content || !self.$content.length) {
            return;
        }

        var weekData = self.getWeekViewData();
        var $card = $('<div class="rb-timeline-main-card rb-timeline-main-card--week" />');

        if (!weekData || !Array.isArray(weekData.days) || !weekData.days.length) {
            $card.addClass('rb-timeline-main-card--empty')
                .append($('<div class="rb-timeline-empty" />').text(self.strings.noWeekData || 'No weekly data available.'));
            self.$content.append($card);
            return;
        }

        var title = weekData.label || self.formatWeekRange(weekData.start, weekData.end);
        if (title) {
            $card.append($('<div class="rb-timeline-week-title" />').text(title));
        }

        var $list = $('<div class="rb-timeline-week-list" />');

        weekData.days.forEach(function (day) {
            var normalized = self.normalizeWeekDay(day, weekData);
            var $row = $('<button type="button" class="rb-timeline-week-row" />');

            if (!normalized.date) {
                $row.prop('disabled', true);
            }

            if (normalized.date && normalized.date === self.activeDate) {
                $row.addClass('is-active');
            }

            if (normalized.isToday) {
                $row.addClass('is-today');
            }

            var weekdayText = normalized.weekday || '';
            var dateLabel = normalized.dateLabel || '';

            $row.append($('<div class="rb-timeline-weekday" />').text(weekdayText));
            $row.append($('<div class="rb-timeline-week-date" />').text(dateLabel));

            var $counts = $('<div class="rb-timeline-week-counts" />');
            var bookingsLabel = self.strings.bookingsLabel || 'Bookings';
            if (typeof normalized.total === 'number') {
                var totalText = normalized.total + ' ' + bookingsLabel;
                $counts.append($('<span class="rb-timeline-week-count rb-timeline-week-count--total" />').text(totalText));
            }

            var $badges = self.buildStatusBadges(normalized.statusCounts);
            if ($badges) {
                $counts.append($badges);
            }

            $row.append($counts);

            if (normalized.date) {
                var ariaLabel = normalized.ariaLabel || self.formatDayButtonLabel({
                    date: normalized.date,
                    count: normalized.total,
                    isToday: normalized.isToday
                });
                if (ariaLabel) {
                    $row.attr('aria-label', ariaLabel);
                }

                $row.on('click', function (event) {
                    event.preventDefault();
                    self.handleDaySelection(normalized.date, { source: 'week' });
                });
            }

            $list.append($row);
        });

        $card.append($list);
        self.$content.append($card);
    };

    TimelineApp.prototype.renderMonthView = function () {
        var self = this;

        if (!self.$content || !self.$content.length) {
            return;
        }

        var monthData = self.getMonthViewData();
        var $card = $('<div class="rb-timeline-main-card rb-timeline-main-card--month" />');

        if (!monthData || !Array.isArray(monthData.weeks) || !monthData.weeks.length) {
            $card.addClass('rb-timeline-main-card--empty')
                .append($('<div class="rb-timeline-empty" />').text(self.strings.noCalendarData || 'No calendar data available.'));
            self.$content.append($card);
            return;
        }

        var referenceMonth = monthData.current || monthData.month || monthData.start || self.activeDate;
        var monthTitle = monthData.label || self.formatMonthLabel(referenceMonth);
        if (monthTitle) {
            $card.append($('<div class="rb-timeline-month-title" />').text(monthTitle));
        }

        var weekdays = monthData.weekdays || self.getWeekdayNames();
        if (Array.isArray(weekdays) && weekdays.length) {
            var $weekdays = $('<div class="rb-timeline-month-weekdays" />');
            weekdays.forEach(function (weekday) {
                $weekdays.append($('<div class="rb-timeline-month-weekday" />').text(weekday));
            });
            $card.append($weekdays);
        }

        var $grid = $('<div class="rb-timeline-month-grid" />');

        monthData.weeks.forEach(function (week) {
            if (!Array.isArray(week)) {
                return;
            }
            week.forEach(function (day) {
                var normalized = self.normalizeCalendarDay(day, monthData);
                var $cell = $('<button type="button" class="rb-timeline-month-cell" />');

                if (!normalized.date) {
                    $cell.prop('disabled', true);
                }

                if (!normalized.isCurrentMonth) {
                    $cell.addClass('is-outside');
                }

                if (normalized.isToday) {
                    $cell.addClass('is-today');
                }

                if (normalized.date && normalized.date === self.activeDate) {
                    $cell.addClass('is-active');
                }

                if (normalized.count > 0) {
                    $cell.addClass('has-bookings');
                }

                var dayLabel = normalized.dayLabel || (normalized.dayNumber !== null ? String(normalized.dayNumber) : '');
                $cell.append($('<span class="rb-timeline-month-day" />').text(dayLabel));

                if (normalized.count > 0) {
                    $cell.append($('<span class="rb-timeline-month-count" />').text(normalized.count));
                }

                if (normalized.date) {
                    var ariaLabel = normalized.ariaLabel || self.formatDayButtonLabel({
                        date: normalized.date,
                        count: normalized.count,
                        isToday: normalized.isToday
                    });
                    if (ariaLabel) {
                        $cell.attr('aria-label', ariaLabel);
                    }

                    $cell.on('click', function (event) {
                        event.preventDefault();
                        self.handleDaySelection(normalized.date, { source: 'month' });
                    });
                }

                $grid.append($cell);
            });
        });

        if (!$grid.children().length) {
            $card.addClass('rb-timeline-main-card--empty')
                .append($('<div class="rb-timeline-empty" />').text(self.strings.noCalendarData || 'No calendar data available.'));
        } else {
            $card.append($grid);
        }

        self.$content.append($card);
    };

    TimelineApp.prototype.getWeekViewData = function () {
        var data = this.currentData || {};

        if (data.week_view && Array.isArray(data.week_view.days)) {
            return data.week_view;
        }

        if (data.week && Array.isArray(data.week.days)) {
            return data.week;
        }

        if (Array.isArray(data.week_days)) {
            return { days: data.week_days };
        }

        return null;
    };

    TimelineApp.prototype.getMonthViewData = function () {
        var data = this.currentData || {};

        if (data.month_view && Array.isArray(data.month_view.weeks)) {
            return data.month_view;
        }

        if (data.calendar && Array.isArray(data.calendar.weeks)) {
            return data.calendar;
        }

        if (Array.isArray(data.month_weeks)) {
            return { weeks: data.month_weeks };
        }

        if (Array.isArray(data.weeks)) {
            return { weeks: data.weeks };
        }

        return null;
    };

    TimelineApp.prototype.normalizeWeekDay = function (day, weekData) {
        var self = this;
        var info = {
            date: null,
            weekday: '',
            dateLabel: '',
            total: 0,
            statusCounts: null,
            isToday: false,
            ariaLabel: ''
        };

        if (!day) {
            return info;
        }

        if (day.date) {
            info.date = day.date;
        } else if (day.value) {
            info.date = day.value;
        } else if (day.full_date) {
            info.date = day.full_date;
        }

        if (!info.date && typeof day.day === 'string' && day.day.indexOf('-') !== -1) {
            info.date = day.day;
        }

        if (!info.date && weekData && weekData.start && typeof day.offset === 'number') {
            var base = self.parseDateValue(weekData.start);
            if (base) {
                var candidate = new Date(base);
                candidate.setDate(candidate.getDate() + day.offset);
                info.date = candidate.getFullYear() + '-' + ('0' + (candidate.getMonth() + 1)).slice(-2) + '-' + ('0' + candidate.getDate()).slice(-2);
            }
        }

        info.weekday = day.weekday || day.day_label || '';
        if (!info.weekday && info.date) {
            info.weekday = self.formatWeekdayLabel(info.date);
        }

        info.dateLabel = day.label || day.display || self.formatDateLabel(info.date);

        if (typeof day.total !== 'undefined') {
            info.total = parseInt(day.total, 10);
        } else if (typeof day.total_bookings !== 'undefined') {
            info.total = parseInt(day.total_bookings, 10);
        } else if (typeof day.bookings_count !== 'undefined') {
            info.total = parseInt(day.bookings_count, 10);
        } else {
            info.total = self.extractBookingCount(day);
        }

        if (isNaN(info.total) || info.total < 0) {
            info.total = 0;
        }

        info.statusCounts = self.extractStatusCounts(day);

        var dateObj = self.parseDateValue(info.date);
        if (dateObj) {
            var today = new Date();
            info.isToday = dateObj.toDateString() === today.toDateString();
            info.ariaLabel = self.formatDayButtonLabel({
                date: info.date,
                count: info.total,
                isToday: info.isToday
            });
        }

        return info;
    };

    TimelineApp.prototype.normalizeCalendarDay = function (day, monthData) {
        var self = this;
        var info = {
            date: null,
            dayNumber: null,
            dayLabel: '',
            count: 0,
            statusCounts: null,
            isCurrentMonth: true,
            isToday: false,
            ariaLabel: ''
        };

        if (!day) {
            return info;
        }

        if (typeof day === 'string') {
            info.date = day;
        } else if (day.date) {
            info.date = day.date;
        } else if (day.value) {
            info.date = day.value;
        }

        if (typeof day.day !== 'undefined') {
            info.dayNumber = parseInt(day.day, 10);
        } else if (info.date) {
            var parts = info.date.split('-');
            if (parts.length === 3) {
                info.dayNumber = parseInt(parts[2], 10);
            }
        }

        if (day.label || day.display) {
            info.dayLabel = day.label || day.display;
        }

        if (typeof day.is_current_month === 'boolean') {
            info.isCurrentMonth = day.is_current_month;
        } else if (typeof day.in_month === 'boolean') {
            info.isCurrentMonth = day.in_month;
        } else if (info.date && monthData && (monthData.month || monthData.current || monthData.start)) {
            var reference = monthData.month || monthData.current || monthData.start;
            if (reference && reference.length >= 7) {
                info.isCurrentMonth = info.date.slice(0, 7) === reference.slice(0, 7);
            }
        }

        info.count = self.extractBookingCount(day);
        info.statusCounts = self.extractStatusCounts(day);

        if (isNaN(info.count) || info.count < 0) {
            info.count = 0;
        }

        var dateObj = self.parseDateValue(info.date);
        if (dateObj) {
            var today = new Date();
            info.isToday = dateObj.toDateString() === today.toDateString();
            info.ariaLabel = self.formatDayButtonLabel({
                date: info.date,
                count: info.count,
                isToday: info.isToday
            });
        }

        return info;
    };

    TimelineApp.prototype.extractBookingCount = function (item) {
        if (!item) {
            return 0;
        }

        var value = null;

        if (typeof item.bookings_count !== 'undefined') {
            value = item.bookings_count;
        } else if (typeof item.total_bookings !== 'undefined') {
            value = item.total_bookings;
        } else if (typeof item.count !== 'undefined') {
            value = item.count;
        } else if (item.summary && typeof item.summary.total !== 'undefined') {
            value = item.summary.total;
        } else if (Array.isArray(item.bookings)) {
            value = item.bookings.length;
        }

        var parsed = parseInt(value, 10);
        if (isNaN(parsed) || parsed < 0) {
            return 0;
        }

        return parsed;
    };

    TimelineApp.prototype.extractStatusCounts = function (item) {
        if (!item) {
            return null;
        }

        if (item.status_counts && typeof item.status_counts === 'object') {
            return item.status_counts;
        }

        if (item.summary && typeof item.summary === 'object') {
            if (item.summary.status_counts && typeof item.summary.status_counts === 'object') {
                return item.summary.status_counts;
            }
            return item.summary;
        }

        return null;
    };

    TimelineApp.prototype.buildStatusBadges = function (statusCounts) {
        if (!statusCounts) {
            return null;
        }

        var map = {
            'confirmed': this.strings.statusConfirmed || 'Confirmed',
            'pending': this.strings.statusPending || 'Pending',
            'completed': this.strings.statusCompleted || 'Completed',
            'cancelled': this.strings.statusCancelled || 'Cancelled'
        };

        var keys = Object.keys(map);
        var $wrapper = $('<div class="rb-timeline-week-statuses" />');
        var hasContent = false;

        for (var i = 0; i < keys.length; i++) {
            var key = keys[i];
            var count = parseInt(statusCounts[key], 10);
            if (!isNaN(count) && count > 0) {
                hasContent = true;
                var label = count + ' ' + map[key];
                $wrapper.append($('<span class="rb-timeline-week-status status-' + key + '" />').text(label));
            }
        }

        if (!hasContent) {
            return null;
        }

        return $wrapper;
    };

    TimelineApp.prototype.getWeekdayNames = function () {
        var names = [];
        for (var i = 0; i < 7; i++) {
            var date = new Date(2020, 5, 7 + i);
            names.push(date.toLocaleDateString(undefined, { weekday: 'short' }));
        }
        return names;
    };

    TimelineApp.prototype.parseDateValue = function (value) {
        if (!value) {
            return null;
        }

        if (value instanceof Date) {
            return value;
        }

        if (typeof value !== 'string') {
            value = String(value);
        }

        if (value.length === 7) {
            value = value + '-01';
        }

        var parts = value.split(/[-\/]/);
        if (parts.length < 3) {
            return null;
        }

        var year = parseInt(parts[0], 10);
        var month = parseInt(parts[1], 10) - 1;
        var day = parseInt(parts[2], 10);

        if (isNaN(year) || isNaN(month) || isNaN(day)) {
            return null;
        }

        var date = new Date(year, month, day);
        if (isNaN(date.getTime())) {
            return null;
        }

        return date;
    };

    TimelineApp.prototype.formatMonthLabel = function (value) {
        var date = this.parseDateValue(value);
        if (!date) {
            return value || '';
        }

        return date.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
    };

    TimelineApp.prototype.formatWeekRange = function (start, end) {
        var startDate = this.parseDateValue(start);
        var endDate = this.parseDateValue(end);

        if (startDate && endDate) {
            var startLabel = startDate.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
            var endLabel = endDate.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
            return startLabel + ' – ' + endLabel;
        }

        var single = startDate || endDate;
        if (single) {
            return single.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
        }

        return '';
    };

    TimelineApp.prototype.formatDateLabel = function (value) {
        var date = this.parseDateValue(value);
        if (!date) {
            return value || '';
        }

        return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    };

    TimelineApp.prototype.formatWeekdayLabel = function (value) {
        var date = this.parseDateValue(value);
        if (!date) {
            return '';
        }

        return date.toLocaleDateString(undefined, { weekday: 'short' });
    };

    TimelineApp.prototype.formatDayButtonLabel = function (info) {
        if (!info || !info.date) {
            return '';
        }

        var date = this.parseDateValue(info.date);
        if (!date) {
            return info.date;
        }

        var label = date.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' });
        var count = typeof info.count === 'number' ? info.count : 0;
        if (count > 0) {
            label += ' – ' + count + ' ' + (this.strings.bookingsLabel || 'Bookings');
        }
        if (info.isToday && this.strings.todayLabel) {
            label += ' (' + this.strings.todayLabel + ')';
        }
        return label;
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
