(function (wp, settings) {
    const appRoot = document.getElementById('rb-admin-app');
    const fallbackConfig = settings && settings.fallback ? settings.fallback : {};
    const fallbackHandles = Array.isArray(fallbackConfig.missingHandles) ? fallbackConfig.missingHandles : [];

    const renderFallback = (override) => {
        if (!appRoot) {
            return;
        }

        const message = override && override.message ? override.message : (fallbackConfig.message || '');
        const help = override && override.help ? override.help : (fallbackConfig.help || '');
        const handles = override && Array.isArray(override.missingHandles) ? override.missingHandles : fallbackHandles;
        const listLabel = (override && override.listLabel) || fallbackConfig.listLabel || '';
        const legacyUrl = (override && override.legacyUrl) || fallbackConfig.legacyUrl || '';
        const legacyLabel = (override && override.legacyLabel) || fallbackConfig.legacyLabel || '';

        const container = document.createElement('div');
        container.className = 'rb-admin-app__fallback';

        if (message) {
            const title = document.createElement('p');
            title.className = 'rb-admin-app__fallback-title';
            title.textContent = message;
            container.appendChild(title);
        }

        if (help) {
            const helpEl = document.createElement('p');
            helpEl.textContent = help;
            container.appendChild(helpEl);
        }

        if (handles.length) {
            const listEl = document.createElement('p');
            if (listLabel && listLabel.indexOf('%s') !== -1) {
                listEl.textContent = listLabel.replace('%s', handles.join(', '));
            } else if (listLabel) {
                listEl.textContent = `${listLabel} ${handles.join(', ')}`;
            } else {
                listEl.textContent = handles.join(', ');
            }
            container.appendChild(listEl);
        }

        if (legacyUrl && legacyLabel) {
            const action = document.createElement('p');
            const link = document.createElement('a');
            link.className = 'button button-primary';
            link.href = legacyUrl;
            link.textContent = legacyLabel;
            action.appendChild(link);
            container.appendChild(action);
        }

        appRoot.innerHTML = '';
        appRoot.appendChild(container);
    };

    if (settings) {
        settings.renderFallback = renderFallback;
    }

    if (!wp || !wp.element || !wp.components || !wp.apiFetch) {
        renderFallback();
        return;
    }

    const { createElement: h, Fragment, useCallback, useEffect, useMemo, useRef, useState } = wp.element;
    const { __ } = wp.i18n || { __: (text) => text };
    const {
        Button,
        Card,
        CardBody,
        CardHeader,
        Notice,
        SelectControl,
        Spinner,
        TabPanel,
        TextControl,
    } = wp.components;
    const BadgeComponent = wp.components.Badge
        ? (props) => h(wp.components.Badge, props)
        : (props) => h('span', { className: `rb-admin-app__badge rb-admin-app__badge--${props.status || 'info'}` }, props.children);
    const apiFetch = wp.apiFetch;
    const restRoot = settings && settings.root ? settings.root : settings.legacyRoot;

    if (restRoot && apiFetch && typeof apiFetch.createRootURLMiddleware === 'function') {
        apiFetch.use(apiFetch.createRootURLMiddleware(restRoot));
    }

    if (settings && settings.nonce) {
        apiFetch.use(apiFetch.createNonceMiddleware(settings.nonce));
    }

    const STATUS_ACTIONS = ['pending', 'confirmed', 'completed', 'cancelled', 'no-show'];

    const buildQuery = (params) => {
        const query = new URLSearchParams();
        Object.keys(params).forEach((key) => {
            if (params[key] !== undefined && params[key] !== '' && params[key] !== null) {
                query.append(key, params[key]);
            }
        });

        const result = query.toString();
        return result ? `?${result}` : '';
    };

    const useMountedRef = () => {
        const mounted = useRef(false);
        useEffect(() => {
            mounted.current = true;
            return () => {
                mounted.current = false;
            };
        }, []);
        return mounted;
    };

    const useStats = (locationId) => {
        const [state, setState] = useState({ loading: true, error: null, data: null });
        const mounted = useMountedRef();

        useEffect(() => {
            setState({ loading: true, error: null, data: null });
            apiFetch({ path: `stats${buildQuery({ location_id: locationId })}` })
                .then((response) => {
                    if (mounted.current) {
                        setState({ loading: false, error: null, data: response });
                    }
                })
                .catch((error) => {
                    if (mounted.current) {
                        setState({ loading: false, error: error, data: null });
                    }
                });
        }, [locationId]);

        return state;
    };

    const usePaginatedResource = (resource, params, deps) => {
        const [state, setState] = useState({ loading: true, error: null, data: null });
        const mounted = useMountedRef();

        useEffect(() => {
            setState({ loading: true, error: null, data: null });
            apiFetch({ path: `${resource}${buildQuery(params)}` })
                .then((response) => {
                    if (mounted.current) {
                        setState({ loading: false, error: null, data: response });
                    }
                })
                .catch((error) => {
                    if (mounted.current) {
                        setState({ loading: false, error: error, data: null });
                    }
                });
        }, deps);

        return state;
    };

    const MetricGrid = ({ metrics }) => {
        const items = useMemo(() => {
            if (!metrics) {
                return [];
            }

            return [
                { key: 'total', label: __('Total', 'restaurant-booking') },
                { key: 'pending', label: __('Pending', 'restaurant-booking') },
                { key: 'confirmed', label: __('Confirmed', 'restaurant-booking') },
                { key: 'completed', label: __('Completed', 'restaurant-booking') },
                { key: 'cancelled', label: __('Cancelled', 'restaurant-booking') },
                { key: 'today', label: __('Today', 'restaurant-booking') },
                { key: 'today_confirmed', label: __('Today confirmed', 'restaurant-booking') },
                { key: 'today_cancelled', label: __('Today cancelled', 'restaurant-booking') },
            ];
        }, [metrics]);

        return h(
            'div',
            { className: 'rb-admin-app__metrics' },
            items.map((item) =>
                h(
                    'div',
                    { key: item.key, className: 'rb-admin-app__metric' },
                    h('span', { className: 'rb-admin-app__metric-label' }, item.label),
                    h('strong', { className: 'rb-admin-app__metric-value' }, metrics ? metrics[item.key] || 0 : '–')
                )
            )
        );
    };

    const SourcesList = ({ sources }) => {
        if (!sources || sources.length === 0) {
            return h('p', { className: 'rb-admin-app__muted' }, __('No booking source data yet.', 'restaurant-booking'));
        }

        return h(
            'ul',
            { className: 'rb-admin-app__sources' },
            sources.map((source) =>
                h(
                    'li',
                    { key: source.source || 'unknown' },
                    h('span', { className: 'rb-admin-app__source-name' }, source.source || __('Unknown', 'restaurant-booking')),
                    h('span', { className: 'rb-admin-app__source-value' }, source.total)
                )
            )
        );
    };

    const StatusBadge = ({ status }) => {
        const labels = settings && settings.statusLabels ? settings.statusLabels : {};
        return h(
            'span',
            { className: `rb-admin-app__status rb-admin-app__status--${status}` },
            labels[status] || status
        );
    };

    const Pagination = ({ pagination, onPageChange }) => {
        if (!pagination) {
            return null;
        }

        const currentPage = pagination.page || 1;
        const totalPages = pagination.total_pages || 1;

        return h(
            'div',
            { className: 'rb-admin-app__pagination' },
            h(Button, {
                variant: 'tertiary',
                disabled: currentPage <= 1,
                onClick: () => onPageChange(Math.max(1, currentPage - 1)),
                children: __('Previous', 'restaurant-booking'),
            }),
            h('span', { className: 'rb-admin-app__pagination-status' }, `${currentPage} / ${Math.max(totalPages, 1)}`),
            h(Button, {
                variant: 'tertiary',
                disabled: currentPage >= totalPages,
                onClick: () => onPageChange(Math.min(totalPages, currentPage + 1)),
                children: __('Next', 'restaurant-booking'),
            })
        );
    };

    const BookingsPanel = ({ locationId, statusLabels, i18n }) => {
        const [status, setStatus] = useState('');
        const [search, setSearch] = useState('');
        const [page, setPage] = useState(1);
        const [perPage, setPerPage] = useState(10);
        const [order, setOrder] = useState('DESC');
        const [refreshIndex, setRefreshIndex] = useState(0);
        const [notice, setNotice] = useState(null);
        const [updatingId, setUpdatingId] = useState(null);

        const params = useMemo(
            () => ({
                location_id: locationId,
                status,
                search,
                order,
                per_page: perPage,
                page,
            }),
            [locationId, status, search, order, perPage, page]
        );

        const { loading, error, data } = usePaginatedResource('bookings', params, [params, refreshIndex]);
        const bookings = (data && data.bookings) || [];
        const pagination = data && data.pagination;

        useEffect(() => {
            setPage(1);
        }, [locationId, status, search, perPage]);

        const statusOptions = useMemo(() => {
            const options = [{ label: __('All statuses', 'restaurant-booking'), value: '' }];
            STATUS_ACTIONS.forEach((key) => {
                options.push({ label: statusLabels[key] || key, value: key });
            });
            return options;
        }, [statusLabels]);

        const refresh = useCallback(() => {
            setRefreshIndex((value) => value + 1);
        }, []);

        const handleStatusChange = useCallback(
            (bookingId, nextStatus) => {
                setUpdatingId(bookingId);
                apiFetch({
                    path: `bookings/${bookingId}/status`,
                    method: 'POST',
                    data: { status: nextStatus },
                })
                    .then(() => {
                        setNotice({ type: 'success', message: __('Booking updated.', 'restaurant-booking') });
                        refresh();
                    })
                    .catch((updateError) => {
                        setNotice({ type: 'error', message: updateError.message || __('Failed to update booking.', 'restaurant-booking') });
                    })
                    .finally(() => setUpdatingId(null));
            },
            [refresh]
        );

        return h(
            Fragment,
            null,
            notice &&
                h(Notice, {
                    status: notice.type,
                    isDismissible: true,
                    onRemove: () => setNotice(null),
                    children: notice.message,
                }),
            h(
                'div',
                { className: 'rb-admin-app__filters' },
                h(SelectControl, {
                    label: i18n.filters,
                    value: status,
                    options: statusOptions,
                    onChange: (value) => setStatus(value),
                }),
                h(TextControl, {
                    label: i18n.searchBookings,
                    value: search,
                    onChange: (value) => setSearch(value),
                    placeholder: __('Customer, phone or booking ID…', 'restaurant-booking'),
                }),
                h(SelectControl, {
                    label: i18n.perPage,
                    value: String(perPage),
                    options: [10, 20, 50].map((size) => ({ label: String(size), value: String(size) })),
                    onChange: (value) => setPerPage(parseInt(value, 10)),
                }),
                h(SelectControl, {
                    label: __('Order', 'restaurant-booking'),
                    value: order,
                    options: [
                        { label: __('Newest first', 'restaurant-booking'), value: 'DESC' },
                        { label: __('Oldest first', 'restaurant-booking'), value: 'ASC' },
                    ],
                    onChange: (value) => setOrder(value),
                }),
                h(Button, { variant: 'secondary', onClick: refresh, children: i18n.reload })
            ),
            loading && h('div', { className: 'rb-admin-app__loading' }, h(Spinner, null)),
            error &&
                h(Notice, {
                    status: 'error',
                    isDismissible: false,
                    children: error.message || __('Failed to load bookings.', 'restaurant-booking'),
                }),
            !loading && !error && bookings.length === 0 &&
                h('p', { className: 'rb-admin-app__empty' }, i18n.emptyState),
            !loading && !error && bookings.length > 0 &&
                h(
                    'table',
                    { className: 'rb-admin-app__table' },
                    h(
                        'thead',
                        null,
                        h(
                            'tr',
                            null,
                            ['#', __('Customer', 'restaurant-booking'), __('Schedule', 'restaurant-booking'), __('Guests', 'restaurant-booking'), __('Status', 'restaurant-booking'), __('Source', 'restaurant-booking'), __('Actions', 'restaurant-booking')].map((label) =>
                                h('th', { key: label }, label)
                            )
                        )
                    ),
                    h(
                        'tbody',
                        null,
                        bookings.map((booking) =>
                            h(
                                'tr',
                                { key: booking.id },
                                h('td', null, `#${booking.id}`),
                                h(
                                    'td',
                                    null,
                                    h('div', { className: 'rb-admin-app__cell-title' }, booking.customer_name || __('Unknown', 'restaurant-booking')),
                                    h('div', { className: 'rb-admin-app__cell-muted' }, booking.customer_phone || booking.customer_email || '—')
                                ),
                                h(
                                    'td',
                                    null,
                                    h('div', null, `${booking.booking_date} ${booking.booking_time}`),
                                    booking.table_number ? h('div', { className: 'rb-admin-app__cell-muted' }, `${__('Table', 'restaurant-booking')} ${booking.table_number}`) : null
                                ),
                                h('td', null, booking.guest_count),
                                h('td', null, h(StatusBadge, { status: booking.status })),
                                h('td', null, booking.booking_source || '—'),
                                h(
                                    'td',
                                    { className: 'rb-admin-app__actions' },
                                    STATUS_ACTIONS.filter((key) => key !== booking.status).map((key) =>
                                        h(Button, {
                                            key,
                                            size: 'small',
                                            variant: 'tertiary',
                                            onClick: () => handleStatusChange(booking.id, key),
                                            disabled: updatingId === booking.id,
                                            children: statusLabels[key] || key,
                                        })
                                    )
                                )
                            )
                        )
                    )
                ),
            h(Pagination, {
                pagination,
                onPageChange: (nextPage) => setPage(nextPage),
            })
        );
    };

    const TablesPanel = ({ locationId, i18n }) => {
        const [page, setPage] = useState(1);
        const [notice, setNotice] = useState(null);
        const [updating, setUpdating] = useState(null);
        const [refreshIndex, setRefreshIndex] = useState(0);

        const params = useMemo(
            () => ({ location_id: locationId, page, per_page: 10 }),
            [locationId, page]
        );

        const { loading, error, data } = usePaginatedResource('tables', params, [params, refreshIndex]);
        const tables = (data && data.tables) || [];
        const pagination = data && data.pagination;

        useEffect(() => {
            setPage(1);
        }, [locationId]);

        const toggleTable = useCallback((table) => {
            setUpdating(table.id);
            apiFetch({
                path: `tables/${table.id}`,
                method: 'POST',
                data: { is_available: !table.is_available },
            })
                .then(() => {
                    setNotice({ type: 'success', message: __('Table updated.', 'restaurant-booking') });
                    setRefreshIndex((value) => value + 1);
                })
                .catch((err) => {
                    setNotice({ type: 'error', message: err.message || __('Failed to update table.', 'restaurant-booking') });
                })
                .finally(() => setUpdating(null));
        }, []);

        return h(
            Fragment,
            null,
            notice &&
                h(Notice, {
                    status: notice.type,
                    isDismissible: true,
                    onRemove: () => setNotice(null),
                    children: notice.message,
                }),
            loading && h('div', { className: 'rb-admin-app__loading' }, h(Spinner, null)),
            error &&
                h(Notice, {
                    status: 'error',
                    isDismissible: false,
                    children: error.message || __('Failed to load tables.', 'restaurant-booking'),
                }),
            !loading && !error && tables.length === 0 &&
                h('p', { className: 'rb-admin-app__empty' }, i18n.emptyState),
            !loading && !error && tables.length > 0 &&
                h(
                    'div',
                    { className: 'rb-admin-app__cards' },
                    tables.map((table) =>
                        h(
                            Card,
                            { key: table.id },
                            h(CardHeader, null, `${__('Table', 'restaurant-booking')} ${table.table_number}`),
                            h(
                                CardBody,
                                null,
                                h('p', null, `${__('Capacity', 'restaurant-booking')}: ${table.capacity}`),
                                h(
                                    'p',
                                    null,
                                    h(
                                        BadgeComponent,
                                        { status: table.is_available ? 'success' : 'warning' },
                                        table.is_available ? i18n.available : i18n.unavailable
                                    )
                                ),
                                h(Button, {
                                    variant: 'secondary',
                                    onClick: () => toggleTable(table),
                                    disabled: updating === table.id,
                                    children: i18n.toggleTable,
                                })
                            )
                        )
                    )
                ),
            h(Pagination, {
                pagination,
                onPageChange: (nextPage) => setPage(nextPage),
            })
        );
    };

    const CustomersPanel = ({ locationId, i18n }) => {
        const [search, setSearch] = useState('');
        const [page, setPage] = useState(1);
        const [perPage, setPerPage] = useState(10);

        const params = useMemo(
            () => ({ location_id: locationId, search, per_page: perPage, page }),
            [locationId, search, perPage, page]
        );

        const { loading, error, data } = usePaginatedResource('customers', params, [params]);
        const customers = (data && data.customers) || [];
        const pagination = data && data.pagination;

        useEffect(() => {
            setPage(1);
        }, [locationId, search, perPage]);

        return h(
            Fragment,
            null,
            h(
                'div',
                { className: 'rb-admin-app__filters' },
                h(TextControl, {
                    label: i18n.searchCustomers,
                    value: search,
                    onChange: (value) => setSearch(value),
                    placeholder: __('Name, phone or email…', 'restaurant-booking'),
                }),
                h(SelectControl, {
                    label: i18n.perPage,
                    value: String(perPage),
                    options: [10, 20, 50].map((size) => ({ label: String(size), value: String(size) })),
                    onChange: (value) => setPerPage(parseInt(value, 10)),
                })
            ),
            loading && h('div', { className: 'rb-admin-app__loading' }, h(Spinner, null)),
            error &&
                h(Notice, {
                    status: 'error',
                    isDismissible: false,
                    children: error.message || __('Failed to load customers.', 'restaurant-booking'),
                }),
            !loading && !error && customers.length === 0 &&
                h('p', { className: 'rb-admin-app__empty' }, i18n.emptyState),
            !loading && !error && customers.length > 0 &&
                h(
                    'table',
                    { className: 'rb-admin-app__table' },
                    h(
                        'thead',
                        null,
                        h(
                            'tr',
                            null,
                            [__('Name', 'restaurant-booking'), __('Contact', 'restaurant-booking'), __('Completed', 'restaurant-booking'), __('Total', 'restaurant-booking'), __('Status', 'restaurant-booking')].map((label) =>
                                h('th', { key: label }, label)
                            )
                        )
                    ),
                    h(
                        'tbody',
                        null,
                        customers.map((customer) =>
                            h(
                                'tr',
                                { key: customer.id },
                                h('td', null, customer.name || __('Unknown', 'restaurant-booking')),
                                h(
                                    'td',
                                    null,
                                    h('div', null, customer.phone || '—'),
                                    h('div', { className: 'rb-admin-app__cell-muted' }, customer.email || '—')
                                ),
                                h('td', null, customer.completed),
                                h('td', null, customer.total),
                                h(
                                    'td',
                                    null,
                                    customer.vip
                                        ? h(BadgeComponent, { status: 'success' }, __('VIP', 'restaurant-booking'))
                                        : customer.blacklisted
                                        ? h(BadgeComponent, { status: 'error' }, __('Blacklisted', 'restaurant-booking'))
                                        : h(BadgeComponent, { status: 'neutral' }, __('Regular', 'restaurant-booking'))
                                )
                            )
                        )
                    )
                ),
            h(Pagination, {
                pagination,
                onPageChange: (nextPage) => setPage(nextPage),
            })
        );
    };

    const BookingHubApp = () => {
        const [location, setLocation] = useState(settings.locations && settings.locations.length ? String(settings.locations[0].id) : '0');
        const locationId = parseInt(location, 10) || 0;
        const statsState = useStats(locationId);

        const i18n = settings && settings.i18n ? settings.i18n : {};
        const tabs = [
            { name: 'bookings', title: i18n.bookingsHeading || __('Bookings', 'restaurant-booking') },
            { name: 'tables', title: i18n.tablesHeading || __('Tables', 'restaurant-booking') },
            { name: 'customers', title: i18n.customersHeading || __('Customers', 'restaurant-booking') },
        ];

        return h(
            'div',
            { className: 'rb-admin-app__container' },
            h(
                'div',
                { className: 'rb-admin-app__toolbar' },
                h('h2', null, i18n.bookingHubTitle || __('Booking Hub', 'restaurant-booking')),
                settings && settings.locations && settings.locations.length
                    ? h(SelectControl, {
                          label: __('Location', 'restaurant-booking'),
                          value: location,
                          options: [
                              { label: __('All locations', 'restaurant-booking'), value: '0' },
                              ...settings.locations.map((loc) => ({ label: loc.name, value: String(loc.id) })),
                          ],
                          onChange: (value) => setLocation(value),
                      })
                    : null
            ),
            h(
                'div',
                { className: 'rb-admin-app__summary' },
                statsState.loading
                    ? h('div', { className: 'rb-admin-app__loading' }, h(Spinner, null))
                    : statsState.error
                    ? h(Notice, {
                          status: 'error',
                          isDismissible: false,
                          children: statsState.error.message || __('Failed to load stats.', 'restaurant-booking'),
                      })
                    : h(
                          Fragment,
                          null,
                          h(Card, null, h(CardHeader, null, i18n.statsHeading || __('Today’s overview', 'restaurant-booking')), h(CardBody, null, h(MetricGrid, { metrics: statsState.data ? statsState.data.metrics : null })) ),
                          h(Card, null, h(CardHeader, null, i18n.sourcesHeading || __('Source breakdown', 'restaurant-booking')), h(CardBody, null, h(SourcesList, { sources: statsState.data ? statsState.data.sources : null })))
                      )
            ),
            h(TabPanel, {
                className: 'rb-admin-app__tabs',
                activeClass: 'is-active',
                tabs,
                children: (tab) => {
                    switch (tab.name) {
                        case 'tables':
                            return h(TablesPanel, { locationId, i18n });
                        case 'customers':
                            return h(CustomersPanel, { locationId, i18n });
                        case 'bookings':
                        default:
                            return h(BookingsPanel, { locationId, statusLabels: settings && settings.statusLabels ? settings.statusLabels : {}, i18n });
                    }
                },
            })
        );
    };

    const mountPoint = document.getElementById('rb-admin-app');
    if (mountPoint && wp && wp.element) {
        if (typeof wp.element.createRoot === 'function') {
            const root = mountPoint.__rbRoot || wp.element.createRoot(mountPoint);
            root.render(h(BookingHubApp));
            mountPoint.__rbRoot = root;
        } else if (typeof wp.element.render === 'function') {
            wp.element.render(h(BookingHubApp), mountPoint);
        } else if (window.ReactDOM && typeof window.ReactDOM.render === 'function') {
            window.ReactDOM.render(h(BookingHubApp), mountPoint);
        }
    }
})(window.wp || {}, window.RBAdminSettings || {});
