<?php
/**
 * Database Class - Tạo và quản lý database tables
 */

if (!defined('ABSPATH')) {
    exit;
}

class RB_Database {
    
    private $wpdb;
    private $charset_collate;
    
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->charset_collate = $wpdb->get_charset_collate();
    }
    
    public function create_tables() {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        $this->create_bookings_table();
        $this->create_tables_table();
        $this->create_customers_table();
        $this->insert_default_tables();
        $this->add_booking_source_column();
    }
    
    private function create_bookings_table() {
        $table_name = $this->wpdb->prefix . 'rb_bookings';
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id int(11) NOT NULL AUTO_INCREMENT,
            customer_name varchar(100) NOT NULL,
            customer_phone varchar(20) NOT NULL,
            customer_email varchar(100) NOT NULL,
            guest_count int(11) NOT NULL,
            booking_date date NOT NULL,
            booking_time time NOT NULL,
            table_number int(11) DEFAULT NULL,
            status varchar(20) DEFAULT 'pending',
            booking_source varchar(50) DEFAULT 'website',
            special_requests text DEFAULT NULL,
            admin_notes text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            confirmed_at datetime DEFAULT NULL,
            created_by int(11) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY booking_date (booking_date),
            KEY status (status),
            KEY booking_source (booking_source)
        ) $this->charset_collate;";
        
        dbDelta($sql);
    }
    
    private function create_tables_table() {
        $table_name = $this->wpdb->prefix . 'rb_tables';
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id int(11) NOT NULL AUTO_INCREMENT,
            table_number int(11) NOT NULL,
            capacity int(11) NOT NULL,
            is_available tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY table_number (table_number)
        ) $this->charset_collate;";
        
        dbDelta($sql);
    }
    
    private function create_customers_table() {
        $table_name = $this->wpdb->prefix . 'rb_customers';
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id int(11) NOT NULL AUTO_INCREMENT,
            phone varchar(20) UNIQUE NOT NULL,
            email varchar(100),
            name varchar(100),
            total_bookings int DEFAULT 0,
            completed_bookings int DEFAULT 0,
            cancelled_bookings int DEFAULT 0,
            no_shows int DEFAULT 0,
            last_visit date,
            first_visit date,
            customer_notes text,
            vip_status tinyint(1) DEFAULT 0,
            blacklisted tinyint(1) DEFAULT 0,
            preferred_source varchar(50),
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY phone (phone),
            KEY email (email)
        ) $this->charset_collate;";
        
        dbDelta($sql);
    }
    
    public function add_booking_source_column() {
        $table_name = $this->wpdb->prefix . 'rb_bookings';
        
        $column_exists = $this->wpdb->get_results(
            "SHOW COLUMNS FROM $table_name LIKE 'booking_source'"
        );
        
        if (empty($column_exists)) {
            $this->wpdb->query(
                "ALTER TABLE $table_name 
                ADD COLUMN booking_source varchar(50) DEFAULT 'website' AFTER status,
                ADD COLUMN admin_notes text DEFAULT NULL AFTER special_requests,
                ADD COLUMN created_by int(11) DEFAULT NULL AFTER confirmed_at"
            );
        }
    }
    
    private function insert_default_tables() {
        $table_name = $this->wpdb->prefix . 'rb_tables';
        
        $count = $this->wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        
        if ($count == 0) {
            for ($i = 1; $i <= 10; $i++) {
                $this->wpdb->insert(
                    $table_name,
                    array(
                        'table_number' => $i,
                        'capacity' => ($i <= 4) ? 2 : (($i <= 8) ? 4 : 6),
                        'is_available' => 1,
                        'created_at' => current_time('mysql')
                    ),
                    array('%d', '%d', '%d', '%s')
                );
            }
        }
    }
    
    public function drop_tables() {
        $tables = array(
            $this->wpdb->prefix . 'rb_bookings',
            $this->wpdb->prefix . 'rb_tables',
            $this->wpdb->prefix . 'rb_customers'
        );
        
        foreach ($tables as $table) {
            $this->wpdb->query("DROP TABLE IF EXISTS $table");
        }
    }
}