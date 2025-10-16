<?php
/**
 * Location manager portal surface.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RB_Frontend_Manager extends RB_Frontend_Base {

    private static $instance = null;

    private $portal_account_manager;
    private $portal_session;
    private $portal_account = null;
    private $manager_login_error = '';
    private $active_location_id = 0;

    /**
     * @return self
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    protected function __construct() {
        parent::__construct();
        $this->init_portal_account_support();
        $this->init_ajax_handlers();
        add_action('template_redirect', array($this, 'maybe_handle_manager_login'));
    }

    private function init_portal_account_support() {
        if (class_exists('RB_Portal_Account_Manager') && class_exists('RB_Portal_Session_Manager')) {
            $this->portal_account_manager = RB_Portal_Account_Manager::get_instance();
            $this->portal_session = new RB_Portal_Session_Manager();
        }
    }

    private function init_ajax_handlers() {
        add_action('wp_ajax_rb_manager_update_booking', array($this, 'handle_manager_update_booking'));
        add_action('wp_ajax_nopriv_rb_manager_update_booking', array($this, 'handle_manager_update_booking'));
        add_action('wp_ajax_rb_manager_save_booking', array($this, 'handle_manager_save_booking'));
        add_action('wp_ajax_nopriv_rb_manager_save_booking', array($this, 'handle_manager_save_booking'));
        add_action('wp_ajax_rb_manager_create_booking', array($this, 'handle_manager_create_booking'));
        add_action('wp_ajax_nopriv_rb_manager_create_booking', array($this, 'handle_manager_create_booking'));
        add_action('wp_ajax_rb_manager_add_table', array($this, 'handle_manager_add_table'));
        add_action('wp_ajax_nopriv_rb_manager_add_table', array($this, 'handle_manager_add_table'));
        add_action('wp_ajax_rb_manager_toggle_table', array($this, 'handle_manager_toggle_table'));
        add_action('wp_ajax_nopriv_rb_manager_toggle_table', array($this, 'handle_manager_toggle_table'));
        add_action('wp_ajax_rb_manager_delete_table', array($this, 'handle_manager_delete_table'));
        add_action('wp_ajax_nopriv_rb_manager_delete_table', array($this, 'handle_manager_delete_table'));
        add_action('wp_ajax_rb_manager_set_customer_vip', array($this, 'handle_manager_set_customer_vip'));
        add_action('wp_ajax_nopriv_rb_manager_set_customer_vip', array($this, 'handle_manager_set_customer_vip'));
        add_action('wp_ajax_rb_manager_set_customer_blacklist', array($this, 'handle_manager_set_customer_blacklist'));
        add_action('wp_ajax_nopriv_rb_manager_set_customer_blacklist', array($this, 'handle_manager_set_customer_blacklist'));
        add_action('wp_ajax_rb_manager_customer_history', array($this, 'handle_manager_customer_history'));
        add_action('wp_ajax_nopriv_rb_manager_customer_history', array($this, 'handle_manager_customer_history'));
        add_action('wp_ajax_rb_manager_update_customer_note', array($this, 'handle_manager_update_customer_note'));
        add_action('wp_ajax_nopriv_rb_manager_update_customer_note', array($this, 'handle_manager_update_customer_note'));
        add_action('wp_ajax_rb_manager_update_settings', array($this, 'handle_manager_update_settings'));
        add_action('wp_ajax_nopriv_rb_manager_update_settings', array($this, 'handle_manager_update_settings'));
    }

    public function maybe_handle_manager_login() {
        if (is_admin()) {
            return;
        }

        if (!isset($_POST['rb_manager_login_nonce'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['rb_manager_login_nonce'], 'rb_manager_login')) {
            $this->manager_login_error = __('Security check failed. Please try again.', 'restaurant-booking');
            return;
        }

        $identifier_raw = isset($_POST['rb_username']) ? wp_unslash($_POST['rb_username']) : '';
        $identifier = trim(sanitize_text_field($identifier_raw));
        $password = isset($_POST['rb_password']) ? wp_unslash($_POST['rb_password']) : '';
        $language_code = isset($_POST['rb_language']) ? sanitize_text_field(wp_unslash($_POST['rb_language'])) : '';

        if ($identifier === '' || $password === '') {
            $this->manager_login_error = __('Please provide both username/email and password.', 'restaurant-booking');
            return;
        }

        if (!$this->portal_account_manager || !$this->portal_session) {
            $this->manager_login_error = __('Portal account authentication is not available. Please contact an administrator.', 'restaurant-booking');
            return;
        }

        $account = $this->portal_account_manager->authenticate($identifier, $password);

        if ($account instanceof WP_Error) {
            $this->manager_login_error = $account->get_error_message();
            return;
        }

        if (!$account) {
            $this->manager_login_error = __('Invalid credentials. Please try again.', 'restaurant-booking');
            return;
        }

        $allowed_locations = !empty($account->locations) ? array_map('intval', (array) $account->locations) : array();

        if (empty($allowed_locations)) {
            $this->manager_login_error = __('This account is not assigned to any locations. Please contact an administrator.', 'restaurant-booking');
            return;
        }

        $locations = $this->get_locations_data();
        $resolved_location = $this->resolve_location_from_allowed(
            0,
            $locations,
            $allowed_locations,
            isset($account->last_location_id) ? (int) $account->last_location_id : 0
        );

        if (!$resolved_location) {
            $this->manager_login_error = __('The selected location is not available for this account.', 'restaurant-booking');
            return;
        }

        if ($this->portal_session) {
            $this->portal_session->start_session($account->id);
        }

        if ($this->portal_account_manager) {
            $this->portal_account_manager->set_active_location($account->id, $resolved_location);
        }
        $account->last_location_id = $resolved_location;
        $account->locations = $allowed_locations;
        $this->portal_account = $account;

        if (!empty($language_code) && function_exists('rb_get_available_languages') && class_exists('RB_I18n')) {
            $available_languages = rb_get_available_languages();
            if (isset($available_languages[$language_code])) {
                RB_I18n::get_instance()->set_language($language_code);
            }
        }

        wp_safe_redirect(esc_url_raw(add_query_arg(array())));
        exit;
    }

    public function render_location_manager($atts) {
        $atts = shortcode_atts(array(
            'title' => __('Location Manager', 'restaurant-booking')
        ), $atts, 'restaurant_booking_manager');

        $locations = $this->get_locations_data();
        if (empty($locations)) {
            return '<div class="rb-manager rb-alert">' . esc_html__('Locations are not configured yet.', 'restaurant-booking') . '</div>';
        }

        if (isset($_POST['rb_manager_logout'])) {
            check_admin_referer('rb_manager_logout', 'rb_manager_logout_nonce');
            if ($this->portal_session) {
                $this->portal_session->destroy_session();
                $this->portal_account = null;
            }

            wp_safe_redirect(esc_url_raw(add_query_arg(array())));
            exit;
        }

        $manager_permissions = $this->get_current_manager_permissions();

        if (!$manager_permissions) {
            return $this->render_manager_login($atts, $locations);
        }

        $allowed_location_ids = $manager_permissions['allowed_locations'];
        $filtered_locations = $locations;

        if (!empty($allowed_location_ids)) {
            $filtered_locations = array_values(array_filter($filtered_locations, function ($location) use ($allowed_location_ids) {
                return in_array((int) $location['id'], $allowed_location_ids, true);
            }));
        }

        if (empty($filtered_locations)) {
            return '<div class="rb-manager rb-alert">' . esc_html__('No locations have been assigned to your account. Please contact an administrator.', 'restaurant-booking') . '</div>';
        }

        $selected_location_id = isset($_GET['location_id']) ? intval($_GET['location_id']) : 0;
        $manager_name = '';

        $account = $manager_permissions['account'];

        if (empty($allowed_location_ids)) {
            $allowed_location_ids = array_map('intval', wp_list_pluck($filtered_locations, 'id'));
        }

        $fallback_location = isset($account->last_location_id) ? (int) $account->last_location_id : 0;
        $selected_location_id = $this->resolve_location_from_allowed($selected_location_id, $filtered_locations, $allowed_location_ids, $fallback_location);

        if (!$selected_location_id) {
            return '<div class="rb-manager rb-alert">' . esc_html__('Selected location is no longer available. Please contact an administrator.', 'restaurant-booking') . '</div>';
        }

        if ($this->portal_account_manager) {
            $this->portal_account_manager->set_active_location($account->id, $selected_location_id);
        }

        $account->last_location_id = $selected_location_id;
        $this->portal_account = $account;
        $this->active_location_id = $selected_location_id;
        $manager_name = !empty($account->display_name) ? $account->display_name : $account->username;

        $locations = $filtered_locations;

        global $rb_booking;
        if (!$rb_booking) {
            require_once RB_PLUGIN_DIR . 'includes/class-booking.php';
            $rb_booking = new RB_Booking();
        }

        $filters = $this->get_dashboard_filters();

        $booking_query = array(
            'location_id' => $selected_location_id,
            'status' => $filters['filter_status'],
            'source' => $filters['filter_source'],
            'date_from' => $filters['filter_date_from'],
            'date_to' => $filters['filter_date_to'],
            'orderby' => $filters['sort_by'],
            'order' => $filters['sort_order'],
            'search' => $filters['search_term'],
        );

        $bookings = $rb_booking->get_bookings($booking_query);
        $stats = method_exists($rb_booking, 'get_location_stats') ? $rb_booking->get_location_stats($selected_location_id) : array();
        $source_stats = method_exists($rb_booking, 'get_source_stats') ? $rb_booking->get_source_stats($selected_location_id) : array();

        $location_lookup = wp_list_pluck($locations, null, 'id');
        $active_location = isset($location_lookup[$selected_location_id]) ? $location_lookup[$selected_location_id] : $locations[0];

        global $rb_location;
        if (!$rb_location) {
            require_once RB_PLUGIN_DIR . 'includes/class-location.php';
            $rb_location = new RB_Location();
        }

        $location_settings = $rb_location ? $rb_location->get_settings($selected_location_id) : array();
        $section = isset($_GET['rb_section']) ? sanitize_key($_GET['rb_section']) : 'dashboard';
        $allowed_sections = array('dashboard', 'create', 'tables', 'customers', 'settings');
        if (!in_array($section, $allowed_sections, true)) {
            $section = 'dashboard';
        }

        $ajax_nonce = wp_create_nonce('rb_frontend_nonce');

        $nav_items = array(
            'dashboard' => array('icon' => '📊', 'label' => __('Dashboard', 'restaurant-booking')),
            'create' => array('icon' => '📝', 'label' => __('Create Booking', 'restaurant-booking')),
            'tables' => array('icon' => '🍽️', 'label' => __('Manage Tables', 'restaurant-booking')),
            'customers' => array('icon' => '👥', 'label' => __('Customers', 'restaurant-booking')),
            'settings' => array('icon' => '⚙️', 'label' => __('Location Settings', 'restaurant-booking')),
        );

        $available_languages = isset($location_settings['languages']) ? (array) $location_settings['languages'] : array();

        ob_start();
        ?>
        <div class="rb-manager" data-location="<?php echo esc_attr($selected_location_id); ?>">
            <div class="rb-manager-header">
                <div class="rb-manager-header-left">
                    <h2><?php echo esc_html($atts['title']); ?></h2>
                    <form method="get" class="rb-manager-location-switcher">
                        <?php if (!empty($_GET)) : ?>
                            <?php foreach ($_GET as $key => $value) : ?>
                                <?php if (in_array($key, array('location_id'), true)) { continue; } ?>
                                <input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($value); ?>" />
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <label for="rb-manager-location-select"><?php esc_html_e('Location', 'restaurant-booking'); ?></label>
                        <select name="location_id" id="rb-manager-location-select" onchange="this.form.submit();">
                            <?php foreach ($locations as $location) : ?>
                                <option value="<?php echo esc_attr($location['id']); ?>" <?php selected($selected_location_id, $location['id']); ?>>
                                    <?php echo esc_html($location['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
                <div class="rb-manager-header-right">
                    <?php if (!empty($manager_name)) : ?>
                        <div class="rb-manager-user">
                            <?php printf(esc_html__('Logged in as %s', 'restaurant-booking'), esc_html($manager_name)); ?>
                        </div>
                    <?php endif; ?>
                    <form method="post" class="rb-manager-logout">
                        <?php wp_nonce_field('rb_manager_logout', 'rb_manager_logout_nonce'); ?>
                        <button type="submit" name="rb_manager_logout" class="rb-btn-secondary"><?php esc_html_e('Log out', 'restaurant-booking'); ?></button>
                    </form>
                </div>
            </div>

            <div class="rb-manager-layout">
                <aside class="rb-manager-sidebar">
                    <div class="rb-manager-location-card">
                        <h3><?php echo esc_html($active_location['name']); ?></h3>
                        <?php if (!empty($active_location['address'])) : ?>
                            <p>📍 <?php echo esc_html($active_location['address']); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($active_location['hotline'])) : ?>
                            <p>📞 <?php echo esc_html($active_location['hotline']); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($active_location['email'])) : ?>
                            <p>✉️ <?php echo esc_html($active_location['email']); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($available_languages)) : ?>
                            <p>🌐 <?php echo esc_html(implode(', ', array_map('strtoupper', $available_languages))); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($location_settings['shift_notes'])) : ?>
                            <div class="rb-manager-location-notes">
                                <strong><?php esc_html_e('Shift notes', 'restaurant-booking'); ?>:</strong>
                                <p><?php echo esc_html($location_settings['shift_notes']); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <nav class="rb-manager-nav">
                        <ul>
                            <?php foreach ($nav_items as $key => $item) :
                                $url = add_query_arg(array(
                                    'location_id' => $selected_location_id,
                                    'rb_section' => $key,
                                ), remove_query_arg(array('rb_section')));
                                ?>
                                <li class="<?php echo $section === $key ? 'active' : ''; ?>">
                                    <a href="<?php echo esc_url($url); ?>">
                                        <span class="rb-manager-nav-icon"><?php echo esc_html($item['icon']); ?></span>
                                        <span class="rb-manager-nav-label"><?php echo esc_html($item['label']); ?></span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </nav>
                </aside>

                <div class="rb-manager-content">
                    <?php
                    switch ($section) {
                        case 'create':
                            echo $this->render_section_create_booking($location_settings, $selected_location_id, $active_location, $ajax_nonce);
                            break;
                        case 'tables':
                            echo $this->render_section_tables($selected_location_id, $ajax_nonce);
                            break;
                        case 'customers':
                            echo $this->render_section_customers($selected_location_id, $ajax_nonce);
                            break;
                        case 'settings':
                            echo $this->render_section_settings($location_settings, $selected_location_id, $ajax_nonce);
                            break;
                        case 'dashboard':
                        default:
                            echo $this->render_section_dashboard($bookings, $ajax_nonce, $filters, $stats, $source_stats, $selected_location_id);
                            break;
                    }
                    ?>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_section_dashboard($bookings, $ajax_nonce, $filters, $stats, $source_stats, $location_id) {
        $status_options = array(
            '' => __('All statuses', 'restaurant-booking'),
            'pending' => __('Pending', 'restaurant-booking'),
            'confirmed' => __('Confirmed', 'restaurant-booking'),
            'completed' => __('Completed', 'restaurant-booking'),
            'cancelled' => __('Cancelled', 'restaurant-booking'),
        );

        $source_options = array(
            '' => __('All sources', 'restaurant-booking'),
            'website' => '🌐 ' . __('Website', 'restaurant-booking'),
            'phone' => '📞 ' . __('Phone', 'restaurant-booking'),
            'facebook' => '📘 Facebook',
            'zalo' => '💬 Zalo',
            'instagram' => '📷 Instagram',
            'walk-in' => '🚶 ' . __('Walk-in', 'restaurant-booking'),
            'email' => '✉️ ' . __('Email', 'restaurant-booking'),
            'other' => '❓ ' . __('Other', 'restaurant-booking'),
        );

        $sort_options = array(
            'created_at' => __('Created time', 'restaurant-booking'),
            'booking_date' => __('Booking date', 'restaurant-booking'),
            'booking_time' => __('Booking time', 'restaurant-booking'),
            'guest_count' => __('Guest count', 'restaurant-booking'),
            'status' => __('Status', 'restaurant-booking'),
        );

        $order_options = array(
            'DESC' => __('Newest first', 'restaurant-booking'),
            'ASC' => __('Oldest first', 'restaurant-booking'),
        );

        $reset_url = add_query_arg(array(
            'location_id' => $location_id,
            'rb_section' => 'dashboard',
        ), remove_query_arg(array('filter_status', 'filter_source', 'filter_date_from', 'filter_date_to', 'sort_by', 'sort_order', 'search')));

        ob_start();
        ?>
        <div class="rb-manager-dashboard">
            <form class="rb-manager-filters" method="get">
                <input type="hidden" name="location_id" value="<?php echo esc_attr($location_id); ?>">
                <input type="hidden" name="rb_section" value="dashboard">
                <div class="rb-manager-filter-grid">
                    <label>
                        <?php esc_html_e('Search', 'restaurant-booking'); ?>
                        <input type="text" name="search" value="<?php echo esc_attr($filters['search_term']); ?>" placeholder="<?php esc_attr_e('Name, phone, email or booking ID', 'restaurant-booking'); ?>">
                    </label>
                    <label>
                        <?php esc_html_e('Status', 'restaurant-booking'); ?>
                        <select name="filter_status">
                            <?php foreach ($status_options as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($filters['filter_status'], $value); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <?php esc_html_e('Source', 'restaurant-booking'); ?>
                        <select name="filter_source">
                            <?php foreach ($source_options as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($filters['filter_source'], $value); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <?php esc_html_e('From date', 'restaurant-booking'); ?>
                        <input type="date" name="filter_date_from" value="<?php echo esc_attr($filters['filter_date_from']); ?>">
                    </label>
                    <label>
                        <?php esc_html_e('To date', 'restaurant-booking'); ?>
                        <input type="date" name="filter_date_to" value="<?php echo esc_attr($filters['filter_date_to']); ?>">
                    </label>
                    <label>
                        <?php esc_html_e('Sort by', 'restaurant-booking'); ?>
                        <select name="sort_by">
                            <?php foreach ($sort_options as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($filters['sort_by'], $value); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <?php esc_html_e('Order', 'restaurant-booking'); ?>
                        <select name="sort_order">
                            <?php foreach ($order_options as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected(strtoupper($filters['sort_order']), $value); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <div class="rb-manager-filter-actions">
                    <button type="submit" class="rb-btn-primary"><?php esc_html_e('Apply filters', 'restaurant-booking'); ?></button>
                    <a class="rb-btn-secondary" href="<?php echo esc_url($reset_url); ?>"><?php esc_html_e('Reset', 'restaurant-booking'); ?></a>
                </div>
            </form>

            <?php if (!empty($stats)) : ?>
                <div class="rb-manager-dashboard-stats">
                    <div class="rb-manager-stat-card"><span class="rb-manager-stat-label"><?php esc_html_e('Total', 'restaurant-booking'); ?></span><span class="rb-manager-stat-value"><?php echo esc_html($stats['total']); ?></span></div>
                    <div class="rb-manager-stat-card"><span class="rb-manager-stat-label"><?php esc_html_e('Pending', 'restaurant-booking'); ?></span><span class="rb-manager-stat-value"><?php echo esc_html($stats['pending']); ?></span></div>
                    <div class="rb-manager-stat-card"><span class="rb-manager-stat-label"><?php esc_html_e('Confirmed', 'restaurant-booking'); ?></span><span class="rb-manager-stat-value"><?php echo esc_html($stats['confirmed']); ?></span></div>
                    <div class="rb-manager-stat-card"><span class="rb-manager-stat-label"><?php esc_html_e('Completed', 'restaurant-booking'); ?></span><span class="rb-manager-stat-value"><?php echo esc_html($stats['completed']); ?></span></div>
                    <div class="rb-manager-stat-card"><span class="rb-manager-stat-label"><?php esc_html_e('Cancelled', 'restaurant-booking'); ?></span><span class="rb-manager-stat-value"><?php echo esc_html($stats['cancelled']); ?></span></div>
                    <div class="rb-manager-stat-card"><span class="rb-manager-stat-label"><?php esc_html_e('Today', 'restaurant-booking'); ?></span><span class="rb-manager-stat-value"><?php echo esc_html($stats['today']); ?></span></div>
                </div>
            <?php endif; ?>

            <?php if (!empty($source_stats)) : ?>
                <div class="rb-manager-dashboard-sources">
                    <?php foreach ($source_stats as $source) : ?>
                        <div class="rb-manager-source-card">
                            <span class="rb-manager-source-label"><?php echo esc_html($source->booking_source); ?></span>
                            <span class="rb-manager-source-value"><?php echo esc_html($source->total); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="rb-manager-bookings-table-wrapper">
                <table class="rb-manager-bookings-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('ID', 'restaurant-booking'); ?></th>
                            <th><?php esc_html_e('Guest', 'restaurant-booking'); ?></th>
                            <th><?php esc_html_e('Contact', 'restaurant-booking'); ?></th>
                            <th><?php esc_html_e('Date', 'restaurant-booking'); ?></th>
                            <th><?php esc_html_e('Time', 'restaurant-booking'); ?></th>
                            <th><?php esc_html_e('Guests', 'restaurant-booking'); ?></th>
                            <th><?php esc_html_e('Source', 'restaurant-booking'); ?></th>
                            <th><?php esc_html_e('Status', 'restaurant-booking'); ?></th>
                            <th><?php esc_html_e('Table', 'restaurant-booking'); ?></th>
                            <th><?php esc_html_e('Created', 'restaurant-booking'); ?></th>
                            <th><?php esc_html_e('Actions', 'restaurant-booking'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($bookings)) : ?>
                            <?php foreach ($bookings as $booking) :
                                $status_label = $this->format_booking_status($booking->status);
                                $created = !empty($booking->created_at) ? date_i18n(get_option('date_format', 'd/m/Y') . ' H:i', strtotime($booking->created_at)) : '';
                                $note = !empty($booking->special_requests) ? $booking->special_requests : '';
                                $admin_note = !empty($booking->admin_notes) ? $booking->admin_notes : '';
                                ?>
                                <tr
                                    data-booking-id="<?php echo esc_attr($booking->id); ?>"
                                    data-customer-name="<?php echo esc_attr($booking->customer_name); ?>"
                                    data-customer-phone="<?php echo esc_attr($booking->customer_phone); ?>"
                                    data-customer-email="<?php echo esc_attr($booking->customer_email); ?>"
                                    data-booking-date="<?php echo esc_attr($booking->booking_date); ?>"
                                    data-booking-time="<?php echo esc_attr($booking->booking_time); ?>"
                                    data-guest-count="<?php echo esc_attr($booking->guest_count); ?>"
                                    data-booking-source="<?php echo esc_attr($booking->booking_source); ?>"
                                    data-special-requests="<?php echo esc_attr($note); ?>"
                                    data-admin-notes="<?php echo esc_attr($admin_note); ?>"
                                    data-status="<?php echo esc_attr($booking->status); ?>"
                                    data-table-number="<?php echo esc_attr(isset($booking->table_number) ? $booking->table_number : ''); ?>"
                                >
                                    <td>#<?php echo esc_html(str_pad($booking->id, 5, '0', STR_PAD_LEFT)); ?></td>
                                    <td>
                                        <strong><?php echo esc_html($booking->customer_name); ?></strong>
                                        <?php if (!empty($note)) : ?>
                                            <div class="rb-manager-note"><?php echo esc_html($note); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($admin_note)) : ?>
                                            <div class="rb-manager-note rb-manager-note-internal"><?php echo esc_html($admin_note); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div><?php echo esc_html($booking->customer_phone); ?></div>
                                        <div><?php echo esc_html($booking->customer_email); ?></div>
                                    </td>
                                    <td><?php echo esc_html(date_i18n(get_option('date_format', 'd/m/Y'), strtotime($booking->booking_date))); ?></td>
                                    <td><?php echo esc_html($booking->booking_time); ?></td>
                                    <td><?php echo esc_html($booking->guest_count); ?></td>
                                    <td><?php echo esc_html($this->format_booking_source($booking->booking_source)); ?></td>
                                    <td>
                                        <span class="rb-status rb-status-<?php echo esc_attr($booking->status); ?>"><?php echo esc_html($status_label); ?></span>
                                    </td>
                                    <td><?php echo !empty($booking->table_number) ? esc_html($booking->table_number) : '—'; ?></td>
                                    <td><?php echo esc_html($created); ?></td>
                                    <td class="rb-manager-actions">
                                        <div class="rb-manager-action-stack">
                                            <?php if ($booking->status === 'pending') : ?>
                                                <button class="rb-btn-success rb-manager-action" data-action="confirm" data-id="<?php echo esc_attr($booking->id); ?>"><?php esc_html_e('Confirm', 'restaurant-booking'); ?></button>
                                            <?php endif; ?>
                                            <?php if (in_array($booking->status, array('pending', 'confirmed'), true)) : ?>
                                                <button class="rb-btn-danger rb-manager-action" data-action="cancel" data-id="<?php echo esc_attr($booking->id); ?>"><?php esc_html_e('Cancel', 'restaurant-booking'); ?></button>
                                            <?php endif; ?>
                                            <?php if (in_array($booking->status, array('confirmed'), true)) : ?>
                                                <button class="rb-btn-secondary rb-manager-action" data-action="complete" data-id="<?php echo esc_attr($booking->id); ?>"><?php esc_html_e('Complete', 'restaurant-booking'); ?></button>
                                            <?php endif; ?>
                                            <button class="rb-btn-secondary rb-manager-edit-booking" data-id="<?php echo esc_attr($booking->id); ?>"><?php esc_html_e('Edit', 'restaurant-booking'); ?></button>
                                            <button class="rb-btn-danger rb-manager-action" data-action="delete" data-id="<?php echo esc_attr($booking->id); ?>"><?php esc_html_e('Delete', 'restaurant-booking'); ?></button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="11" class="rb-manager-empty"><?php esc_html_e('No reservations found for this location.', 'restaurant-booking'); ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div id="rb-manager-feedback" class="rb-portal-result" hidden data-nonce="<?php echo esc_attr($ajax_nonce); ?>"></div>

            <div id="rb-manager-edit-modal" class="rb-manager-modal" hidden>
                <div class="rb-manager-modal-dialog">
                    <button type="button" class="rb-manager-modal-close" aria-label="<?php esc_attr_e('Close', 'restaurant-booking'); ?>">&times;</button>
                    <h3><?php esc_html_e('Edit booking', 'restaurant-booking'); ?></h3>
                    <form id="rb-manager-edit-booking-form">
                        <input type="hidden" name="booking_id" value="">
                        <input type="hidden" name="nonce" value="<?php echo esc_attr($ajax_nonce); ?>">
                        <input type="hidden" name="location_id" value="<?php echo esc_attr($location_id); ?>">
                        <div class="rb-manager-form-grid">
                            <label>
                                <?php esc_html_e('Customer name', 'restaurant-booking'); ?>
                                <input type="text" name="customer_name" required>
                            </label>
                            <label>
                                <?php esc_html_e('Phone number', 'restaurant-booking'); ?>
                                <input type="tel" name="customer_phone" required pattern="[0-9]{8,15}">
                            </label>
                            <label>
                                <?php esc_html_e('Email', 'restaurant-booking'); ?>
                                <input type="email" name="customer_email" required>
                            </label>
                            <label>
                                <?php esc_html_e('Guests', 'restaurant-booking'); ?>
                                <input type="number" name="guest_count" min="1" max="50" required>
                            </label>
                            <label>
                                <?php esc_html_e('Date', 'restaurant-booking'); ?>
                                <input type="date" name="booking_date" required>
                            </label>
                            <label>
                                <?php esc_html_e('Time', 'restaurant-booking'); ?>
                                <input type="time" name="booking_time" required>
                            </label>
                            <label>
                                <?php esc_html_e('Source', 'restaurant-booking'); ?>
                                <select name="booking_source">
                                    <?php foreach ($source_options as $value => $label) : ?>
                                        <?php if ($value === '') { continue; } ?>
                                        <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>
                        <label class="rb-manager-wide">
                            <?php esc_html_e('Special requests', 'restaurant-booking'); ?>
                            <textarea name="special_requests" rows="3"></textarea>
                        </label>
                        <label class="rb-manager-wide">
                            <?php esc_html_e('Internal notes', 'restaurant-booking'); ?>
                            <textarea name="admin_notes" rows="3"></textarea>
                        </label>
                        <div class="rb-manager-actions-row">
                            <button type="submit" class="rb-btn-primary"><?php esc_html_e('Save changes', 'restaurant-booking'); ?></button>
                            <button type="button" class="rb-btn-secondary rb-manager-modal-cancel"><?php esc_html_e('Cancel', 'restaurant-booking'); ?></button>
                        </div>
                    </form>
                    <div id="rb-manager-edit-feedback" class="rb-portal-result" hidden></div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function get_dashboard_filters() {
        $status = isset($_GET['filter_status']) ? sanitize_text_field(wp_unslash($_GET['filter_status'])) : '';
        $source = isset($_GET['filter_source']) ? sanitize_text_field(wp_unslash($_GET['filter_source'])) : '';
        $date_from = isset($_GET['filter_date_from']) ? sanitize_text_field(wp_unslash($_GET['filter_date_from'])) : '';
        $date_to = isset($_GET['filter_date_to']) ? sanitize_text_field(wp_unslash($_GET['filter_date_to'])) : '';
        $sort_by = isset($_GET['sort_by']) ? sanitize_key($_GET['sort_by']) : 'created_at';
        $sort_order = isset($_GET['sort_order']) ? strtoupper(sanitize_text_field(wp_unslash($_GET['sort_order']))) : 'DESC';
        $search = isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '';

        $allowed_status = array('pending', 'confirmed', 'completed', 'cancelled');
        if (!in_array($status, $allowed_status, true)) {
            $status = '';
        }

        $allowed_sources = array('website', 'phone', 'facebook', 'zalo', 'instagram', 'walk-in', 'email', 'other');
        if (!in_array($source, $allowed_sources, true)) {
            $source = '';
        }

        $allowed_sort = array('created_at', 'booking_date', 'booking_time', 'guest_count', 'status');
        if (!in_array($sort_by, $allowed_sort, true)) {
            $sort_by = 'created_at';
        }

        if (!in_array($sort_order, array('ASC', 'DESC'), true)) {
            $sort_order = 'DESC';
        }

        return array(
            'filter_status' => $status,
            'filter_source' => $source,
            'filter_date_from' => $date_from,
            'filter_date_to' => $date_to,
            'sort_by' => $sort_by,
            'sort_order' => $sort_order,
            'search_term' => $search,
        );
    }

    private function format_booking_status($status) {
        switch ($status) {
            case 'pending':
                return __('Pending', 'restaurant-booking');
            case 'confirmed':
                return __('Confirmed', 'restaurant-booking');
            case 'completed':
                return __('Completed', 'restaurant-booking');
            case 'cancelled':
                return __('Cancelled', 'restaurant-booking');
            default:
                return ucfirst($status);
        }
    }

    private function format_booking_source($source) {
        switch ($source) {
            case 'website':
                return __('Website', 'restaurant-booking');
            case 'phone':
                return __('Phone', 'restaurant-booking');
            case 'facebook':
                return 'Facebook';
            case 'zalo':
                return 'Zalo';
            case 'instagram':
                return 'Instagram';
            case 'walk-in':
                return __('Walk-in', 'restaurant-booking');
            case 'email':
                return __('Email', 'restaurant-booking');
            case 'other':
                return __('Other', 'restaurant-booking');
            default:
                return $source;
        }
    }

    private function get_customer_filters() {
        $search = isset($_GET['customer_search']) ? sanitize_text_field(wp_unslash($_GET['customer_search'])) : '';
        $vip = isset($_GET['filter_vip']) ? sanitize_text_field(wp_unslash($_GET['filter_vip'])) : 'all';
        $blacklist = isset($_GET['filter_blacklist']) ? sanitize_text_field(wp_unslash($_GET['filter_blacklist'])) : '';
        $orderby = isset($_GET['customer_orderby']) ? sanitize_key($_GET['customer_orderby']) : 'total_bookings';
        $order = isset($_GET['customer_order']) ? strtoupper(sanitize_text_field(wp_unslash($_GET['customer_order']))) : 'DESC';

        $allowed_vip = array('all', 'yes');
        if (!in_array($vip, $allowed_vip, true)) {
            $vip = 'all';
        }

        $allowed_blacklist = array('', 'yes', 'no');
        if (!in_array($blacklist, $allowed_blacklist, true)) {
            $blacklist = '';
        }

        $allowed_orderby = array('total_bookings', 'completed_bookings', 'cancelled_bookings', 'last_visit', 'name');
        if (!in_array($orderby, $allowed_orderby, true)) {
            $orderby = 'total_bookings';
        }

        if (!in_array($order, array('ASC', 'DESC'), true)) {
            $order = 'DESC';
        }

        return array(
            'search' => $search,
            'vip' => $vip,
            'blacklist' => $blacklist,
            'orderby' => $orderby,
            'order' => $order,
        );
    }

    private function format_booking_for_response($booking) {
        if (!$booking) {
            return null;
        }

        $date_display = '';
        if (!empty($booking->booking_date)) {
            $timestamp = strtotime($booking->booking_date);
            if ($timestamp) {
                $date_display = date_i18n(get_option('date_format', 'd/m/Y'), $timestamp);
            }
        }

        $created_display = '';
        if (!empty($booking->created_at)) {
            $created_timestamp = strtotime($booking->created_at);
            if ($created_timestamp) {
                $created_display = date_i18n(get_option('date_format', 'd/m/Y') . ' H:i', $created_timestamp);
            }
        }

        return array(
            'id' => (int) $booking->id,
            'padded_id' => str_pad($booking->id, 5, '0', STR_PAD_LEFT),
            'customer_name' => $booking->customer_name,
            'customer_phone' => $booking->customer_phone,
            'customer_email' => $booking->customer_email,
            'booking_date' => $booking->booking_date,
            'booking_time' => $booking->booking_time,
            'guest_count' => (int) $booking->guest_count,
            'booking_source' => $booking->booking_source,
            'special_requests' => $booking->special_requests,
            'admin_notes' => $booking->admin_notes,
            'status' => $booking->status,
            'table_number' => isset($booking->table_number) ? $booking->table_number : '',
            'created_at' => isset($booking->created_at) ? $booking->created_at : '',
            'status_label' => $this->format_booking_status($booking->status),
            'source_label' => $this->format_booking_source($booking->booking_source),
            'date_display' => $date_display,
            'created_display' => $created_display,
        );
    }

    private function render_section_create_booking($location_settings, $location_id, $location_details, $ajax_nonce) {
        $opening_time = isset($location_settings['opening_time']) ? substr($location_settings['opening_time'], 0, 5) : '09:00';
        $closing_time = isset($location_settings['closing_time']) ? substr($location_settings['closing_time'], 0, 5) : '22:00';
        $time_interval = isset($location_settings['time_slot_interval']) ? intval($location_settings['time_slot_interval']) : 30;
        $time_slots = $this->generate_time_slots($opening_time, $closing_time, $time_interval);

        $min_hours = isset($location_settings['min_advance_booking']) ? intval($location_settings['min_advance_booking']) : 2;
        if ($min_hours < 0) {
            $min_hours = 0;
        }
        $max_days = isset($location_settings['max_advance_booking']) ? intval($location_settings['max_advance_booking']) : 30;
        if ($max_days <= 0) {
            $max_days = 30;
        }

        $now = current_time('timestamp');
        $min_timestamp = $now + ($min_hours * HOUR_IN_SECONDS);
        if ($min_timestamp < $now) {
            $min_timestamp = $now;
        }
        $min_date = date('Y-m-d', $min_timestamp);
        $max_date = date('Y-m-d', $now + ($max_days * DAY_IN_SECONDS));

        ob_start();
        ?>
        <div class="rb-manager-create">
            <h3><?php esc_html_e('Create a new reservation', 'restaurant-booking'); ?></h3>
            <form id="rb-manager-create-booking" method="post">
                <input type="hidden" name="location_id" value="<?php echo esc_attr($location_id); ?>">
                <input type="hidden" name="nonce" value="<?php echo esc_attr($ajax_nonce); ?>">
                <div class="rb-form-grid">
                    <label>
                        <?php esc_html_e('Customer name', 'restaurant-booking'); ?> *
                        <input type="text" name="customer_name" required>
                    </label>
                    <label>
                        <?php esc_html_e('Phone number', 'restaurant-booking'); ?> *
                        <input type="tel" name="customer_phone" required pattern="[0-9]{8,15}">
                    </label>
                    <label>
                        <?php esc_html_e('Email', 'restaurant-booking'); ?> *
                        <input type="email" name="customer_email" required>
                    </label>
                    <label>
                        <?php esc_html_e('Guests', 'restaurant-booking'); ?> *
                        <select name="guest_count" required>
                            <?php for ($i = 1; $i <= 20; $i++) : ?>
                                <option value="<?php echo esc_attr($i); ?>"><?php echo esc_html($i); ?></option>
                            <?php endfor; ?>
                        </select>
                    </label>
                    <label>
                        <?php esc_html_e('Date', 'restaurant-booking'); ?> *
                        <input type="date" name="booking_date" min="<?php echo esc_attr($min_date); ?>" max="<?php echo esc_attr($max_date); ?>" required>
                    </label>
                    <label>
                        <?php esc_html_e('Time', 'restaurant-booking'); ?> *
                        <select name="booking_time" required>
                            <option value=""><?php esc_html_e('Select a time', 'restaurant-booking'); ?></option>
                            <?php foreach ($time_slots as $slot) : ?>
                                <option value="<?php echo esc_attr($slot); ?>"><?php echo esc_html($slot); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <?php esc_html_e('Source', 'restaurant-booking'); ?>
                        <select name="booking_source">
                            <option value="phone">📞 <?php esc_html_e('Phone', 'restaurant-booking'); ?></option>
                            <option value="facebook">📘 Facebook</option>
                            <option value="zalo">💬 Zalo</option>
                            <option value="instagram">📷 Instagram</option>
                            <option value="walk-in">🚶 <?php esc_html_e('Walk-in', 'restaurant-booking'); ?></option>
                            <option value="email">✉️ Email</option>
                            <option value="other">❓ <?php esc_html_e('Other', 'restaurant-booking'); ?></option>
                        </select>
                    </label>
                </div>

                <label class="rb-manager-wide">
                    <?php esc_html_e('Special requests', 'restaurant-booking'); ?>
                    <textarea name="special_requests" rows="3"></textarea>
                </label>

                <label class="rb-manager-wide">
                    <?php esc_html_e('Internal notes', 'restaurant-booking'); ?>
                    <textarea name="admin_notes" rows="3"></textarea>
                </label>

                <label class="rb-manager-checkbox">
                    <input type="checkbox" name="auto_confirm" value="1">
                    <?php esc_html_e('Confirm immediately if a table is available', 'restaurant-booking'); ?>
                </label>

                <div class="rb-manager-actions-row">
                    <button type="submit" class="rb-btn-primary"><?php esc_html_e('Create booking', 'restaurant-booking'); ?></button>
                </div>
            </form>
            <div id="rb-manager-create-feedback" class="rb-portal-result" hidden></div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_section_tables($location_id, $ajax_nonce) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rb_tables';
        $tables = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE location_id = %d ORDER BY table_number",
            $location_id
        ));

        ob_start();
        ?>
        <div class="rb-manager-tables">
            <h3><?php esc_html_e('Table management', 'restaurant-booking'); ?></h3>
            <form id="rb-manager-add-table" class="rb-manager-add-table" method="post">
                <input type="hidden" name="location_id" value="<?php echo esc_attr($location_id); ?>">
                <input type="hidden" name="nonce" value="<?php echo esc_attr($ajax_nonce); ?>">
                <label>
                    <?php esc_html_e('Table number', 'restaurant-booking'); ?>
                    <input type="number" name="table_number" min="1" required>
                </label>
                <label>
                    <?php esc_html_e('Capacity', 'restaurant-booking'); ?>
                    <input type="number" name="capacity" min="1" required>
                </label>
                <button type="submit" class="rb-btn-primary"><?php esc_html_e('Add table', 'restaurant-booking'); ?></button>
            </form>
            <div id="rb-manager-table-feedback" class="rb-portal-result" hidden></div>

            <div class="rb-manager-bookings-table-wrapper">
                <table class="rb-manager-bookings-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Table', 'restaurant-booking'); ?></th>
                            <th><?php esc_html_e('Capacity', 'restaurant-booking'); ?></th>
                            <th><?php esc_html_e('Status', 'restaurant-booking'); ?></th>
                            <th><?php esc_html_e('Actions', 'restaurant-booking'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($tables)) : ?>
                            <?php foreach ($tables as $table) :
                                $is_available = (int) $table->is_available === 1;
                                $next_status = $is_available ? 0 : 1;
                                ?>
                                <tr>
                                    <td><?php echo esc_html($table->table_number); ?></td>
                                    <td><?php echo esc_html($table->capacity); ?></td>
                                    <td>
                                        <?php if ($is_available) : ?>
                                            <span class="rb-status rb-status-available"><?php esc_html_e('Available', 'restaurant-booking'); ?></span>
                                        <?php else : ?>
                                            <span class="rb-status rb-status-unavailable"><?php esc_html_e('Unavailable', 'restaurant-booking'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="rb-btn-secondary rb-manager-toggle-table" data-table-id="<?php echo esc_attr($table->id); ?>" data-next-status="<?php echo esc_attr($next_status); ?>">
                                            <?php echo $is_available ? esc_html__('Deactivate', 'restaurant-booking') : esc_html__('Activate', 'restaurant-booking'); ?>
                                        </button>
                                        <button class="rb-btn-danger rb-manager-delete-table" data-table-id="<?php echo esc_attr($table->id); ?>">
                                            <?php esc_html_e('Delete', 'restaurant-booking'); ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="4" class="rb-manager-empty"><?php esc_html_e('No tables configured for this location.', 'restaurant-booking'); ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_section_customers($location_id, $ajax_nonce) {
        if (!class_exists('RB_Customer')) {
            require_once RB_PLUGIN_DIR . 'includes/class-customer.php';
        }

        global $rb_customer;
        if (!$rb_customer) {
            $rb_customer = new RB_Customer();
        }

        $filters = $this->get_customer_filters();

        $query_args = array(
            'location_id' => $location_id,
            'limit' => 100,
            'orderby' => $filters['orderby'],
            'order' => $filters['order'],
            'search' => $filters['search'],
        );

        if ($filters['vip'] === 'yes') {
            $query_args['vip_only'] = true;
        }

        if ($filters['blacklist'] !== '') {
            $query_args['blacklisted'] = $filters['blacklist'] === 'yes' ? 1 : 0;
        }

        $customers = $rb_customer->get_customers($query_args);
        $stats = $rb_customer->get_stats($location_id);
        $vip_suggestions = $rb_customer->get_vip_suggestions($location_id);
        $problematic = $rb_customer->get_problematic_customers($location_id);

        $reset_url = add_query_arg(array(
            'location_id' => $location_id,
            'rb_section' => 'customers',
        ), remove_query_arg(array('customer_search', 'filter_vip', 'filter_blacklist', 'customer_orderby', 'customer_order')));

        ob_start();
        ?>
        <div class="rb-manager-customers">
            <h3><?php esc_html_e('Customer management', 'restaurant-booking'); ?></h3>

            <form class="rb-manager-filters" method="get">
                <input type="hidden" name="location_id" value="<?php echo esc_attr($location_id); ?>">
                <input type="hidden" name="rb_section" value="customers">
                <div class="rb-manager-filter-grid">
                    <label>
                        <?php esc_html_e('Search', 'restaurant-booking'); ?>
                        <input type="text" name="customer_search" value="<?php echo esc_attr($filters['search']); ?>" placeholder="<?php esc_attr_e('Name, phone or email', 'restaurant-booking'); ?>">
                    </label>
                    <label>
                        <?php esc_html_e('VIP filter', 'restaurant-booking'); ?>
                        <select name="filter_vip">
                            <option value="all" <?php selected($filters['vip'], 'all'); ?>><?php esc_html_e('All customers', 'restaurant-booking'); ?></option>
                            <option value="yes" <?php selected($filters['vip'], 'yes'); ?>><?php esc_html_e('VIP only', 'restaurant-booking'); ?></option>
                        </select>
                    </label>
                    <label>
                        <?php esc_html_e('Blacklist', 'restaurant-booking'); ?>
                        <select name="filter_blacklist">
                            <option value="" <?php selected($filters['blacklist'], ''); ?>><?php esc_html_e('All', 'restaurant-booking'); ?></option>
                            <option value="yes" <?php selected($filters['blacklist'], 'yes'); ?>><?php esc_html_e('Blacklisted', 'restaurant-booking'); ?></option>
                            <option value="no" <?php selected($filters['blacklist'], 'no'); ?>><?php esc_html_e('Not blacklisted', 'restaurant-booking'); ?></option>
                        </select>
                    </label>
                    <label>
                        <?php esc_html_e('Sort by', 'restaurant-booking'); ?>
                        <select name="customer_orderby">
                            <option value="total_bookings" <?php selected($filters['orderby'], 'total_bookings'); ?>><?php esc_html_e('Total bookings', 'restaurant-booking'); ?></option>
                            <option value="completed_bookings" <?php selected($filters['orderby'], 'completed_bookings'); ?>><?php esc_html_e('Completed bookings', 'restaurant-booking'); ?></option>
                            <option value="cancelled_bookings" <?php selected($filters['orderby'], 'cancelled_bookings'); ?>><?php esc_html_e('Cancelled bookings', 'restaurant-booking'); ?></option>
                            <option value="last_visit" <?php selected($filters['orderby'], 'last_visit'); ?>><?php esc_html_e('Last visit', 'restaurant-booking'); ?></option>
                            <option value="name" <?php selected($filters['orderby'], 'name'); ?>><?php esc_html_e('Name', 'restaurant-booking'); ?></option>
                        </select>
                    </label>
                    <label>
                        <?php esc_html_e('Order', 'restaurant-booking'); ?>
                        <select name="customer_order">
                            <option value="DESC" <?php selected($filters['order'], 'DESC'); ?>><?php esc_html_e('Descending', 'restaurant-booking'); ?></option>
                            <option value="ASC" <?php selected($filters['order'], 'ASC'); ?>><?php esc_html_e('Ascending', 'restaurant-booking'); ?></option>
                        </select>
                    </label>
                </div>
                <div class="rb-manager-filter-actions">
                    <button type="submit" class="rb-btn-primary"><?php esc_html_e('Apply filters', 'restaurant-booking'); ?></button>
                    <a class="rb-btn-secondary" href="<?php echo esc_url($reset_url); ?>"><?php esc_html_e('Reset', 'restaurant-booking'); ?></a>
                </div>
            </form>

            <div class="rb-manager-stats">
                <div class="rb-manager-stat-card">
                    <span class="rb-manager-stat-label"><?php esc_html_e('Total customers', 'restaurant-booking'); ?></span>
                    <span class="rb-manager-stat-value"><?php echo esc_html($stats['total']); ?></span>
                </div>
                <div class="rb-manager-stat-card">
                    <span class="rb-manager-stat-label"><?php esc_html_e('VIP', 'restaurant-booking'); ?></span>
                    <span class="rb-manager-stat-value">⭐ <?php echo esc_html($stats['vip']); ?></span>
                </div>
                <div class="rb-manager-stat-card">
                    <span class="rb-manager-stat-label"><?php esc_html_e('Blacklisted', 'restaurant-booking'); ?></span>
                    <span class="rb-manager-stat-value">🚫 <?php echo esc_html($stats['blacklisted']); ?></span>
                </div>
                <div class="rb-manager-stat-card">
                    <span class="rb-manager-stat-label"><?php esc_html_e('New this month', 'restaurant-booking'); ?></span>
                    <span class="rb-manager-stat-value">✨ <?php echo esc_html($stats['new_this_month']); ?></span>
                </div>
            </div>

            <?php if (!empty($vip_suggestions)) : ?>
                <div class="rb-manager-tip">
                    <strong><?php esc_html_e('VIP suggestions:', 'restaurant-booking'); ?></strong>
                    <?php printf(esc_html__('%d customers are close to VIP status.', 'restaurant-booking'), count($vip_suggestions)); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($problematic)) : ?>
                <div class="rb-manager-warning">
                    <strong><?php esc_html_e('Attention:', 'restaurant-booking'); ?></strong>
                    <?php printf(esc_html__('%d customers frequently cancel or no-show.', 'restaurant-booking'), count($problematic)); ?>
                </div>
            <?php endif; ?>

            <div id="rb-manager-customers-feedback" class="rb-portal-result" hidden></div>

            <div class="rb-manager-bookings-table-wrapper">
                <table class="rb-manager-bookings-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Name', 'restaurant-booking'); ?></th>
                            <th><?php esc_html_e('Phone', 'restaurant-booking'); ?></th>
                            <th><?php esc_html_e('Email', 'restaurant-booking'); ?></th>
                            <th><?php esc_html_e('Bookings', 'restaurant-booking'); ?></th>
                            <th><?php esc_html_e('Completed', 'restaurant-booking'); ?></th>
                            <th><?php esc_html_e('Cancelled', 'restaurant-booking'); ?></th>
                            <th><?php esc_html_e('Notes', 'restaurant-booking'); ?></th>
                            <th><?php esc_html_e('Tags', 'restaurant-booking'); ?></th>
                            <th><?php esc_html_e('Actions', 'restaurant-booking'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($customers)) : ?>
                            <?php foreach ($customers as $customer) :
                                $problem_rate = 0;
                                if (!empty($customer->total_bookings)) {
                                    $problem_rate = (($customer->no_shows + $customer->cancelled_bookings) / max(1, $customer->total_bookings)) * 100;
                                }
                                ?>
                                <tr>
                                    <td><?php echo esc_html($customer->name); ?></td>
                                    <td><?php echo esc_html($customer->phone); ?></td>
                                    <td><?php echo esc_html($customer->email); ?></td>
                                    <td><?php echo esc_html($customer->total_bookings); ?></td>
                                    <td><?php echo esc_html($customer->completed_bookings); ?></td>
                                    <td><?php echo esc_html($customer->cancelled_bookings); ?></td>
                                    <td>
                                        <textarea class="rb-manager-customer-note" data-customer-id="<?php echo esc_attr($customer->id); ?>" rows="2"><?php echo esc_textarea($customer->customer_notes); ?></textarea>
                                        <button class="rb-btn-secondary rb-manager-save-note" data-customer-id="<?php echo esc_attr($customer->id); ?>"><?php esc_html_e('Save note', 'restaurant-booking'); ?></button>
                                    </td>
                                    <td>
                                        <?php if (!empty($customer->vip_status)) : ?>
                                            <span class="rb-status rb-status-vip"><?php esc_html_e('VIP', 'restaurant-booking'); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($customer->blacklisted)) : ?>
                                            <span class="rb-status rb-status-blacklist"><?php esc_html_e('Blacklisted', 'restaurant-booking'); ?></span>
                                        <?php elseif ($problem_rate > 50) : ?>
                                            <span class="rb-status rb-status-warning"><?php esc_html_e('Risk', 'restaurant-booking'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="rb-btn-secondary rb-manager-view-history" data-phone="<?php echo esc_attr($customer->phone); ?>">
                                            <?php esc_html_e('History', 'restaurant-booking'); ?>
                                        </button>
                                        <?php if (empty($customer->vip_status) && (int) $customer->completed_bookings >= 3) : ?>
                                            <button class="rb-btn-primary rb-manager-set-vip" data-customer-id="<?php echo esc_attr($customer->id); ?>">
                                                <?php esc_html_e('Set VIP', 'restaurant-booking'); ?>
                                            </button>
                                        <?php endif; ?>
                                        <?php if (empty($customer->blacklisted) && $problem_rate > 50) : ?>
                                            <button class="rb-btn-danger rb-manager-blacklist" data-customer-id="<?php echo esc_attr($customer->id); ?>">
                                                <?php esc_html_e('Blacklist', 'restaurant-booking'); ?>
                                            </button>
                                        <?php elseif (!empty($customer->blacklisted)) : ?>
                                            <button class="rb-btn-secondary rb-manager-unblacklist" data-customer-id="<?php echo esc_attr($customer->id); ?>">
                                                <?php esc_html_e('Remove blacklist', 'restaurant-booking'); ?>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="9" class="rb-manager-empty"><?php esc_html_e('No customers found for this location.', 'restaurant-booking'); ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div id="rb-manager-history" class="rb-manager-history" hidden>
                <div class="rb-manager-history-inner">
                    <button type="button" class="rb-manager-history-close">&times;</button>
                    <div id="rb-manager-history-content"></div>
                </div>
            </div>

            <input type="hidden" id="rb-manager-customers-nonce" value="<?php echo esc_attr($ajax_nonce); ?>">
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_section_settings($location_settings, $location_id, $ajax_nonce) {
        $active_tab = isset($_GET['rb_settings_tab']) ? sanitize_key($_GET['rb_settings_tab']) : 'hours';
        $allowed_tabs = array('hours', 'booking');
        if (!in_array($active_tab, $allowed_tabs, true)) {
            $active_tab = 'hours';
        }

        $defaults = array(
            'opening_time' => '09:00',
            'closing_time' => '22:00',
            'time_slot_interval' => 30,
            'booking_buffer_time' => 0,
            'min_advance_booking' => 2,
            'max_advance_booking' => 30,
            'max_guests_per_booking' => 20,
            'working_hours_mode' => 'simple',
            'lunch_break_enabled' => 'no',
            'lunch_break_start' => '14:00',
            'lunch_break_end' => '17:00',
            'morning_shift_start' => '09:00',
            'morning_shift_end' => '14:00',
            'evening_shift_start' => '17:00',
            'evening_shift_end' => '22:00',
            'hotline' => '',
            'email' => '',
            'address' => '',
            'shift_notes' => '',
            'languages' => array(),
            'auto_confirm_enabled' => 'no',
            'require_deposit' => 'no',
            'deposit_amount' => 0,
            'deposit_for_guests' => 0,
            'admin_email' => get_option('admin_email'),
            'enable_email' => 'yes',
            'enable_sms' => 'no',
            'sms_api_key' => '',
            'reminder_hours_before' => 24,
            'special_closed_dates' => '',
            'cancellation_hours' => 2,
            'weekend_enabled' => 'yes',
            'no_show_auto_blacklist' => 3,
        );

        $settings = wp_parse_args($location_settings, $defaults);
        if (!is_array($settings['languages'])) {
            $settings['languages'] = array_filter(array_map('trim', explode(',', $settings['languages'])));
        }

        $tabs = array(
            'hours' => __('Working hours', 'restaurant-booking'),
            'booking' => __('Booking rules', 'restaurant-booking'),
        );

        ob_start();
        ?>
        <div class="rb-manager-settings">
            <h3><?php esc_html_e('Location settings', 'restaurant-booking'); ?></h3>
            <form id="rb-manager-settings-form">
                <input type="hidden" name="nonce" value="<?php echo esc_attr($ajax_nonce); ?>">
                <input type="hidden" name="location_id" value="<?php echo esc_attr($location_id); ?>">

                <div class="rb-manager-settings-tabs">
                    <?php foreach ($tabs as $slug => $label) : ?>
                        <button type="button" class="rb-manager-settings-tab <?php echo $active_tab === $slug ? 'active' : ''; ?>" data-tab="<?php echo esc_attr($slug); ?>"><?php echo esc_html($label); ?></button>
                    <?php endforeach; ?>
                </div>

                <div class="rb-manager-settings-panels">
                    <section class="rb-manager-settings-panel" data-tab="hours" <?php echo $active_tab === 'hours' ? '' : 'hidden'; ?>>
                        <div class="rb-manager-toggle-group">
                            <label>
                                <input type="radio" name="location_settings[working_hours_mode]" value="simple" <?php checked($settings['working_hours_mode'], 'simple'); ?>>
                                <?php esc_html_e('Simple: one opening and closing time', 'restaurant-booking'); ?>
                            </label>
                            <label>
                                <input type="radio" name="location_settings[working_hours_mode]" value="advanced" <?php checked($settings['working_hours_mode'], 'advanced'); ?>>
                                <?php esc_html_e('Advanced: morning/evening shifts', 'restaurant-booking'); ?>
                            </label>
                        </div>
                        <div class="rb-manager-settings-grid">
                            <label>
                                <?php esc_html_e('Opening time', 'restaurant-booking'); ?>
                                <input type="time" name="location_settings[opening_time]" value="<?php echo esc_attr(substr($settings['opening_time'], 0, 5)); ?>">
                            </label>
                            <label>
                                <?php esc_html_e('Closing time', 'restaurant-booking'); ?>
                                <input type="time" name="location_settings[closing_time]" value="<?php echo esc_attr(substr($settings['closing_time'], 0, 5)); ?>">
                            </label>
                            <label>
                                <input type="checkbox" name="location_settings[lunch_break_enabled]" value="yes" <?php checked($settings['lunch_break_enabled'], 'yes'); ?>>
                                <?php esc_html_e('Enable lunch break', 'restaurant-booking'); ?>
                            </label>
                            <label>
                                <?php esc_html_e('Lunch break start', 'restaurant-booking'); ?>
                                <input type="time" name="location_settings[lunch_break_start]" value="<?php echo esc_attr(substr($settings['lunch_break_start'], 0, 5)); ?>">
                            </label>
                            <label>
                                <?php esc_html_e('Lunch break end', 'restaurant-booking'); ?>
                                <input type="time" name="location_settings[lunch_break_end]" value="<?php echo esc_attr(substr($settings['lunch_break_end'], 0, 5)); ?>">
                            </label>
                            <label>
                                <?php esc_html_e('Morning shift', 'restaurant-booking'); ?>
                                <div class="rb-manager-shift">
                                    <input type="time" name="location_settings[morning_shift_start]" value="<?php echo esc_attr(substr($settings['morning_shift_start'], 0, 5)); ?>">
                                    <span>→</span>
                                    <input type="time" name="location_settings[morning_shift_end]" value="<?php echo esc_attr(substr($settings['morning_shift_end'], 0, 5)); ?>">
                                </div>
                            </label>
                            <label>
                                <?php esc_html_e('Evening shift', 'restaurant-booking'); ?>
                                <div class="rb-manager-shift">
                                    <input type="time" name="location_settings[evening_shift_start]" value="<?php echo esc_attr(substr($settings['evening_shift_start'], 0, 5)); ?>">
                                    <span>→</span>
                                    <input type="time" name="location_settings[evening_shift_end]" value="<?php echo esc_attr(substr($settings['evening_shift_end'], 0, 5)); ?>">
                                </div>
                            </label>
                        </div>
                    </section>

                    <section class="rb-manager-settings-panel" data-tab="booking" <?php echo $active_tab === 'booking' ? '' : 'hidden'; ?>>
                        <div class="rb-manager-settings-grid">
                            <label>
                                <?php esc_html_e('Time slot interval (minutes)', 'restaurant-booking'); ?>
                                <input type="number" name="location_settings[time_slot_interval]" min="5" max="120" step="5" value="<?php echo esc_attr((int) $settings['time_slot_interval']); ?>">
                            </label>
                            <label>
                                <?php esc_html_e('Buffer time (minutes)', 'restaurant-booking'); ?>
                                <input type="number" name="location_settings[booking_buffer_time]" min="0" max="120" step="5" value="<?php echo esc_attr((int) $settings['booking_buffer_time']); ?>">
                            </label>
                            <label>
                                <?php esc_html_e('Minimum hours in advance', 'restaurant-booking'); ?>
                                <input type="number" name="location_settings[min_advance_booking]" min="0" max="72" value="<?php echo esc_attr((int) $settings['min_advance_booking']); ?>">
                            </label>
                            <label>
                                <?php esc_html_e('Maximum days in advance', 'restaurant-booking'); ?>
                                <input type="number" name="location_settings[max_advance_booking]" min="1" max="180" value="<?php echo esc_attr((int) $settings['max_advance_booking']); ?>">
                            </label>
                            <label>
                                <?php esc_html_e('Max guests per booking', 'restaurant-booking'); ?>
                                <input type="number" name="location_settings[max_guests_per_booking]" min="1" max="100" value="<?php echo esc_attr((int) $settings['max_guests_per_booking']); ?>">
                            </label>
                            <label>
                                <input type="checkbox" name="location_settings[auto_confirm_enabled]" value="yes" <?php checked($settings['auto_confirm_enabled'], 'yes'); ?>>
                                <?php esc_html_e('Auto confirm when a table is available', 'restaurant-booking'); ?>
                            </label>
                        </div>
                    </section>
                </div>

                <div class="rb-manager-actions-row">
                    <button type="submit" class="rb-btn-primary"><?php esc_html_e('Save settings', 'restaurant-booking'); ?></button>
                </div>
            </form>
            <div id="rb-manager-settings-feedback" class="rb-portal-result" hidden></div>
        </div>
        <?php
        return ob_get_clean();
    }

    protected function generate_time_slots($start = null, $end = null, $interval = null) {
        $slots = array();

        $start_time = strtotime($start);
        $end_time = strtotime($end);
        $interval = max(5, (int) $interval);

        if (!$start_time || !$end_time || $start_time >= $end_time) {
            return $slots;
        }

        while ($start_time < $end_time) {
            $slots[] = date('H:i', $start_time);
            $start_time += $interval * MINUTE_IN_SECONDS;
        }

        return $slots;
    }

    private function render_manager_login($atts, $locations) {
        $error = $this->manager_login_error;
        $languages = function_exists('rb_get_available_languages') ? rb_get_available_languages() : array();
        $current_language = function_exists('rb_get_current_language') ? rb_get_current_language() : '';

        ob_start();
        ?>
        <div class="rb-manager rb-manager-login">
            <h2><?php echo esc_html($atts['title']); ?></h2>
            <?php if (!empty($error)) : ?>
                <div class="rb-portal-result error"><?php echo wp_kses_post($error); ?></div>
            <?php endif; ?>
            <form method="post" class="rb-manager-login-form">
                <?php wp_nonce_field('rb_manager_login', 'rb_manager_login_nonce'); ?>
                <div class="rb-form-group">
                    <label for="rb-manager-username"><?php esc_html_e('Username or email', 'restaurant-booking'); ?></label>
                    <input type="text" id="rb-manager-username" name="rb_username" required />
                </div>
                <div class="rb-form-group">
                    <label for="rb-manager-password"><?php esc_html_e('Password', 'restaurant-booking'); ?></label>
                    <input type="password" id="rb-manager-password" name="rb_password" required />
                </div>
                <?php if (!empty($languages)) : ?>
                    <div class="rb-form-group">
                        <label for="rb-manager-language"><?php esc_html_e('Interface language', 'restaurant-booking'); ?></label>
                        <select id="rb-manager-language" name="rb_language">
                            <?php foreach ($languages as $code => $info) :
                                $label = isset($info['flag']) ? $info['flag'] . ' ' : '';
                                $label .= isset($info['name']) ? $info['name'] : $code;
                                ?>
                                <option value="<?php echo esc_attr($code); ?>" <?php selected($current_language, $code); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="rb-portal-actions">
                    <button type="submit" class="rb-btn-primary"><?php esc_html_e('Log in', 'restaurant-booking'); ?></button>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    private function resolve_location_from_allowed($requested_location_id, $locations, $allowed_ids, $fallback_id = 0) {
        if (empty($locations)) {
            return 0;
        }

        $available_ids = array_map('intval', wp_list_pluck($locations, 'id'));
        $available_ids = array_values(array_filter($available_ids));

        if (empty($available_ids)) {
            return 0;
        }

        $allowed_ids = array_map('intval', (array) $allowed_ids);
        $allowed_ids = array_values(array_filter($allowed_ids));

        if (!empty($allowed_ids)) {
            $allowed_ids = array_values(array_intersect($allowed_ids, $available_ids));

            if (empty($allowed_ids)) {
                return 0;
            }
        } else {
            $allowed_ids = $available_ids;
        }

        $requested_location_id = (int) $requested_location_id;
        if ($requested_location_id && in_array($requested_location_id, $allowed_ids, true)) {
            return $requested_location_id;
        }

        $fallback_id = (int) $fallback_id;
        if ($fallback_id && in_array($fallback_id, $allowed_ids, true)) {
            return $fallback_id;
        }

        return (int) $allowed_ids[0];
    }

    private function get_portal_session_account() {
        if (!$this->portal_session) {
            return null;
        }

        if ($this->portal_account === null) {
            $account = $this->portal_session->get_current_account();
            $this->portal_account = $account ? $account : false;
        }

        return $this->portal_account ? $this->portal_account : null;
    }

    private function get_current_manager_permissions() {
        $account = $this->get_portal_session_account();

        if ($account) {
            $allowed = !empty($account->locations) ? array_map('intval', (array) $account->locations) : array();

            return array(
                'account' => $account,
                'allowed_locations' => $allowed,
            );
        }

        return null;
    }

    private function manager_can_access_location($permissions, $location_id) {
        if (!$permissions) {
            return false;
        }

        $location_id = (int) $location_id;
        if ($location_id <= 0) {
            return false;
        }

        $allowed = isset($permissions['allowed_locations']) ? (array) $permissions['allowed_locations'] : array();

        if (empty($allowed)) {
            return true;
        }

        return in_array($location_id, array_map('intval', $allowed), true);
    }

    private function get_permissions_active_location($permissions) {
        if (!$permissions || empty($permissions['account'])) {
            return 0;
        }

        $account = $permissions['account'];

        return isset($account->last_location_id) ? (int) $account->last_location_id : 0;
    }

    public function handle_manager_update_booking() {
        $permissions = $this->get_current_manager_permissions();

        if (!$permissions) {
            wp_send_json_error(array('message' => __('You are not allowed to perform this action.', 'restaurant-booking')));
            wp_die();
        }

        if (!check_ajax_referer('rb_frontend_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
            wp_die();
        }

        $booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;
        $action = isset($_POST['manager_action']) ? sanitize_text_field($_POST['manager_action']) : '';

        if (!$booking_id || empty($action)) {
            wp_send_json_error(array('message' => __('Invalid request data.', 'restaurant-booking')));
            wp_die();
        }

        if (!$this->portal_session || !$this->portal_account_manager) {
            wp_send_json_error(array('message' => __('You are not allowed to perform this action.', 'restaurant-booking')));
            wp_die();
        }

        $account = $permissions['account'];
        $active_location = isset($account->last_location_id) ? (int) $account->last_location_id : 0;

        global $rb_booking;
        if (!$rb_booking) {
            require_once RB_PLUGIN_DIR . 'includes/class-booking.php';
            $rb_booking = new RB_Booking();
        }

        $booking = $rb_booking->get_booking($booking_id);

        if (!$booking) {
            wp_send_json_error(array('message' => __('Booking not found.', 'restaurant-booking')));
            wp_die();
        }

        $booking_location = (int) $booking->location_id;
        $allowed_locations = $permissions['allowed_locations'];

        if (!empty($allowed_locations) && !in_array($booking_location, $allowed_locations, true)) {
            wp_send_json_error(array('message' => __('You are not allowed to manage this location.', 'restaurant-booking')));
            wp_die();
        }

        if ($active_location && $booking_location !== $active_location) {
            wp_send_json_error(array('message' => __('You can only manage bookings for your current location.', 'restaurant-booking')));
            wp_die();
        }

        switch ($action) {
            case 'confirm':
                $result = $rb_booking->confirm_booking($booking_id);
                if (!is_wp_error($result) && class_exists('RB_Email')) {
                    $email = new RB_Email();
                    $email->send_confirmation_email($rb_booking->get_booking($booking_id));
                }
                break;
            case 'cancel':
                $result = $rb_booking->cancel_booking($booking_id);
                break;
            case 'complete':
                $result = $rb_booking->complete_booking($booking_id);
                break;
            case 'delete':
                $result = $rb_booking->delete_booking($booking_id);
                break;
            default:
                $result = new WP_Error('rb_invalid_action', __('Unsupported action', 'restaurant-booking'));
        }

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
            wp_die();
        }

        if ($action === 'delete') {
            wp_send_json_success(array('message' => __('Booking deleted successfully.', 'restaurant-booking'), 'deleted' => true));
            wp_die();
        }

        if (!class_exists('RB_Customer')) {
            require_once RB_PLUGIN_DIR . 'includes/class-customer.php';
        }

        global $rb_customer;
        if (!$rb_customer) {
            $rb_customer = new RB_Customer();
        }

        $rb_customer->update_customer_from_booking($booking_id, true);

        $updated_booking = $rb_booking->get_booking($booking_id);
        $formatted = $this->format_booking_for_response($updated_booking);

        wp_send_json_success(array(
            'message' => __('Booking updated successfully.', 'restaurant-booking'),
            'booking' => $formatted,
        ));

        wp_die();
    }

    public function handle_manager_create_booking() {
        $permissions = $this->get_current_manager_permissions();

        if (!$permissions) {
            wp_send_json_error(array('message' => __('You are not allowed to perform this action.', 'restaurant-booking')));
            wp_die();
        }

        if (!check_ajax_referer('rb_frontend_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
            wp_die();
        }

        $location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;

        if (!$this->manager_can_access_location($permissions, $location_id)) {
            wp_send_json_error(array('message' => __('You are not allowed to manage this location.', 'restaurant-booking')));
            wp_die();
        }

        $active_location = $this->get_permissions_active_location($permissions);
        if ($active_location && $active_location !== $location_id) {
            wp_send_json_error(array('message' => __('Please switch to the correct location before creating a booking.', 'restaurant-booking')));
            wp_die();
        }

        if (!class_exists('RB_Booking')) {
            require_once RB_PLUGIN_DIR . 'includes/class-booking.php';
        }

        global $rb_booking;
        if (!$rb_booking) {
            $rb_booking = new RB_Booking();
        }

        $customer_name = isset($_POST['customer_name']) ? sanitize_text_field(wp_unslash($_POST['customer_name'])) : '';
        $customer_phone = isset($_POST['customer_phone']) ? sanitize_text_field(wp_unslash($_POST['customer_phone'])) : '';
        $customer_email = isset($_POST['customer_email']) ? sanitize_email(wp_unslash($_POST['customer_email'])) : '';
        $guest_count = isset($_POST['guest_count']) ? intval($_POST['guest_count']) : 0;
        $booking_date = isset($_POST['booking_date']) ? sanitize_text_field(wp_unslash($_POST['booking_date'])) : '';
        $booking_time = isset($_POST['booking_time']) ? sanitize_text_field(wp_unslash($_POST['booking_time'])) : '';
        $booking_source = isset($_POST['booking_source']) ? sanitize_text_field(wp_unslash($_POST['booking_source'])) : 'portal';
        $special_requests = isset($_POST['special_requests']) ? sanitize_textarea_field(wp_unslash($_POST['special_requests'])) : '';
        $admin_notes = isset($_POST['admin_notes']) ? sanitize_textarea_field(wp_unslash($_POST['admin_notes'])) : '';
        $auto_confirm = !empty($_POST['auto_confirm']);

        if (empty($customer_name) || empty($customer_phone) || empty($customer_email) || !$guest_count || empty($booking_date) || empty($booking_time)) {
            wp_send_json_error(array('message' => __('Please fill out all required fields.', 'restaurant-booking')));
            wp_die();
        }

        $is_available = $rb_booking->is_time_slot_available(
            $booking_date,
            $booking_time,
            $guest_count,
            null,
            $location_id
        );

        if (!$is_available) {
            wp_send_json_error(array('message' => __('This time slot is no longer available. Please choose a different time.', 'restaurant-booking')));
            wp_die();
        }

        $booking_data = array(
            'customer_name' => $customer_name,
            'customer_phone' => $customer_phone,
            'customer_email' => $customer_email,
            'guest_count' => $guest_count,
            'booking_date' => $booking_date,
            'booking_time' => $booking_time,
            'booking_source' => $booking_source,
            'special_requests' => $special_requests,
            'admin_notes' => $admin_notes,
            'status' => 'pending',
            'created_at' => current_time('mysql'),
            'location_id' => $location_id,
            'created_by' => 0,
        );

        $booking_id = $rb_booking->create_booking($booking_data);

        if (is_wp_error($booking_id)) {
            wp_send_json_error(array('message' => $booking_id->get_error_message()));
            wp_die();
        }

        if ($auto_confirm) {
            $result = $rb_booking->confirm_booking($booking_id);
            if (is_wp_error($result)) {
                wp_send_json_error(array('message' => $result->get_error_message()));
                wp_die();
            }

            if (class_exists('RB_Email')) {
                $booking = $rb_booking->get_booking($booking_id);
                if ($booking) {
                    $email = new RB_Email();
                    $email->send_confirmation_email($booking);
                }
            }
        }

        wp_send_json_success(array('message' => __('Booking created successfully.', 'restaurant-booking')));
        wp_die();
    }

    public function handle_manager_save_booking() {
        $permissions = $this->get_current_manager_permissions();

        if (!$permissions) {
            wp_send_json_error(array('message' => __('You are not allowed to perform this action.', 'restaurant-booking')));
            wp_die();
        }

        if (!check_ajax_referer('rb_frontend_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
            wp_die();
        }

        $booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;
        if (!$booking_id) {
            wp_send_json_error(array('message' => __('Invalid booking.', 'restaurant-booking')));
            wp_die();
        }

        if (!class_exists('RB_Booking')) {
            require_once RB_PLUGIN_DIR . 'includes/class-booking.php';
        }

        global $rb_booking;
        if (!$rb_booking) {
            $rb_booking = new RB_Booking();
        }

        $booking = $rb_booking->get_booking($booking_id);
        if (!$booking) {
            wp_send_json_error(array('message' => __('Booking not found.', 'restaurant-booking')));
            wp_die();
        }

        $booking_location = (int) $booking->location_id;
        $allowed_locations = $permissions['allowed_locations'];

        if (!empty($allowed_locations) && !in_array($booking_location, $allowed_locations, true)) {
            wp_send_json_error(array('message' => __('You are not allowed to manage this location.', 'restaurant-booking')));
            wp_die();
        }

        $active_location = $this->get_permissions_active_location($permissions);
        if ($active_location && $active_location !== $booking_location) {
            wp_send_json_error(array('message' => __('Please switch to the correct location before editing this booking.', 'restaurant-booking')));
            wp_die();
        }

        $customer_name = isset($_POST['customer_name']) ? sanitize_text_field(wp_unslash($_POST['customer_name'])) : '';
        $customer_phone = isset($_POST['customer_phone']) ? sanitize_text_field(wp_unslash($_POST['customer_phone'])) : '';
        $customer_email = isset($_POST['customer_email']) ? sanitize_email(wp_unslash($_POST['customer_email'])) : '';
        $guest_count = isset($_POST['guest_count']) ? intval($_POST['guest_count']) : 0;
        $booking_date = isset($_POST['booking_date']) ? sanitize_text_field(wp_unslash($_POST['booking_date'])) : '';
        $booking_time = isset($_POST['booking_time']) ? sanitize_text_field(wp_unslash($_POST['booking_time'])) : '';
        $booking_source = isset($_POST['booking_source']) ? sanitize_text_field(wp_unslash($_POST['booking_source'])) : 'portal';
        $special_requests = isset($_POST['special_requests']) ? sanitize_textarea_field(wp_unslash($_POST['special_requests'])) : '';
        $admin_notes = isset($_POST['admin_notes']) ? sanitize_textarea_field(wp_unslash($_POST['admin_notes'])) : '';

        if (empty($customer_name) || empty($customer_phone) || empty($customer_email) || !$guest_count || empty($booking_date) || empty($booking_time)) {
            wp_send_json_error(array('message' => __('Please fill out all required fields.', 'restaurant-booking')));
            wp_die();
        }

        if (!is_email($customer_email)) {
            wp_send_json_error(array('message' => __('Please provide a valid customer email.', 'restaurant-booking')));
            wp_die();
        }

        $allowed_sources = array('website', 'phone', 'facebook', 'zalo', 'instagram', 'walk-in', 'email', 'other', 'portal');
        if (!in_array($booking_source, $allowed_sources, true)) {
            $booking_source = 'portal';
        }

        $is_available = $rb_booking->is_time_slot_available(
            $booking_date,
            $booking_time,
            $guest_count,
            $booking_id,
            $booking_location
        );

        if (!$is_available) {
            wp_send_json_error(array('message' => __('This time slot is no longer available. Please choose a different time.', 'restaurant-booking')));
            wp_die();
        }

        $update_data = array(
            'customer_name' => $customer_name,
            'customer_phone' => $customer_phone,
            'customer_email' => $customer_email,
            'guest_count' => $guest_count,
            'booking_date' => $booking_date,
            'booking_time' => $booking_time,
            'booking_source' => $booking_source,
            'special_requests' => $special_requests,
            'admin_notes' => $admin_notes,
        );

        $updated = $rb_booking->update_booking($booking_id, $update_data);

        if (!$updated) {
            wp_send_json_error(array('message' => __('Could not update the booking. Please try again.', 'restaurant-booking')));
            wp_die();
        }

        if (!class_exists('RB_Customer')) {
            require_once RB_PLUGIN_DIR . 'includes/class-customer.php';
        }

        global $rb_customer;
        if (!$rb_customer) {
            $rb_customer = new RB_Customer();
        }

        $rb_customer->update_customer_from_booking($booking_id, true);

        $updated_booking = $rb_booking->get_booking($booking_id);
        $formatted = $this->format_booking_for_response($updated_booking);

        wp_send_json_success(array(
            'message' => __('Booking updated successfully.', 'restaurant-booking'),
            'booking' => $formatted,
        ));
        wp_die();
    }

    public function handle_manager_add_table() {
        $permissions = $this->get_current_manager_permissions();

        if (!$permissions) {
            wp_send_json_error(array('message' => __('You are not allowed to perform this action.', 'restaurant-booking')));
            wp_die();
        }

        if (!check_ajax_referer('rb_frontend_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
            wp_die();
        }

        $location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;

        if (!$this->manager_can_access_location($permissions, $location_id)) {
            wp_send_json_error(array('message' => __('You are not allowed to manage this location.', 'restaurant-booking')));
            wp_die();
        }

        $active_location = $this->get_permissions_active_location($permissions);
        if ($active_location && $active_location !== $location_id) {
            wp_send_json_error(array('message' => __('Please switch to the correct location before making changes.', 'restaurant-booking')));
            wp_die();
        }

        $table_number = isset($_POST['table_number']) ? intval($_POST['table_number']) : 0;
        $capacity = isset($_POST['capacity']) ? intval($_POST['capacity']) : 0;

        if ($table_number <= 0 || $capacity <= 0) {
            wp_send_json_error(array('message' => __('Please provide a valid table number and capacity.', 'restaurant-booking')));
            wp_die();
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'rb_tables';

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE table_number = %d AND location_id = %d",
            $table_number,
            $location_id
        ));

        if ($exists) {
            wp_send_json_error(array('message' => __('Table number already exists for this location.', 'restaurant-booking')));
            wp_die();
        }

        $result = $wpdb->insert(
            $table_name,
            array(
                'location_id' => $location_id,
                'table_number' => $table_number,
                'capacity' => $capacity,
                'is_available' => 1,
                'created_at' => current_time('mysql'),
            )
        );

        if ($result === false) {
            wp_send_json_error(array('message' => __('Could not add table. Please try again.', 'restaurant-booking')));
            wp_die();
        }

        wp_send_json_success(array('message' => __('Table added successfully.', 'restaurant-booking')));
        wp_die();
    }

    public function handle_manager_toggle_table() {
        $permissions = $this->get_current_manager_permissions();

        if (!$permissions) {
            wp_send_json_error(array('message' => __('You are not allowed to perform this action.', 'restaurant-booking')));
            wp_die();
        }

        if (!check_ajax_referer('rb_frontend_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
            wp_die();
        }

        $table_id = isset($_POST['table_id']) ? intval($_POST['table_id']) : 0;
        $is_available = isset($_POST['is_available']) ? intval($_POST['is_available']) : 0;

        global $wpdb;
        $table_name = $wpdb->prefix . 'rb_tables';
        $table = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $table_id));

        if (!$table) {
            wp_send_json_error(array('message' => __('Table not found.', 'restaurant-booking')));
            wp_die();
        }

        if (!$this->manager_can_access_location($permissions, $table->location_id)) {
            wp_send_json_error(array('message' => __('You are not allowed to manage this location.', 'restaurant-booking')));
            wp_die();
        }

        $active_location = $this->get_permissions_active_location($permissions);
        if ($active_location && $active_location !== (int) $table->location_id) {
            wp_send_json_error(array('message' => __('Please switch to the correct location before making changes.', 'restaurant-booking')));
            wp_die();
        }

        $result = $wpdb->update(
            $table_name,
            array('is_available' => $is_available ? 1 : 0),
            array('id' => $table_id)
        );

        if ($result === false) {
            wp_send_json_error(array('message' => __('Could not update the table status.', 'restaurant-booking')));
            wp_die();
        }

        wp_send_json_success(array('message' => __('Table status updated.', 'restaurant-booking')));
        wp_die();
    }

    public function handle_manager_delete_table() {
        $permissions = $this->get_current_manager_permissions();

        if (!$permissions) {
            wp_send_json_error(array('message' => __('You are not allowed to perform this action.', 'restaurant-booking')));
            wp_die();
        }

        if (!check_ajax_referer('rb_frontend_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
            wp_die();
        }

        $table_id = isset($_POST['table_id']) ? intval($_POST['table_id']) : 0;

        global $wpdb;
        $table_name = $wpdb->prefix . 'rb_tables';
        $table = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $table_id));

        if (!$table) {
            wp_send_json_error(array('message' => __('Table not found.', 'restaurant-booking')));
            wp_die();
        }

        if (!$this->manager_can_access_location($permissions, $table->location_id)) {
            wp_send_json_error(array('message' => __('You are not allowed to manage this location.', 'restaurant-booking')));
            wp_die();
        }

        $active_location = $this->get_permissions_active_location($permissions);
        if ($active_location && $active_location !== (int) $table->location_id) {
            wp_send_json_error(array('message' => __('Please switch to the correct location before making changes.', 'restaurant-booking')));
            wp_die();
        }

        $deleted = $wpdb->delete($table_name, array('id' => $table_id));

        if (!$deleted) {
            wp_send_json_error(array('message' => __('Could not delete table.', 'restaurant-booking')));
            wp_die();
        }

        wp_send_json_success(array('message' => __('Table deleted successfully.', 'restaurant-booking')));
        wp_die();
    }

    public function handle_manager_set_customer_vip() {
        $permissions = $this->get_current_manager_permissions();

        if (!$permissions) {
            wp_send_json_error(array('message' => __('You are not allowed to perform this action.', 'restaurant-booking')));
            wp_die();
        }

        if (!check_ajax_referer('rb_frontend_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
            wp_die();
        }

        $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;
        $status = isset($_POST['status']) ? intval($_POST['status']) : 0;

        if (!class_exists('RB_Customer')) {
            require_once RB_PLUGIN_DIR . 'includes/class-customer.php';
        }

        global $rb_customer;
        if (!$rb_customer) {
            $rb_customer = new RB_Customer();
        }

        global $wpdb;
        $customer_table = $wpdb->prefix . 'rb_customers';
        $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $customer_table WHERE id = %d", $customer_id));

        if (!$customer) {
            wp_send_json_error(array('message' => __('Customer not found.', 'restaurant-booking')));
            wp_die();
        }

        if (!$this->manager_can_access_location($permissions, $customer->location_id)) {
            wp_send_json_error(array('message' => __('You are not allowed to manage this customer.', 'restaurant-booking')));
            wp_die();
        }

        $active_location = $this->get_permissions_active_location($permissions);
        if ($active_location && $active_location !== (int) $customer->location_id) {
            wp_send_json_error(array('message' => __('Please switch to the correct location before making changes.', 'restaurant-booking')));
            wp_die();
        }

        $rb_customer->set_vip_status($customer_id, $status ? 1 : 0);

        wp_send_json_success(array('message' => __('Customer updated successfully.', 'restaurant-booking')));
        wp_die();
    }

    public function handle_manager_set_customer_blacklist() {
        $permissions = $this->get_current_manager_permissions();

        if (!$permissions) {
            wp_send_json_error(array('message' => __('You are not allowed to perform this action.', 'restaurant-booking')));
            wp_die();
        }

        if (!check_ajax_referer('rb_frontend_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
            wp_die();
        }

        $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;
        $status = isset($_POST['status']) ? intval($_POST['status']) : 0;

        if (!class_exists('RB_Customer')) {
            require_once RB_PLUGIN_DIR . 'includes/class-customer.php';
        }

        global $rb_customer;
        if (!$rb_customer) {
            $rb_customer = new RB_Customer();
        }

        global $wpdb;
        $customer_table = $wpdb->prefix . 'rb_customers';
        $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $customer_table WHERE id = %d", $customer_id));

        if (!$customer) {
            wp_send_json_error(array('message' => __('Customer not found.', 'restaurant-booking')));
            wp_die();
        }

        if (!$this->manager_can_access_location($permissions, $customer->location_id)) {
            wp_send_json_error(array('message' => __('You are not allowed to manage this customer.', 'restaurant-booking')));
            wp_die();
        }

        $active_location = $this->get_permissions_active_location($permissions);
        if ($active_location && $active_location !== (int) $customer->location_id) {
            wp_send_json_error(array('message' => __('Please switch to the correct location before making changes.', 'restaurant-booking')));
            wp_die();
        }

        $rb_customer->set_blacklist($customer_id, $status ? 1 : 0);

        wp_send_json_success(array('message' => __('Customer updated successfully.', 'restaurant-booking')));
        wp_die();
    }

    public function handle_manager_update_customer_note() {
        $permissions = $this->get_current_manager_permissions();

        if (!$permissions) {
            wp_send_json_error(array('message' => __('You are not allowed to perform this action.', 'restaurant-booking')));
            wp_die();
        }

        if (!check_ajax_referer('rb_frontend_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
            wp_die();
        }

        $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;
        $note = isset($_POST['note']) ? sanitize_textarea_field(wp_unslash($_POST['note'])) : '';

        if (!$customer_id) {
            wp_send_json_error(array('message' => __('Invalid customer.', 'restaurant-booking')));
            wp_die();
        }

        if (!class_exists('RB_Customer')) {
            require_once RB_PLUGIN_DIR . 'includes/class-customer.php';
        }

        global $rb_customer, $wpdb;
        if (!$rb_customer) {
            $rb_customer = new RB_Customer();
        }

        $customer_table = $wpdb->prefix . 'rb_customers';
        $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $customer_table WHERE id = %d", $customer_id));

        if (!$customer) {
            wp_send_json_error(array('message' => __('Customer not found.', 'restaurant-booking')));
            wp_die();
        }

        if (!$this->manager_can_access_location($permissions, $customer->location_id)) {
            wp_send_json_error(array('message' => __('You are not allowed to manage this customer.', 'restaurant-booking')));
            wp_die();
        }

        $active_location = $this->get_permissions_active_location($permissions);
        if ($active_location && $active_location !== (int) $customer->location_id) {
            wp_send_json_error(array('message' => __('Please switch to the correct location before making changes.', 'restaurant-booking')));
            wp_die();
        }

        $rb_customer->update_customer_notes($customer_id, $note);

        wp_send_json_success(array('message' => __('Customer note saved successfully.', 'restaurant-booking')));
        wp_die();
    }

    public function handle_manager_customer_history() {
        $permissions = $this->get_current_manager_permissions();

        if (!$permissions) {
            wp_send_json_error(array('message' => __('You are not allowed to perform this action.', 'restaurant-booking')));
            wp_die();
        }

        if (!check_ajax_referer('rb_frontend_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
            wp_die();
        }

        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';

        if ($phone === '') {
            wp_send_json_error(array('message' => __('Phone number is required.', 'restaurant-booking')));
            wp_die();
        }

        if (!class_exists('RB_Customer')) {
            require_once RB_PLUGIN_DIR . 'includes/class-customer.php';
        }

        global $rb_customer;
        if (!$rb_customer) {
            $rb_customer = new RB_Customer();
        }

        $active_location = $this->get_permissions_active_location($permissions);

        $history = $rb_customer->get_customer_history($phone, $active_location ? $active_location : null);

        $formatted = array();
        foreach ($history as $booking) {
            $formatted[] = array(
                'booking_date' => $booking->booking_date,
                'booking_time' => $booking->booking_time,
                'guest_count' => $booking->guest_count,
                'table_number' => isset($booking->table_number) ? $booking->table_number : '',
                'status' => $booking->status,
            );
        }

        wp_send_json_success(array('history' => $formatted));
        wp_die();
    }

    public function handle_manager_update_settings() {
        $permissions = $this->get_current_manager_permissions();

        if (!$permissions) {
            wp_send_json_error(array('message' => __('You are not allowed to perform this action.', 'restaurant-booking')));
            wp_die();
        }

        if (!check_ajax_referer('rb_frontend_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
            wp_die();
        }

        $location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;

        if (!$this->manager_can_access_location($permissions, $location_id)) {
            wp_send_json_error(array('message' => __('You are not allowed to manage this location.', 'restaurant-booking')));
            wp_die();
        }

        $active_location = $this->get_permissions_active_location($permissions);
        if ($active_location && $active_location !== $location_id) {
            wp_send_json_error(array('message' => __('Please switch to the correct location before making changes.', 'restaurant-booking')));
            wp_die();
        }

        if (!class_exists('RB_Location')) {
            require_once RB_PLUGIN_DIR . 'includes/class-location.php';
        }

        global $rb_location;
        if (!$rb_location) {
            $rb_location = new RB_Location();
        }

        $settings_input = isset($_POST['location_settings']) ? (array) wp_unslash($_POST['location_settings']) : array();

        $data = array(
            'hotline' => isset($settings_input['hotline']) ? sanitize_text_field($settings_input['hotline']) : '',
            'email' => isset($settings_input['email']) ? sanitize_email($settings_input['email']) : '',
            'address' => isset($settings_input['address']) ? sanitize_text_field($settings_input['address']) : '',
            'opening_time' => isset($settings_input['opening_time']) ? sanitize_text_field($settings_input['opening_time']) : '',
            'closing_time' => isset($settings_input['closing_time']) ? sanitize_text_field($settings_input['closing_time']) : '',
            'time_slot_interval' => isset($settings_input['time_slot_interval']) ? intval($settings_input['time_slot_interval']) : 30,
            'booking_buffer_time' => isset($settings_input['booking_buffer_time']) ? intval($settings_input['booking_buffer_time']) : 0,
            'min_advance_booking' => isset($settings_input['min_advance_booking']) ? intval($settings_input['min_advance_booking']) : 0,
            'max_advance_booking' => isset($settings_input['max_advance_booking']) ? intval($settings_input['max_advance_booking']) : 30,
            'max_guests_per_booking' => isset($settings_input['max_guests_per_booking']) ? intval($settings_input['max_guests_per_booking']) : 20,
            'shift_notes' => isset($settings_input['shift_notes']) ? sanitize_textarea_field($settings_input['shift_notes']) : '',
            'working_hours_mode' => isset($settings_input['working_hours_mode']) && $settings_input['working_hours_mode'] === 'advanced' ? 'advanced' : 'simple',
            'lunch_break_enabled' => !empty($settings_input['lunch_break_enabled']) ? 'yes' : 'no',
            'lunch_break_start' => isset($settings_input['lunch_break_start']) ? sanitize_text_field($settings_input['lunch_break_start']) : '',
            'lunch_break_end' => isset($settings_input['lunch_break_end']) ? sanitize_text_field($settings_input['lunch_break_end']) : '',
            'morning_shift_start' => isset($settings_input['morning_shift_start']) ? sanitize_text_field($settings_input['morning_shift_start']) : '',
            'morning_shift_end' => isset($settings_input['morning_shift_end']) ? sanitize_text_field($settings_input['morning_shift_end']) : '',
            'evening_shift_start' => isset($settings_input['evening_shift_start']) ? sanitize_text_field($settings_input['evening_shift_start']) : '',
            'evening_shift_end' => isset($settings_input['evening_shift_end']) ? sanitize_text_field($settings_input['evening_shift_end']) : '',
            'auto_confirm_enabled' => !empty($settings_input['auto_confirm_enabled']) ? 'yes' : 'no',
            'require_deposit' => !empty($settings_input['require_deposit']) ? 'yes' : 'no',
            'deposit_amount' => isset($settings_input['deposit_amount']) ? intval($settings_input['deposit_amount']) : 0,
            'deposit_for_guests' => isset($settings_input['deposit_for_guests']) ? intval($settings_input['deposit_for_guests']) : 0,
            'admin_email' => isset($settings_input['admin_email']) ? sanitize_email($settings_input['admin_email']) : '',
            'enable_email' => !empty($settings_input['enable_email']) ? 'yes' : 'no',
            'enable_sms' => !empty($settings_input['enable_sms']) ? 'yes' : 'no',
            'sms_api_key' => isset($settings_input['sms_api_key']) ? sanitize_text_field($settings_input['sms_api_key']) : '',
            'reminder_hours_before' => isset($settings_input['reminder_hours_before']) ? intval($settings_input['reminder_hours_before']) : 24,
            'special_closed_dates' => isset($settings_input['special_closed_dates']) ? sanitize_textarea_field($settings_input['special_closed_dates']) : '',
            'cancellation_hours' => isset($settings_input['cancellation_hours']) ? intval($settings_input['cancellation_hours']) : 2,
            'weekend_enabled' => !empty($settings_input['weekend_enabled']) ? 'yes' : 'no',
            'no_show_auto_blacklist' => isset($settings_input['no_show_auto_blacklist']) ? intval($settings_input['no_show_auto_blacklist']) : 0,
            'languages' => isset($settings_input['languages']) ? array_map('sanitize_text_field', (array) $settings_input['languages']) : array(),
        );

        if (!empty($data['email']) && !is_email($data['email'])) {
            wp_send_json_error(array('message' => __('Please enter a valid email address.', 'restaurant-booking')));
            wp_die();
        }

        if (!empty($data['admin_email']) && !is_email($data['admin_email'])) {
            wp_send_json_error(array('message' => __('Please enter a valid notification email.', 'restaurant-booking')));
            wp_die();
        }

        $updated = $rb_location->update_settings($location_id, $data);

        if ($updated === false) {
            wp_send_json_error(array('message' => __('Could not save settings. Please try again.', 'restaurant-booking')));
            wp_die();
        }

        wp_send_json_success(array('message' => __('Settings saved successfully.', 'restaurant-booking')));
        wp_die();
    }
}
