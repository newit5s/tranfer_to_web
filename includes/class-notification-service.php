<?php
/**
 * Notification service for flagged bookings (VIP & blacklist).
 */

if (!defined('ABSPATH')) {
    exit;
}

class RB_Notification_Service {

    public function __construct() {
        add_action('rb_booking_created', array($this, 'handle_booking_created'), 20, 2);
        add_action('rb_booking_confirmed', array($this, 'handle_booking_confirmed'), 20, 2);
    }

    public function handle_booking_created($booking_id, $booking) {
        $this->dispatch_flagged_alert($booking, 'created');
    }

    public function handle_booking_confirmed($booking_id, $booking) {
        $this->dispatch_flagged_alert($booking, 'confirmed');
    }

    private function dispatch_flagged_alert($booking, $context) {
        if (empty($booking)) {
            return;
        }

        $settings = get_option('rb_settings', array());
        $should_notify_vip = isset($settings['notify_vip_bookings']) && $settings['notify_vip_bookings'] === 'yes';
        $should_notify_blacklist = isset($settings['notify_blacklist_events']) && $settings['notify_blacklist_events'] === 'yes';

        if (!$should_notify_vip && !$should_notify_blacklist) {
            return;
        }

        $customer = $this->get_customer_for_booking($booking);
        if (!$customer) {
            return;
        }

        $flags = array();
        if ($should_notify_vip && !empty($customer->vip_status)) {
            $flags[] = 'vip';
        }

        if ($should_notify_blacklist && !empty($customer->blacklisted)) {
            $flags[] = 'blacklist';
        }

        if (empty($flags)) {
            return;
        }

        $recipients = $this->collect_recipients($settings, $booking, $flags);
        $recipients = apply_filters('rb_flagged_booking_notification_recipients', $recipients, $booking, $customer, $flags, $context);

        if (empty($recipients)) {
            return;
        }

        global $rb_email;
        if (!$rb_email || !is_object($rb_email)) {
            $rb_email = new RB_Email();
        }

        if (method_exists($rb_email, 'send_flagged_booking_alert')) {
            $rb_email->send_flagged_booking_alert($recipients, $booking, $customer, $flags, $context);
        }
    }

    private function collect_recipients($settings, $booking, $flags) {
        $emails = array();

        $admin_email = isset($settings['admin_email']) ? sanitize_email($settings['admin_email']) : '';
        if (!$admin_email) {
            $admin_email = sanitize_email(get_option('admin_email'));
        }

        if ($admin_email && is_email($admin_email)) {
            $emails[] = $admin_email;
        }

        $location_email = $this->get_location_email(isset($booking->location_id) ? (int) $booking->location_id : 0);
        if ($location_email) {
            $emails[] = $location_email;
        }

        if (in_array('vip', $flags, true)) {
            $emails = array_merge($emails, $this->parse_recipients(isset($settings['vip_notification_recipients']) ? $settings['vip_notification_recipients'] : ''));
        }

        if (in_array('blacklist', $flags, true)) {
            $emails = array_merge($emails, $this->parse_recipients(isset($settings['blacklist_notification_recipients']) ? $settings['blacklist_notification_recipients'] : ''));
        }

        return $this->unique_emails($emails);
    }

    private function parse_recipients($value) {
        if (empty($value)) {
            return array();
        }

        if (is_array($value)) {
            $raw = $value;
        } else {
            $raw = preg_split('/[\r\n,]+/', $value);
        }

        $emails = array();
        foreach ($raw as $maybe_email) {
            $maybe_email = trim($maybe_email);
            if ($maybe_email && is_email($maybe_email)) {
                $emails[] = $maybe_email;
            }
        }

        return $emails;
    }

    private function unique_emails($emails) {
        if (empty($emails)) {
            return array();
        }

        $unique = array();
        $seen = array();
        foreach ($emails as $email) {
            $key = strtolower($email);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $email;
        }

        return $unique;
    }

    private function get_location_email($location_id) {
        if ($location_id <= 0) {
            return '';
        }

        global $rb_location;
        if (!$rb_location || !is_a($rb_location, 'RB_Location')) {
            require_once RB_PLUGIN_DIR . 'includes/class-location.php';
            $rb_location = new RB_Location();
        }

        $location = $rb_location->get($location_id);
        if (!$location || empty($location->email)) {
            return '';
        }

        $email = sanitize_email($location->email);
        return $email && is_email($email) ? $email : '';
    }

    private function get_customer_for_booking($booking) {
        global $wpdb;
        if (!$wpdb) {
            return null;
        }

        $table = $wpdb->prefix . 'rb_customers';
        $location_id = isset($booking->location_id) ? (int) $booking->location_id : 0;
        $phone = isset($booking->customer_phone) ? $booking->customer_phone : '';
        $email = isset($booking->customer_email) ? $booking->customer_email : '';

        $customer = null;
        if ($phone) {
            if ($location_id > 0) {
                $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE phone = %s AND location_id = %d", $phone, $location_id));
            } else {
                $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE phone = %s", $phone));
            }
        }

        if (!$customer && $email) {
            if ($location_id > 0) {
                $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE email = %s AND location_id = %d", $email, $location_id));
            } else {
                $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE email = %s", $email));
            }
        }

        return $customer;
    }
}
