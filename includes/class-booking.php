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
            'limit' => -1,
            'offset' => 0,
            'orderby' => 'booking_date',
            'order' => 'DESC'
        );
        
        $args = wp_parse_args($args, $defaults);
        $table_name = $this->wpdb->prefix . 'rb_bookings';
        
        $where_clauses = array('1=1');
        
        if (!empty($args['status'])) {
            $where_clauses[] = $this->wpdb->prepare("status = %s", $args['status']);
        }
        
        if (!empty($args['date'])) {
            $where_clauses[] = $this->wpdb->prepare("booking_date = %s", $args['date']);
        }
        
        $where = implode(' AND ', $where_clauses);
        $orderby = sanitize_sql_orderby($args['orderby'] . ' ' . $args['order']);
        
        $sql = "SELECT * FROM $table_name WHERE $where ORDER BY $orderby";
        
        if ($args['limit'] > 0) {
            $sql .= $this->wpdb->prepare(" LIMIT %d OFFSET %d", $args['limit'], $args['offset']);
        }
        
        return $this->wpdb->get_results($sql);
    }
    
    public function create_booking($data) {
        $table_name = $this->wpdb->prefix . 'rb_bookings';

        $defaults = array(
            'status' => 'pending',
            'booking_source' => 'website',
            'created_at' => current_time('mysql'),
            'table_number' => null
        );

        $data = wp_parse_args($data, $defaults);

        // Validate required fields
        $required = array('customer_name', 'customer_phone', 'customer_email', 'guest_count', 'booking_date', 'booking_time');

        foreach ($required as $field) {
            if (empty($data[$field])) {
                return new WP_Error('missing_field', sprintf(__('Field %s is required', 'restaurant-booking'), $field));
            }
        }

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
        $slot_table = $this->get_smallest_available_table($bk->booking_date, $bk->booking_time, (int)$bk->guest_count);
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
    
    public function is_time_slot_available($date, $time, $guest_count, $exclude_booking_id = null) {
        global $wpdb;
        $tables_table = $wpdb->prefix . 'rb_tables';
        $bookings_table = $wpdb->prefix . 'rb_bookings';

        // Tính tổng sức chứa
        $total_capacity = (int) $wpdb->get_var(
            "SELECT SUM(capacity) FROM {$tables_table} WHERE is_available = 1"
        );

        if ($total_capacity <= 0) {
            return false;
        }

        // Tính tổng số khách đã book (pending + confirmed)
        $exclude_sql = '';
        if ($exclude_booking_id) {
            $exclude_sql = $wpdb->prepare(' AND id != %d', (int)$exclude_booking_id);
        }

        $booked_guests = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(guest_count) 
            FROM {$bookings_table}
            WHERE booking_date = %s
            AND booking_time = %s
            AND status IN ('pending', 'confirmed')
            {$exclude_sql}",
            $date, $time
        ));

        $remaining_capacity = $total_capacity - $booked_guests;
        
        return $remaining_capacity >= $guest_count;
    }

    public function get_smallest_available_table($date, $time, $guest_count) {
        global $wpdb;
        $t = $wpdb->prefix . 'rb_tables';
        $b = $wpdb->prefix . 'rb_bookings';

        $sql = $wpdb->prepare(
            "SELECT t.table_number, t.capacity
             FROM {$t} t
             WHERE t.is_available = 1
               AND t.capacity >= %d
               AND t.table_number NOT IN (
                 SELECT b.table_number
                 FROM {$b} b
                 WHERE b.booking_date = %s
                   AND b.booking_time = %s
                   AND b.status IN ('confirmed', 'pending')
                   AND b.table_number IS NOT NULL
               )
             ORDER BY t.capacity ASC, t.table_number ASC
             LIMIT 1",
            (int)$guest_count, $date, $time
        );
        
        return $wpdb->get_row($sql);
    }

    public function available_table_count($date, $time, $guest_count) {
        global $wpdb;
        $t = $wpdb->prefix . 'rb_tables';
        $b = $wpdb->prefix . 'rb_bookings';

        $sql = $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$t} x
             WHERE x.is_available = 1
               AND x.capacity >= %d
               AND x.table_number NOT IN (
                 SELECT y.table_number
                 FROM {$b} y
                 WHERE y.booking_date = %s
                   AND y.booking_time = %s
                   AND y.status IN ('confirmed', 'pending')
                   AND y.table_number IS NOT NULL
               )",
            (int)$guest_count, $date, $time
        );
        
        return (int) $wpdb->get_var($sql);
    }
}