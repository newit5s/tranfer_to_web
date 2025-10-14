# Restaurant Booking Manager Plugin

Plugin WordPress quản lý đặt bàn nhà hàng hoàn chỉnh với giao diện thân thiện người dùng và quản lý admin chuyên nghiệp.

## 📁 Cấu trúc thư mục

```
restaurant-booking-manager/
├── restaurant-booking-manager.php          # File plugin chính
├── includes/
│   ├── class-database.php                  # Quản lý cơ sở dữ liệu
│   ├── class-booking.php                   # Logic nghiệp vụ đặt bàn  
│   ├── class-ajax.php                      # Xử lý AJAX requests
│   └── class-email.php                     # Gửi email tự động
├── admin/
│   └── class-admin.php                     # Giao diện admin
├── public/
│   └── class-frontend.php                  # Giao diện frontend
└── assets/
    ├── css/
    │   ├── frontend.css                    # CSS cho frontend
    │   └── admin.css                       # CSS cho admin
    └── js/
        ├── frontend.js                     # JavaScript frontend
        └── admin.js                        # JavaScript admin
```

## 🚀 Cài đặt

### Bước 1: Tạo thư mục plugin
```bash
wp-content/plugins/restaurant-booking-manager/
```

### Bước 2: Copy các file
- Tạo tất cả các file theo cấu trúc thư mục ở trên
- Copy code từ các artifacts vào đúng file tương ứng

### Bước 3: Kích hoạt plugin
1. Vào WordPress Admin > Plugins  
2. Tìm "Restaurant Booking Manager"
3. Click "Activate"

### Bước 4: Cấu hình cơ bản
1. Vào **Admin > Đặt bàn > Cài đặt**
2. Thiết lập:
   - Số bàn tối đa
   - Giờ mở cửa/đóng cửa
   - Thời gian đặt bàn

## 📝 Sử dụng

### Hiển thị form đặt bàn

**Shortcode cơ bản:**
```
[restaurant_booking]
```

**Shortcode tùy chỉnh:**
```
[restaurant_booking title="Đặt bàn ngay" button_text="Book Now"]
```

### Quản lý đặt bàn

1. **Xem đặt bàn:** Admin > Đặt bàn
   - Tab "Chờ xác nhận": Đặt bàn mới cần xử lý
   - Tab "Đã xác nhận": Đặt bàn đã confirm
   - Tab "Đã hủy": Đặt bàn bị hủy

2. **Xác nhận đặt bàn:**
   - Click "Xác nhận" trên đặt bàn pending
   - Chọn bàn phù hợp
   - Email confirm tự động gửi cho khách

3. **Quản lý bàn:** Admin > Quản lý bàn
   - Xem tình trạng tất cả bàn
   - Reset bàn khi khách sử dụng xong
   - Tạm ngưng/kích hoạt bàn

## 💻 Tính năng chính

### Frontend (Khách hàng)
- ✅ Modal đặt bàn responsive
- ✅ Kiểm tra bàn trống realtime  
- ✅ Form validation đầy đủ
- ✅ Thông báo trạng thái đặt bàn
- ✅ Tối ưu mobile/desktop

### Backend (Admin)
- ✅ Dashboard quản lý trực quan
- ✅ Xác nhận đặt bàn với chọn bàn
- ✅ Quản lý trạng thái bàn
- ✅ Email tự động HTML đẹp
- ✅ Thống kê cơ bản

### Hệ thống Email
- ✅ Email thông báo admin khi có đặt bàn mới
- ✅ Email xác nhận cho khách hàng  
- ✅ Template HTML responsive
- ✅ Thông tin đầy đủ và đẹp mắt

## 🔧 Customization

### Thay đổi giao diện
**CSS Frontend:**
```css
.rb-booking-widget {
    /* Tùy chỉnh widget đặt bàn */
}

.rb-modal {
    /* Tùy chỉnh modal */
}
```

**CSS Admin:**
```css
.rb-status {
    /* Tùy chỉnh trạng thái đặt bàn */
}
```

### Hooks và Filters

**Actions:**
```php
// Sau khi tạo đặt bàn thành công
do_action('rb_booking_created', $booking_id);

// Sau khi xác nhận đặt bàn
do_action('rb_booking_confirmed', $booking_id);

// Sau khi hủy đặt bàn
do_action('rb_booking_cancelled', $booking_id);
```

**Filters:**
```php
// Tùy chỉnh email template
add_filter('rb_email_template', 'custom_email_template', 10, 2);

// Tùy chỉnh validation
add_filter('rb_booking_validation', 'custom_validation', 10, 2);
```

## 📊 Database Schema

### Bảng `wp_restaurant_bookings`
```sql
- id: ID đặt bàn
- customer_name: Tên khách hàng  
- customer_phone: Số điện thoại
- customer_email: Email
- guest_count: Số lượng khách
- booking_date: Ngày đặt
- booking_time: Giờ đặt  
- table_number: Số bàn
- status: Trạng thái (pending/confirmed/cancelled/completed)
- special_requests: Yêu cầu đặc biệt
- created_at: Thời gian tạo
- confirmed_at: Thời gian xác nhận
```

### Bảng `wp_restaurant_tables`  
```sql
- id: ID bàn
- table_number: Số bàn
- capacity: Sức chứa
- is_available: Có hoạt động không
- created_at: Thời gian tạo
```

### Bảng `wp_restaurant_table_availability`
```sql  
- id: ID record
- table_id: ID bàn
- booking_date: Ngày
- booking_time: Giờ
- is_occupied: Có bị chiếm không  
- booking_id: ID đặt bàn
- created_at: Thời gian tạo
```

## 🔒 Bảo mật

- ✅ **Nonce verification** cho mọi AJAX request
- ✅ **Data sanitization** cho input
- ✅ **Permission checks** cho admin functions
- ✅ **SQL injection prevention** với prepared statements
- ✅ **XSS protection** với proper escaping

## 📱 Responsive Design

Plugin được thiết kế mobile-first:
- Modal tự động điều chỉnh kích thước
- Form layout responsive 
- Touch-friendly buttons
- Optimized cho mọi screen size

## 🚀 Tối ưu Performance  

- ✅ **AJAX loading** - Không reload trang
- ✅ **Lazy loading** - Load content khi cần
- ✅ **Caching friendly** - Tương thích cache plugins
- ✅ **Optimized queries** - Database queries hiệu quả

## 🔄 Tính năng mở rộng

Plugin được thiết kế để dễ dàng mở rộng:

### Tính năng có thể thêm:
- 📊 **Analytics & Reports** - Báo cáo chi tiết
- 💳 **Payment Integration** - Thanh toán online  
- 📱 **SMS Notifications** - Gửi SMS
- 🎫 **QR Code Booking** - Mã QR cho đặt bàn
- 🔄 **Multi-location** - Nhiều chi nhánh
- 📅 **Calendar Integration** - Tích hợp Google Calendar
- ⭐ **Reviews System** - Hệ thống đánh giá
- 🎯 **Loyalty Program** - Chương trình khách hàng thân thiết

## 📞 Support

Để được hỗ trợ và báo lỗi:
1. Kiểm tra WordPress debug log
2. Kiểm tra browser console cho lỗi JavaScript
3. Verify database tables đã được tạo đúng

## 📄 License

GPL v2 or later

---

**Made with ❤️ for Vietnamese Restaurants**
