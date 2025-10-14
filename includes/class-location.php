<?php
/**
 * Location helper class
 */

if (!defined('ABSPATH')) {
    exit;
}

class RB_Location {
    /** @var wpdb */
    private $wpdb;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    public function all($args = array()) {
        $defaults = array(
            'orderby' => 'name',
            'order' => 'ASC',
        );

        $args = wp_parse_args($args, $defaults);

        $order = strtoupper($args['order']) === 'DESC' ? 'DESC' : 'ASC';
        $orderby = in_array($args['orderby'], array('id', 'name', 'slug'), true) ? $args['orderby'] : 'name';

        $table = $this->wpdb->prefix . 'rb_locations';

        return $this->wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY {$orderby} {$order}"
        );
    }

    public function get($id) {
        $table = $this->wpdb->prefix . 'rb_locations';

        return $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id)
        );
    }

    public function get_by_slug($slug) {
        $table = $this->wpdb->prefix . 'rb_locations';

        return $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$table} WHERE slug = %s", $slug)
        );
    }

    public function get_settings($id) {
        $location = $this->get($id);

        if (!$location) {
            return array();
        }

        return array(
            'opening_time' => $location->opening_time,
            'closing_time' => $location->closing_time,
            'time_slot_interval' => (int) $location->time_slot_interval,
            'min_advance_booking' => (int) $location->min_advance_booking,
            'max_advance_booking' => (int) $location->max_advance_booking,
            'languages' => array_map('trim', explode(',', $location->languages)),
            'hotline' => $location->hotline,
            'email' => $location->email,
            'address' => $location->address,
            'shift_notes' => $location->shift_notes,
        );
    }

    public function update_settings($id, $data) {
        $table = $this->wpdb->prefix . 'rb_locations';

        $fields = array_intersect_key($data, array(
            'name' => true,
            'email' => true,
            'hotline' => true,
            'address' => true,
            'opening_time' => true,
            'closing_time' => true,
            'time_slot_interval' => true,
            'min_advance_booking' => true,
            'max_advance_booking' => true,
            'default_table_count' => true,
            'default_capacity' => true,
            'shift_notes' => true,
            'languages' => true,
        ));

        if (empty($fields)) {
            return false;
        }

        if (isset($fields['languages']) && is_array($fields['languages'])) {
            $fields['languages'] = implode(',', array_map('trim', $fields['languages']));
        }

        return $this->wpdb->update(
            $table,
            $fields,
            array('id' => (int) $id)
        );
    }
}
