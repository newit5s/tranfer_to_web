<?php
/**
 * Frontend Class - Xử lý hiển thị frontend và shortcode
 */

if (!defined('ABSPATH')) {
    exit;
}

class RB_Frontend {

    private $location_helper;
    private $portal_account_manager;
    private $portal_session;
    private $portal_account = null;

    public function __construct() {
        $this->init_location_helper();
        $this->init_portal_account_support();
        $this->init_ajax_handlers();
        add_action('init', array($this, 'maybe_handle_email_confirmation'));
    }

    private function init_location_helper() {
        global $rb_location;

        if (!$rb_location) {
            require_once RB_PLUGIN_DIR . 'includes/class-location.php';
            $rb_location = new RB_Location();
        }

        $this->location_helper = $rb_location;
    }

    private function init_portal_account_support() {
        if (class_exists('RB_Portal_Account_Manager')) {
            $this->portal_account_manager = RB_Portal_Account_Manager::get_instance();
            $this->portal_session = new RB_Portal_Session_Manager();
        }
    }
    
    private function init_ajax_handlers() {
        add_action('wp_ajax_rb_submit_booking', array($this, 'handle_booking_submission'));
        add_action('wp_ajax_nopriv_rb_submit_booking', array($this, 'handle_booking_submission'));

        add_action('wp_ajax_rb_check_availability', array($this, 'check_availability'));
        add_action('wp_ajax_nopriv_rb_check_availability', array($this, 'check_availability'));

        add_action('wp_ajax_rb_get_time_slots', array($this, 'get_time_slots'));
        add_action('wp_ajax_nopriv_rb_get_time_slots', array($this, 'get_time_slots'));

        add_action('wp_ajax_rb_manager_update_booking', array($this, 'handle_manager_update_booking'));
        add_action('wp_ajax_nopriv_rb_manager_update_booking', array($this, 'handle_manager_update_booking'));
    }

