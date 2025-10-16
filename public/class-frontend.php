<?php
/**
 * Facade for public and manager frontend surfaces.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('RB_Frontend_Base')) {
    require_once RB_PLUGIN_DIR . 'public/class-frontend-base.php';
}

if (!class_exists('RB_Frontend_Public')) {
    require_once RB_PLUGIN_DIR . 'public/class-frontend-public.php';
}

if (!class_exists('RB_Frontend_Manager')) {
    require_once RB_PLUGIN_DIR . 'public/class-frontend-manager.php';
}

class RB_Frontend {

    private static $public_surface;
    private static $manager_surface;

    public function __construct() {
        if (!self::$public_surface) {
            self::$public_surface = RB_Frontend_Public::get_instance();
        }

        if (!self::$manager_surface) {
            self::$manager_surface = RB_Frontend_Manager::get_instance();
        }
    }

    public function render_booking_form($atts) {
        return self::$public_surface->render_booking_portal($atts);
    }

    public function render_booking_portal($atts) {
        return self::$public_surface->render_booking_portal($atts);
    }

    public function render_multi_location_portal($atts) {
        return self::$public_surface->render_booking_portal($atts);
    }

    public function render_location_manager($atts) {
        return self::$manager_surface->render_location_manager($atts);
    }
}
