<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('RB_Language_Switcher_Widget') && class_exists('WP_Widget')) {
    class RB_Language_Switcher_Widget extends WP_Widget {

        public function __construct() {
            parent::__construct(
                'rb_language_switcher_widget',
                rb_t('language_switcher'),
                array('description' => rb_t('language_switcher_description'))
            );
        }

        public function widget($args, $instance) {
            echo $args['before_widget'];

            if (!empty($instance['title'])) {
                echo $args['before_title'] . apply_filters('widget_title', $instance['title']) . $args['after_title'];
            }

            $switcher = new RB_Language_Switcher();
            $style = !empty($instance['style']) ? $instance['style'] : 'dropdown';

            if ($style === 'flags') {
                $switcher->render_flags();
            } else {
                $switcher->render_dropdown();
            }

            echo $args['after_widget'];
        }

        public function form($instance) {
            $title = !empty($instance['title']) ? $instance['title'] : rb_t('language');
            $style = !empty($instance['style']) ? $instance['style'] : 'dropdown';
            ?>
            <p>
                <label for="<?php echo $this->get_field_id('title'); ?>"><?php rb_e('title'); ?>:</label>
                <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>"
                       name="<?php echo $this->get_field_name('title'); ?>" type="text"
                       value="<?php echo esc_attr($title); ?>">
            </p>
            <p>
                <label for="<?php echo $this->get_field_id('style'); ?>"><?php rb_e('style'); ?>:</label>
                <select class="widefat" id="<?php echo $this->get_field_id('style'); ?>"
                        name="<?php echo $this->get_field_name('style'); ?>">
                    <option value="dropdown" <?php selected($style, 'dropdown'); ?>><?php rb_e('dropdown'); ?></option>
                    <option value="flags" <?php selected($style, 'flags'); ?>><?php rb_e('flags'); ?></option>
                </select>
            </p>
            <?php
        }

        public function update($new_instance, $old_instance) {
            $instance = array();
            $instance['title'] = (!empty($new_instance['title'])) ? strip_tags($new_instance['title']) : '';
            $instance['style'] = (!empty($new_instance['style'])) ? strip_tags($new_instance['style']) : 'dropdown';
            return $instance;
        }
    }
}
