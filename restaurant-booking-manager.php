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
    require_once RB_PLUGIN_DIR . 'includes/class-location.php';
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

    add_role(
        'rb_location_manager',
        __('Location Manager', 'restaurant-booking'),
        array(
            'read' => true,
            'rb_manage_location' => true
        )
    );

    if ($administrator = get_role('administrator')) {
        $administrator->add_cap('rb_manage_location');
    }

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
    require_once RB_PLUGIN_DIR . 'includes/class-location.php';

    // Initialize globals
    global $rb_database, $rb_booking, $rb_customer, $rb_email, $rb_location;
    $rb_database = new RB_Database();
    $rb_booking = new RB_Booking();
    $rb_customer = new RB_Customer();
    $rb_email = new RB_Email();
    $rb_location = new RB_Location();
    
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

    global $rb_location;
    if (!$rb_location) {
        require_once RB_PLUGIN_DIR . 'includes/class-location.php';
        $rb_location = new RB_Location();
    }

    $location_data = array();
    if ($rb_location) {
        $locations = $rb_location->all();
        foreach ($locations as $location) {
            $location_data[] = array(
                'id' => (int) $location->id,
                'name' => $location->name,
                'slug' => $location->slug,
                'hotline' => $location->hotline,
                'email' => $location->email,
                'address' => $location->address,
                'opening_time' => $location->opening_time,
                'closing_time' => $location->closing_time,
                'time_slot_interval' => (int) $location->time_slot_interval,
                'min_advance_booking' => (int) $location->min_advance_booking,
                'max_advance_booking' => (int) $location->max_advance_booking,
                'languages' => array_map('trim', explode(',', $location->languages)),
            );
        }
    }

    $default_location_id = !empty($location_data) ? (int) $location_data[0]['id'] : 0;

    wp_localize_script('rb-frontend-js', 'rb_ajax', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('rb_frontend_nonce'),
        'language_nonce' => wp_create_nonce('rb_language_nonce'),
        'translations' => RB_I18n::get_instance()->get_js_translations(),
        'current_language' => rb_get_current_language(),
        'locations' => $location_data,
        'default_location_id' => $default_location_id,
        'loading_text' => rb_t('checking', __('Checking...', 'restaurant-booking')),
        'error_text' => rb_t('error_generic', __('Something went wrong. Please try again.', 'restaurant-booking')),
        'invalid_phone_text' => rb_t('invalid_phone', __('Invalid phone number. Please enter a valid phone number.', 'restaurant-booking')),
        'choose_location_text' => rb_t('choose_location', __('Please select a location.', 'restaurant-booking')),
        'choose_language_text' => rb_t('choose_language', __('Please select a language.', 'restaurant-booking')),
        'missing_fields_text' => rb_t('availability_missing_fields', __('Please select date, time, guests and location before checking availability.', 'restaurant-booking')),
        'select_time_text' => rb_t('select_time', __('Select Time', 'restaurant-booking')),
        'no_slots_text' => rb_t('no_slots_available', __('No available times', 'restaurant-booking')),
        'confirm_text' => __('Confirm', 'restaurant-booking'),
        'cancel_text' => __('Cancel', 'restaurant-booking'),
        'complete_text' => __('Complete', 'restaurant-booking'),
        'no_actions_text' => __('No actions available', 'restaurant-booking')
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

add_shortcode('restaurant_booking_portal', 'rb_booking_portal_shortcode');
function rb_booking_portal_shortcode($atts) {
    if (!class_exists('RB_Frontend')) {
        require_once RB_PLUGIN_DIR . 'public/class-frontend.php';
    }

    $frontend = new RB_Frontend();
    return $frontend->render_multi_location_portal($atts);
}

add_shortcode('restaurant_booking_manager', 'rb_booking_manager_shortcode');
function rb_booking_manager_shortcode($atts) {
    if (!class_exists('RB_Frontend')) {
        require_once RB_PLUGIN_DIR . 'public/class-frontend.php';
    }

    $frontend = new RB_Frontend();
    return $frontend->render_location_manager($atts);
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
