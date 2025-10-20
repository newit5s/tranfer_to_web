<?php
/**
 * REST controller exposing booking, table and customer data for the admin SPA.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RB_REST_Controller {
    const REST_NAMESPACE = 'rb/v1';
    const LEGACY_NAMESPACE = 'restaurant-booking/v1';

    /**
     * Allowed booking statuses for transitions.
     *
     * @var string[]
     */
    private $allowed_statuses = array('pending', 'confirmed', 'completed', 'cancelled', 'no-show');

    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }


    public function register_routes() {
        foreach ($this->get_namespaces() as $namespace) {
            register_rest_route(
                $namespace,
                '/bookings',
                array(
                    array(
                        'methods'             => WP_REST_Server::READABLE,
                        'callback'            => array($this, 'get_bookings'),
                        'permission_callback' => array($this, 'permissions_manage'),
                        'args'                => $this->get_booking_collection_args(),
                    ),
                )
            );

            register_rest_route(
                $namespace,
                '/bookings/(?P<id>\d+)/status',
                array(
                    array(
                        'methods'             => WP_REST_Server::EDITABLE,
                        'callback'            => array($this, 'update_booking_status'),
                        'permission_callback' => array($this, 'permissions_manage'),
                        'args'                => array(
                            'status' => array(
                                'required'          => true,
                                'sanitize_callback' => 'sanitize_text_field',
                                'validate_callback' => array($this, 'validate_status'),
                            ),
                        ),
                    ),
                )
            );

            register_rest_route(
                $namespace,
                '/stats',
                array(
                    array(
                        'methods'             => WP_REST_Server::READABLE,
                        'callback'            => array($this, 'get_stats'),
                        'permission_callback' => array($this, 'permissions_manage'),
                        'args'                => array(
                            'location_id' => array(
                                'default'           => 0,
                                'sanitize_callback' => 'absint',
                            ),
                        ),
                    ),
                )
            );

            register_rest_route(
                $namespace,
                '/tables',
                array(
                    array(
                        'methods'             => WP_REST_Server::READABLE,
                        'callback'            => array($this, 'get_tables'),
                        'permission_callback' => array($this, 'permissions_manage'),
                        'args'                => array_merge(
                            $this->get_paginated_args(),
                            array(
                                'location_id' => array(
                                    'default'           => 0,
                                    'sanitize_callback' => 'absint',
                                ),
                            )
                        ),
                    ),
                )
            );

            register_rest_route(
                $namespace,
                '/tables/(?P<id>\d+)',
                array(
                    array(
                        'methods'             => WP_REST_Server::EDITABLE,
                        'callback'            => array($this, 'update_table'),
                        'permission_callback' => array($this, 'permissions_manage'),
                        'args'                => array(
                            'is_available' => array(
                                'required'          => true,
                                'sanitize_callback' => array($this, 'sanitize_boolean'),
                            ),
                        ),
                    ),
                )
            );

            register_rest_route(
                $namespace,
                '/customers',
                array(
                    array(
                        'methods'             => WP_REST_Server::READABLE,
                        'callback'            => array($this, 'get_customers'),
                        'permission_callback' => array($this, 'permissions_manage'),
                        'args'                => array_merge(
                            $this->get_paginated_args(),
                            array(
                                'search' => array(
                                    'default'           => '',
                                    'sanitize_callback' => 'sanitize_text_field',
                                ),
                                'location_id' => array(
                                    'default'           => 0,
                                    'sanitize_callback' => 'absint',
                                ),
                            )
                        ),
                    ),
                )
            );
        }

    }

    private function get_namespaces() {
        $namespaces = array(self::REST_NAMESPACE);

        if (!empty(self::LEGACY_NAMESPACE) && self::LEGACY_NAMESPACE !== self::REST_NAMESPACE) {
            $namespaces[] = self::LEGACY_NAMESPACE;
        }

        /**
         * Allow third-parties to register additional namespaces for the admin REST endpoints.
         *
         * @param string[] $namespaces
         */
        return apply_filters('rb_rest_controller_namespaces', $namespaces);
    }

    public function permissions_manage() {
        return current_user_can('manage_options');
    }

    public function validate_status($value) {
        return in_array($value, $this->allowed_statuses, true);
    }

    public function sanitize_boolean($value) {
        return (bool) rest_sanitize_boolean($value);
    }

    private function get_booking_collection_args() {
        return array_merge(
            $this->get_paginated_args(),
            array(
                'status'      => array(
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'date_from'   => array(
                    'default'           => '',
                    'sanitize_callback' => array($this, 'sanitize_date'),
                ),
                'date_to'     => array(
                    'default'           => '',
                    'sanitize_callback' => array($this, 'sanitize_date'),
                ),
                'search'      => array(
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'source'      => array(
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'location_id' => array(
                    'default'           => 0,
                    'sanitize_callback' => 'absint',
                ),
                'orderby'     => array(
                    'default'           => 'booking_date',
                    'sanitize_callback' => 'sanitize_key',
                ),
                'order'       => array(
                    'default'           => 'DESC',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            )
        );
    }

    private function get_paginated_args() {
        return array(
            'page'     => array(
                'default'           => 1,
                'sanitize_callback' => 'absint',
            ),
            'per_page' => array(
                'default'           => 20,
                'sanitize_callback' => 'absint',
            ),
        );
    }

    public function sanitize_date($value) {
        if (!empty($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }

        return '';
    }

    public function get_bookings(WP_REST_Request $request) {
        global $rb_booking;

        if (!$rb_booking) {
            require_once RB_PLUGIN_DIR . 'includes/class-booking.php';
            $rb_booking = new RB_Booking();
        }

        $page     = max(1, (int) $request->get_param('page'));
        $per_page = min(50, max(1, (int) $request->get_param('per_page')));

        $args = array(
            'status'      => $request->get_param('status'),
            'date_from'   => $request->get_param('date_from'),
            'date_to'     => $request->get_param('date_to'),
            'location_id' => $request->get_param('location_id'),
            'source'      => $request->get_param('source'),
            'search'      => $request->get_param('search'),
            'orderby'     => $request->get_param('orderby'),
            'order'       => $request->get_param('order'),
            'limit'       => $per_page,
            'offset'      => ($page - 1) * $per_page,
        );

        $bookings = $rb_booking->get_bookings($args);
        $count    = $rb_booking->count_bookings($args);

        $prepared = array();
        foreach ($bookings as $booking) {
            $prepared[] = $this->prepare_booking_item($booking);
        }

        return rest_ensure_response(
            array(
                'bookings'   => $prepared,
                'pagination' => array(
                    'page'        => $page,
                    'per_page'    => $per_page,
                    'total'       => $count,
                    'total_pages' => $per_page ? (int) ceil($count / $per_page) : 1,
                ),
            )
        );
    }

    public function update_booking_status(WP_REST_Request $request) {
        global $rb_booking;

        if (!$rb_booking) {
            require_once RB_PLUGIN_DIR . 'includes/class-booking.php';
            $rb_booking = new RB_Booking();
        }

        $booking_id = (int) $request->get_param('id');
        $status     = $request->get_param('status');

        switch ($status) {
            case 'confirmed':
                $result = $rb_booking->confirm_booking($booking_id);
                break;
            case 'completed':
                $result = $rb_booking->complete_booking($booking_id);
                break;
            case 'cancelled':
                $result = $rb_booking->cancel_booking($booking_id);
                break;
            case 'no-show':
                $result = $rb_booking->mark_no_show($booking_id);
                break;
            case 'pending':
                $result = $rb_booking->update_booking($booking_id, array('status' => 'pending'));
                break;
            default:
                $result = new WP_Error('rb_invalid_status', __('Status transition is not allowed.', 'restaurant-booking'));
                break;
        }

        if (is_wp_error($result) || !$result) {
            return new WP_Error(
                'rb_status_update_failed',
                is_wp_error($result) ? $result->get_error_message() : __('Could not update booking status.', 'restaurant-booking'),
                array('status' => 400)
            );
        }

        $booking = $rb_booking->get_booking($booking_id);

        if (!$booking) {
            return new WP_Error('rb_booking_not_found', __('Booking not found after updating.', 'restaurant-booking'), array('status' => 404));
        }

        return rest_ensure_response($this->prepare_booking_item($booking));
    }

    public function get_stats(WP_REST_Request $request) {
        global $rb_booking;

        if (!$rb_booking) {
            require_once RB_PLUGIN_DIR . 'includes/class-booking.php';
            $rb_booking = new RB_Booking();
        }

        $location_id = (int) $request->get_param('location_id');

        $stats        = $rb_booking->get_location_stats($location_id);
        $source_stats = $rb_booking->get_source_stats($location_id);

        $sources = array();
        if (!empty($source_stats)) {
            foreach ($source_stats as $row) {
                $sources[] = array(
                    'source' => $row->booking_source,
                    'total'  => (int) $row->total,
                );
            }
        }

        return rest_ensure_response(
            array(
                'metrics' => $stats,
                'sources' => $sources,
            )
        );
    }

    public function get_tables(WP_REST_Request $request) {
        global $wpdb;

        $page        = max(1, (int) $request->get_param('page'));
        $per_page    = min(50, max(1, (int) $request->get_param('per_page')));
        $offset      = ($page - 1) * $per_page;
        $location_id = (int) $request->get_param('location_id');

        $table = $wpdb->prefix . 'rb_tables';

        $where  = '1=1';
        $params = array();

        if ($location_id) {
            $where   = 'location_id = %d';
            $params[] = $location_id;
        }

        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        $query_sql = "SELECT id, location_id, table_number, capacity, is_available, created_at FROM {$table} WHERE {$where} ORDER BY location_id ASC, table_number ASC LIMIT %d OFFSET %d";

        $total = !empty($params)
            ? (int) $wpdb->get_var($wpdb->prepare($count_sql, $params))
            : (int) $wpdb->get_var($count_sql);

        $query_params = $params;
        $query_params[] = $per_page;
        $query_params[] = $offset;

        $rows = !empty($params)
            ? $wpdb->get_results($wpdb->prepare($query_sql, $query_params))
            : $wpdb->get_results($wpdb->prepare($query_sql, $per_page, $offset));

        $tables = array();
        if (!empty($rows)) {
            foreach ($rows as $row) {
                $tables[] = array(
                    'id'           => (int) $row->id,
                    'location_id'  => (int) $row->location_id,
                    'table_number' => (int) $row->table_number,
                    'capacity'     => (int) $row->capacity,
                    'is_available' => (bool) $row->is_available,
                    'created_at'   => $row->created_at,
                );
            }
        }

        return rest_ensure_response(
            array(
                'tables'     => $tables,
                'pagination' => array(
                    'page'        => $page,
                    'per_page'    => $per_page,
                    'total'       => $total,
                    'total_pages' => $per_page ? (int) ceil($total / $per_page) : 1,
                ),
            )
        );
    }

    public function update_table(WP_REST_Request $request) {
        global $wpdb;

        $table_id     = (int) $request->get_param('id');
        $is_available = $request->get_param('is_available') ? 1 : 0;

        $table_name = $wpdb->prefix . 'rb_tables';
        $updated    = $wpdb->update(
            $table_name,
            array('is_available' => $is_available),
            array('id' => $table_id),
            array('%d'),
            array('%d')
        );

        if (false === $updated) {
            return new WP_Error('rb_table_update_failed', __('Could not update table status.', 'restaurant-booking'), array('status' => 400));
        }

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT id, location_id, table_number, capacity, is_available, created_at FROM {$table_name} WHERE id = %d", $table_id)
        );

        if (!$row) {
            return new WP_Error('rb_table_not_found', __('Table not found.', 'restaurant-booking'), array('status' => 404));
        }

        return rest_ensure_response(
            array(
                'id'           => (int) $row->id,
                'location_id'  => (int) $row->location_id,
                'table_number' => (int) $row->table_number,
                'capacity'     => (int) $row->capacity,
                'is_available' => (bool) $row->is_available,
                'created_at'   => $row->created_at,
            )
        );
    }

    public function get_customers(WP_REST_Request $request) {
        global $wpdb;

        $page        = max(1, (int) $request->get_param('page'));
        $per_page    = min(50, max(1, (int) $request->get_param('per_page')));
        $offset      = ($page - 1) * $per_page;
        $location_id = (int) $request->get_param('location_id');
        $search      = $request->get_param('search');

        $table = $wpdb->prefix . 'rb_customers';

        $where  = array('1=1');
        $params = array();

        if ($location_id) {
            $where[]   = 'location_id = %d';
            $params[] = $location_id;
        }

        if (!empty($search)) {
            $where[]   = '(name LIKE %s OR phone LIKE %s OR email LIKE %s)';
            $like      = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = implode(' AND ', $where);

        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $query_sql = "SELECT id, name, phone, email, location_id, completed_bookings, total_bookings, vip_status, blacklisted, customer_notes, updated_at FROM {$table} WHERE {$where_sql} ORDER BY updated_at DESC LIMIT %d OFFSET %d";

        $total = !empty($params)
            ? (int) $wpdb->get_var($wpdb->prepare($count_sql, $params))
            : (int) $wpdb->get_var($count_sql);

        $query_params = $params;
        $query_params[] = $per_page;
        $query_params[] = $offset;

        $rows = !empty($params)
            ? $wpdb->get_results($wpdb->prepare($query_sql, $query_params))
            : $wpdb->get_results($wpdb->prepare($query_sql, $per_page, $offset));

        $customers = array();
        if (!empty($rows)) {
            foreach ($rows as $row) {
                $customers[] = array(
                    'id'                => (int) $row->id,
                    'name'              => $row->name,
                    'phone'             => $row->phone,
                    'email'             => $row->email,
                    'location_id'       => (int) $row->location_id,
                    'completed'         => (int) $row->completed_bookings,
                    'total'             => (int) $row->total_bookings,
                    'vip'               => (bool) $row->vip_status,
                    'blacklisted'       => (bool) $row->blacklisted,
                    'customer_notes'    => $row->customer_notes,
                    'updated_at'        => $row->updated_at,
                );
            }
        }

        return rest_ensure_response(
            array(
                'customers'  => $customers,
                'pagination' => array(
                    'page'        => $page,
                    'per_page'    => $per_page,
                    'total'       => $total,
                    'total_pages' => $per_page ? (int) ceil($total / $per_page) : 1,
                ),
            )
        );
    }

    private function prepare_booking_item($booking) {
        return array(
            'id'             => (int) $booking->id,
            'customer_name'  => $booking->customer_name,
            'customer_phone' => $booking->customer_phone,
            'customer_email' => $booking->customer_email,
            'guest_count'    => (int) $booking->guest_count,
            'status'         => $booking->status,
            'booking_date'   => $booking->booking_date,
            'booking_time'   => $booking->booking_time,
            'checkin_time'   => isset($booking->checkin_time) ? $booking->checkin_time : null,
            'checkout_time'  => isset($booking->checkout_time) ? $booking->checkout_time : null,
            'table_number'   => isset($booking->table_number) ? (int) $booking->table_number : null,
            'booking_source' => $booking->booking_source,
            'location_id'    => isset($booking->location_id) ? (int) $booking->location_id : 0,
            'created_at'     => $booking->created_at,
        );
    }
}
