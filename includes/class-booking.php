<?php
/**
 * Booking Class - Xử lý logic đặt bàn
 */

if (!defined('ABSPATH')) {
    exit;
}

class RB_Booking {
    
    private $wpdb;
    
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }
    
    public function get_booking($booking_id) {
        $table_name = $this->wpdb->prefix . 'rb_bookings';
        return $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $booking_id
        ));
    }

    public function get_bookings($args = array()) {
        $defaults = array(
            'status' => '',
            'date' => '',
            'date_from' => '',
            'date_to' => '',
            'location_id' => 0,
            'limit' => -1,
            'offset' => 0,
            'orderby' => 'booking_date',
            'order' => 'DESC',
            'source' => '',
            'search' => '',
        );

        $args = wp_parse_args($args, $defaults);
        $table_name = $this->wpdb->prefix . 'rb_bookings';

        $where_clauses = array('1=1');
        $where_params = array();

        if (!empty($args['status'])) {
            $where_clauses[] = 'status = %s';
            $where_params[] = $args['status'];
        }

        if (!empty($args['date'])) {
            $where_clauses[] = 'booking_date = %s';
            $where_params[] = $args['date'];
        }

        if (!empty($args['date_from']) && !empty($args['date_to'])) {
            $where_clauses[] = 'booking_date BETWEEN %s AND %s';
            $where_params[] = $args['date_from'];
            $where_params[] = $args['date_to'];
        } elseif (!empty($args['date_from'])) {
            $where_clauses[] = 'booking_date >= %s';
            $where_params[] = $args['date_from'];
        } elseif (!empty($args['date_to'])) {
            $where_clauses[] = 'booking_date <= %s';
            $where_params[] = $args['date_to'];
        }

        if (!empty($args['source'])) {
            $where_clauses[] = 'booking_source = %s';
            $where_params[] = $args['source'];
        }

        if (!empty($args['search'])) {
            $search = '%' . $this->wpdb->esc_like($args['search']) . '%';
            $where_clauses[] = '(customer_name LIKE %s OR customer_phone LIKE %s OR customer_email LIKE %s OR CAST(id AS CHAR) LIKE %s)';
            $where_params[] = $search;
            $where_params[] = $search;
            $where_params[] = $search;
            $where_params[] = $search;
        }

        if (!empty($args['location_id'])) {
            $where_clauses[] = 'location_id = %d';
            $where_params[] = (int) $args['location_id'];
        }

        $where = implode(' AND ', $where_clauses);

        $allowed_orderby = array('id', 'customer_name', 'booking_date', 'booking_time', 'guest_count', 'status', 'booking_source', 'created_at');
        if (!in_array($args['orderby'], $allowed_orderby, true)) {
            $args['orderby'] = 'booking_date';
        }

        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';
        $orderby = sanitize_sql_orderby($args['orderby'] . ' ' . $order);

        $sql = "SELECT * FROM $table_name WHERE $where ORDER BY $orderby";

        if ($args['limit'] > 0) {
            $sql .= $this->wpdb->prepare(' LIMIT %d OFFSET %d', $args['limit'], $args['offset']);
        }

        if (!empty($where_params)) {
            return $this->wpdb->get_results($this->wpdb->prepare($sql, $where_params));
        }

        return $this->wpdb->get_results($sql);
    }

    public function get_location_stats($location_id = 0) {
        $table_name = $this->wpdb->prefix . 'rb_bookings';
        $where = '1=1';
        $params = array();

        if ($location_id) {
            $where = 'location_id = %d';
            $params[] = (int) $location_id;
        }

        $today = date('Y-m-d');

        $stats = array(
            'total' => $this->prepare_and_get_var("SELECT COUNT(*) FROM $table_name WHERE $where", $params),
            'pending' => $this->prepare_and_get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'pending' AND $where", $params),
            'confirmed' => $this->prepare_and_get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'confirmed' AND $where", $params),
            'completed' => $this->prepare_and_get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'completed' AND $where", $params),
            'cancelled' => $this->prepare_and_get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'cancelled' AND $where", $params),
            'today' => $this->prepare_and_get_var(
                "SELECT COUNT(*) FROM $table_name WHERE $where AND booking_date = %s",
                array_merge($params, array($today))
            ),
            'today_pending' => $this->prepare_and_get_var(
                "SELECT COUNT(*) FROM $table_name WHERE status = 'pending' AND $where AND booking_date = %s",
                array_merge($params, array($today))
            ),
            'today_confirmed' => $this->prepare_and_get_var(
                "SELECT COUNT(*) FROM $table_name WHERE status = 'confirmed' AND $where AND booking_date = %s",
                array_merge($params, array($today))
            ),
            'today_completed' => $this->prepare_and_get_var(
                "SELECT COUNT(*) FROM $table_name WHERE status = 'completed' AND $where AND booking_date = %s",
                array_merge($params, array($today))
            ),
            'today_cancelled' => $this->prepare_and_get_var(
                "SELECT COUNT(*) FROM $table_name WHERE status = 'cancelled' AND $where AND booking_date = %s",
                array_merge($params, array($today))
            ),
        );

        return $stats;
    }

    public function get_source_stats($location_id = 0) {
        $table_name = $this->wpdb->prefix . 'rb_bookings';
        $where = '';
        $params = array();

        if ($location_id) {
            $where = 'WHERE location_id = %d';
            $params[] = (int) $location_id;
        }

        $sql = "SELECT booking_source, COUNT(*) as total FROM $table_name $where GROUP BY booking_source ORDER BY total DESC";

        if (!empty($params)) {
            return $this->wpdb->get_results($this->wpdb->prepare($sql, $params));
        }

        return $this->wpdb->get_results($sql);
    }
    
    public function create_booking($data) {
        $table_name = $this->wpdb->prefix . 'rb_bookings';

        $defaults = array(
            'status' => 'pending',
            'booking_source' => 'website',
            'created_at' => current_time('mysql'),
            'table_number' => null,
            'location_id' => 1,
            'language' => rb_get_current_language(),
            'confirmation_token' => $this->generate_confirmation_token(),
            'confirmation_token_expires' => gmdate('Y-m-d H:i:s', current_time('timestamp', true) + DAY_IN_SECONDS),
            'checkin_time' => null,
            'checkout_time' => null,
        );

        $data = wp_parse_args($data, $defaults);

        // Validate required fields
        $required = array('customer_name', 'customer_phone', 'guest_count', 'booking_date', 'booking_time', 'location_id');

        foreach ($required as $field) {
            if (empty($data[$field])) {
                return new WP_Error('missing_field', sprintf(__('Field %s is required', 'restaurant-booking'), $field));
            }
        }

        if (!empty($data['customer_email']) && !is_email($data['customer_email'])) {
            return new WP_Error('invalid_email', __('Customer email is invalid', 'restaurant-booking'));
        }

        $data['booking_time'] = $this->sanitize_time_value($data['booking_time']);
        if (!$data['booking_time']) {
            return new WP_Error('invalid_time', __('Invalid booking time provided.', 'restaurant-booking'));
        }

        $checkin_time = $this->sanitize_time_value(isset($data['checkin_time']) ? $data['checkin_time'] : $data['booking_time']);
        if (!$checkin_time) {
            $checkin_time = $data['booking_time'];
        }

        $checkout_time = $this->sanitize_time_value(isset($data['checkout_time']) ? $data['checkout_time'] : null);
        if (!$checkout_time) {
            $checkout_time = $this->get_default_checkout_for_time($checkin_time);
        }

        $range = $this->get_time_range($data['booking_date'], $checkin_time, $checkout_time);
        if (!$range) {
            return new WP_Error('invalid_time_range', __('Unable to calculate booking duration. Please try again.', 'restaurant-booking'));
        }

        $duration = $range['end'] - $range['start'];
        if ($duration < HOUR_IN_SECONDS) {
            return new WP_Error('short_booking_duration', __('Minimum booking duration is 1 hour.', 'restaurant-booking'));
        }

        if ($duration > 6 * HOUR_IN_SECONDS) {
            return new WP_Error('long_booking_duration', __('Maximum booking duration is 6 hours.', 'restaurant-booking'));
        }

        if ($this->check_time_overlap($data['booking_date'], $range['checkin'], $range['checkout'], (int) $data['location_id'])) {
            return new WP_Error('time_slot_unavailable', __('Selected time slot is not available.', 'restaurant-booking'));
        }

        if (!$this->is_time_slot_available($data['booking_date'], $range['checkin'], (int) $data['guest_count'], null, (int) $data['location_id'], $range['checkout'])) {
            return new WP_Error('time_slot_unavailable', __('Selected time slot is not available.', 'restaurant-booking'));
        }

        $data['checkin_time'] = $range['checkin'];
        $data['checkout_time'] = $range['checkout'];
        $data['booking_time'] = $range['checkin'];

        $result = $this->wpdb->insert($table_name, $data);

        if ($result === false) {
            return new WP_Error('db_error', __('Could not create booking', 'restaurant-booking'));
        }

        $booking_id = $this->wpdb->insert_id;

        // *** THAY ĐỔI CHÍNH: Đảm bảo class Customer được load và khởi tạo ***
        if (!class_exists('RB_Customer')) {
            require_once RB_PLUGIN_DIR . 'includes/class-customer.php';
        }

        global $rb_customer;
        if (!$rb_customer) {
            $rb_customer = new RB_Customer();
        }

        // Tự động cập nhật thông tin khách hàng vào CRM
        $rb_customer->update_customer_from_booking($booking_id);

        $booking = $this->get_booking($booking_id);
        /**
         * Fires right after a booking has been created.
         *
         * @param int   $booking_id Newly created booking ID.
         * @param object $booking   Booking record.
         */
        do_action('rb_booking_created', $booking_id, $booking);

        return $booking_id;
    }
    
    public function update_booking($booking_id, $data) {
        $table_name = $this->wpdb->prefix . 'rb_bookings';
        
        $result = $this->wpdb->update(
            $table_name,
            $data,
            array('id' => $booking_id)
        );
        
        return $result !== false;
    }
    
    public function delete_booking($booking_id) {
        $table_name = $this->wpdb->prefix . 'rb_bookings';
        return $this->wpdb->delete($table_name, array('id' => $booking_id));
    }
    
    public function confirm_booking($booking_id) {
        global $wpdb;
        $b_tbl = $wpdb->prefix . 'rb_bookings';

        $bk = $this->get_booking($booking_id);
        if (!$bk || $bk->status === 'confirmed') {
            return new WP_Error('rb_invalid', 'Booking không tồn tại hoặc đã confirmed.');
        }

        // Chọn bàn nhỏ nhất đủ chỗ
        $checkin = !empty($bk->checkin_time) ? $bk->checkin_time : $bk->booking_time;
        $checkout = !empty($bk->checkout_time) ? $bk->checkout_time : $this->get_default_checkout_for_time($checkin);
        $slot_table = $this->get_smallest_available_table($bk->booking_date, $checkin, (int)$bk->guest_count, (int)$bk->location_id, $bk->id, $checkout);
        if (!$slot_table) {
            return new WP_Error('rb_no_table', 'Hết bàn phù hợp để xác nhận ở khung giờ này.');
        }

        $ok = $wpdb->update(
            $b_tbl,
            array(
                'status' => 'confirmed',
                'table_number' => (int)$slot_table->table_number,
                'confirmed_at' => current_time('mysql'),
            ),
            array('id' => (int)$booking_id),
            array('%s', '%d', '%s'),
            array('%d')
        );

        if (false === $ok) {
            return new WP_Error('rb_update_fail', 'Xác nhận thất bại, vui lòng thử lại.');
        }

        $updated_booking = $this->get_booking($booking_id);

        /**
         * Fires when a booking is confirmed successfully.
         *
         * @param int    $booking_id Booking ID.
         * @param object $booking    Booking record with latest data.
         */
        do_action('rb_booking_confirmed', $booking_id, $updated_booking);

        return true;
    }

    public function cancel_booking($booking_id) {
        $result = $this->update_booking($booking_id, array('status' => 'cancelled'));

        // Đánh dấu booking đã hủy trong CRM
        if ($result && class_exists('RB_Customer')) {
            global $rb_customer;
            if ($rb_customer) {
                $rb_customer->mark_cancelled($booking_id);
            }
        }

        if ($result) {
            $booking = $this->get_booking($booking_id);

            /**
             * Fires after a booking has been cancelled.
             *
             * @param int    $booking_id Booking ID.
             * @param object $booking    Booking record.
             */
            do_action('rb_booking_cancelled', $booking_id, $booking);
        }

        return $result;
    }

    public function complete_booking($booking_id) {
        $result = $this->update_booking($booking_id, array('status' => 'completed'));

        // Đánh dấu booking đã hoàn thành trong CRM
        if ($result && class_exists('RB_Customer')) {
            global $rb_customer;
            if ($rb_customer) {
                $rb_customer->mark_completed($booking_id);
            }
        }

        if ($result) {
            $booking = $this->get_booking($booking_id);
            do_action('rb_booking_completed', $booking_id, $booking);
        }

        return $result;
    }
    
    /**
     * Đánh dấu no-show (khách đặt nhưng không đến)
     */
    public function mark_no_show($booking_id) {
        $result = $this->update_booking($booking_id, array('status' => 'no-show'));
        
        if ($result && class_exists('RB_Customer')) {
            global $rb_customer;
            if ($rb_customer) {
                $rb_customer->mark_no_show($booking_id);
            }
        }
        
        return $result;
    }
    
    public function is_time_slot_available($date, $checkin, $guest_count, $exclude_booking_id = null, $location_id = 1, $checkout = null) {
        global $wpdb;
        $tables_table = $wpdb->prefix . 'rb_tables';
        $bookings_table = $wpdb->prefix . 'rb_bookings';

        $range = $this->get_time_range($date, $checkin, $checkout);
        if (!$range) {
            return false;
        }

        $total_capacity = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(capacity) FROM {$tables_table} WHERE is_available = 1 AND location_id = %d",
            $location_id
        ));

        if ($total_capacity <= 0) {
            return false;
        }

        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT id, guest_count, checkin_time, checkout_time, booking_time
             FROM {$bookings_table}
             WHERE booking_date = %s
               AND location_id = %d
               AND status IN ('pending', 'confirmed')",
            $date,
            $location_id
        ));

        $booked_guests = 0;
        foreach ($bookings as $booking) {
            if (null !== $exclude_booking_id && (int) $booking->id === (int) $exclude_booking_id) {
                continue;
            }

            $existing_range = $this->get_time_range($date, !empty($booking->checkin_time) ? $booking->checkin_time : $booking->booking_time, !empty($booking->checkout_time) ? $booking->checkout_time : null);
            if ($existing_range && $this->ranges_overlap_with_buffer($range, $existing_range)) {
                $booked_guests += (int) $booking->guest_count;
            }
        }

        $remaining_capacity = $total_capacity - $booked_guests;
        if ($remaining_capacity < $guest_count) {
            return false;
        }

        $available_table = $this->get_smallest_available_table($date, $range['checkin'], $guest_count, $location_id, $exclude_booking_id, $range['checkout']);

        return !empty($available_table);
    }

    public function get_smallest_available_table($date, $checkin, $guest_count, $location_id = 1, $exclude_booking_id = null, $checkout = null) {
        global $wpdb;
        $tables_table = $wpdb->prefix . 'rb_tables';

        $range = $this->get_time_range($date, $checkin, $checkout);
        if (!$range) {
            return null;
        }

        $tables = $wpdb->get_results($wpdb->prepare(
            "SELECT id, table_number, capacity
             FROM {$tables_table}
             WHERE is_available = 1
               AND location_id = %d
               AND capacity >= %d
             ORDER BY capacity ASC, table_number ASC",
            (int) $location_id,
            (int) $guest_count
        ));

        foreach ($tables as $table) {
            if ($this->table_is_available((int) $table->table_number, $date, $range, $location_id, $exclude_booking_id)) {
                return $table;
            }
        }

        return null;
    }

    public function available_table_count($date, $checkin, $guest_count, $location_id = 1, $checkout = null, $exclude_booking_id = null) {
        global $wpdb;
        $tables_table = $wpdb->prefix . 'rb_tables';

        $range = $this->get_time_range($date, $checkin, $checkout);
        if (!$range) {
            return 0;
        }

        $tables = $wpdb->get_results($wpdb->prepare(
            "SELECT table_number, capacity
             FROM {$tables_table}
             WHERE is_available = 1
               AND location_id = %d
               AND capacity >= %d",
            (int) $location_id,
            (int) $guest_count
        ));

        $count = 0;
        foreach ($tables as $table) {
            if ($this->table_is_available((int) $table->table_number, $date, $range, $location_id, $exclude_booking_id)) {
                $count++;
            }
        }

        return $count;
    }

    public function check_time_overlap($date, $checkin, $checkout, $location_id, $exclude_booking_id = null) {
        global $wpdb;
        $bookings_table = $wpdb->prefix . 'rb_bookings';

        $range = $this->get_time_range($date, $checkin, $checkout);
        if (!$range) {
            return array();
        }

        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT id, customer_name, customer_phone, checkin_time, checkout_time, booking_time, guest_count, table_number
             FROM {$bookings_table}
             WHERE booking_date = %s
               AND location_id = %d
               AND status IN ('pending', 'confirmed')",
            $date,
            (int) $location_id
        ));

        $conflicts = array();

        foreach ($bookings as $booking) {
            if (null !== $exclude_booking_id && (int) $booking->id === (int) $exclude_booking_id) {
                continue;
            }

            $existing_range = $this->get_time_range($date, !empty($booking->checkin_time) ? $booking->checkin_time : $booking->booking_time, !empty($booking->checkout_time) ? $booking->checkout_time : null);

            if ($existing_range && $this->ranges_overlap_with_buffer($range, $existing_range)) {
                $conflicts[] = array(
                    'id' => (int) $booking->id,
                    'table_number' => null !== $booking->table_number ? (int) $booking->table_number : null,
                    'guest_count' => (int) $booking->guest_count,
                    'checkin_time' => $existing_range['checkin'],
                    'checkout_time' => $existing_range['checkout'],
                    'customer_name' => $booking->customer_name,
                    'customer_phone' => $booking->customer_phone,
                );
            }
        }

        return $conflicts;
    }

    public function get_timeline_data($date, $location_id) {
        $date = sanitize_text_field($date);
        $location_id = (int) $location_id;

        global $rb_location;

        if (!$rb_location) {
            require_once RB_PLUGIN_DIR . 'includes/class-location.php';
            $rb_location = new RB_Location();
        }

        $settings = $rb_location ? $rb_location->get_settings($location_id) : array();

        $opening_time = isset($settings['opening_time']) ? substr($settings['opening_time'], 0, 5) : '09:00';
        $closing_time = isset($settings['closing_time']) ? substr($settings['closing_time'], 0, 5) : '22:00';
        $interval = isset($settings['time_slot_interval']) ? (int) $settings['time_slot_interval'] : 30;
        if ($interval <= 0) {
            $interval = 30;
        }

        $time_slots = $this->generate_time_slots_for_location($opening_time, $closing_time, $interval);

        $tables_table = $this->wpdb->prefix . 'rb_tables';
        $bookings_table = $this->wpdb->prefix . 'rb_bookings';

        $tables = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT id, table_number, capacity, current_status, status_updated_at, last_booking_id
             FROM {$tables_table}
             WHERE location_id = %d
             ORDER BY table_number ASC",
            $location_id
        ));

        $tables_map = array();
        foreach ($tables as $table) {
            $tables_map[(int) $table->table_number] = array(
                'table_id' => (int) $table->id,
                'table_number' => (int) $table->table_number,
                'capacity' => (int) $table->capacity,
                'current_status' => !empty($table->current_status) ? $table->current_status : 'available',
                'status_updated_at' => $table->status_updated_at,
                'last_booking_id' => $table->last_booking_id ? (int) $table->last_booking_id : null,
                'bookings' => array(),
            );
        }

        $bookings = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT id, customer_name, customer_phone, guest_count, status, table_number, checkin_time, checkout_time, booking_time
             FROM {$bookings_table}
             WHERE booking_date = %s
               AND location_id = %d",
            $date,
            $location_id
        ));

        $unassigned = array();

        foreach ($bookings as $booking) {
            $range = $this->get_time_range($date, !empty($booking->checkin_time) ? $booking->checkin_time : $booking->booking_time, !empty($booking->checkout_time) ? $booking->checkout_time : null);

            $entry = array(
                'booking_id' => (int) $booking->id,
                'customer_name' => $booking->customer_name,
                'phone' => $booking->customer_phone,
                'guest_count' => (int) $booking->guest_count,
                'status' => $booking->status,
                'checkin_time' => $range ? $range['checkin'] : $this->sanitize_time_value($booking->booking_time),
                'checkout_time' => $range ? $range['checkout'] : $this->get_default_checkout_for_time($booking->booking_time),
                'table_number' => null !== $booking->table_number ? (int) $booking->table_number : null,
            );

            if (null !== $entry['table_number'] && isset($tables_map[$entry['table_number']])) {
                $tables_map[$entry['table_number']]['bookings'][] = $entry;
            } else {
                $unassigned[] = $entry;
            }
        }

        foreach ($tables_map as &$table_data) {
            if (!empty($table_data['bookings'])) {
                usort($table_data['bookings'], function ($a, $b) {
                    return strcmp($a['checkin_time'], $b['checkin_time']);
                });
            }
        }
        unset($table_data);

        ksort($tables_map, SORT_NUMERIC);
        $tables_list = array_values($tables_map);

        if (!empty($unassigned)) {
            $tables_list[] = array(
                'table_id' => 0,
                'table_number' => null,
                'capacity' => 0,
                'current_status' => 'available',
                'status_updated_at' => null,
                'last_booking_id' => null,
                'bookings' => $unassigned,
            );
        }

        return array(
            'date' => $date,
            'location_id' => $location_id,
            'time_slots' => $time_slots,
            'tables' => $tables_list,
            'cleanup_buffer' => $this->get_cleanup_buffer_seconds(),
        );
    }

    public function update_table_status($table_id, $status, $booking_id = null) {
        global $wpdb;

        $table_id = (int) $table_id;
        $tables_table = $wpdb->prefix . 'rb_tables';

        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$tables_table} WHERE id = %d",
            $table_id
        ));

        if (!$table_exists) {
            return new WP_Error('table_not_found', __('Table not found.', 'restaurant-booking'));
        }

        $status = sanitize_key($status);
        $allowed_status = array('available', 'occupied', 'cleaning', 'reserved');
        if (!in_array($status, $allowed_status, true)) {
            return new WP_Error('invalid_status', __('Invalid table status provided.', 'restaurant-booking'));
        }

        $data = array(
            'current_status' => $status,
            'status_updated_at' => current_time('mysql'),
        );
        $format = array('%s', '%s');

        if ($booking_id) {
            $data['last_booking_id'] = (int) $booking_id;
            $format[] = '%d';
        } else {
            $data['last_booking_id'] = null;
            $format[] = '%d';
        }

        $updated = $wpdb->update(
            $tables_table,
            $data,
            array('id' => $table_id),
            $format,
            array('%d')
        );

        if (false === $updated) {
            return new WP_Error('db_error', __('Unable to update table status.', 'restaurant-booking'));
        }

        if (!$booking_id) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$tables_table} SET last_booking_id = NULL WHERE id = %d",
                $table_id
            ));
        }

        if ($booking_id) {
            $this->update_booking_timestamps_for_status((int) $booking_id, $status);
        }

        return true;
    }

    public function suggest_time_slots($location_id, $date, $time, $guest_count, $range_minutes = 30) {
        global $rb_location;

        if (!$rb_location) {
            require_once RB_PLUGIN_DIR . 'includes/class-location.php';
            $rb_location = new RB_Location();
        }

        $settings = $rb_location->get_settings($location_id);

        if (empty($settings)) {
            return array();
        }

        $interval = isset($settings['time_slot_interval']) ? (int) $settings['time_slot_interval'] : 30;
        if ($interval <= 0) {
            $interval = 30;
        }

        $all_slots = $this->generate_time_slots_for_location($settings['opening_time'], $settings['closing_time'], $interval);

        $target_timestamp = strtotime($date . ' ' . $time);
        if (!$target_timestamp) {
            return array();
        }

        $range_seconds = absint($range_minutes) * MINUTE_IN_SECONDS;
        $candidates = array();

        foreach ($all_slots as $slot) {
            $slot_timestamp = strtotime($date . ' ' . $slot);
            if (!$slot_timestamp) {
                continue;
            }

            $difference = abs($slot_timestamp - $target_timestamp);
            if ($difference <= $range_seconds) {
                if ($this->is_time_slot_available($date, $slot, $guest_count, null, $location_id)) {
                    $candidates[] = array(
                        'time' => $slot,
                        'diff' => $difference,
                        'is_after' => $slot_timestamp > $target_timestamp
                    );
                }
            }
        }

        if (empty($candidates)) {
            return array();
        }

        usort($candidates, function($a, $b) {
            if ($a['diff'] === $b['diff']) {
                if ($a['is_after'] === $b['is_after']) {
                    return 0;
                }

                return $a['is_after'] ? 1 : -1;
            }

            return ($a['diff'] < $b['diff']) ? -1 : 1;
        });

        $limited = array_slice($candidates, 0, 2);

        return array_map(function($candidate) {
            return $candidate['time'];
        }, $limited);
    }

    private function table_is_available($table_number, $date, array $range, $location_id, $exclude_booking_id = null) {
        global $wpdb;
        $bookings_table = $wpdb->prefix . 'rb_bookings';

        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT id, checkin_time, checkout_time, booking_time
             FROM {$bookings_table}
             WHERE booking_date = %s
               AND location_id = %d
               AND table_number = %d
               AND status IN ('pending', 'confirmed')",
            $date,
            (int) $location_id,
            (int) $table_number
        ));

        foreach ($bookings as $booking) {
            if (null !== $exclude_booking_id && (int) $booking->id === (int) $exclude_booking_id) {
                continue;
            }

            $existing_range = $this->get_time_range($date, !empty($booking->checkin_time) ? $booking->checkin_time : $booking->booking_time, !empty($booking->checkout_time) ? $booking->checkout_time : null);
            if ($existing_range && $this->ranges_overlap_with_buffer($range, $existing_range)) {
                return false;
            }
        }

        return true;
    }

    private function ranges_overlap_with_buffer(array $range_a, array $range_b) {
        $buffer = $this->get_cleanup_buffer_seconds();

        $start_a = $range_a['start'];
        $end_a = $range_a['end'] + $buffer;
        $start_b = $range_b['start'];
        $end_b = $range_b['end'] + $buffer;

        return ($start_a < $end_b) && ($end_a > $start_b);
    }

    private function get_cleanup_buffer_seconds() {
        return HOUR_IN_SECONDS;
    }

    private function update_booking_timestamps_for_status($booking_id, $status) {
        $booking_id = (int) $booking_id;
        if (!$booking_id) {
            return;
        }

        $table = $this->wpdb->prefix . 'rb_bookings';
        $now = current_time('mysql');

        if ('occupied' === $status) {
            $this->wpdb->update($table, array('actual_checkin' => $now), array('id' => $booking_id), array('%s'), array('%d'));
        } elseif ('cleaning' === $status) {
            $this->wpdb->update($table, array('actual_checkout' => $now), array('id' => $booking_id), array('%s'), array('%d'));
        } elseif ('available' === $status) {
            $this->wpdb->update($table, array('cleanup_completed_at' => $now), array('id' => $booking_id), array('%s'), array('%d'));
        }
    }

    private function sanitize_time_value($time) {
        if (null === $time) {
            return null;
        }

        $time = trim((string) $time);
        if ($time === '') {
            return null;
        }

        $timezone = function_exists('wp_timezone') ? wp_timezone() : null;
        $formats = array('H:i:s', 'H:i');

        foreach ($formats as $format) {
            $date_time = $timezone
                ? DateTime::createFromFormat($format, $time, $timezone)
                : DateTime::createFromFormat($format, $time);

            if ($date_time instanceof DateTime) {
                return $date_time->format('H:i:s');
            }
        }

        $timestamp = strtotime($time);
        if (false !== $timestamp) {
            $seconds = $timestamp % DAY_IN_SECONDS;
            if ($seconds < 0) {
                $seconds += DAY_IN_SECONDS;
            }
            return gmdate('H:i:s', $seconds);
        }

        return null;
    }

    private function get_default_checkout_for_time($checkin_time) {
        $checkin_time = $this->sanitize_time_value($checkin_time);
        if (!$checkin_time) {
            return '00:00:00';
        }

        $parts = explode(':', $checkin_time);
        $hours = isset($parts[0]) ? (int) $parts[0] : 0;
        $minutes = isset($parts[1]) ? (int) $parts[1] : 0;
        $seconds = isset($parts[2]) ? (int) $parts[2] : 0;

        $total_seconds = ($hours * HOUR_IN_SECONDS) + ($minutes * MINUTE_IN_SECONDS) + $seconds + (2 * HOUR_IN_SECONDS);

        if ($total_seconds >= DAY_IN_SECONDS) {
            $total_seconds = DAY_IN_SECONDS - MINUTE_IN_SECONDS;
        }

        $hours = (int) floor($total_seconds / HOUR_IN_SECONDS);
        $minutes = (int) floor(($total_seconds % HOUR_IN_SECONDS) / MINUTE_IN_SECONDS);
        $seconds = (int) ($total_seconds % MINUTE_IN_SECONDS);

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }

    private function get_time_range($date, $checkin, $checkout = null) {
        $checkin_time = $this->sanitize_time_value($checkin);
        if (!$checkin_time) {
            return null;
        }

        $checkout_time = $this->sanitize_time_value($checkout);
        if (!$checkout_time) {
            $checkout_time = $this->get_default_checkout_for_time($checkin_time);
        }

        $start = strtotime($date . ' ' . $checkin_time);
        if (false === $start) {
            return null;
        }

        $end = strtotime($date . ' ' . $checkout_time);
        if (false === $end || $end <= $start) {
            $end = $start + HOUR_IN_SECONDS;
            $checkout_time = date('H:i:s', $end);
        }

        return array(
            'checkin' => $checkin_time,
            'checkout' => $checkout_time,
            'start' => $start,
            'end' => $end,
        );
    }

    private function generate_time_slots_for_location($opening_time, $closing_time, $interval) {
        $slots = array();
        $current = strtotime($opening_time);
        $end = strtotime($closing_time);

        if ($current === false || $end === false) {
            return $slots;
        }

        while ($current <= $end) {
            $slots[] = date('H:i', $current);
            $current = strtotime("+{$interval} minutes", $current);
        }

        return $slots;
    }

    private function prepare_and_get_var($sql, $params = array()) {
        if (!empty($params)) {
            return $this->wpdb->get_var($this->wpdb->prepare($sql, $params));
        }

        return $this->wpdb->get_var($sql);
    }

    private function generate_confirmation_token() {
        return wp_generate_password(32, false, false);
    }

    public function confirm_booking_by_token($token) {
        if (empty($token)) {
            return new WP_Error('rb_invalid_token', __('Invalid confirmation token', 'restaurant-booking'));
        }

        $table_name = $this->wpdb->prefix . 'rb_bookings';
        $booking = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE confirmation_token = %s",
            $token
        ));

        if (!$booking) {
            return new WP_Error('rb_token_not_found', __('Booking not found or already confirmed', 'restaurant-booking'));
        }

        if (!empty($booking->confirmation_token_expires)) {
            $expires = strtotime($booking->confirmation_token_expires);
            if ($expires && $expires < current_time('timestamp', true)) {
                return new WP_Error('rb_token_expired', __('Confirmation link has expired', 'restaurant-booking'));
            }
        }

        if ($booking->status === 'confirmed') {
            return true;
        }

        $result = $this->confirm_booking($booking->id);

        if (is_wp_error($result)) {
            return $result;
        }

        $this->wpdb->update(
            $table_name,
            array(
                'confirmation_token' => null,
                'confirmation_token_expires' => null,
                'confirmed_via' => 'email'
            ),
            array('id' => $booking->id),
            array('%s', '%s', '%s'),
            array('%d')
        );

        return true;
    }
}
