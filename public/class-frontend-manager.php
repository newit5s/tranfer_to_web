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
        if (class_exists('RB_Portal_Account_Manager')) {
            $this->portal_account_manager = RB_Portal_Account_Manager::get_instance();
            $this->portal_session = new RB_Portal_Session_Manager();
        }
    }

    private function init_ajax_handlers() {
        add_action('wp_ajax_rb_manager_update_booking', array($this, 'handle_manager_update_booking'));
        add_action('wp_ajax_nopriv_rb_manager_update_booking', array($this, 'handle_manager_update_booking'));
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
        $location_id = isset($_POST['rb_location_id']) ? intval($_POST['rb_location_id']) : 0;

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
            $location_id,
            $locations,
            $allowed_locations,
            isset($account->last_location_id) ? (int) $account->last_location_id : 0
        );

        if (!$resolved_location) {
            $this->manager_login_error = __('The selected location is not available for this account.', 'restaurant-booking');
            return;
        }

        $this->portal_session->start_session($account->id);
        $this->portal_account_manager->set_active_location($account->id, $resolved_location);
        $account->last_location_id = $resolved_location;
        $account->locations = $allowed_locations;
        $this->portal_account = $account;

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

        $this->portal_account_manager->set_active_location($account->id, $selected_location_id);
        $account->last_location_id = $selected_location_id;
        $this->portal_account = $account;
        $manager_name = !empty($account->display_name) ? $account->display_name : $account->username;

        $locations = $filtered_locations;

        global $rb_booking;
        if (!$rb_booking) {
            require_once RB_PLUGIN_DIR . 'includes/class-booking.php';
            $rb_booking = new RB_Booking();
        }

        $bookings = $rb_booking->get_bookings(array(
            'location_id' => $selected_location_id,
            'orderby' => 'booking_date',
            'order' => 'ASC',
            'limit' => 100
        ));

        $location_lookup = wp_list_pluck($locations, null, 'id');
        $active_location = isset($location_lookup[$selected_location_id]) ? $location_lookup[$selected_location_id] : $locations[0];

        ob_start();
        ?>
        <div class="rb-manager" data-location="<?php echo esc_attr($selected_location_id); ?>">
            <div class="rb-manager-header">
                <h2><?php echo esc_html($atts['title']); ?></h2>
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

            <div class="rb-manager-location-switcher">
                <form method="get">
                    <?php if (!empty($_GET)) : ?>
                        <?php foreach ($_GET as $key => $value) : ?>
                            <?php if ($key === 'location_id') { continue; } ?>
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

            <div class="rb-manager-location-info">
                <p><strong><?php esc_html_e('Hotline:', 'restaurant-booking'); ?></strong> <?php echo esc_html($active_location['hotline']); ?></p>
                <p><strong><?php esc_html_e('Email:', 'restaurant-booking'); ?></strong> <?php echo esc_html($active_location['email']); ?></p>
                <p><strong><?php esc_html_e('Address:', 'restaurant-booking'); ?></strong> <?php echo esc_html($active_location['address']); ?></p>
            </div>

            <div class="rb-manager-bookings">
                <h3><?php esc_html_e('Upcoming reservations', 'restaurant-booking'); ?></h3>
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
                                <th><?php esc_html_e('Status', 'restaurant-booking'); ?></th>
                                <th><?php esc_html_e('Actions', 'restaurant-booking'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($bookings)) : ?>
                                <?php foreach ($bookings as $booking) : ?>
                                    <tr data-booking-id="<?php echo esc_attr($booking->id); ?>">
                                        <td>#<?php echo esc_html(str_pad($booking->id, 5, '0', STR_PAD_LEFT)); ?></td>
                                        <td>
                                            <strong><?php echo esc_html($booking->customer_name); ?></strong>
                                            <?php if (!empty($booking->special_requests)) : ?>
                                                <div class="rb-manager-note"><?php echo esc_html($booking->special_requests); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div><?php echo esc_html($booking->customer_phone); ?></div>
                                            <div><?php echo esc_html($booking->customer_email); ?></div>
                                        </td>
                                        <td><?php echo esc_html(date_i18n(get_option('date_format', 'd/m/Y'), strtotime($booking->booking_date))); ?></td>
                                        <td><?php echo esc_html($booking->booking_time); ?></td>
                                        <td><?php echo esc_html($booking->guest_count); ?></td>
                                        <td>
                                            <span class="rb-status rb-status-<?php echo esc_attr($booking->status); ?>">
                                                <?php echo esc_html(ucwords(str_replace('-', ' ', $booking->status))); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (in_array($booking->status, array('pending', 'confirmed'), true)) : ?>
                                                <button class="rb-btn-success rb-manager-action" data-action="confirm" data-id="<?php echo esc_attr($booking->id); ?>"><?php esc_html_e('Confirm', 'restaurant-booking'); ?></button>
                                                <button class="rb-btn-danger rb-manager-action" data-action="cancel" data-id="<?php echo esc_attr($booking->id); ?>"><?php esc_html_e('Cancel', 'restaurant-booking'); ?></button>
                                            <?php elseif ($booking->status === 'completed') : ?>
                                                <em><?php esc_html_e('Completed', 'restaurant-booking'); ?></em>
                                            <?php else : ?>
                                                <em><?php esc_html_e('No actions available', 'restaurant-booking'); ?></em>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <tr>
                                    <td colspan="8" class="rb-manager-empty"><?php esc_html_e('No reservations found for this location.', 'restaurant-booking'); ?></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div id="rb-manager-feedback" class="rb-portal-result" hidden></div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_manager_login($atts, $locations) {
        $error = $this->manager_login_error;

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
                <div class="rb-form-group">
                    <label for="rb-manager-location"><?php esc_html_e('Location', 'restaurant-booking'); ?></label>
                    <select id="rb-manager-location" name="rb_location_id">
                        <?php foreach ($locations as $location) : ?>
                            <option value="<?php echo esc_attr($location['id']); ?>"><?php echo esc_html($location['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
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
            default:
                $result = new WP_Error('rb_invalid_action', __('Unsupported action', 'restaurant-booking'));
        }

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        } else {
            wp_send_json_success(array('message' => __('Booking updated successfully.', 'restaurant-booking')));
        }

        wp_die();
    }
}
