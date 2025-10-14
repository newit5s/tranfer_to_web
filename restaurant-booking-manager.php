<?php
/**
 * Plugin Name: Restaurant Booking Manager
 * Plugin URI: https://github.com/newit5s/wp_booking-table
 * Description: Plugin quản lý đặt bàn nhà hàng hoàn chỉnh với giao diện thân thiện
 * Version: 1.0.0
 * Author: NewIT5S
 * Author URI: https://github.com/newit5s
 * License: GPL v2 or later
 * Text Domain: restaurant-booking
 * Domain Path: /languages
 */

// Ngăn truy cập trực tiếp
if (!defined('ABSPATH')) {
    exit;
}

// Define constants
define('RB_VERSION', '1.0.0');
define('RB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('RB_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Activation Hook - Tạo database tables
 */
register_activation_hook(__FILE__, 'rb_activate_plugin');
function rb_activate_plugin() {
    require_once RB_PLUGIN_DIR . 'includes/class-database.php';
    $database = new RB_Database();
    $database->create_tables();
    
    add_option('rb_settings', array(
        'max_tables' => 20,
        'opening_time' => '09:00',
        'closing_time' => '22:00',
        'time_slot_interval' => 30,
        'admin_email' => get_option('admin_email'),
        'enable_email' => 'yes'
    ));
    
    flush_rewrite_rules();
}

/**
 * Deactivation Hook
 */
register_deactivation_hook(__FILE__, 'rb_deactivate_plugin');
function rb_deactivate_plugin() {
    flush_rewrite_rules();
}

/**
 * Load plugin textdomain
 */
add_action('plugins_loaded', 'rb_load_textdomain');
function rb_load_textdomain() {
    load_plugin_textdomain('restaurant-booking', false, dirname(RB_PLUGIN_BASENAME) . '/languages');
}

/**
 * Initialize Plugin
 */
add_action('plugins_loaded', 'rb_init_plugin', 5);
function rb_init_plugin() {
    // Load I18n FIRST
    require_once RB_PLUGIN_DIR . 'includes/class-i18n.php';
    RB_I18n::get_instance();
    
    // Load Language Switcher
    require_once RB_PLUGIN_DIR . 'includes/class-language-switcher.php';
    new RB_Language_Switcher();
    
    // Load required files
    require_once RB_PLUGIN_DIR . 'includes/class-database.php';
    require_once RB_PLUGIN_DIR . 'includes/class-booking.php';
    require_once RB_PLUGIN_DIR . 'includes/class-customer.php';
    require_once RB_PLUGIN_DIR . 'includes/class-ajax.php';
    require_once RB_PLUGIN_DIR . 'includes/class-email.php';
    
    // Initialize globals
    global $rb_database, $rb_booking, $rb_customer, $rb_email;
    $rb_database = new RB_Database();
    $rb_booking = new RB_Booking();
    $rb_customer = new RB_Customer();
    $rb_email = new RB_Email();
    
    // Initialize AJAX
    new RB_Ajax();
    
    // Load Admin
    if (is_admin()) {
        require_once RB_PLUGIN_DIR . 'admin/class-admin.php';
        new RB_Admin();
    }
    
    // Load Frontend
    if (!is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
        if (!class_exists('RB_Frontend')) {
            require_once RB_PLUGIN_DIR . 'public/class-frontend.php';
        }
        new RB_Frontend();
    }
}

/**
 * Enqueue admin scripts and styles
 */
add_action('admin_enqueue_scripts', 'rb_admin_enqueue_scripts');
function rb_admin_enqueue_scripts($hook) {
    if (strpos($hook, 'restaurant-booking') !== false || strpos($hook, 'rb-') !== false) {
        wp_enqueue_style('rb-admin-css', RB_PLUGIN_URL . 'assets/css/admin.css', array(), RB_VERSION);
        wp_enqueue_script('rb-admin-js', RB_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), RB_VERSION, true);
        
        wp_localize_script('rb-admin-js', 'rb_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rb_admin_nonce'),
            'language_nonce' => wp_create_nonce('rb_language_nonce'),
            'translations' => RB_I18n::get_instance()->get_js_translations(),
            'current_language' => rb_get_current_language()
        ));
    }
}

/**
 * Enqueue frontend scripts and styles
 */
add_action('wp_enqueue_scripts', 'rb_frontend_enqueue_scripts');
function rb_frontend_enqueue_scripts() {
    wp_enqueue_style('rb-frontend-css', RB_PLUGIN_URL . 'assets/css/frontend.css', array(), RB_VERSION);
    wp_enqueue_script('rb-frontend-js', RB_PLUGIN_URL . 'assets/js/frontend.js', array('jquery'), RB_VERSION, true);
    
    wp_localize_script('rb-frontend-js', 'rb_ajax', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('rb_frontend_nonce'),
        'language_nonce' => wp_create_nonce('rb_language_nonce'),
        'translations' => RB_I18n::get_instance()->get_js_translations(),
        'current_language' => rb_get_current_language()
    ));
}

/**
 * Register shortcode
 */
add_shortcode('restaurant_booking', 'rb_booking_shortcode');
function rb_booking_shortcode($atts) {
    if (!class_exists('RB_Frontend')) {
        require_once RB_PLUGIN_DIR . 'public/class-frontend.php';
    }
    
    $frontend = new RB_Frontend();
    return $frontend->render_booking_form($atts);
}

/**
 * Add plugin action links
 */
add_filter('plugin_action_links_' . RB_PLUGIN_BASENAME, 'rb_plugin_action_links');
function rb_plugin_action_links($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=rb-settings') . '">' . rb_t('settings') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

/**
 * Check plugin dependencies
 */
add_action('admin_notices', 'rb_check_dependencies');
function rb_check_dependencies() {
    if (version_compare(PHP_VERSION, '7.0', '<')) {
        ?>
        <div class="notice notice-error">
            <p><?php echo rb_t('php_version_error'); ?></p>
        </div>
        <?php
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'rb_bookings';
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        ?>
        <div class="notice notice-warning is-dismissible">
            <p><?php echo rb_t('database_tables_error'); ?></p>
        </div>
        <?php
    }
}
