<?php
/**
 * Enqueue scripts and styles for the modern booking interface.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RB_Assets_Manager {

    /**
     * Singleton instance.
     *
     * @var self|null
     */
    private static $instance = null;

    /**
     * Cache the result of should_enqueue_assets to avoid duplicate checks.
     *
     * @var bool|null
     */
    private $should_enqueue = null;

    /**
     * Flag to prevent loading timeline assets multiple times.
     *
     * @var bool
     */
    private $timeline_enqueued = false;

    /**
     * Get singleton instance.
     *
     * @return self
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('wp_head', array($this, 'print_inline_styles'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }

    /**
     * Enqueue CSS/JS for the booking widget.
     */
    public function enqueue_frontend_assets() {
        if ($this->should_enqueue_timeline_frontend_assets()) {
            $this->enqueue_timeline_assets('frontend');
        }

        if (!$this->should_enqueue_assets()) {
            return;
        }

        $style_path = RB_PLUGIN_DIR . 'assets/css/new-frontend.css';
        $style_version = file_exists($style_path) ? filemtime($style_path) : RB_VERSION;
        wp_enqueue_style(
            'rb-new-frontend',
            RB_PLUGIN_URL . 'assets/css/new-frontend.css',
            array(),
            $style_version
        );

        $script_path = RB_PLUGIN_DIR . 'assets/js/new-booking.js';
        $script_version = file_exists($script_path) ? filemtime($script_path) : RB_VERSION;
        wp_enqueue_script(
            'rb-new-booking',
            RB_PLUGIN_URL . 'assets/js/new-booking.js',
            array('jquery'),
            $script_version,
            true
        );

        wp_localize_script('rb-new-booking', 'rbBookingAjax', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rb_frontend_nonce'),
            'languageNonce' => wp_create_nonce('rb_language_nonce'),
            'languageAction' => 'rb_switch_language',
            'shouldReloadOnLanguageChange' => true,
            'currentLanguage' => rb_get_current_language(),
        ));

        wp_localize_script('rb-new-booking', 'rbBookingStrings', array(
            'checking' => rb_t('checking', __('Checking...', 'restaurant-booking')),
            'processing' => rb_t('processing', __('Processing...', 'restaurant-booking')),
            'selectTime' => rb_t('select_time', __('Select time', 'restaurant-booking')),
            'people' => rb_t('people', __('people', 'restaurant-booking')),
            'connectionError' => __('Connection error. Please try again.', 'restaurant-booking'),
            'securityError' => __('Security check failed', 'restaurant-booking'),
            'fillRequired' => __('Please fill in all required fields', 'restaurant-booking'),
            'suggestedTimes' => rb_t('suggested_times', __('Suggested Times', 'restaurant-booking')),
            'back' => rb_t('back', __('Back', 'restaurant-booking')),
            'continue' => rb_t('continue', __('Continue', 'restaurant-booking')),
            'checkingAvailability' => rb_t('check_availability', __('Check Availability', 'restaurant-booking')),
            'confirmBooking' => rb_t('confirm_booking', __('Confirm Booking', 'restaurant-booking')),
            'invalidEmail' => rb_t('invalid_email', __('Please enter a valid email address', 'restaurant-booking')),
            'invalidPhone' => rb_t('invalid_phone', __('Please enter a valid phone number', 'restaurant-booking')),
            'languageSwitching' => rb_t('language_switching', __('Switching language…', 'restaurant-booking')),
            'languageSwitched' => rb_t('language_switched', __('Language switched', 'restaurant-booking')),
            'languageSwitchFailed' => rb_t('language_switch_failed', __('Could not change language. Please try again.', 'restaurant-booking')),
            'advanceNoteGeneric' => rb_t('advance_booking_note', __('If you would like to book within 2 hours or cannot select a time slot, please contact the restaurant hotline or email for assistance.', 'restaurant-booking')),
            'advanceNoteHotlineOnly' => rb_t('advance_booking_note_hotline_only', __('If you would like to book within 2 hours or cannot select a time slot, please call %1$s for assistance.', 'restaurant-booking')),
            'advanceNoteEmailOnly' => rb_t('advance_booking_note_email_only', __('If you would like to book within 2 hours or cannot select a time slot, please email %1$s for assistance.', 'restaurant-booking')),
            'advanceNoteHotlineEmail' => rb_t('advance_booking_note_hotline_email', __('If you would like to book within 2 hours or cannot select a time slot, please contact %1$s or %2$s for assistance.', 'restaurant-booking')),
        ));
    }

    public function enqueue_admin_assets($hook) {
        if ($this->should_enqueue_timeline_admin_assets($hook)) {
            $this->enqueue_timeline_assets('admin');
        }
    }

    /**
     * Print small inline CSS helpers.
     */
    public function print_inline_styles() {
        if (!$this->should_enqueue_assets()) {
            return;
        }
        $settings = get_option('rb_settings', array());
        $defaults = array(
            'frontend_primary_color' => '#2271b1',
            'frontend_primary_dark_color' => '#185b8f',
            'frontend_primary_light_color' => '#3a8ad6',
            'frontend_background_color' => '#f5f7fb',
            'frontend_surface_color' => '#ffffff',
            'frontend_text_color' => '#1c2a39',
            'frontend_muted_text_color' => '#52637a',
            'frontend_card_radius' => 18,
            'frontend_button_radius' => 12,
            'frontend_field_radius' => 10,
            'frontend_font_family' => 'modern',
        );

        $settings = wp_parse_args($settings, $defaults);

        $primary = sanitize_hex_color($settings['frontend_primary_color']);
        $primary_dark = sanitize_hex_color($settings['frontend_primary_dark_color']);
        $primary_light = sanitize_hex_color($settings['frontend_primary_light_color']);
        $background = sanitize_hex_color($settings['frontend_background_color']);
        $surface = sanitize_hex_color($settings['frontend_surface_color']);
        $text = sanitize_hex_color($settings['frontend_text_color']);
        $muted = sanitize_hex_color($settings['frontend_muted_text_color']);

        $primary = $primary ? $primary : '#2271b1';
        $primary_dark = $primary_dark ? $primary_dark : '#185b8f';
        $primary_light = $primary_light ? $primary_light : '#3a8ad6';
        $background = $background ? $background : '#f5f7fb';
        $surface = $surface ? $surface : '#ffffff';
        $text = $text ? $text : '#1c2a39';
        $muted = $muted ? $muted : '#52637a';

        $card_radius = max(0, min(60, intval($settings['frontend_card_radius'])));
        $button_radius = max(0, min(60, intval($settings['frontend_button_radius'])));
        $field_radius = max(0, min(60, intval($settings['frontend_field_radius'])));

        $font_map = array(
            'modern' => "'Roboto', 'Segoe UI', 'Open Sans', 'Helvetica Neue', Arial, sans-serif",
            'system' => "-apple-system, BlinkMacSystemFont, 'Segoe UI', 'Helvetica Neue', Arial, sans-serif",
            'serif' => '"Playfair Display", Georgia, "Times New Roman", serif',
            'rounded' => "'Nunito', 'Quicksand', 'Poppins', 'Segoe UI', sans-serif",
        );

        $font_key = isset($settings['frontend_font_family']) ? sanitize_text_field($settings['frontend_font_family']) : 'modern';
        if (!isset($font_map[$font_key])) {
            $font_key = 'modern';
        }
        $font_stack = $font_map[$font_key];
        ?>
        <style>
            :root {
                --rb-new-bg: <?php echo esc_attr($background); ?>;
                --rb-new-surface: <?php echo esc_attr($surface); ?>;
                --rb-new-text: <?php echo esc_attr($text); ?>;
                --rb-new-muted: <?php echo esc_attr($muted); ?>;
                --rb-new-primary: <?php echo esc_attr($primary); ?>;
                --rb-new-primary-dark: <?php echo esc_attr($primary_dark); ?>;
                --rb-new-primary-light: <?php echo esc_attr($primary_light); ?>;
                --rb-new-radius-card: <?php echo esc_attr($card_radius); ?>px;
                --rb-new-radius-button: <?php echo esc_attr($button_radius); ?>px;
                --rb-new-radius-control: <?php echo esc_attr($field_radius); ?>px;
                --rb-new-font: <?php echo $font_stack; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
            }

            body.rb-modal-open {
                overflow: hidden;
                padding-right: 15px;
            }

            .rb-new-suggestion-btn.selected {
                background: var(--rb-new-primary) !important;
                color: #ffffff !important;
                border-color: var(--rb-new-primary) !important;
                transform: translateY(-1px);
            }

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

            .rb-new-form-group input.error,
            .rb-new-form-group select.error,
            .rb-new-form-group textarea.error {
                border-color: #d6a39b !important;
                box-shadow: 0 0 0 3px rgba(214, 163, 155, 0.18) !important;
            }

            .rb-new-result.success {
                animation: rb-success-pulse 0.6s ease-in-out;
            }

            @keyframes rb-success-pulse {
                0% { transform: scale(1); }
                50% { transform: scale(1.02); }
                100% { transform: scale(1); }
            }
        </style>
        <?php
    }

    /**
     * Determine whether assets should be loaded on the current request.
     *
     * @return bool
     */
    private function should_enqueue_assets() {
        if (null !== $this->should_enqueue) {
            return $this->should_enqueue;
        }

        if (is_admin()) {
            $this->should_enqueue = false;
            return false;
        }

        $should_enqueue = apply_filters('rb_should_enqueue_new_frontend_assets', false);

        if ($should_enqueue) {
            $this->should_enqueue = true;
            return true;
        }

        $shortcodes = array('restaurant_booking', 'restaurant_booking_portal');

        if (is_singular()) {
            global $post;
            if ($post && isset($post->post_content)) {
                foreach ($shortcodes as $shortcode) {
                    if (has_shortcode($post->post_content, $shortcode)) {
                        $this->should_enqueue = true;
                        return true;
                    }
                }
            }
        }

        if (is_front_page() || is_home()) {
            $queried_id = get_queried_object_id();
            if ($queried_id) {
                $content = get_post_field('post_content', $queried_id);
                if ($content) {
                    foreach ($shortcodes as $shortcode) {
                        if (has_shortcode($content, $shortcode)) {
                            $this->should_enqueue = true;
                            return true;
                        }
                    }
                }
            }
        }

        $this->should_enqueue = false;
        return false;
    }

    private function should_enqueue_timeline_frontend_assets() {
        $should_enqueue = apply_filters('rb_should_enqueue_timeline_frontend_assets', false);

        if ($should_enqueue) {
            return true;
        }

        $shortcodes = array('rb_timeline', 'restaurant_booking_timeline');

        if (is_singular()) {
            global $post;
            if ($post && isset($post->post_content)) {
                foreach ($shortcodes as $shortcode) {
                    if (has_shortcode($post->post_content, $shortcode)) {
                        return true;
                    }
                }
            }
        }

        if (is_front_page() || is_home()) {
            $queried_id = get_queried_object_id();
            if ($queried_id) {
                $content = get_post_field('post_content', $queried_id);
                if ($content) {
                    foreach ($shortcodes as $shortcode) {
                        if (has_shortcode($content, $shortcode)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    private function should_enqueue_timeline_admin_assets($hook) {
        $should_enqueue = apply_filters('rb_should_enqueue_timeline_admin_assets', false, $hook);

        if ($should_enqueue) {
            return true;
        }

        if (empty($hook)) {
            return false;
        }

        if (false !== strpos($hook, 'rb-timeline')) {
            return true;
        }

        return false;
    }

    private function enqueue_timeline_assets($context = 'admin') {
        if ($this->timeline_enqueued) {
            return;
        }

        $style_path = RB_PLUGIN_DIR . 'assets/css/timeline.css';
        $style_version = file_exists($style_path) ? filemtime($style_path) : RB_VERSION;
        wp_enqueue_style(
            'rb-timeline',
            RB_PLUGIN_URL . 'assets/css/timeline.css',
            array(),
            $style_version
        );

        $script_path = RB_PLUGIN_DIR . 'assets/js/timeline-view.js';
        $script_version = file_exists($script_path) ? filemtime($script_path) : RB_VERSION;
        wp_enqueue_script(
            'rb-timeline-view',
            RB_PLUGIN_URL . 'assets/js/timeline-view.js',
            array('jquery'),
            $script_version,
            true
        );

        $strings = array(
            'currentStatus' => rb_t('current_status', __('Current Status', 'restaurant-booking')),
            'checkin' => rb_t('checkin_time', __('Check-in Time', 'restaurant-booking')),
            'checkout' => rb_t('checkout_time', __('Check-out Time', 'restaurant-booking')),
            'cleanup' => rb_t('cleanup_time', __('Cleanup Time', 'restaurant-booking')),
            'available' => rb_t('available', __('Available', 'restaurant-booking')),
            'occupied' => rb_t('occupied', __('Occupied', 'restaurant-booking')),
            'reserved' => rb_t('reserved', __('Reserved', 'restaurant-booking')),
            'cleaning' => rb_t('cleaning', __('Cleaning', 'restaurant-booking')),
            'statusPending' => rb_t('pending', __('Pending', 'restaurant-booking')),
            'statusConfirmed' => rb_t('confirmed', __('Confirmed', 'restaurant-booking')),
            'statusCancelled' => rb_t('cancelled', __('Cancelled', 'restaurant-booking')),
            'statusCompleted' => rb_t('completed', __('Completed', 'restaurant-booking')),
            'statusNoShow' => rb_t('no_show', __('No-show', 'restaurant-booking')),
            'statusUpdated' => __('Table status updated successfully.', 'restaurant-booking'),
            'statusUpdateFailed' => __('Could not update table status. Please try again.', 'restaurant-booking'),
            'noTables' => __('No tables found for the selected date.', 'restaurant-booking'),
            'noBookings' => __('No bookings for this table.', 'restaurant-booking'),
            'loadingError' => __('Unable to load timeline data.', 'restaurant-booking'),
            'guestsLabel' => rb_t('people', __('people', 'restaurant-booking')),
            'tableLabel' => rb_t('table', __('Table', 'restaurant-booking')),
            'unassigned' => rb_t('unassigned', __('Unassigned', 'restaurant-booking')),
            'tablesSidebarTitle' => rb_t('timeline_tables_title', __('Tables', 'restaurant-booking')),
            'allTablesLabel' => rb_t('timeline_all_tables', __('All tables', 'restaurant-booking')),
            'showAllTables' => rb_t('timeline_show_all', __('Show all tables', 'restaurant-booking')),
            'noTablesSelected' => rb_t('timeline_no_tables_selected', __('Select tables from the list to display bookings.', 'restaurant-booking')),
            'sidebarToggleLabel' => rb_t('timeline_sidebar_toggle', __('Show tables', 'restaurant-booking')),
            'viewMonth' => rb_t('timeline_view_month', __('Month', 'restaurant-booking')),
            'viewWeek' => rb_t('timeline_view_week', __('Week', 'restaurant-booking')),
            'viewDay' => rb_t('timeline_view_day', __('Day', 'restaurant-booking')),
            'openTablesLabel' => rb_t('timeline_open_tables', __('Tables', 'restaurant-booking')),
            'hideTablesLabel' => rb_t('timeline_hide_tables', __('Hide tables', 'restaurant-booking')),
            'closeLabel' => rb_t('close', __('Close', 'restaurant-booking')),
            'backLabel' => rb_t('back', __('Back', 'restaurant-booking')),
            'bookingsLabel' => rb_t('bookings', __('Bookings', 'restaurant-booking')),
            'noCalendarData' => rb_t('timeline_no_calendar_data', __('No calendar data available.', 'restaurant-booking')),
            'noWeekData' => rb_t('timeline_no_week_data', __('No weekly data available.', 'restaurant-booking')),
            'todayLabel' => rb_t('today', __('Today', 'restaurant-booking')),
            'manageTable' => rb_t('manage_table', __('Manage table', 'restaurant-booking')),
            'bookingsTitle' => rb_t('bookings_for_selected_day', __('Bookings for %s', 'restaurant-booking')),
        );

        wp_localize_script('rb-timeline-view', 'rbTimelineConfig', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rb_timeline_nonce'),
            'context' => $context,
            'strings' => $strings,
        ));

        $this->timeline_enqueued = true;
    }
}

RB_Assets_Manager::get_instance();

