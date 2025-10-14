<?php
/**
 * Frontend Class - Xử lý hiển thị frontend và shortcode
 */

if (!defined('ABSPATH')) {
    exit;
}

class RB_Frontend {
    
    public function __construct() {
        $this->init_ajax_handlers();
    }
    
    private function init_ajax_handlers() {
        add_action('wp_ajax_rb_submit_booking', array($this, 'handle_booking_submission'));
        add_action('wp_ajax_nopriv_rb_submit_booking', array($this, 'handle_booking_submission'));
        
        add_action('wp_ajax_rb_check_availability', array($this, 'check_availability'));
        add_action('wp_ajax_nopriv_rb_check_availability', array($this, 'check_availability'));
    }
    
    public function render_booking_form($atts) {
        $atts = shortcode_atts(array(
            'title' => rb_t('book_now'),
            'button_text' => rb_t('book_now'),
            'show_button' => 'yes'
        ), $atts, 'restaurant_booking');

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
  /**
 * Check if booking allowed on date
 */
    private function is_booking_allowed_on_date($date) {
        $settings = get_option('rb_settings', array());
        
        // Check special closed dates
        $closed_dates = isset($settings['special_closed_dates']) ? $settings['special_closed_dates'] : '';
        if (!empty($closed_dates)) {
            $dates_array = array_map('trim', explode("\n", $closed_dates));
            if (in_array($date, $dates_array)) {
                return false;
            }
        }
        
        // Check weekend
        $weekend_enabled = isset($settings['weekend_enabled']) && $settings['weekend_enabled'] === 'yes';
        $day_of_week = date('N', strtotime($date));
        
        if (!$weekend_enabled && ($day_of_week == 6 || $day_of_week == 7)) {
            return false;
        }
        
        // Check advance booking limits
        $min_advance = isset($settings['min_advance_booking']) ? intval($settings['min_advance_booking']) : 2;
        $max_advance = isset($settings['max_advance_booking']) ? intval($settings['max_advance_booking']) : 30;
        
        $booking_timestamp = strtotime($date);
        $now = current_time('timestamp');
        $min_timestamp = $now + ($min_advance * 3600);
        $max_timestamp = $now + ($max_advance * 86400);
        
        if ($booking_timestamp < $min_timestamp || $booking_timestamp > $max_timestamp) {
            return false;
        }
        
        return true;
    }  
    public function handle_booking_submission() {
        $nonce = isset($_POST['rb_nonce']) ? $_POST['rb_nonce'] : (isset($_POST['rb_nonce_inline']) ? $_POST['rb_nonce_inline'] : '');
        if (!wp_verify_nonce($nonce, 'rb_booking_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            wp_die();
        }

        $required_fields = array('customer_name', 'customer_phone', 'customer_email', 'guest_count', 'booking_date', 'booking_time');

        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                wp_send_json_error(array('message' => 'Vui lòng điền đầy đủ thông tin bắt buộc'));
                wp_die();
            }
        }

        $booking_data = array(
            'customer_name' => sanitize_text_field($_POST['customer_name']),
            'customer_phone' => sanitize_text_field($_POST['customer_phone']),
            'customer_email' => sanitize_email($_POST['customer_email']),
            'guest_count' => intval($_POST['guest_count']),
            'booking_date' => sanitize_text_field($_POST['booking_date']),
            'booking_time' => sanitize_text_field($_POST['booking_time']),
            'special_requests' => isset($_POST['special_requests']) ? sanitize_textarea_field($_POST['special_requests']) : '',
            'status' => 'pending',
            'booking_source' => 'website',
            'created_at' => current_time('mysql')
        );

        if (!is_email($booking_data['customer_email'])) {
            wp_send_json_error(array('message' => 'Email không hợp lệ'));
            wp_die();
        }

        if (!preg_match('/^[0-9]{10,11}$/', $booking_data['customer_phone'])) {
            wp_send_json_error(array('message' => 'Số điện thoại không hợp lệ'));
            wp_die();
        }

        $booking_date = strtotime($booking_data['booking_date']);
        $today = strtotime(date('Y-m-d'));

        if ($booking_date === false || $booking_date < $today) {
            wp_send_json_error(array('message' => 'Ngày đặt bàn không hợp lệ'));
            wp_die();
        }

        global $rb_booking;

        // Đảm bảo $rb_booking đã được khởi tạo
        if (!$rb_booking) {
            require_once RB_PLUGIN_DIR . 'includes/class-booking.php';
            $rb_booking = new RB_Booking();
        }

        $is_available = $rb_booking->is_time_slot_available(
            $booking_data['booking_date'],
            $booking_data['booking_time'],
            $booking_data['guest_count']
        );

        if (!$is_available) {
            wp_send_json_error(array(
                'message' => 'Rất tiếc, không còn bàn trống cho ' . $booking_data['guest_count'] . 
                            ' người vào lúc ' . $booking_data['booking_time'] . 
                            ' ngày ' . date('d/m/Y', strtotime($booking_data['booking_date'])) . 
                            '. Vui lòng chọn thời gian khác.'
            ));
            wp_die();
        }

        // *** THAY ĐỔI CHÍNH: Dùng create_booking() thay vì insert trực tiếp ***
        $booking_id = $rb_booking->create_booking($booking_data);

        if (is_wp_error($booking_id)) {
            wp_send_json_error(array('message' => $booking_id->get_error_message()));
            wp_die();
        }

        // Send admin notification
        $booking = $rb_booking->get_booking($booking_id);
        if ($booking && class_exists('RB_Email')) {
            $email = new RB_Email();
            $email->send_admin_notification($booking);
        }

        wp_send_json_success(array(
            'message' => 'Đặt bàn thành công! Chúng tôi sẽ liên hệ với bạn sớm để xác nhận.',
            'booking_id' => $booking_id
        ));

        wp_die();
    }
    public function check_availability() {
        if (!check_ajax_referer('rb_frontend_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Security check failed'));
            wp_die();
        }
        
        $date = sanitize_text_field($_POST['date']);
        $time = sanitize_text_field($_POST['time']);
        $guests = intval($_POST['guests']);
        
        global $rb_booking;
        $is_available = $rb_booking->is_time_slot_available($date, $time, $guests);
        $count = $rb_booking->available_table_count($date, $time, $guests);
        
        if ($is_available && $count > 0) {
            $message = sprintf('Có %d bàn trống phù hợp cho %d khách', $count, $guests);
            wp_send_json_success(array(
                'available' => true,
                'message' => $message,
                'count' => $count
            ));
        } else {
            wp_send_json_success(array(
                'available' => false,
                'message' => 'Không có bàn trống vào thời gian này. Vui lòng chọn thời gian khác.'
            ));
        }
        
        wp_die();
    }
}
