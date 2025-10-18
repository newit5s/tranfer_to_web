(function ($) {
    'use strict';

    var GmailManager = {
        init: function () {
            this.layout = $('.rb-manager-gmail-layout');
            if (!this.layout.length) {
                return;
            }

            this.sidebar = this.layout.find('.rb-gmail-sidebar');
            this.detail = this.layout.find('.rb-gmail-detail');
            this.selectAll = this.layout.find('.rb-gmail-select-all-checkbox');
            this.selection = new Set();
            this.lastCheckedIndex = null;
            this.lastFocused = null;

            this.bindEvents();
            this.refreshCards();
            this.syncSelectionUI();
        },

        bindEvents: function () {
            var self = this;

            this.layout.on('click', '[data-rb-toggle-sidebar]', function () {
                self.toggleSidebar();
            });

            this.layout.on('click', '.rb-booking-select-checkbox', function (event) {
                event.stopPropagation();
                self.handleCheckbox($(this), event);
            });

            this.layout.on('click', '.rb-booking-item', function (event) {
                if ($(event.target).closest('.rb-booking-select, .rb-gmail-bulk-actions, .rb-gmail-bulk-button').length) {
                    return;
                }

                var card = $(this);
                self.focusCard(card);
                if (window.innerWidth < 769) {
                    self.openDetail();
                }
            });

            this.layout.on('keydown', '.rb-booking-item', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    $(this).trigger('click');
                }
            });

            this.layout.on('click', '.rb-gmail-bulk-button', function () {
                var action = $(this).data('bulk-action');
                if (!action) {
                    return;
                }
                self.performBulkAction(action, $(this));
            });

            this.layout.on('click', '[data-bulk-clear]', function () {
                self.clearSelection();
            });

            this.layout.on('click', '[data-rb-close-detail]', function () {
                self.closeDetail();
            });

            if (this.selectAll.length) {
                this.selectAll.on('change', function () {
                    self.toggleSelectAll($(this).prop('checked'));
                });
            }

            $(document).on('rb:manager:bookingSelected', function (event, element) {
                if (!element) {
                    return;
                }
                var card = $(element);
                self.markFocused(card);
                if (window.innerWidth < 769 && card.length) {
                    self.openDetail();
                }
            });

            $(document).on('rb:manager:bookingUpdated', function (event, booking) {
                if (!booking || !booking.id) {
                    return;
                }
                self.refreshCardFromResponse(booking);
            });

            $(document).on('rb:manager:bookingRemoved', function (event, bookingId) {
                if (!bookingId) {
                    return;
                }
                self.removeCard(bookingId);
            });

            $(document).on('keydown', function (event) {
                self.handleGlobalKeydown(event);
            });

            $(window).on('resize', function () {
                self.handleResize();
            });
        },

        refreshCards: function () {
            this.cards = this.layout.find('.rb-booking-item');
            this.cards.each(function (index, element) {
                element.setAttribute('data-rb-index', index);
            });
        },

        getCardById: function (bookingId) {
            if (!bookingId && bookingId !== 0) {
                return $();
            }
            return this.layout.find('.rb-booking-item[data-booking-id="' + bookingId + '"]');
        },

        toggleSidebar: function () {
            if (window.matchMedia('(max-width: 768px)').matches) {
                this.layout.toggleClass('is-sidebar-open');
                if (this.layout.hasClass('is-sidebar-open')) {
                    this.sidebar.removeClass('is-collapsed');
                } else {
                    this.sidebar.addClass('is-collapsed');
                }
            } else {
                this.layout.toggleClass('is-sidebar-collapsed');
                this.sidebar.toggleClass('is-collapsed', this.layout.hasClass('is-sidebar-collapsed'));
            }
        },

        openDetail: function () {
            this.layout.addClass('has-detail-open');
        },

        closeDetail: function () {
            this.layout.removeClass('has-detail-open');
        },

        handleResize: function () {
            if (window.innerWidth >= 769) {
                this.layout.removeClass('has-detail-open is-sidebar-open');
                this.sidebar.toggleClass('is-collapsed', this.layout.hasClass('is-sidebar-collapsed'));
            }
        },

        handleCheckbox: function (checkbox, event) {
            var card = checkbox.closest('.rb-booking-item');
            if (!card.length) {
                return;
            }

            var bookingId = String(card.data('bookingId') || card.attr('data-booking-id'));
            var isChecked = checkbox.prop('checked');
            var index = Number(card.attr('data-rb-index'));

            if (event.shiftKey && this.lastCheckedIndex !== null) {
                this.selectRange(this.lastCheckedIndex, index, isChecked);
            } else {
                this.updateSelectionState(bookingId, isChecked, card);
            }

            this.lastCheckedIndex = index;
            this.syncSelectionUI();
        },

        selectRange: function (start, end, checked) {
            if (start === null || start === undefined || end === null || end === undefined) {
                return;
            }

            var min = Math.min(start, end);
            var max = Math.max(start, end);
            var self = this;

            this.cards.slice(min, max + 1).each(function () {
                var card = $(this);
                var bookingId = String(card.data('bookingId') || card.attr('data-booking-id'));
                card.find('.rb-booking-select-checkbox').prop('checked', checked);
                self.updateSelectionState(bookingId, checked, card);
            });
        },

        updateSelectionState: function (bookingId, isSelected, card) {
            if (!bookingId) {
                return;
            }

            if (isSelected) {
                this.selection.add(bookingId);
                if (card) {
                    card.addClass('is-selected');
                }
            } else {
                this.selection.delete(bookingId);
                if (card) {
                    card.removeClass('is-selected');
                }
            }
        },

        toggleSelectAll: function (checked) {
            var visibleCards = this.layout.find('.rb-booking-item:visible');
            var self = this;

            visibleCards.each(function () {
                var card = $(this);
                var bookingId = String(card.data('bookingId') || card.attr('data-booking-id'));
                card.find('.rb-booking-select-checkbox').prop('checked', checked);
                self.updateSelectionState(bookingId, checked, card);
            });

            this.syncSelectionUI();
        },

        syncSelectionUI: function () {
            var count = this.selection.size;
            var visibleCards = this.layout.find('.rb-booking-item:visible');
            var selectAllChecked = count > 0 && count === visibleCards.length && visibleCards.length > 0;

            if (this.selectAll.length) {
                this.selectAll.prop('checked', selectAllChecked);
                this.selectAll.prop('indeterminate', count > 0 && count < visibleCards.length);
            }

            var label = this.layout.find('.rb-gmail-selected-count');
            if (label.length) {
                var format = label.data('selected-format');
                if (typeof format === 'string' && format.indexOf('%d') !== -1) {
                    label.text(format.replace('%d', count));
                } else {
                    label.text(count + ' selected');
                }
            }
        },

        clearSelection: function () {
            var self = this;
            this.selection.clear();
            this.layout.find('.rb-booking-select-checkbox').prop('checked', false);
            this.layout.find('.rb-booking-item').removeClass('is-selected');
            this.syncSelectionUI();
            this.lastCheckedIndex = null;
        },

        focusCard: function (card) {
            if (!card || !card.length) {
                return;
            }

            this.markFocused(card);
        },

        markFocused: function (card) {
            this.layout.find('.rb-booking-item.is-focused').removeClass('is-focused');
            if (card && card.length) {
                card.addClass('is-focused');
                this.lastFocused = card;
            }
        },

        handleGlobalKeydown: function (event) {
            if (!this.layout.length) {
                return;
            }

            if ($(event.target).is('input, textarea, select, button, [contenteditable]')) {
                return;
            }

            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                this.navigate(event.key === 'ArrowDown' ? 1 : -1);
            }

            if (event.key === 'Escape') {
                if (window.innerWidth < 769) {
                    this.closeDetail();
                }
            }
        },

        navigate: function (direction) {
            var cards = this.layout.find('.rb-booking-item:visible');
            if (!cards.length) {
                return;
            }

            var current = cards.index(cards.filter('.active').first());
            if (current === -1) {
                current = 0;
            } else {
                current = Math.max(0, Math.min(cards.length - 1, current + direction));
            }

            var next = cards.eq(current);
            if (next.length) {
                next.trigger('click');
                this.scrollIntoView(next);
            }
        },

        scrollIntoView: function (card) {
            if (!card || !card.length) {
                return;
            }

            var container = this.layout.find('.rb-gmail-list');
            if (!container.length) {
                return;
            }

            var containerEl = container.get(0);
            var cardEl = card.get(0);

            if (containerEl && cardEl && containerEl.scrollHeight > containerEl.clientHeight) {
                var cardTop = cardEl.offsetTop;
                var cardBottom = cardTop + cardEl.offsetHeight;
                var visibleTop = containerEl.scrollTop;
                var visibleBottom = visibleTop + containerEl.clientHeight;

                if (cardTop < visibleTop) {
                    containerEl.scrollTo({ top: cardTop - 16, behavior: 'smooth' });
                } else if (cardBottom > visibleBottom) {
                    containerEl.scrollTo({ top: cardBottom - containerEl.clientHeight + 16, behavior: 'smooth' });
                }
            }
        },

        performBulkAction: function (action, trigger) {
            var ids = Array.from(this.selection.values());
            if (!ids.length) {
                return;
            }

            if (action === 'cancel' && !window.confirm(rb_ajax.bulk_cancel_confirm || 'Cancel selected bookings?')) {
                return;
            }

            var self = this;
            var nonce = (window.RBManagerInbox && typeof window.RBManagerInbox.getManagerNonce === 'function') ? window.RBManagerInbox.getManagerNonce() : (rb_ajax ? rb_ajax.nonce : '');
            var feedback = $('#rb-manager-feedback');
            var successes = 0;
            var failures = [];
            var index = 0;

            trigger.prop('disabled', true);

            function next() {
                if (index >= ids.length) {
                    trigger.prop('disabled', false);
                    self.finishBulkAction(action, successes, failures, feedback);
                    self.refreshCards();
                    self.syncSelectionUI();
                    return;
                }

                var bookingId = ids[index++];

                $.ajax({
                    url: rb_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'rb_manager_update_booking',
                        booking_id: bookingId,
                        manager_action: action,
                        nonce: nonce
                    }
                }).done(function (response) {
                    if (response && response.success) {
                        successes++;
                        if (response.data && response.data.booking && window.RBManagerInbox && typeof window.RBManagerInbox.updateBookingRow === 'function') {
                            var card = self.getCardById(response.data.booking.id);
                            if (card.length) {
                                window.RBManagerInbox.updateBookingRow(card, response.data.booking);
                            }
                        }
                        if (response.data && response.data.deleted) {
                            self.removeCard(bookingId);
                            $(document).trigger('rb:manager:bookingRemoved', [bookingId]);
                        }
                        self.selection.delete(String(bookingId));
                    } else {
                        failures.push(bookingId);
                    }
                }).fail(function () {
                    failures.push(bookingId);
                }).always(function () {
                    next();
                });
            }

            next();
        },

        finishBulkAction: function (action, successes, failures, feedback) {
            var total = successes + failures.length;
            if (!feedback.length) {
                return;
            }

            var successMessage = successes > 0 ? successes + ' ' + (rb_ajax.success_text || 'updated successfully') : '';
            var failureMessage = failures.length > 0 ? failures.length + ' ' + (rb_ajax.error_text || 'failed') : '';

            feedback.removeClass('success error warning');
            if (successes && !failures.length) {
                feedback.addClass('success').text(successMessage).removeAttr('hidden').show();
            } else if (failures.length && !successes) {
                feedback.addClass('error').text(failureMessage).removeAttr('hidden').show();
            } else if (total) {
                feedback.addClass('warning').text(successMessage + (failureMessage ? ' · ' + failureMessage : '')).removeAttr('hidden').show();
            }
        },

        refreshCardFromResponse: function (booking) {
            var card = this.getCardById(booking.id);
            if (!card.length) {
                return;
            }

            card.attr('data-status', booking.status || '');
            card.attr('data-status-label', booking.status_label || booking.status || '');
            card.attr('data-guest-count', booking.guest_count || '');
            card.attr('data-booking-time', booking.booking_time || '');
            card.attr('data-booking-date', booking.booking_date || '');
            card.attr('data-date-display', booking.date_display || '');
            card.attr('data-source-label', booking.source_label || '');
            card.attr('data-created-display', booking.created_display || '');
            card.attr('data-special-requests', booking.special_requests || '');
            card.attr('data-admin-notes', booking.admin_notes || '');

            card.find('.rb-booking-item-name').text(booking.customer_name || '');
            card.find('.rb-booking-item-time').text((booking.date_display || booking.booking_date || '') + (booking.booking_time ? ' ' + booking.booking_time : ''));
            card.find('.rb-booking-item-slot').text(booking.booking_time || '');
            card.find('[data-meta="phone"]').text(booking.customer_phone ? '📞 ' + booking.customer_phone : '');
            card.find('[data-meta="guests"]').text('👥 ' + (booking.guest_count || '0'));
            card.find('[data-meta="status"]').text(booking.status_label || booking.status || '');
            card.find('[data-meta="source"]').text(booking.source_label || booking.booking_source || '');
            card.find('[data-meta="created"]').text(booking.created_display ? '⏱ ' + booking.created_display : '');
            card.find('[data-meta="special"]').text(booking.special_requests ? '📝 ' + booking.special_requests : '');

            card.removeClass(function (index, className) {
                return (className.match(/status-[^\s]+/g) || []).join(' ');
            });
            card.addClass('status-' + (booking.status || ''));
        },

        removeCard: function (bookingId) {
            var card = this.getCardById(bookingId);
            if (!card.length) {
                return;
            }
            card.remove();
            this.selection.delete(String(bookingId));
            this.refreshCards();
            this.syncSelectionUI();
            if (this.lastFocused && !this.lastFocused.closest('body').length) {
                this.lastFocused = null;
            }
        }
    };

    $(function () {
        GmailManager.init();
    });

    window.RBGmailManager = GmailManager;
})(jQuery);
