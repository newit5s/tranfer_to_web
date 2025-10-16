<?php
/**
 * Customer facing booking surfaces.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RB_Frontend_Public extends RB_Frontend_Base {

    private static $instance = null;

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
        $this->init_ajax_handlers();
        add_action('init', array($this, 'maybe_handle_email_confirmation'));
    }

    private function init_ajax_handlers() {
        add_action('wp_ajax_rb_submit_booking', array($this, 'handle_booking_submission'));
        add_action('wp_ajax_nopriv_rb_submit_booking', array($this, 'handle_booking_submission'));

        add_action('wp_ajax_rb_check_availability', array($this, 'check_availability'));
        add_action('wp_ajax_nopriv_rb_check_availability', array($this, 'check_availability'));

        add_action('wp_ajax_rb_get_time_slots', array($this, 'get_time_slots'));
        add_action('wp_ajax_nopriv_rb_get_time_slots', array($this, 'get_time_slots'));
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

            <div id="rb-booking-modal" class="rb-modal" aria-hidden="true">
                <div class="rb-modal-content" role="dialog" aria-modal="true">
                    <button type="button" class="rb-close" aria-label="<?php esc_attr_e('Close booking form', 'restaurant-booking'); ?>">&times;</button>

                    <div class="rb-booking-layout">
                        <aside class="rb-booking-sidebar">
                            <div class="rb-booking-sidebar-inner">
                                <?php if (!empty($default_location['name'])) : ?>
                                    <h3 class="rb-sidebar-heading"><?php echo esc_html($default_location['name']); ?></h3>
                                <?php endif; ?>

                                <?php if (!empty($default_location['address'])) : ?>
                                    <p class="rb-sidebar-text">
                                        <?php echo esc_html($default_location['address']); ?>
                                    </p>
                                <?php endif; ?>

                                <ul class="rb-sidebar-meta">
                                    <li>
                                        <span class="rb-meta-label"><?php esc_html_e('Opening hours', 'restaurant-booking'); ?></span>
                                        <span class="rb-meta-value"><?php echo esc_html($opening_time . ' - ' . $closing_time); ?></span>
                                    </li>
                                    <?php if (!empty($default_location['hotline'])) : ?>
                                        <li>
                                            <span class="rb-meta-label"><?php esc_html_e('Hotline', 'restaurant-booking'); ?></span>
                                            <span class="rb-meta-value"><?php echo esc_html($default_location['hotline']); ?></span>
                                        </li>
                                    <?php endif; ?>
                                    <?php if (!empty($default_location['email'])) : ?>
                                        <li>
                                            <span class="rb-meta-label"><?php esc_html_e('Email', 'restaurant-booking'); ?></span>
                                            <span class="rb-meta-value"><?php echo esc_html($default_location['email']); ?></span>
                                        </li>
                                    <?php endif; ?>
                                </ul>

                                <p class="rb-sidebar-note"><?php echo esc_html(rb_t('confirmation_email_note', __('A confirmation link will be sent to this email. If you do not have an email address, please call the hotline of your selected location to reserve.', 'restaurant-booking'))); ?></p>
                            </div>
                        </aside>

                        <div class="rb-booking-main">
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

                            <div class="rb-step-indicator" aria-hidden="true">
                                <div class="rb-step-item active">
                                    <span class="rb-step-number">1</span>
                                    <span class="rb-step-label"><?php esc_html_e('Reservation', 'restaurant-booking'); ?></span>
                                </div>
                                <div class="rb-step-item active">
                                    <span class="rb-step-number">2</span>
                                    <span class="rb-step-label"><?php esc_html_e('Contact', 'restaurant-booking'); ?></span>
                                </div>
                                <div class="rb-step-item">
                                    <span class="rb-step-number">3</span>
                                    <span class="rb-step-label"><?php esc_html_e('Confirm', 'restaurant-booking'); ?></span>
                                </div>
                            </div>

                            <form id="rb-booking-form" class="rb-form">
                                <?php wp_nonce_field('rb_booking_nonce', 'rb_nonce'); ?>
                                <input type="hidden" name="location_id" value="<?php echo esc_attr($default_location_id); ?>">
                                <input type="hidden" name="language" value="<?php echo esc_attr($current_language); ?>">

                                <div class="rb-form-section">
                                    <h3 class="rb-form-section-title"><?php esc_html_e('Reservation details', 'restaurant-booking'); ?></h3>
                                    <div class="rb-form-grid">
                                        <div class="rb-form-group">
                                            <label for="rb_guest_count"><?php rb_e('number_of_guests'); ?> *</label>
                                            <select id="rb_guest_count" name="guest_count" required>
                                                <?php for ($i = 1; $i <= 20; $i++) : ?>
                                                    <option value="<?php echo $i; ?>"><?php echo $i; ?> <?php rb_e('people'); ?></option>
                                                <?php endfor; ?>
                                            </select>
                                        </div>

                                        <div class="rb-form-group">
                                            <label for="rb_booking_date"><?php rb_e('booking_date'); ?> *</label>
                                            <input type="date" id="rb_booking_date" name="booking_date"
                                                min="<?php echo $min_date; ?>"
                                                max="<?php echo $max_date; ?>" required>
                                        </div>

                                        <div class="rb-form-group">
                                            <label for="rb_booking_time"><?php rb_e('booking_time'); ?> *</label>
                                            <select id="rb_booking_time" name="booking_time" required>
                                                <option value=""><?php rb_e('select_time'); ?></option>
                                                <?php if (!empty($time_slots)) : ?>
                                                    <?php foreach ($time_slots as $slot) : ?>
                                                        <option value="<?php echo esc_attr($slot); ?>"><?php echo esc_html($slot); ?></option>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div class="rb-form-section">
                                    <h3 class="rb-form-section-title"><?php esc_html_e('Contact information', 'restaurant-booking'); ?></h3>
                                    <div class="rb-form-grid">
                                        <div class="rb-form-group">
                                            <label for="rb_customer_name"><?php rb_e('full_name'); ?> *</label>
                                            <input type="text" id="rb_customer_name" name="customer_name" required>
                                        </div>

                                        <div class="rb-form-group">
                                            <label for="rb_customer_phone"><?php rb_e('phone_number'); ?> *</label>
                                            <input type="tel" id="rb_customer_phone" name="customer_phone" required>
                                        </div>

                                        <div class="rb-form-group">
                                            <label for="rb_customer_email"><?php rb_e('email'); ?> *</label>
                                            <input type="email" id="rb_customer_email" name="customer_email" required>
                                        </div>
                                    </div>
                                </div>

                                <div class="rb-form-section">
                                    <h3 class="rb-form-section-title"><?php rb_e('special_requests'); ?></h3>
                                    <div class="rb-form-group">
                                        <label class="screen-reader-text" for="rb_special_requests"><?php rb_e('special_requests'); ?></label>
                                        <textarea id="rb_special_requests" name="special_requests" rows="3" placeholder="<?php esc_attr_e('Add a note for our team', 'restaurant-booking'); ?>"></textarea>
                                    </div>
                                </div>

                                <div class="rb-form-actions">
                                    <button type="button" class="rb-btn-secondary rb-close-modal"><?php rb_e('cancel'); ?></button>
                                    <button type="submit" class="rb-btn-primary"><?php rb_e('confirm_booking'); ?></button>
                                </div>

                                <div id="rb-form-message" class="rb-form-message"></div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($atts['show_button'] === 'no') : ?>
                <div class="rb-inline-shell">
                    <div class="rb-inline-layout">
                        <aside class="rb-booking-sidebar">
                            <div class="rb-booking-sidebar-inner">
                                <?php if (!empty($default_location['name'])) : ?>
                                    <h3 class="rb-sidebar-heading"><?php echo esc_html($default_location['name']); ?></h3>
                                <?php endif; ?>

                                <?php if (!empty($default_location['address'])) : ?>
                                    <p class="rb-sidebar-text"><?php echo esc_html($default_location['address']); ?></p>
                                <?php endif; ?>

                                <ul class="rb-sidebar-meta">
                                    <li>
                                        <span class="rb-meta-label"><?php esc_html_e('Opening hours', 'restaurant-booking'); ?></span>
                                        <span class="rb-meta-value"><?php echo esc_html($opening_time . ' - ' . $closing_time); ?></span>
                                    </li>
                                    <?php if (!empty($default_location['hotline'])) : ?>
                                        <li>
                                            <span class="rb-meta-label"><?php esc_html_e('Hotline', 'restaurant-booking'); ?></span>
                                            <span class="rb-meta-value"><?php echo esc_html($default_location['hotline']); ?></span>
                                        </li>
                                    <?php endif; ?>
                                    <?php if (!empty($default_location['email'])) : ?>
                                        <li>
                                            <span class="rb-meta-label"><?php esc_html_e('Email', 'restaurant-booking'); ?></span>
                                            <span class="rb-meta-value"><?php echo esc_html($default_location['email']); ?></span>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </aside>

                        <div class="rb-inline-form-card">
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

                            <div class="rb-step-indicator" aria-hidden="true">
                                <div class="rb-step-item active">
                                    <span class="rb-step-number">1</span>
                                    <span class="rb-step-label"><?php esc_html_e('Reservation', 'restaurant-booking'); ?></span>
                                </div>
                                <div class="rb-step-item active">
                                    <span class="rb-step-number">2</span>
                                    <span class="rb-step-label"><?php esc_html_e('Contact', 'restaurant-booking'); ?></span>
                                </div>
                                <div class="rb-step-item">
                                    <span class="rb-step-number">3</span>
                                    <span class="rb-step-label"><?php esc_html_e('Confirm', 'restaurant-booking'); ?></span>
                                </div>
                            </div>

                            <form id="rb-booking-form-inline" class="rb-form">
                                <?php wp_nonce_field('rb_booking_nonce', 'rb_nonce_inline'); ?>
                                <input type="hidden" name="location_id" value="<?php echo esc_attr($default_location_id); ?>">
                                <input type="hidden" name="language" value="<?php echo esc_attr($current_language); ?>">

                                <div class="rb-form-section">
                                    <h3 class="rb-form-section-title"><?php esc_html_e('Reservation details', 'restaurant-booking'); ?></h3>
                                    <div class="rb-form-grid">
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
                                                        <option value="<?php echo esc_attr($slot); ?>"><?php echo esc_html($slot); ?></option>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div class="rb-form-section">
                                    <h3 class="rb-form-section-title"><?php esc_html_e('Contact information', 'restaurant-booking'); ?></h3>
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
                                    </div>
                                </div>

                                <div class="rb-form-section">
                                    <h3 class="rb-form-section-title"><?php rb_e('special_requests'); ?></h3>
                                    <div class="rb-form-group">
                                        <label class="screen-reader-text" for="rb_requests_inline"><?php rb_e('special_requests'); ?></label>
                                        <textarea id="rb_requests_inline" name="special_requests" rows="3" placeholder="<?php esc_attr_e('Add a note for our team', 'restaurant-booking'); ?>"></textarea>
                                    </div>
                                </div>

                                <div class="rb-form-actions">
                                    <button type="submit" class="rb-btn-primary"><?php rb_e('book_now'); ?></button>
                                </div>

                                <div id="rb-form-message-inline" class="rb-form-message"></div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
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
        $languages = array();
        $available_languages = rb_get_available_languages();

        foreach ($available_languages as $locale => $info) {
            $fallback_label = isset($info['name']) ? $info['name'] : $locale;

            switch ($locale) {
                case 'vi_VN':
                    $label = rb_t('language_vietnamese', __('Vietnamese', 'restaurant-booking'));
                    break;
                case 'en_US':
                    $label = rb_t('language_english', __('English', 'restaurant-booking'));
                    break;
                case 'ja_JP':
                    $label = rb_t('language_japanese', __('Japanese', 'restaurant-booking'));
                    break;
                default:
                    $label = $fallback_label;
                    break;
            }

            if (!empty($info['flag'])) {
                $label = trim($info['flag'] . ' ' . $label);
            }

            $languages[$locale] = $label;
        }

        if (empty($languages)) {
            $languages = array(
                'vi_VN' => rb_t('language_vietnamese', __('Vietnamese', 'restaurant-booking')),
                'en_US' => rb_t('language_english', __('English', 'restaurant-booking')),
                'ja_JP' => rb_t('language_japanese', __('Japanese', 'restaurant-booking')),
            );
        }

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

        $phone = sanitize_text_field($_POST['customer_phone']);
        if (!preg_match('/^[0-9+\-\s]{8,20}$/', $phone)) {
            wp_send_json_error(array('message' => __('Please enter a valid phone number.', 'restaurant-booking')));
            wp_die();
        }

        $guest_count = intval($_POST['guest_count']);
        if ($guest_count <= 0) {
            wp_send_json_error(array('message' => __('Please select a valid number of guests.', 'restaurant-booking')));
            wp_die();
        }

        $booking_date_raw = sanitize_text_field($_POST['booking_date']);
        $booking_time = sanitize_text_field($_POST['booking_time']);

        if (!$this->is_booking_allowed_on_date($booking_date_raw, $location_id)) {
            wp_send_json_error(array('message' => __('This date is not available for reservations. Please choose another day.', 'restaurant-booking')));
            wp_die();
        }

        $booking_date = date('Y-m-d', strtotime($booking_date_raw));
        if (!$booking_date || !$booking_time) {
            wp_send_json_error(array('message' => __('Please choose a valid booking date and time.', 'restaurant-booking')));
            wp_die();
        }

        global $rb_booking;
        if (!$rb_booking) {
            require_once RB_PLUGIN_DIR . 'includes/class-booking.php';
            $rb_booking = new RB_Booking();
        }

        $is_available = $rb_booking->is_time_slot_available($booking_date, $booking_time, $guest_count, null, $location_id);

        if (!$is_available) {
            $suggestions = $rb_booking->suggest_time_slots(
                $location_id,
                $booking_date,
                $booking_time,
                $guest_count,
                30
            );

            $message = sprintf(
                __('No availability for %1$s at %2$s. Please choose another time.', 'restaurant-booking'),
                $booking_date,
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