    private function get_manager_allowed_location_ids($user_id) {
        $assigned = get_user_meta($user_id, 'rb_manager_locations', true);

        if (empty($assigned)) {
            return array();
        }

        if (!is_array($assigned)) {
            $assigned = array($assigned);
        }

        $assigned = array_map('intval', $assigned);

        return array_values(array_filter($assigned));
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

    private function resolve_manager_location($user_id, $requested_location_id, $locations, $allowed_override = null, $fallback_override = null) {
        $allowed_ids = is_array($allowed_override) ? array_map('intval', $allowed_override) : $this->get_manager_allowed_location_ids($user_id);
        $fallback = !is_null($fallback_override) ? (int) $fallback_override : (int) get_user_meta($user_id, 'rb_active_location', true);

        return $this->resolve_location_from_allowed($requested_location_id, $locations, $allowed_ids, $fallback);
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
        if (is_user_logged_in()) {
            $user = wp_get_current_user();

            if ($user && $user->has_cap('rb_manage_location')) {
                $allowed = $this->get_manager_allowed_location_ids($user->ID);

                return array(
                    'type' => 'wp',
                    'user' => $user,
                    'allowed_locations' => $allowed,
                );
            }
        }

        $account = $this->get_portal_session_account();

        if ($account) {
            $allowed = !empty($account->locations) ? array_map('intval', (array) $account->locations) : array();

            return array(
                'type' => 'portal',
                'account' => $account,
                'allowed_locations' => $allowed,
            );
        }

        return null;
    }

    public function maybe_handle_email_confirmation() {
        if (!isset($_GET['rb_confirm_token'])) {
            return;
        }

        $token = sanitize_text_field(wp_unslash($_GET['rb_confirm_token']));

        global $rb_booking;

        if (!$rb_booking) {
            require_once RB_PLUGIN_DIR . 'includes/class-booking.php';
            $rb_booking = new RB_Booking();
        }

        $result = $rb_booking->confirm_booking_by_token($token);

        $redirect_url = apply_filters('rb_confirmation_redirect_url', home_url('/'));

        if (is_wp_error($result)) {
            $redirect_url = add_query_arg(array(
                'rb_confirmation' => 'error',
                'rb_message' => rawurlencode($result->get_error_message()),
            ), $redirect_url);
        } else {
            $redirect_url = add_query_arg(array(
                'rb_confirmation' => 'success'
            ), $redirect_url);
        }

        wp_safe_redirect($redirect_url);
        exit;
    }
    
    public function render_booking_form($atts) {
        $atts = shortcode_atts(array(
            'title' => rb_t('book_now'),
            'button_text' => rb_t('book_now'),
            'show_button' => 'yes'
        ), $atts, 'restaurant_booking');

        $locations = $this->get_locations_data();

        if (empty($locations)) {
            return '<div class="rb-alert rb-no-location">' . esc_html__('Please configure at least one restaurant location before displaying the booking form.', 'restaurant-booking') . '</div>';
        }

        $default_location = $locations[0];
        $default_location_id = (int) $default_location['id'];
        $current_language = rb_get_current_language();

        $settings = get_option('rb_settings', array(
            'opening_time' => '09:00',
            'closing_time' => '22:00',
            'time_slot_interval' => 30,
            'min_advance_booking' => 2,
            'max_advance_booking' => 30
        ));

        $opening_time = isset($settings['opening_time']) ? $settings['opening_time'] : '09:00';
        $closing_time = isset($settings['closing_time']) ? $settings['closing_time'] : '22:00';
        $time_interval = isset($settings['time_slot_interval']) ? intval($settings['time_slot_interval']) : 30;

        // ✅ FIX: Define min_date and max_date
        $min_hours = isset($settings['min_advance_booking']) ? intval($settings['min_advance_booking']) : 2;
        $max_days = isset($settings['max_advance_booking']) ? intval($settings['max_advance_booking']) : 30;

        $min_date = date('Y-m-d', strtotime('+' . $min_hours . ' hours'));
        $max_date = date('Y-m-d', strtotime('+' . $max_days . ' days'));

        $time_slots = $this->generate_time_slots($opening_time, $closing_time, $time_interval);

        ob_start();
        ?>
        <div class="rb-booking-widget">
            <?php if ($atts['show_button'] === 'yes') : ?>
                <button type="button" class="rb-open-modal-btn">
                    <?php echo esc_html($atts['button_text']); ?>
                </button>
            <?php endif; ?>

            <div id="rb-booking-modal" class="rb-modal">
                <div class="rb-modal-content">
                    <span class="rb-close">&times;</span>

                    <!-- ✅ THÊM LANGUAGE SWITCHER -->
                    <div class="rb-modal-header">
                        <h2><?php echo esc_html($atts['title']); ?></h2>

                        <div class="rb-modal-language-switcher">
                            <?php 
                            if (class_exists('RB_Language_Switcher')) {
                                $switcher = new RB_Language_Switcher();
                                $switcher->render_dropdown();
                            }
                            ?>
                        </div>
                    </div>

                    <form id="rb-booking-form" class="rb-form">
                        <?php wp_nonce_field('rb_booking_nonce', 'rb_nonce'); ?>
                        <input type="hidden" name="location_id" value="<?php echo esc_attr($default_location_id); ?>">
                        <input type="hidden" name="language" value="<?php echo esc_attr($current_language); ?>">

                        <div class="rb-form-row">
                            <div class="rb-form-group">
                                <label for="rb_customer_name">
                                    <?php rb_e('full_name'); ?> *
                                </label>
                                <input type="text" id="rb_customer_name" name="customer_name" required>
                            </div>

                            <div class="rb-form-group">
                                <label for="rb_customer_phone">
                                    <?php rb_e('phone_number'); ?> *
                                </label>
                                <input type="tel" id="rb_customer_phone" name="customer_phone" required>
                            </div>
                        </div>

                        <div class="rb-form-row">
                            <div class="rb-form-group">
                                <label for="rb_customer_email">
                                    <?php rb_e('email'); ?> *
                                </label>
                                <input type="email" id="rb_customer_email" name="customer_email" required>
                            </div>

                            <div class="rb-form-group">
                                <label for="rb_guest_count">
                                    <?php rb_e('number_of_guests'); ?> *
                                </label>
                                <select id="rb_guest_count" name="guest_count" required>
                                    <?php for ($i = 1; $i <= 20; $i++) : ?>
                                        <option value="<?php echo $i; ?>">
                                            <?php echo $i; ?> <?php rb_e('people'); ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>

                        <div class="rb-form-row">
                            <div class="rb-form-group">
                                <label for="rb_booking_date">
                                    <?php rb_e('booking_date'); ?> *
                                </label>
                                <input type="date" id="rb_booking_date" name="booking_date" 
                                    min="<?php echo $min_date; ?>" 
                                    max="<?php echo $max_date; ?>" required>
                            </div>

                            <div class="rb-form-group">
                                <label for="rb_booking_time">
                                    <?php rb_e('booking_time'); ?> *
                                </label>
                                <select id="rb_booking_time" name="booking_time" required>
                                    <option value=""><?php rb_e('select_time'); ?></option>
                                    <?php if (!empty($time_slots)) : ?>
                                        <?php foreach ($time_slots as $slot) : ?>
                                            <option value="<?php echo esc_attr($slot); ?>">
                                                <?php echo esc_html($slot); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>

                        <div class="rb-form-group">
                            <label for="rb_special_requests">
                                <?php rb_e('special_requests'); ?>
                            </label>
                            <textarea id="rb_special_requests" name="special_requests" rows="3"></textarea>
                        </div>

                        <div class="rb-form-actions">
                            <button type="submit" class="rb-btn-primary">
                                <?php rb_e('confirm_booking'); ?>
                            </button>
                            <button type="button" class="rb-btn-cancel rb-close-modal">
                                <?php rb_e('cancel'); ?>
                            </button>
                        </div>

                        <div id="rb-form-message"></div>
                    </form>
                </div>
            </div>

            <!-- INLINE FORM -->
            <?php if ($atts['show_button'] === 'no') : ?>
                <div class="rb-inline-form">
                    <!-- ✅ THÊM LANGUAGE SWITCHER -->
                    <div class="rb-inline-header">
                        <h3><?php echo esc_html($atts['title']); ?></h3>

                        <div class="rb-inline-language-switcher">
                            <?php 
                            if (class_exists('RB_Language_Switcher')) {
                                $switcher = new RB_Language_Switcher();
                                $switcher->render_dropdown();
                            }
                            ?>
                        </div>
                    </div>

                    <form id="rb-booking-form-inline" class="rb-form">
                        <?php wp_nonce_field('rb_booking_nonce', 'rb_nonce_inline'); ?>
                        <input type="hidden" name="location_id" value="<?php echo esc_attr($default_location_id); ?>">
                        <input type="hidden" name="language" value="<?php echo esc_attr($current_language); ?>">

                        <div class="rb-form-grid">
                            <div class="rb-form-group">
                                <label for="rb_name_inline"><?php rb_e('full_name'); ?> *</label>
                                <input type="text" id="rb_name_inline" name="customer_name" required>
                            </div>

                            <div class="rb-form-group">
                                <label for="rb_phone_inline"><?php rb_e('phone_number'); ?> *</label>
                                <input type="tel" id="rb_phone_inline" name="customer_phone" required>
                            </div>

                            <div class="rb-form-group">
                                <label for="rb_email_inline"><?php rb_e('email'); ?> *</label>
                                <input type="email" id="rb_email_inline" name="customer_email" required>
                            </div>

                            <div class="rb-form-group">
                                <label for="rb_guests_inline"><?php rb_e('number_of_guests'); ?> *</label>
                                <select id="rb_guests_inline" name="guest_count" required>
                                    <?php for ($i = 1; $i <= 20; $i++) : ?>
                                        <option value="<?php echo $i; ?>"><?php echo $i; ?> <?php rb_e('people'); ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>

                            <div class="rb-form-group">
                                <label for="rb_date_inline"><?php rb_e('booking_date'); ?> *</label>
                                <input type="date" id="rb_date_inline" name="booking_date" 
                                    min="<?php echo $min_date; ?>"
                                    max="<?php echo $max_date; ?>" required>
                            </div>

                            <div class="rb-form-group">
                                <label for="rb_time_inline"><?php rb_e('booking_time'); ?> *</label>
                                <select id="rb_time_inline" name="booking_time" required>
                                    <option value=""><?php rb_e('select_time'); ?></option>
                                    <?php if (!empty($time_slots)) : ?>
                                        <?php foreach ($time_slots as $slot) : ?>
                                            <option value="<?php echo esc_attr($slot); ?>">
                                                <?php echo esc_html($slot); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>

                        <div class="rb-form-group">
                            <label for="rb_requests_inline"><?php rb_e('special_requests'); ?></label>
                            <textarea id="rb_requests_inline" name="special_requests" rows="3"></textarea>
                        </div>

                        <button type="submit" class="rb-btn-primary">
                            <?php rb_e('book_now'); ?>
                        </button>

                        <div id="rb-form-message-inline"></div>
                    </form>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function get_locations_data() {
        if (!$this->location_helper) {
            return array();
        }

        $locations = $this->location_helper->all();
        $data = array();

        foreach ($locations as $location) {
            $data[] = array(
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

        return $data;
    }

    private function get_location_details($location_id) {
        if (!$this->location_helper) {
            return array();
        }

        $location = $this->location_helper->get($location_id);

        if (!$location) {
            return array();
        }

        return array(
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

    public function render_multi_location_portal($atts) {
        $default_title = rb_t('reserve_your_table', __('Reserve Your Table', 'restaurant-booking'));
        $atts = shortcode_atts(array(
            'title' => $default_title,
            'show_language_selector' => 'yes'
        ), $atts, 'restaurant_booking_portal');

        $locations = $this->get_locations_data();

        if (empty($locations)) {
            return '<div class="rb-portal rb-alert">' . esc_html(rb_t('locations_not_configured', __('Locations are not configured yet.', 'restaurant-booking'))) . '</div>';
        }

        $default_location = $locations[0];
        $languages = array(
            'vi' => rb_t('language_vietnamese', __('Vietnamese', 'restaurant-booking')),
            'en' => rb_t('language_english', __('English', 'restaurant-booking')),
            'ja' => rb_t('language_japanese', __('Japanese', 'restaurant-booking')),
        );

        $confirmation_state = isset($_GET['rb_confirmation']) ? sanitize_text_field(wp_unslash($_GET['rb_confirmation'])) : '';
        $confirmation_message = '';
        if (isset($_GET['rb_message'])) {
            $confirmation_message = sanitize_text_field(rawurldecode(wp_unslash($_GET['rb_message'])));
        }

        ob_start();
        ?>
        <div class="rb-portal" data-default-location="<?php echo esc_attr($default_location['id']); ?>">
            <div class="rb-portal-header">
                <h2><?php echo esc_html($atts['title']); ?></h2>
            </div>

            <?php if (!empty($confirmation_state)) : ?>
                <div class="rb-portal-notice <?php echo $confirmation_state === 'success' ? 'success' : 'error'; ?>">
                    <?php
                    if ($confirmation_state === 'success') {
                        echo esc_html(rb_t('reservation_confirmed_notice', __('Your reservation has been confirmed. We look forward to serving you!', 'restaurant-booking')));
                    } else {
                        $fallback_message = rb_t('reservation_confirmed_error', __('We could not confirm your reservation. Please contact the restaurant.', 'restaurant-booking'));
                        echo esc_html($confirmation_message ? $confirmation_message : $fallback_message);
                    }
                    ?>
                </div>
            <?php endif; ?>

            <div class="rb-portal-step rb-portal-step-start" data-step="start">
                <p><?php echo esc_html(rb_t('portal_start_prompt', __('Ready to make a reservation? Start by choosing your language.', 'restaurant-booking'))); ?></p>
                <div class="rb-portal-actions">
                    <button type="button" class="rb-btn-primary" id="rb-portal-start">
                        <?php echo esc_html(rb_t('book_a_table', __('Book a table', 'restaurant-booking'))); ?>
                    </button>
                </div>
            </div>

            <div class="rb-portal-step rb-portal-step-language" data-step="1" hidden>
                <h3><?php echo esc_html(rb_t('select_language', __('Select language', 'restaurant-booking'))); ?></h3>
                <form id="rb-portal-language-form">
                    <div class="rb-portal-language-options">
                        <?php foreach ($languages as $code => $label) : ?>
                            <label class="rb-portal-language-option">
                                <input type="radio" name="language" value="<?php echo esc_attr($code); ?>"
                                    <?php checked($code, rb_get_current_language()); ?> />
                                <span><?php echo esc_html($label); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="rb-portal-actions">
                        <button type="button" class="rb-btn-secondary" id="rb-portal-back-to-start">
                            <?php echo esc_html(rb_t('back', __('Back', 'restaurant-booking'))); ?>
                        </button>
                        <button type="submit" class="rb-btn-primary">
                            <?php echo esc_html(rb_t('continue', __('Continue', 'restaurant-booking'))); ?>
                        </button>
                    </div>
                </form>
            </div>

            <div class="rb-portal-step rb-portal-step-availability" data-step="2" hidden>
                <h3><?php echo esc_html(rb_t('check_availability', __('Check availability', 'restaurant-booking'))); ?></h3>
                <form id="rb-portal-availability-form">
                    <div class="rb-form-group">
                        <label for="rb-portal-location"><?php echo esc_html(rb_t('location', __('Location', 'restaurant-booking'))); ?> *</label>
                        <select id="rb-portal-location" name="location_id" required>
                            <?php foreach ($locations as $location) : ?>
                                <option value="<?php echo esc_attr($location['id']); ?>">
                                    <?php echo esc_html($location['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <input type="hidden" name="language" id="rb-portal-language-selected" value="<?php echo esc_attr(rb_get_current_language()); ?>" />

                    <div class="rb-form-row">
                        <div class="rb-form-group">
                            <label for="rb-portal-date"><?php echo esc_html(rb_t('booking_date', __('Booking date', 'restaurant-booking'))); ?> *</label>
                            <input type="date" id="rb-portal-date" name="booking_date" required />
                        </div>
                        <div class="rb-form-group">
                            <label for="rb-portal-time"><?php echo esc_html(rb_t('booking_time', __('Booking time', 'restaurant-booking'))); ?> *</label>
                            <input type="time" id="rb-portal-time" name="booking_time" required />
                        </div>
                        <div class="rb-form-group">
                            <label for="rb-portal-guests"><?php echo esc_html(rb_t('guests', __('Guests', 'restaurant-booking'))); ?> *</label>
                            <select id="rb-portal-guests" name="guest_count" required>
                                <?php for ($i = 1; $i <= 20; $i++) : ?>
                                    <option value="<?php echo esc_attr($i); ?>"><?php echo esc_html($i); ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>

                    <div class="rb-portal-actions">
                        <button type="button" class="rb-btn-secondary" id="rb-portal-back-to-language">
                            <?php echo esc_html(rb_t('back', __('Back', 'restaurant-booking'))); ?>
                        </button>
                        <button type="submit" class="rb-btn-primary" id="rb-portal-check-availability">
                            <?php echo esc_html(rb_t('check_availability', __('Check availability', 'restaurant-booking'))); ?>
                        </button>
                    </div>

                    <div id="rb-portal-availability-result" class="rb-portal-result" hidden></div>
                    <div id="rb-portal-suggestions" class="rb-portal-suggestions" hidden>
                        <p><?php echo esc_html(rb_t('suggested_time_slots', __('Suggested time slots within ±30 minutes:', 'restaurant-booking'))); ?></p>
                        <div class="rb-portal-suggestion-list"></div>
                    </div>

                    <div class="rb-portal-actions" id="rb-portal-availability-continue" hidden>
                        <button type="button" class="rb-btn-success" id="rb-portal-go-to-details">
                            <?php echo esc_html(rb_t('continue_to_details', __('Continue to reservation details', 'restaurant-booking'))); ?>
                        </button>
                    </div>
                </form>
            </div>

            <div class="rb-portal-step rb-portal-step-details" data-step="3" hidden>
                <h3><?php echo esc_html(rb_t('reservation_details_heading', __('Your reservation details', 'restaurant-booking'))); ?></h3>
                <form id="rb-portal-details-form">
                    <?php wp_nonce_field('rb_booking_nonce', 'rb_nonce_portal'); ?>
                    <input type="hidden" name="location_id" id="rb-portal-location-hidden" value="<?php echo esc_attr($default_location['id']); ?>" />
                    <input type="hidden" name="language" id="rb-portal-language-hidden" value="<?php echo esc_attr(rb_get_current_language()); ?>" />
                    <input type="hidden" name="booking_date" id="rb-portal-date-hidden" />
                    <input type="hidden" name="booking_time" id="rb-portal-time-hidden" />
                    <input type="hidden" name="guest_count" id="rb-portal-guests-hidden" />

                    <div class="rb-form-row">
                        <div class="rb-form-group">
                            <label for="rb-portal-name"><?php echo esc_html(rb_t('full_name', __('Full name', 'restaurant-booking'))); ?> *</label>
                            <input type="text" id="rb-portal-name" name="customer_name" required />
                        </div>
                        <div class="rb-form-group">
                            <label for="rb-portal-phone"><?php echo esc_html(rb_t('phone_number', __('Phone number', 'restaurant-booking'))); ?> *</label>
                            <input type="tel" id="rb-portal-phone" name="customer_phone" pattern="[0-9]{8,15}" required />
                        </div>
                    </div>

                    <div class="rb-form-row">
                        <div class="rb-form-group">
                            <label for="rb-portal-email"><?php echo esc_html(rb_t('email', __('Email', 'restaurant-booking'))); ?> *</label>
                            <input type="email" id="rb-portal-email" name="customer_email" required />
                            <small class="rb-portal-email-note">
                                <?php echo esc_html(rb_t('confirmation_email_note', __('A confirmation link will be sent to this email. If you do not have an email address, please call the hotline of your selected location to reserve.', 'restaurant-booking'))); ?>
                                <strong id="rb-portal-hotline-note"></strong>
                            </small>
                        </div>
                        <div class="rb-form-group">
                            <label for="rb-portal-special"><?php echo esc_html(rb_t('special_requests', __('Special requests', 'restaurant-booking'))); ?></label>
                            <textarea id="rb-portal-special" name="special_requests" rows="3"></textarea>
                        </div>
                    </div>

                    <div class="rb-portal-location-summary">
                        <h4><?php echo esc_html(rb_t('location_information', __('Location information', 'restaurant-booking'))); ?></h4>
                        <p id="rb-portal-location-address"></p>
                        <p>
                            <?php echo esc_html(rb_t('hotline_label', __('Hotline:', 'restaurant-booking'))); ?>
                            <span id="rb-portal-location-hotline"></span>
                        </p>
                    </div>

                    <div class="rb-portal-actions">
                        <button type="button" class="rb-btn-secondary" id="rb-portal-back-to-availability">
                            <?php echo esc_html(rb_t('back', __('Back', 'restaurant-booking'))); ?>
                        </button>
                        <button type="submit" class="rb-btn-primary">
                            <?php echo esc_html(rb_t('submit_reservation', __('Submit reservation', 'restaurant-booking'))); ?>
                        </button>
                    </div>

                    <div id="rb-portal-details-message" class="rb-portal-result" hidden></div>
                </form>
            </div>
        </div>
        <?php
        return ob_get_clean();
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

            if (is_user_logged_in()) {
                wp_logout();
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

        if ($manager_permissions['type'] === 'wp') {
            /** @var WP_User $user */
            $user = $manager_permissions['user'];
            $fallback_location = (int) get_user_meta($user->ID, 'rb_active_location', true);
            $selected_location_id = $this->resolve_location_from_allowed($selected_location_id, $filtered_locations, $allowed_location_ids, $fallback_location);

            if (!$selected_location_id) {
                return '<div class="rb-manager rb-alert">' . esc_html__('Selected location is no longer available. Please contact an administrator.', 'restaurant-booking') . '</div>';
            }

            update_user_meta($user->ID, 'rb_active_location', $selected_location_id);
            $manager_name = $user->display_name ? $user->display_name : $user->user_login;
        } else {
            $account = $manager_permissions['account'];

            if (empty($allowed_location_ids)) {
                return '<div class="rb-manager rb-alert">' . esc_html__('This account is not assigned to any locations. Please contact an administrator.', 'restaurant-booking') . '</div>';
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
        }

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
                                        <td class="rb-manager-actions">
                                            <?php if ($booking->status === 'pending') : ?>
                                                <button class="rb-btn-success rb-manager-action" data-action="confirm" data-id="<?php echo esc_attr($booking->id); ?>"><?php esc_html_e('Confirm', 'restaurant-booking'); ?></button>
                                                <button class="rb-btn-danger rb-manager-action" data-action="cancel" data-id="<?php echo esc_attr($booking->id); ?>"><?php esc_html_e('Cancel', 'restaurant-booking'); ?></button>
                                            <?php elseif ($booking->status === 'confirmed') : ?>
                                                <button class="rb-btn-info rb-manager-action" data-action="complete" data-id="<?php echo esc_attr($booking->id); ?>"><?php esc_html_e('Complete', 'restaurant-booking'); ?></button>
                                                <button class="rb-btn-danger rb-manager-action" data-action="cancel" data-id="<?php echo esc_attr($booking->id); ?>"><?php esc_html_e('Cancel', 'restaurant-booking'); ?></button>
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
    
    private function generate_time_slots($start = null, $end = null, $interval = null) {
        $settings = get_option('rb_settings', array());
        
        $mode = isset($settings['working_hours_mode']) ? $settings['working_hours_mode'] : 'simple';
        $interval = $interval ?: (isset($settings['time_slot_interval']) ? intval($settings['time_slot_interval']) : 30);
        $buffer = isset($settings['booking_buffer_time']) ? intval($settings['booking_buffer_time']) : 0;
        
        $slots = array();
        
        if ($mode === 'advanced') {
            // Advanced mode: 2 shifts
            $morning_start = isset($settings['morning_shift_start']) ? $settings['morning_shift_start'] : '09:00';
            $morning_end = isset($settings['morning_shift_end']) ? $settings['morning_shift_end'] : '14:00';
            $evening_start = isset($settings['evening_shift_start']) ? $settings['evening_shift_start'] : '17:00';
            $evening_end = isset($settings['evening_shift_end']) ? $settings['evening_shift_end'] : '22:00';
            
            // Morning shift
            $slots = array_merge($slots, $this->generate_shift_slots($morning_start, $morning_end, $interval, $buffer));
            
            // Evening shift
            $slots = array_merge($slots, $this->generate_shift_slots($evening_start, $evening_end, $interval, $buffer));
            
        } else {
            // Simple mode
            $start = $start ?: (isset($settings['opening_time']) ? $settings['opening_time'] : '09:00');
            $end = $end ?: (isset($settings['closing_time']) ? $settings['closing_time'] : '22:00');
            
            $has_lunch_break = isset($settings['lunch_break_enabled']) && $settings['lunch_break_enabled'] === 'yes';
            
            if ($has_lunch_break) {
                $lunch_start = isset($settings['lunch_break_start']) ? $settings['lunch_break_start'] : '14:00';
                $lunch_end = isset($settings['lunch_break_end']) ? $settings['lunch_break_end'] : '17:00';
                
                // Before lunch
                $slots = array_merge($slots, $this->generate_shift_slots($start, $lunch_start, $interval, $buffer));
                
                // After lunch
                $slots = array_merge($slots, $this->generate_shift_slots($lunch_end, $end, $interval, $buffer));
            } else {
                // No lunch break
                $slots = $this->generate_shift_slots($start, $end, $interval, $buffer);
            }
        }
        
        return $slots;
    }

/**
 * Generate slots for one shift
 */
    private function generate_shift_slots($start, $end, $interval, $buffer = 0) {
        $slots = array();
        $start_time = strtotime($start);
        $end_time = strtotime($end);
        $step = ($interval + $buffer) * 60;
        
        while ($start_time < $end_time) {
            $slots[] = date('H:i', $start_time);
            $start_time += $step;
        }
        
        return $slots;
    }
    private function is_booking_allowed_on_date($date, $location_id = null) {
        $settings = array();

        if ($location_id && $this->location_helper) {
            $settings = $this->location_helper->get_settings($location_id);
        }

        if (empty($settings)) {
            $settings = get_option('rb_settings', array());
        }

        $closed_dates = isset($settings['special_closed_dates']) ? $settings['special_closed_dates'] : '';
        if (!empty($closed_dates)) {
            $dates_array = array_map('trim', explode("\n", $closed_dates));
            if (in_array($date, $dates_array, true)) {
                return false;
            }
        }

        $weekend_enabled = isset($settings['weekend_enabled']) && $settings['weekend_enabled'] === 'yes';
        $day_of_week = date('N', strtotime($date));

        if (!$weekend_enabled && ($day_of_week == 6 || $day_of_week == 7)) {
            return false;
        }

        $min_advance = isset($settings['min_advance_booking']) ? intval($settings['min_advance_booking']) : 2;
        $max_advance = isset($settings['max_advance_booking']) ? intval($settings['max_advance_booking']) : 30;

        $booking_timestamp = strtotime($date);
        $now = current_time('timestamp');
        $min_timestamp = $now + ($min_advance * HOUR_IN_SECONDS);
        $max_timestamp = $now + ($max_advance * DAY_IN_SECONDS);

        if ($booking_timestamp < $min_timestamp || $booking_timestamp > $max_timestamp) {
            return false;
        }

        return true;
    }

    private function render_manager_login($atts, $locations) {
        $error = '';

        if (isset($_POST['rb_manager_login_nonce']) && wp_verify_nonce($_POST['rb_manager_login_nonce'], 'rb_manager_login')) {
            $identifier_raw = isset($_POST['rb_username']) ? wp_unslash($_POST['rb_username']) : '';
            $identifier = trim(sanitize_text_field($identifier_raw));
            $password = isset($_POST['rb_password']) ? wp_unslash($_POST['rb_password']) : '';
            $location_id = isset($_POST['rb_location_id']) ? intval($_POST['rb_location_id']) : 0;

            if ($identifier === '' || $password === '') {
                $error = __('Please provide both username/email and password.', 'restaurant-booking');
            } else {
                $login_username = $identifier;

                if (strpos($identifier, '@') !== false) {
                    $maybe_user = get_user_by('email', $identifier);
                    if ($maybe_user) {
                        $login_username = $maybe_user->user_login;
                    }
                }

                $user = wp_signon(array(
                    'user_login' => $login_username,
                    'user_password' => $password,
                    'remember' => true,
                ), false);

                if (!is_wp_error($user) && $user) {
                    if ($user->has_cap('rb_manage_location')) {
                        $resolved_location = $this->resolve_manager_location($user->ID, $location_id, $locations);

                        if ($resolved_location) {
                            update_user_meta($user->ID, 'rb_active_location', $resolved_location);
                        } else {
                            delete_user_meta($user->ID, 'rb_active_location');
                        }

                        if ($this->portal_session) {
                            $this->portal_session->destroy_session();
                            $this->portal_account = null;
                        }

                        wp_safe_redirect(esc_url_raw(add_query_arg(array())));
                        exit;
                    }

                    wp_logout();
                    $error = __('Your WordPress account does not have permission to manage locations.', 'restaurant-booking');
                } else {
                    // Try portal account authentication if available.
                    if ($this->portal_account_manager && $this->portal_session) {
                        $account = $this->portal_account_manager->authenticate($identifier, $password);

                        if ($account instanceof WP_Error) {
                            $error = $account->get_error_message();
                        } elseif ($account) {
                            $allowed_locations = !empty($account->locations) ? (array) $account->locations : array();

                            if (empty($allowed_locations)) {
                                $error = __('This account is not assigned to any locations. Please contact an administrator.', 'restaurant-booking');
                            } else {
                                $resolved_location = $this->resolve_location_from_allowed($location_id, $locations, $allowed_locations, isset($account->last_location_id) ? (int) $account->last_location_id : 0);

                                if (!$resolved_location) {
                                    $error = __('The selected location is not available for this account.', 'restaurant-booking');
                                } else {
                                    $this->portal_session->start_session($account->id);
                                    $this->portal_account_manager->set_active_location($account->id, $resolved_location);
                                    $account->last_location_id = $resolved_location;
                                    $this->portal_account = $account;

                                    wp_safe_redirect(esc_url_raw(add_query_arg(array())));
                                    exit;
                                }
                            }
                        } else {
                            $error = __('Invalid credentials. Please try again.', 'restaurant-booking');
                        }
                    } else {
                        $error = __('Invalid credentials. Please try again.', 'restaurant-booking');
                    }
                }
            }
        }

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

        if ($permissions['type'] === 'portal' && (!$this->portal_session || !$this->portal_account_manager)) {
            wp_send_json_error(array('message' => __('You are not allowed to perform this action.', 'restaurant-booking')));
            wp_die();
        }

        $active_location = 0;
        if ($permissions['type'] === 'wp') {
            $active_location = (int) get_user_meta($permissions['user']->ID, 'rb_active_location', true);
        } else {
            $account = $permissions['account'];
            $active_location = isset($account->last_location_id) ? (int) $account->last_location_id : 0;
        }

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
    public function handle_booking_submission() {
        $nonce = isset($_POST['rb_nonce']) ? $_POST['rb_nonce'] : (isset($_POST['rb_nonce_inline']) ? $_POST['rb_nonce_inline'] : (isset($_POST['rb_nonce_portal']) ? $_POST['rb_nonce_portal'] : ''));
        if (!wp_verify_nonce($nonce, 'rb_booking_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
            wp_die();
        }

        $location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;
        if (!$location_id) {
            wp_send_json_error(array('message' => __('Please choose a location before submitting.', 'restaurant-booking')));
            wp_die();
        }

        $location = $this->get_location_details($location_id);
        if (empty($location)) {
            wp_send_json_error(array('message' => __('Selected location is not available.', 'restaurant-booking')));
            wp_die();
        }

        $language = isset($_POST['language']) ? sanitize_text_field($_POST['language']) : rb_get_current_language();

        $required_fields = array('customer_name', 'customer_phone', 'customer_email', 'guest_count', 'booking_date', 'booking_time');

        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                wp_send_json_error(array('message' => __('Please fill in all required fields.', 'restaurant-booking')));
                wp_die();
            }
        }

        $email = sanitize_email($_POST['customer_email']);
        if (empty($email) || !is_email($email)) {
            $hotline_message = !empty($location['hotline']) ? sprintf(__('Please call %s to complete your reservation.', 'restaurant-booking'), $location['hotline']) : __('Please contact the restaurant directly to book.', 'restaurant-booking');
            wp_send_json_error(array('message' => $hotline_message));
            wp_die();
        }

        $phone = preg_replace('/\D+/', '', sanitize_text_field($_POST['customer_phone']));
        if (strlen($phone) < 8 || strlen($phone) > 15) {
            wp_send_json_error(array('message' => __('Please provide a valid phone number.', 'restaurant-booking')));
            wp_die();
        }

        $booking_date_raw = sanitize_text_field($_POST['booking_date']);
        $booking_time = sanitize_text_field($_POST['booking_time']);
        $guest_count = intval($_POST['guest_count']);

        if (!$this->is_booking_allowed_on_date($booking_date_raw, $location_id)) {
            wp_send_json_error(array('message' => __('This date is not available for reservations. Please choose another day.', 'restaurant-booking')));
            wp_die();
        }

        $booking_datetime = strtotime($booking_date_raw . ' ' . $booking_time);
        if (!$booking_datetime || $booking_datetime < current_time('timestamp')) {
            wp_send_json_error(array('message' => __('Selected time is in the past. Please choose another slot.', 'restaurant-booking')));
            wp_die();
        }

        global $rb_booking;

        if (!$rb_booking) {
            require_once RB_PLUGIN_DIR . 'includes/class-booking.php';
            $rb_booking = new RB_Booking();
        }

        $is_available = $rb_booking->is_time_slot_available(
            $booking_date_raw,
            $booking_time,
            $guest_count,
            null,
            $location_id
        );

        if (!$is_available) {
            $suggestions = $rb_booking->suggest_time_slots($location_id, $booking_date_raw, $booking_time, $guest_count, 30);
            $message = sprintf(
                __('We are fully booked for %1$s at %2$s. Please choose another time.', 'restaurant-booking'),
                date_i18n(get_option('date_format', 'd/m/Y'), strtotime($booking_date_raw)),
                $booking_time
            );

            wp_send_json_error(array(
                'message' => $message,
                'suggestions' => $suggestions
            ));
            wp_die();
        }

        $booking_data = array(
            'customer_name' => sanitize_text_field($_POST['customer_name']),
            'customer_phone' => $phone,
            'customer_email' => $email,
            'guest_count' => $guest_count,
            'booking_date' => $booking_date_raw,
            'booking_time' => $booking_time,
            'special_requests' => isset($_POST['special_requests']) ? sanitize_textarea_field($_POST['special_requests']) : '',
            'status' => 'pending',
            'booking_source' => 'website',
            'created_at' => current_time('mysql'),
            'location_id' => $location_id,
            'language' => $language
        );

        $booking_id = $rb_booking->create_booking($booking_data);

        if (is_wp_error($booking_id)) {
            wp_send_json_error(array('message' => $booking_id->get_error_message()));
            wp_die();
        }

        $booking = $rb_booking->get_booking($booking_id);

        if ($booking && class_exists('RB_Email')) {
            $email_handler = new RB_Email();
            $email_handler->send_admin_notification($booking);
            $email_handler->send_pending_confirmation($booking, $location);
        }

        $success_message = sprintf(
            __('Thank you %1$s! We have sent a confirmation email to %2$s. Please click the link to secure your table at %3$s. For urgent assistance call %4$s.', 'restaurant-booking'),
            $booking_data['customer_name'],
            $booking_data['customer_email'],
            $location['name'],
            !empty($location['hotline']) ? $location['hotline'] : __('the restaurant hotline', 'restaurant-booking')
        );

        wp_send_json_success(array(
            'message' => $success_message,
            'booking_id' => $booking_id
        ));

        wp_die();
    }
    public function check_availability() {
        if (!check_ajax_referer('rb_frontend_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
            wp_die();
        }

        $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
        $time = isset($_POST['time']) ? sanitize_text_field($_POST['time']) : '';
        $guests = isset($_POST['guests']) ? intval($_POST['guests']) : 0;
        $location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;

        if (empty($date) || empty($time) || $guests <= 0 || !$location_id) {
            wp_send_json_error(array('message' => __('Missing data. Please select location, date, time and number of guests.', 'restaurant-booking')));
            wp_die();
        }

        if (!$this->is_booking_allowed_on_date($date, $location_id)) {
            wp_send_json_error(array('message' => __('This date is not available for reservations. Please choose another day.', 'restaurant-booking')));
            wp_die();
        }

        global $rb_booking;
        if (!$rb_booking) {
            require_once RB_PLUGIN_DIR . 'includes/class-booking.php';
            $rb_booking = new RB_Booking();
        }

        $is_available = $rb_booking->is_time_slot_available($date, $time, $guests, null, $location_id);
        $count = $rb_booking->available_table_count($date, $time, $guests, $location_id);

        if ($is_available && $count > 0) {
            $message = sprintf(__('We have %1$d tables available for %2$d guests.', 'restaurant-booking'), $count, $guests);
            wp_send_json_success(array(
                'available' => true,
                'message' => $message,
                'count' => $count
            ));
        } else {
            $suggestions = $rb_booking->suggest_time_slots($location_id, $date, $time, $guests, 30);
            $message = __('No availability for the selected time. Please consider one of the suggested slots.', 'restaurant-booking');

            wp_send_json_success(array(
                'available' => false,
                'message' => $message,
                'suggestions' => $suggestions
            ));
        }

        wp_die();
    }

    public function get_time_slots() {
        if (!check_ajax_referer('rb_frontend_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
            wp_die();
        }

        $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
        $guest_count = isset($_POST['guest_count']) ? intval($_POST['guest_count']) : 0;
        $location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;

        if (empty($date) || $guest_count <= 0 || !$location_id) {
            wp_send_json_error(array('message' => __('Missing data. Please select date, guests and location.', 'restaurant-booking')));
            wp_die();
        }

        if (!$this->is_booking_allowed_on_date($date, $location_id)) {
            wp_send_json_success(array('slots' => array()));
            wp_die();
        }

        global $rb_booking;

        if (!$rb_booking) {
            require_once RB_PLUGIN_DIR . 'includes/class-booking.php';
            $rb_booking = new RB_Booking();
        }

        $settings = array();

        if ($this->location_helper) {
            $settings = $this->location_helper->get_settings($location_id);
        }

        if (empty($settings)) {
            $settings = get_option('rb_settings', array());
        }

        $opening_time = isset($settings['opening_time']) ? $settings['opening_time'] : null;
        $closing_time = isset($settings['closing_time']) ? $settings['closing_time'] : null;
        $interval = isset($settings['time_slot_interval']) ? intval($settings['time_slot_interval']) : null;

        $time_slots = $this->generate_time_slots($opening_time, $closing_time, $interval);
        $available_slots = array();
        $current_timestamp = current_time('timestamp');

        foreach ($time_slots as $slot) {
            $slot_timestamp = strtotime($date . ' ' . $slot);

            if (!$slot_timestamp || $slot_timestamp <= $current_timestamp) {
                continue;
            }

            if ($rb_booking->is_time_slot_available($date, $slot, $guest_count, null, $location_id)) {
                $available_slots[] = $slot;
            }
        }

        wp_send_json_success(array('slots' => array_values(array_unique($available_slots))));

        wp_die();
    }
}
