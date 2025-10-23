<?php
/**
 * Email Class - Xử lý gửi email
 */

if (!defined('ABSPATH')) {
    exit;
}

class RB_Email {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Add email content type filter
        add_filter('wp_mail_content_type', array($this, 'set_html_content_type'));
    }
    
    /**
     * Set email content type to HTML
     */
    public function set_html_content_type() {
        return 'text/html';
    }
    
    /**
     * Send confirmation email to customer
     */
    public function send_confirmation_email($booking) {
        $settings = get_option('rb_settings', array());

        if (!isset($settings['enable_email']) || $settings['enable_email'] !== 'yes') {
            return false;
        }

        $to = $booking->customer_email;
        $subject = sprintf(__('[%s] Xác nhận đặt bàn', 'restaurant-booking'), get_bloginfo('name'));
        $message = $this->get_confirmation_email_template($booking);

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
        );

        return wp_mail($to, $subject, $message, $headers);
    }

    public function send_pending_confirmation($booking, $location = array()) {
        $settings = get_option('rb_settings', array());

        if (!isset($settings['enable_email']) || $settings['enable_email'] !== 'yes' || empty($booking->customer_email)) {
            return false;
        }

        $confirm_link = add_query_arg('rb_confirm_token', $booking->confirmation_token, home_url('/'));
        $subject = sprintf(__('[%s] Please confirm your reservation', 'restaurant-booking'), get_bloginfo('name'));
        $message = $this->get_pending_confirmation_template($booking, $confirm_link, $location);

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
        );

        return wp_mail($booking->customer_email, $subject, $message, $headers);
    }
    
    /**
     * Send notification email to admin
     */
    public function send_admin_notification($booking) {
        $settings = get_option('rb_settings', array());
        $admin_email = isset($settings['admin_email']) ? $settings['admin_email'] : get_option('admin_email');

        $subject = sprintf(__('[%s] Đặt bàn mới', 'restaurant-booking'), get_bloginfo('name'));
        $message = $this->get_admin_notification_template($booking);

        $headers = array(
            'Content-Type: text/html; charset=UTF-8'
        );

        return wp_mail($admin_email, $subject, $message, $headers);
    }

    public function send_flagged_booking_alert($recipients, $booking, $customer, $flags, $context = 'created') {
        if (empty($recipients)) {
            return false;
        }

        if (!is_array($recipients)) {
            $recipients = array($recipients);
        }

        $recipients = array_filter($recipients, 'is_email');
        if (empty($recipients)) {
            return false;
        }

        $subject = $this->get_flagged_booking_subject($booking, $flags, $context);
        $message = $this->get_flagged_booking_template($booking, $customer, $flags, $context);

        $headers = array(
            'Content-Type: text/html; charset=UTF-8'
        );

        return wp_mail($recipients, $subject, $message, $headers);
    }
    
    /**
     * Send cancellation email to customer
     */
    public function send_cancellation_email($booking) {
        $settings = get_option('rb_settings', array());
        
        if (!isset($settings['enable_email']) || $settings['enable_email'] !== 'yes') {
            return false;
        }
        
        $to = $booking->customer_email;
        $subject = sprintf(__('[%s] Đặt bàn đã bị hủy', 'restaurant-booking'), get_bloginfo('name'));
        $message = $this->get_cancellation_email_template($booking);
        
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
        );
        
        return wp_mail($to, $subject, $message, $headers);
    }
    
    /**
     * Get confirmation email template
     */
    private function get_confirmation_email_template($booking) {
        $template = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #2c3e50; color: white; padding: 30px; text-align: center; border-radius: 5px 5px 0 0; }
                .content { background: #fff; padding: 30px; border: 1px solid #ddd; border-radius: 0 0 5px 5px; }
                .booking-details { background: #f9f9f9; padding: 20px; border-radius: 5px; margin: 20px 0; }
                .detail-row { display: flex; padding: 10px 0; border-bottom: 1px solid #eee; }
                .detail-label { font-weight: bold; width: 150px; }
                .detail-value { flex: 1; }
                .footer { text-align: center; padding: 20px; color: #777; font-size: 14px; }
                .button { display: inline-block; padding: 12px 30px; background: #27ae60; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>' . get_bloginfo('name') . '</h1>
                    <p>XÁC NHẬN ĐẶT BÀN</p>
                </div>
                
                <div class="content">
                    <h2>Xin chào ' . esc_html($booking->customer_name) . ',</h2>
                    
                    <p>Đặt bàn của bạn đã được <strong>XÁC NHẬN</strong>. Chúng tôi rất mong được phục vụ bạn!</p>
                    
                    <div class="booking-details">
                        <h3>Chi tiết đặt bàn:</h3>
                        <div class="detail-row">
                            <div class="detail-label">Mã đặt bàn:</div>
                            <div class="detail-value">#' . str_pad($booking->id, 5, '0', STR_PAD_LEFT) . '</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Ngày:</div>
                            <div class="detail-value">' . date_i18n('l, d/m/Y', strtotime($booking->booking_date)) . '</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Giờ:</div>
                            <div class="detail-value">' . esc_html($booking->booking_time) . '</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Số khách:</div>
                            <div class="detail-value">' . esc_html($booking->guest_count) . ' người</div>
                        </div>';
                        
        if ($booking->table_number) {
            $template .= '
                        <div class="detail-row">
                            <div class="detail-label">Bàn số:</div>
                            <div class="detail-value">' . esc_html($booking->table_number) . '</div>
                        </div>';
        }
        
        if (!empty($booking->special_requests)) {
            $template .= '
                        <div class="detail-row">
                            <div class="detail-label">Yêu cầu đặc biệt:</div>
                            <div class="detail-value">' . nl2br(esc_html($booking->special_requests)) . '</div>
                        </div>';
        }
        
        $template .= '
                    </div>
                    
                    <h3>Thông tin liên hệ:</h3>
                    <p>
                        <strong>Điện thoại:</strong> ' . esc_html($booking->customer_phone) . '<br>
                        <strong>Email:</strong> ' . esc_html($booking->customer_email) . '
                    </p>
                    
                    <p><strong>Lưu ý quan trọng:</strong></p>
                    <ul>
                        <li>Vui lòng đến đúng giờ đã đặt</li>
                        <li>Bàn sẽ được giữ trong vòng 15 phút kể từ giờ đặt</li>
                        <li>Nếu cần thay đổi hoặc hủy, vui lòng liên hệ sớm</li>
                    </ul>
                    
                    <center>
                        <a href="' . home_url() . '" class="button">Ghé thăm website</a>
                    </center>
                </div>

                <div class="footer">
                    <p>Cảm ơn bạn đã chọn ' . get_bloginfo('name') . '!</p>
                    <p>
                        ' . get_option('admin_email') . '<br>
                        © ' . date('Y') . ' ' . get_bloginfo('name') . '. All rights reserved.
                    </p>
                </div>
            </div>
        </body>
        </html>';

        return $template;
    }

    private function get_pending_confirmation_template($booking, $confirm_link, $location = array()) {
        $location_block = '';
        if (!empty($location)) {
            $location_block .= '<p><strong>' . esc_html($location['name']) . '</strong><br>';
            if (!empty($location['address'])) {
                $location_block .= esc_html($location['address']) . '<br>';
            }
            if (!empty($location['hotline'])) {
                $location_block .= __('Hotline:', 'restaurant-booking') . ' ' . esc_html($location['hotline']);
            }
            $location_block .= '</p>';
        }

        $template = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #f39c12; color: white; padding: 30px; text-align: center; border-radius: 5px 5px 0 0; }
                .content { background: #fff; padding: 30px; border: 1px solid #ddd; border-radius: 0 0 5px 5px; }
                .button { display: inline-block; padding: 12px 30px; background: #27ae60; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
                .booking-details { background: #f9f9f9; padding: 20px; border-radius: 5px; margin: 20px 0; }
                .footer { text-align: center; padding: 20px; color: #777; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>' . get_bloginfo('name') . '</h1>
                    <p>' . __('Confirm your reservation', 'restaurant-booking') . '</p>
                </div>
                <div class="content">
                    <p>' . sprintf(__('Hi %s,', 'restaurant-booking'), esc_html($booking->customer_name)) . '</p>
                    <p>' . __('Thank you for choosing us! Please confirm your reservation by clicking the button below.', 'restaurant-booking') . '</p>
                    <div class="booking-details">
                        <p><strong>' . __('Date', 'restaurant-booking') . ':</strong> ' . date_i18n('l, d/m/Y', strtotime($booking->booking_date)) . '</p>
                        <p><strong>' . __('Time', 'restaurant-booking') . ':</strong> ' . esc_html($booking->booking_time) . '</p>
                        <p><strong>' . __('Guests', 'restaurant-booking') . ':</strong> ' . esc_html($booking->guest_count) . '</p>
                    </div>
                    <center>
                        <a href="' . esc_url($confirm_link) . '" class="button">' . __('Confirm my reservation', 'restaurant-booking') . '</a>
                    </center>
                    <p>' . __('If you did not make this reservation or need assistance, please contact us using the details below.', 'restaurant-booking') . '</p>
                    ' . $location_block . '
                </div>
                <div class="footer">
                    <p>' . get_bloginfo('name') . '</p>
                </div>
            </div>
        </body>
        </html>';

        return $template;
    }
    
    /**
     * Get admin notification template
     */
    private function get_admin_notification_template($booking) {
        $admin_url = admin_url('admin.php?page=restaurant-booking&action=view&id=' . $booking->id);
        
        $template = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #e74c3c; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
                .content { background: #fff; padding: 30px; border: 1px solid #ddd; border-radius: 0 0 5px 5px; }
                .booking-details { background: #f9f9f9; padding: 20px; border-radius: 5px; margin: 20px 0; }
                table { width: 100%; border-collapse: collapse; }
                td { padding: 10px; border-bottom: 1px solid #eee; }
                td:first-child { font-weight: bold; width: 150px; }
                .button { display: inline-block; padding: 12px 30px; background: #3498db; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
                .urgent { background: #fff3cd; border: 1px solid #ffc107; padding: 15px; border-radius: 5px; margin: 20px 0; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>ĐẶT BÀN MỚI</h1>
                    <p>Cần xử lý</p>
                </div>
                
                <div class="content">
                    <div class="urgent">
                        <strong>⚠️ Đặt bàn mới cần xác nhận!</strong><br>
                        Mã đặt bàn: #' . str_pad($booking->id, 5, '0', STR_PAD_LEFT) . '
                    </div>
                    
                    <div class="booking-details">
                        <h3>Thông tin khách hàng:</h3>
                        <table>
                            <tr>
                                <td>Họ tên:</td>
                                <td>' . esc_html($booking->customer_name) . '</td>
                            </tr>
                            <tr>
                                <td>Điện thoại:</td>
                                <td><a href="tel:' . esc_html($booking->customer_phone) . '">' . esc_html($booking->customer_phone) . '</a></td>
                            </tr>
                            <tr>
                                <td>Email:</td>
                                <td><a href="mailto:' . esc_html($booking->customer_email) . '">' . esc_html($booking->customer_email) . '</a></td>
                            </tr>
                        </table>
                    </div>
                    
                    <div class="booking-details">
                        <h3>Chi tiết đặt bàn:</h3>
                        <table>
                            <tr>
                                <td>Ngày:</td>
                                <td><strong>' . date_i18n('l, d/m/Y', strtotime($booking->booking_date)) . '</strong></td>
                            </tr>
                            <tr>
                                <td>Giờ:</td>
                                <td><strong>' . esc_html($booking->booking_time) . '</strong></td>
                            </tr>
                            <tr>
                                <td>Số khách:</td>
                                <td><strong>' . esc_html($booking->guest_count) . ' người</strong></td>
                            </tr>';
                            
        if (!empty($booking->special_requests)) {
            $template .= '
                            <tr>
                                <td>Yêu cầu:</td>
                                <td>' . nl2br(esc_html($booking->special_requests)) . '</td>
                            </tr>';
        }
        
        $template .= '
                            <tr>
                                <td>Thời gian đặt:</td>
                                <td>' . date_i18n('d/m/Y H:i', strtotime($booking->created_at)) . '</td>
                            </tr>
                        </table>
                    </div>
                    
                    <center>
                        <a href="' . $admin_url . '" class="button">Xem trong Admin Panel</a>
                    </center>
                    
                    <p><strong>Hành động cần thực hiện:</strong></p>
                    <ol>
                        <li>Kiểm tra bàn trống phù hợp</li>
                        <li>Xác nhận đặt bàn trong admin panel</li>
                        <li>Hệ thống sẽ tự động gửi email xác nhận cho khách</li>
                    </ol>
                </div>
            </div>
        </body>
        </html>';
        
        return $template;
    }

    private function get_flagged_booking_subject($booking, $flags, $context) {
        $flag_labels = array();
        if (in_array('vip', $flags, true)) {
            $flag_labels[] = rb_t('vip', __('VIP', 'restaurant-booking'));
        }

        if (in_array('blacklist', $flags, true)) {
            $flag_labels[] = rb_t('blacklisted', __('Blacklisted', 'restaurant-booking'));
        }

        $flag_text = !empty($flag_labels) ? implode(' & ', $flag_labels) : rb_t('notifications', __('Notifications', 'restaurant-booking'));

        $context_key = $context === 'confirmed' ? 'flagged_context_confirmed' : 'flagged_context_created';
        $context_text = rb_t($context_key, $context === 'confirmed' ? __('confirmed', 'restaurant-booking') : __('created', 'restaurant-booking'));

        $booking_code = isset($booking->id) ? '#' . str_pad($booking->id, 5, '0', STR_PAD_LEFT) : '';

        $subject_template = rb_t('flagged_booking_subject', __('[%1$s] %2$s booking %3$s (%4$s)', 'restaurant-booking'));

        return sprintf(
            $subject_template,
            get_bloginfo('name'),
            $flag_text,
            $context_text,
            $booking_code
        );
    }

    private function get_flagged_booking_template($booking, $customer, $flags, $context) {
        $flag_labels = array();
        if (in_array('vip', $flags, true)) {
            $flag_labels[] = rb_t('vip', __('VIP', 'restaurant-booking'));
        }

        if (in_array('blacklist', $flags, true)) {
            $flag_labels[] = rb_t('blacklisted', __('Blacklisted', 'restaurant-booking'));
        }

        $flag_text = !empty($flag_labels) ? implode(' & ', $flag_labels) : rb_t('notifications', __('Notifications', 'restaurant-booking'));

        $context_key = $context === 'confirmed' ? 'flagged_context_confirmed' : 'flagged_context_created';
        $context_text = rb_t($context_key, $context === 'confirmed' ? __('confirmed', 'restaurant-booking') : __('created', 'restaurant-booking'));

        $booking_code = isset($booking->id) ? '#' . str_pad($booking->id, 5, '0', STR_PAD_LEFT) : '';
        $booking_date = isset($booking->booking_date) ? date_i18n('l, d/m/Y', strtotime($booking->booking_date)) : '';
        $checkin = !empty($booking->checkin_time) ? $booking->checkin_time : (isset($booking->booking_time) ? $booking->booking_time : '');
        $checkout = !empty($booking->checkout_time) ? $booking->checkout_time : '';
        $time_range = $checkout ? sprintf('%s – %s', $checkin, $checkout) : $checkin;
        $guest_count = isset($booking->guest_count) ? intval($booking->guest_count) : 0;

        $location_name = '';
        if (!empty($booking->location_id)) {
            global $rb_location;
            if (!$rb_location || !is_a($rb_location, 'RB_Location')) {
                require_once RB_PLUGIN_DIR . 'includes/class-location.php';
                $rb_location = new RB_Location();
            }

            $location = $rb_location->get((int) $booking->location_id);
            if ($location && !empty($location->name)) {
                $location_name = $location->name;
            }
        }

        $customer_name = isset($booking->customer_name) ? $booking->customer_name : '';
        $customer_phone = isset($booking->customer_phone) ? $booking->customer_phone : '';
        $customer_email = isset($booking->customer_email) ? $booking->customer_email : '';
        $special_requests = isset($booking->special_requests) ? $booking->special_requests : '';
        $customer_notes = ($customer && isset($customer->customer_notes)) ? $customer->customer_notes : '';

        $header_context = sprintf(rb_t('flagged_booking_context', __('%1$s booking %2$s', 'restaurant-booking')), $flag_text, $context_text);

        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #8e44ad; color: white; padding: 24px; text-align: center; border-radius: 6px 6px 0 0; }
                .content { background: #fff; border: 1px solid #ddd; border-top: none; border-radius: 0 0 6px 6px; padding: 30px; }
                .badges { margin: 10px 0 20px; }
                .badge { display: inline-block; padding: 6px 12px; margin-right: 6px; border-radius: 999px; font-size: 12px; font-weight: 600; text-transform: uppercase; }
                .badge--vip { background: #f1c40f; color: #2c3e50; }
                .badge--blacklist { background: #c0392b; color: #fff; }
                .section { margin-bottom: 24px; }
                .section h3 { margin-top: 0; }
                table { width: 100%; border-collapse: collapse; }
                td { padding: 8px 0; border-bottom: 1px solid #f0f0f0; }
                td:first-child { font-weight: 600; width: 180px; }
                ul { margin: 0 0 0 20px; padding: 0; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1><?php echo esc_html(rb_t('flagged_booking_alert', __('Flagged reservation alert', 'restaurant-booking'))); ?></h1>
                    <p><?php echo esc_html($header_context); ?></p>
                </div>
                <div class="content">
                    <div class="badges">
                        <?php if (in_array('vip', $flags, true)) : ?>
                            <span class="badge badge--vip"><?php echo esc_html(rb_t('vip', __('VIP', 'restaurant-booking'))); ?></span>
                        <?php endif; ?>
                        <?php if (in_array('blacklist', $flags, true)) : ?>
                            <span class="badge badge--blacklist"><?php echo esc_html(rb_t('blacklisted', __('Blacklisted', 'restaurant-booking'))); ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="section">
                        <p><strong><?php echo esc_html(rb_t('flagged_booking_summary', __('This reservation needs attention because:', 'restaurant-booking'))); ?></strong></p>
                        <ul>
                            <?php foreach ($flag_labels as $label) : ?>
                                <li><?php echo esc_html($label); ?></li>
                            <?php endforeach; ?>
                            <li><?php echo esc_html(sprintf(rb_t('flagged_booking_state', __('Booking %1$s', 'restaurant-booking')), $context_text)); ?></li>
                        </ul>
                    </div>

                    <div class="section">
                        <h3><?php echo esc_html(rb_t('booking_details', __('Booking details', 'restaurant-booking'))); ?></h3>
                        <table>
                            <?php if ($booking_code) : ?>
                                <tr>
                                    <td><?php echo esc_html(rb_t('booking', __('Booking', 'restaurant-booking'))); ?></td>
                                    <td><?php echo esc_html($booking_code); ?></td>
                                </tr>
                            <?php endif; ?>
                            <?php if ($booking_date) : ?>
                                <tr>
                                    <td><?php echo esc_html(rb_t('date', __('Date', 'restaurant-booking'))); ?></td>
                                    <td><?php echo esc_html($booking_date); ?></td>
                                </tr>
                            <?php endif; ?>
                            <?php if (!empty($time_range)) : ?>
                                <tr>
                                    <td><?php echo esc_html(rb_t('time', __('Time', 'restaurant-booking'))); ?></td>
                                    <td><?php echo esc_html($time_range); ?></td>
                                </tr>
                            <?php endif; ?>
                            <tr>
                                <td><?php echo esc_html(rb_t('guests', __('Guests', 'restaurant-booking'))); ?></td>
                                <td><?php echo esc_html(number_format_i18n($guest_count)); ?></td>
                            </tr>
                            <?php if ($location_name) : ?>
                                <tr>
                                    <td><?php echo esc_html(rb_t('location', __('Location', 'restaurant-booking'))); ?></td>
                                    <td><?php echo esc_html($location_name); ?></td>
                                </tr>
                            <?php endif; ?>
                            <?php if (!empty($special_requests)) : ?>
                                <tr>
                                    <td><?php echo esc_html(rb_t('special_requests', __('Special Requests', 'restaurant-booking'))); ?></td>
                                    <td><?php echo nl2br(esc_html($special_requests)); ?></td>
                                </tr>
                            <?php endif; ?>
                        </table>
                    </div>

                    <div class="section">
                        <h3><?php echo esc_html(rb_t('customer', __('Customer', 'restaurant-booking'))); ?></h3>
                        <table>
                            <?php if ($customer_name) : ?>
                                <tr>
                                    <td><?php echo esc_html(rb_t('name', __('Name', 'restaurant-booking'))); ?></td>
                                    <td><?php echo esc_html($customer_name); ?></td>
                                </tr>
                            <?php endif; ?>
                            <?php if ($customer_phone) : ?>
                                <tr>
                                    <td><?php echo esc_html(rb_t('phone', __('Phone', 'restaurant-booking'))); ?></td>
                                    <td><a href="tel:<?php echo esc_attr($customer_phone); ?>"><?php echo esc_html($customer_phone); ?></a></td>
                                </tr>
                            <?php endif; ?>
                            <?php if ($customer_email) : ?>
                                <tr>
                                    <td><?php echo esc_html(rb_t('email', __('Email', 'restaurant-booking'))); ?></td>
                                    <td><a href="mailto:<?php echo esc_attr($customer_email); ?>"><?php echo esc_html($customer_email); ?></a></td>
                                </tr>
                            <?php endif; ?>
                            <?php if (!empty($customer_notes)) : ?>
                                <tr>
                                    <td><?php echo esc_html(rb_t('customer_notes', __('Customer Notes', 'restaurant-booking'))); ?></td>
                                    <td><?php echo nl2br(esc_html($customer_notes)); ?></td>
                                </tr>
                            <?php endif; ?>
                        </table>
                    </div>

                    <p><?php echo esc_html(rb_t('flagged_booking_footer', __('Please follow up with the assigned team and update the booking status once handled.', 'restaurant-booking'))); ?></p>
                </div>
            </div>
        </body>
        </html>
        <?php

        return ob_get_clean();
    }
    
    /**
     * Get cancellation email template
     */
    private function get_cancellation_email_template($booking) {
        $template = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #e74c3c; color: white; padding: 30px; text-align: center; border-radius: 5px 5px 0 0; }
                .content { background: #fff; padding: 30px; border: 1px solid #ddd; border-radius: 0 0 5px 5px; }
                .booking-details { background: #f9f9f9; padding: 20px; border-radius: 5px; margin: 20px 0; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>' . get_bloginfo('name') . '</h1>
                    <p>THÔNG BÁO HỦY ĐẶT BÀN</p>
                </div>
                
                <div class="content">
                    <h2>Xin chào ' . esc_html($booking->customer_name) . ',</h2>
                    
                    <p>Đặt bàn của bạn đã bị <strong>HỦY</strong>.</p>
                    
                    <div class="booking-details">
                        <p><strong>Mã đặt bàn:</strong> #' . str_pad($booking->id, 5, '0', STR_PAD_LEFT) . '</p>
                        <p><strong>Ngày:</strong> ' . date_i18n('d/m/Y', strtotime($booking->booking_date)) . '</p>
                        <p><strong>Giờ:</strong> ' . esc_html($booking->booking_time) . '</p>
                    </div>
                    
                    <p>Nếu bạn muốn đặt bàn lại, vui lòng truy cập website của chúng tôi.</p>
                    
                    <p>Trân trọng,<br>' . get_bloginfo('name') . '</p>
                </div>
            </div>
        </body>
        </html>';
        
        return $template;
    }
}