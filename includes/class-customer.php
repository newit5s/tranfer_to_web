<?php
class RB_Customer {
    private $wpdb;
    
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }
    
    /**
     * Tự động cập nhật thông tin khách khi có booking mới
     */
    public function update_customer_from_booking($booking_id) {
        $booking_table = $this->wpdb->prefix . 'rb_bookings';
        $customer_table = $this->wpdb->prefix . 'rb_customers';

        $booking = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM $booking_table WHERE id = %d", $booking_id
        ));

        if (!$booking) {
            return false;
        }

        // Check if customer exists
        $customer = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM $customer_table WHERE phone = %s", 
            $booking->customer_phone
        ));

        if ($customer) {
            // Update existing customer
            $this->wpdb->query($this->wpdb->prepare(
                "UPDATE $customer_table SET 
                total_bookings = total_bookings + 1,
                last_visit = %s,
                name = %s,
                email = %s,
                updated_at = NOW()
                WHERE phone = %s",
                $booking->booking_date,
                $booking->customer_name,
                $booking->customer_email,
                $booking->customer_phone
            ));
        } else {
            // Create new customer
            $this->wpdb->insert($customer_table, array(
                'phone' => $booking->customer_phone,
                'email' => $booking->customer_email,
                'name' => $booking->customer_name,
                'total_bookings' => 1,
                'first_visit' => $booking->booking_date,
                'last_visit' => $booking->booking_date,
                'preferred_source' => $booking->booking_source,
                'created_at' => current_time('mysql')
            ));
        }

        return true;
    }
    
    /**
     * Cập nhật khi booking completed + Auto VIP
     */
    public function mark_completed($booking_id) {
        $booking_table = $this->wpdb->prefix . 'rb_bookings';
        $customer_table = $this->wpdb->prefix . 'rb_customers';
        
        $booking = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM $booking_table WHERE id = %d", $booking_id
        ));
        
        if ($booking) {
            $this->wpdb->query($this->wpdb->prepare(
                "UPDATE $customer_table SET 
                completed_bookings = completed_bookings + 1
                WHERE phone = %s",
                $booking->customer_phone
            ));
            
            // *** AUTO VIP CHECK ***
            $this->auto_upgrade_vip($booking->customer_phone);
        }
    }
    
    /**
     * Auto upgrade to VIP nếu completed >= 5
     */
    private function auto_upgrade_vip($phone) {
        $customer_table = $this->wpdb->prefix . 'rb_customers';
        
        $customer = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM $customer_table WHERE phone = %s", $phone
        ));
        
        if ($customer && $customer->completed_bookings >= 5 && !$customer->vip_status) {
            $this->wpdb->update(
                $customer_table,
                array('vip_status' => 1),
                array('phone' => $phone)
            );
            
            // Trigger VIP upgrade action (for notifications, etc.)
            do_action('rb_customer_upgraded_vip', $customer);
        }
    }
    
    /**
     * Manual set VIP status
     */
    public function set_vip_status($customer_id, $status) {
        $customer_table = $this->wpdb->prefix . 'rb_customers';
        
        return $this->wpdb->update(
            $customer_table,
            array('vip_status' => $status ? 1 : 0),
            array('id' => $customer_id)
        );
    }
    
    /**
     * Blacklist customer
     */
    public function set_blacklist($customer_id, $status) {
        $customer_table = $this->wpdb->prefix . 'rb_customers';
        
        return $this->wpdb->update(
            $customer_table,
            array('blacklisted' => $status ? 1 : 0),
            array('id' => $customer_id)
        );
    }
    
    /**
     * Cập nhật khi booking cancelled
     */
    public function mark_cancelled($booking_id) {
        $booking_table = $this->wpdb->prefix . 'rb_bookings';
        $customer_table = $this->wpdb->prefix . 'rb_customers';
        
        $booking = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM $booking_table WHERE id = %d", $booking_id
        ));
        
        if ($booking) {
            $this->wpdb->query($this->wpdb->prepare(
                "UPDATE $customer_table SET 
                cancelled_bookings = cancelled_bookings + 1
                WHERE phone = %s",
                $booking->customer_phone
            ));
            
            // Auto check for problematic customer
            $this->check_problematic_customer($booking->customer_phone);
        }
    }
    
    /**
     * Đánh dấu no-show
     */
    public function mark_no_show($booking_id) {
        $booking_table = $this->wpdb->prefix . 'rb_bookings';
        $customer_table = $this->wpdb->prefix . 'rb_customers';
        
        $booking = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM $booking_table WHERE id = %d", $booking_id
        ));
        
        if ($booking) {
            $this->wpdb->query($this->wpdb->prepare(
                "UPDATE $customer_table SET 
                no_shows = no_shows + 1
                WHERE phone = %s",
                $booking->customer_phone
            ));
            
            $this->check_problematic_customer($booking->customer_phone);
        }
    }
    
    /**
     * Check nếu customer có vấn đề (nhiều cancel/no-show)
     */
    private function check_problematic_customer($phone) {
        $customer_table = $this->wpdb->prefix . 'rb_customers';
        
        $customer = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM $customer_table WHERE phone = %s", $phone
        ));
        
        if ($customer && $customer->total_bookings >= 3) {
            $problem_count = $customer->no_shows + $customer->cancelled_bookings;
            $problem_rate = ($problem_count / $customer->total_bookings) * 100;
            
            // Nếu > 50% cancel/no-show -> trigger warning
            if ($problem_rate > 50) {
                do_action('rb_problematic_customer_detected', $customer);
            }
        }
    }
    
    /**
     * Lấy lịch sử khách hàng
     */
    public function get_customer_history($phone) {
        $booking_table = $this->wpdb->prefix . 'rb_bookings';
        
        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM $booking_table 
            WHERE customer_phone = %s 
            ORDER BY booking_date DESC, booking_time DESC 
            LIMIT 20",
            $phone
        ));
    }
    
    /**
     * Gợi ý VIP (khách đặt nhiều)
     */
    public function get_vip_suggestions() {
        $customer_table = $this->wpdb->prefix . 'rb_customers';
        
        return $this->wpdb->get_results(
            "SELECT * FROM $customer_table 
            WHERE completed_bookings >= 5 
            AND vip_status = 0
            ORDER BY completed_bookings DESC
            LIMIT 20"
        );
    }
    
    /**
     * Cảnh báo khách có vấn đề
     */
    public function get_problematic_customers() {
        $customer_table = $this->wpdb->prefix . 'rb_customers';
        
        return $this->wpdb->get_results(
            "SELECT *, 
            (no_shows + cancelled_bookings) as problem_count,
            (no_shows + cancelled_bookings) / total_bookings * 100 as problem_rate
            FROM $customer_table 
            WHERE total_bookings >= 3
            AND (no_shows + cancelled_bookings) / total_bookings > 0.3
            ORDER BY problem_rate DESC
            LIMIT 20"
        );
    }
    
    /**
     * Get customers với filters và sorting
     */
    public function get_customers($args = array()) {
        $customer_table = $this->wpdb->prefix . 'rb_customers';
        
        $defaults = array(
            'vip_only' => false,
            'blacklisted' => null,
            'search' => '',
            'orderby' => 'total_bookings',
            'order' => 'DESC',
            'limit' => -1
        );
        
        $args = wp_parse_args($args, $defaults);
        
        $where = array('1=1');
        
        if ($args['vip_only']) {
            $where[] = 'vip_status = 1';
        }
        
        if ($args['blacklisted'] !== null) {
            $where[] = $this->wpdb->prepare('blacklisted = %d', $args['blacklisted']);
        }
        
        if (!empty($args['search'])) {
            $search = '%' . $this->wpdb->esc_like($args['search']) . '%';
            $where[] = $this->wpdb->prepare(
                '(name LIKE %s OR phone LIKE %s OR email LIKE %s)',
                $search, $search, $search
            );
        }
        
        $where_sql = implode(' AND ', $where);
        
        $orderby = sanitize_sql_orderby($args['orderby'] . ' ' . $args['order']);
        
        $limit_sql = '';
        if ($args['limit'] > 0) {
            $limit_sql = $this->wpdb->prepare('LIMIT %d', $args['limit']);
        }
        
        $sql = "SELECT * FROM $customer_table 
                WHERE $where_sql 
                ORDER BY $orderby 
                $limit_sql";
        
        return $this->wpdb->get_results($sql);
    }
    
    /**
     * Get customer stats
     */
    public function get_stats() {
        $customer_table = $this->wpdb->prefix . 'rb_customers';
        
        return array(
            'total' => $this->wpdb->get_var("SELECT COUNT(*) FROM $customer_table"),
            'vip' => $this->wpdb->get_var("SELECT COUNT(*) FROM $customer_table WHERE vip_status = 1"),
            'blacklisted' => $this->wpdb->get_var("SELECT COUNT(*) FROM $customer_table WHERE blacklisted = 1"),
            'new_this_month' => $this->wpdb->get_var(
                "SELECT COUNT(*) FROM $customer_table 
                WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) 
                AND YEAR(created_at) = YEAR(CURRENT_DATE())"
            )
        );
    }
}