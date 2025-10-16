# Hướng dẫn tích hợp giao diện Booking mới

## Tổng quan

Giao diện booking mới được thiết kế theo phong cách hiện đại tương tự Pizza 4Ps với:
- **2 bước đặt bàn**: Kiểm tra chỗ trống → Điền thông tin
- **1 shortcode duy nhất**: `[restaurant_booking]` 
- **Responsive design**: Hoạt động tốt trên mọi thiết bị
- **Multilingual support**: Hỗ trợ đa ngôn ngữ với language switcher
- **Modern UX/UI**: Giao diện đẹp, smooth animations

## Cấu trúc Files

```
/public/
├── class-frontend-public.php     (Đã cập nhật với giao diện mới)
├── class-frontend.php           (Đã cập nhật - facade class)
├── class-frontend-base.php      (Giữ nguyên)
└── class-frontend-manager.php   (Giữ nguyên)

/assets/
├── css/
│   ├── frontend.css            (CSS cũ - có thể giữ để backward compatibility)
│   └── new-frontend.css        (CSS mới)
└── js/
    └── new-booking.js          (JavaScript mới)
```

## Cách tích hợp

### Bước 1: Cập nhật file chính của plugin

Thêm code sau vào file chính của plugin hoặc trong `functions.php`:

```php
// Enqueue assets cho giao diện mới
require_once RB_PLUGIN_DIR . 'includes/class-assets-manager.php';

// Hoặc thêm trực tiếp vào hook
add_action('wp_enqueue_scripts', function() {
    if (is_singular() || is_front_page()) {
        wp_enqueue_style('rb-new-frontend', plugin_dir_url(__FILE__) . 'assets/css/new-frontend.css', array(), '1.0.0');
        wp_enqueue_script('rb-new-booking', plugin_dir_url(__FILE__) . 'assets/js/new-booking.js', array('jquery'), '1.0.0', true);
        
        wp_localize_script('rb-new-booking', 'rbBookingAjax', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rb_frontend_nonce'),
        ));
    }
});
```

### Bước 2: Cập nhật shortcode registration

```php
// Trong file chính của plugin
add_shortcode('restaurant_booking', array($rb_frontend, 'render_booking_form'));

// Backward compatibility (optional)
add_shortcode('restaurant_booking_portal', array($rb_frontend, 'render_booking_form'));
```

### Bước 3: Tạo thư mục assets

```bash
mkdir -p assets/css
mkdir -p assets/js

# Copy CSS và JS từ artifacts vào đúng folder
```

## Sử dụng Shortcode

### Shortcode cơ bản
```
[restaurant_booking]
```

### Với các tùy chọn
```
[restaurant_booking title="Đặt bàn ngay" button_text="Book Now" show_button="yes"]
```

### Inline form (không có button modal)
```
[restaurant_booking show_button="no" title="Reservation Form"]
```

## Tùy chỉnh giao diện

### CSS Variables (tùy chọn)

Thêm vào theme hoặc custom CSS:

```css
:root {
    --rb-primary-color: #ff6b6b;
    --rb-primary-hover: #ff5252;
    --rb-border-radius: 12px;
    --rb-box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
}

/* Override primary button color */
.rb-new-btn-primary {
    background: linear-gradient(135deg, var(--rb-primary-color), var(--rb-primary-hover));
}
```

### Thay đổi màu sắc chính

```css
/* Custom theme colors */
.rb-new-open-modal-btn,
.rb-new-btn-primary {
    background: linear-gradient(135deg, #your-color, #your-hover-color);
}

.rb-new-form-group input:focus,
.rb-new-form-group select:focus {
    border-color: #your-color;
    box-shadow: 0 0 0 3px rgba(your-color-rgb, 0.1);
}
```

## Tính năng chính

### 1. Kiểm tra tình trạng bàn trống
- Chọn location, ngày, giờ, số khách
- AJAX real-time checking
- Hiển thị suggestions nếu không có chỗ trống

### 2. Language Switcher
- Dropdown chọn ngôn ngữ
- Sync across all form steps
- Support WPML/Polylang

### 3. Responsive Design
- Mobile-first approach
- Touch-friendly buttons
- Smooth animations

### 4. Accessibility
- Keyboard navigation
- Screen reader support
- Focus management
- ARIA attributes

## Cấu hình Backend

### Settings cần thiết

1. **Locations**: Cấu hình ít nhất 1 location
2. **Time slots**: Cài đặt giờ mở cửa, đóng cửa, interval
3. **Booking rules**: Min/max advance booking
4. **Languages**: Cấu hình các ngôn ngữ support

### Email Templates

Đảm bảo email templates hoạt động với:
- Pending confirmation
- Admin notification
- Booking confirmed

## Migration từ giao diện cũ

### Backward Compatibility

Giao diện mới hoàn toàn tương thích ngược:
- Tất cả AJAX endpoints giữ nguyên
- Database schema không thay đổi
- Shortcode cũ vẫn hoạt động

### Testing

1. Test trên các shortcode cũ
2. Kiểm tra responsive trên mobile
3. Test multilingual functionality
4. Verify email notifications

## Troubleshooting

### CSS không load
```php
// Kiểm tra đường dẫn file CSS
wp_enqueue_style('rb-new-frontend', 
    plugin_dir_url(__FILE__) . 'assets/css/new-frontend.css'
);
```

### JavaScript errors
```javascript
// Kiểm tra jQuery đã load
if (typeof jQuery === 'undefined') {
    console.error('jQuery is required for Restaurant Booking');
}
```

### AJAX không hoạt động
```php
// Kiểm tra nonce và permissions
wp_localize_script('rb-new-booking', 'rbBookingAjax', array(
    'ajaxUrl' => admin_url('admin-ajax.php'),
    'nonce' => wp_create_nonce('rb_frontend_nonce'),
));
```

## Performance Optimization

### Conditional Loading
```php
// Chỉ load trên trang cần thiết
function should_load_booking_assets() {
    global $post;
    
    if (is_admin()) return false;
    
    // Check for shortcode in content
    if (has_shortcode($post->post_content, 'restaurant_booking')) {
        return true;
    }
    
    // Check custom pages
    if (is_page(array('booking', 'reservation'))) {
        return true;
    }
    
    return false;
}
```

### Asset Minification
```bash
# Minify CSS và JS cho production
npm install -g clean-css-cli uglify-js

cleancss -o new-frontend.min.css new-frontend.css
uglifyjs new-booking.js -o new-booking.min.js
```

## Browser Support

- Chrome 60+
- Firefox 55+
- Safari 12+
- Edge 79+
- Mobile browsers (iOS Safari, Chrome Mobile)

## Next Steps

1. **Testing**: Test toàn diện trên staging environment
2. **Customization**: Tùy chỉnh màu sắc, typography theo brand
3. **Analytics**: Thêm tracking events cho booking flow
4. **A/B Testing**: So sánh conversion rate với giao diện cũ
5. **Documentation**: Cập nhật user documentation

## Support & Updates

- Monitor console errors sau khi deploy
- Collect user feedback về UX
- Plan cho future enhancements (calendar picker, time range selection, etc.)