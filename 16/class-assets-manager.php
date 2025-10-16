<?php
/**
 * Enqueue scripts and styles for the new booking interface
 * Add this to your main plugin file or create a separate file
 */

if (!defined('ABSPATH')) {
    exit;
}

class RB_Assets_Manager {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('wp_head', array($this, 'add_custom_css'));
    }

    public function enqueue_frontend_assets() {
        // Only enqueue on pages that might have the booking shortcode
        if (!$this->should_enqueue_assets()) {
            return;
        }

        // Enqueue the new CSS
        wp_enqueue_style(
            'rb-new-frontend-css',
            plugin_dir_url(__FILE__) . 'assets/css/new-frontend.css',
            array(),
            '1.0.0'
        );

        // Enqueue jQuery (if not already loaded)
        wp_enqueue_script('jquery');

        // Enqueue the new JavaScript
        wp_enqueue_script(
            'rb-new-booking-js',
            plugin_dir_url(__FILE__) . 'assets/js/new-booking.js',
            array('jquery'),
            '1.0.0',
            true
        );

        // Localize script with AJAX data and translated strings
        wp_localize_script('rb-new-booking-js', 'rbBookingAjax', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rb_frontend_nonce'),
        ));

        wp_localize_script('rb-new-booking-js', 'rbBookingStrings', array(
            'checking' => __('Checking...', 'restaurant-booking'),
            'processing' => __('Processing...', 'restaurant-booking'),
            'selectTime' => __('Select time', 'restaurant-booking'),
            'people' => __('people', 'restaurant-booking'),
            'connectionError' => __('Connection error. Please try again.', 'restaurant-booking'),
            'securityError' => __('Security check failed', 'restaurant-booking'),
            'fillRequired' => __('Please fill in all required fields', 'restaurant-booking'),
            'suggestedTimes' => __('Suggested Times', 'restaurant-booking'),
            'back' => __('Back', 'restaurant-booking'),
            'continue' => __('Continue', 'restaurant-booking'),
            'checkingAvailability' => __('Check Availability', 'restaurant-booking'),
            'confirmBooking' => __('Confirm Booking', 'restaurant-booking'),
            'invalidEmail' => __('Please enter a valid email address', 'restaurant-booking'),
            'invalidPhone' => __('Please enter a valid phone number', 'restaurant-booking'),
        ));
    }

    private function should_enqueue_assets() {
        global $post;

        // Check if we're on a page that might contain the booking shortcode
        if (is_admin()) {
            return false;
        }

        // Always enqueue on posts/pages (shortcode might be anywhere)
        if (is_singular()) {
            return true;
        }

        // Check for specific pages or post types
        if (is_front_page() || is_home()) {
            return true;
        }

        // You can add more conditions here based on your needs
        // For example, specific page templates or custom post types

        return false;
    }

    public function add_custom_css() {
        if (!$this->should_enqueue_assets()) {
            return;
        }

        // Add some additional CSS directly in head for immediate loading
        ?>
        <style>
        /* Prevent body scroll when modal is open */
        body.rb-modal-open {
            overflow: hidden;
            padding-right: 15px; /* Prevent layout shift */
        }
        
        /* Additional utility classes */
        .rb-new-suggestion-btn.selected {
            background: #ff6b6b !important;
            color: #ffffff !important;
            border-color: #ff6b6b !important;
            transform: translateY(-1px);
        }
        
        /* Loading spinner for buttons */
        .rb-new-loading {
            position: relative;
            pointer-events: none;
        }
        
        .rb-new-loading::after {
            content: "";
            position: absolute;
            top: 50%;
            left: 50%;
            width: 16px;
            height: 16px;
            margin: -8px 0 0 -8px;
            border: 2px solid transparent;
            border-top: 2px solid currentColor;
            border-radius: 50%;
            animation: rb-spin 1s linear infinite;
        }
        
        @keyframes rb-spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Error state for form fields */
        .rb-new-form-group input.error,
        .rb-new-form-group select.error,
        .rb-new-form-group textarea.error {
            border-color: #fc8181 !important;
            box-shadow: 0 0 0 3px rgba(252, 129, 129, 0.1) !important;
        }
        
        /* Success animation */
        .rb-new-result.success {
            animation: rb-success-pulse 0.6s ease-in-out;
        }
        
        @keyframes rb-success-pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.02); }
            100% { transform: scale(1); }
        }
        
        /* Smooth transitions for all interactive elements */
        .rb-new-form-group input,
        .rb-new-form-group select,
        .rb-new-form-group textarea,
        .rb-new-btn-primary,
        .rb-new-btn-secondary,
        .rb-new-suggestion-btn {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        /* Improve focus visibility */
        .rb-new-form-group input:focus,
        .rb-new-form-group select:focus,
        .rb-new-form-group textarea:focus {
            transform: translateY(-1px);
            box-shadow: 0 0 0 3px rgba(255, 107, 107, 0.1), 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        </style>
        <?php
    }
}

// Initialize the assets manager
RB_Assets_Manager::get_instance();

/**
 * Helper function to get current language for booking forms
 */
if (!function_exists('rb_get_current_language')) {
    function rb_get_current_language() {
        // Check if WPML is active
        if (function_exists('icl_get_current_language')) {
            return icl_get_current_language();
        }
        
        // Check if Polylang is active
        if (function_exists('pll_current_language')) {
            return pll_current_language();
        }
        
        // Default to site locale
        return get_locale();
    }
}

/**
 * Helper function to get available languages
 */
if (!function_exists('rb_get_available_languages')) {
    function rb_get_available_languages() {
        $languages = array();
        
        // Check if WPML is active
        if (function_exists('icl_get_languages')) {
            $wpml_languages = icl_get_languages('skip_missing=0');
            foreach ($wpml_languages as $lang) {
                $languages[$lang['language_code']] = array(
                    'name' => $lang['native_name'],
                    'flag' => $lang['country_flag_url'] ? '🏳️' : '', // Simplified flag
                );
            }
        }
        
        // Check if Polylang is active
        elseif (function_exists('pll_the_languages')) {
            $pll_languages = pll_the_languages(array('raw' => 1));
            foreach ($pll_languages as $lang) {
                $languages[$lang['locale']] = array(
                    'name' => $lang['name'],
                    'flag' => $lang['flag'] ?? '',
                );
            }
        }
        
        // Default languages if no multilingual plugin
        if (empty($languages)) {
            $languages = array(
                'vi_VN' => array('name' => 'Tiếng Việt', 'flag' => '🇻🇳'),
                'en_US' => array('name' => 'English', 'flag' => '🇺🇸'),
                'ja_JP' => array('name' => '日本語', 'flag' => '🇯🇵'),
            );
        }
        
        return $languages;
    }
}

/**
 * Helper function for translation
 */
if (!function_exists('rb_t')) {
    function rb_t($key, $fallback = '') {
        // This should integrate with your translation system
        // For now, just return the fallback
        return $fallback ? $fallback : $key;
    }
}

/**
 * Helper function for echo translation
 */
if (!function_exists('rb_e')) {
    function rb_e($key, $fallback = '') {
        echo esc_html(rb_t($key, $fallback));
    }
}