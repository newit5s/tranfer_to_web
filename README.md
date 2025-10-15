# Restaurant Booking Manager Plugin

Plugin WordPress quản lý đặt bàn nhà hàng hoàn chỉnh với giao diện thân thiện người dùng và quản lý admin chuyên nghiệp.

Phiên bản hiện tại bổ sung **tài khoản portal nội bộ** cho nhân viên duyệt đơn, quy trình đặt bàn đa bước `[restaurant_booking_portal]` và bộ lọc chi nhánh theo người dùng, cho phép triển khai hệ thống đặt bàn mà không cần tạo tài khoản WordPress cho từng quản lý.

## 📁 Cấu trúc thư mục

```
restaurant-booking-manager/
├── restaurant-booking-manager.php          # File plugin chính
├── includes/
│   ├── class-database.php                  # Quản lý cơ sở dữ liệu
│   ├── class-booking.php                   # Logic nghiệp vụ đặt bàn  
│   ├── class-ajax.php                      # Xử lý AJAX requests
│   ├── class-email.php                     # Gửi email tự động
│   └── class-portal-account.php            # Quản lý tài khoản portal & session
├── admin/
│   └── class-admin.php                     # Giao diện admin
├── public/
│   └── class-frontend.php                  # Giao diện frontend
└── assets/
    ├── css/
    │   ├── frontend.css                    # CSS cho frontend & portal đa bước
    │   └── admin.css                       # CSS cho admin & tab portal accounts
    └── js/
        ├── frontend.js                     # JavaScript frontend & flow đa bước
        └── admin.js                        # JavaScript admin & CRUD portal account
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
2. Ở tab **Cấu hình**, thiết lập:
   - Số bàn tối đa
   - Giờ mở cửa/đóng cửa
   - Thời gian đặt bàn
3. Chuyển sang tab **Portal Accounts** để tạo tài khoản portal, gán chi nhánh và thiết lập trạng thái hoạt động cho từng nhân viên.

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

### Portal đa bước cho khách

Shortcode mới hiển thị flow đặt bàn 3 bước, hỗ trợ đa ngôn ngữ và kiểm tra chỗ trống theo chi nhánh:

```
[restaurant_booking_portal]
```

*Bước 1:* chọn ngôn ngữ & chi nhánh → *Bước 2:* kiểm tra giờ trống (kèm gợi ý) → *Bước 3:* nhập thông tin khách và xác nhận.

### Quản lý đặt bàn

1. **Xem đặt bàn:** Admin > Đặt bàn
   - Tab "Chờ xác nhận": Đặt bàn mới cần xử lý
   - Tab "Đã xác nhận": Đặt bàn đã confirm
   - Tab "Đã hủy": Đặt bàn bị hủy

2. **Quản lý chi nhánh theo tài khoản:**
   - Tab **Portal Accounts** (trong trang Cài đặt) cho phép tạo tài khoản nội bộ, đặt tên hiển thị, email, trạng thái, mật khẩu.
   - Chọn một hoặc nhiều chi nhánh để giới hạn quyền truy cập của từng tài khoản.

3. **Portal quản lý đặt bàn:**
   - Shortcode `[restaurant_booking_manager]` hiển thị portal quản lý cho tài khoản portal và người dùng có quyền `rb_manage_location`.
   - Portal chỉ load danh sách chi nhánh đã gán và lưu lựa chọn vào hồ sơ người vận hành.

4. **Xác nhận đặt bàn:**
   - Click "Xác nhận" trên đặt bàn pending
   - Chọn bàn phù hợp
   - Email confirm tự động gửi cho khách

5. **Quản lý bàn:** Admin > Quản lý bàn
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

### Portal Accounts (Quản lý nội bộ)
- ✅ Tạo/Chỉnh sửa/Xoá tài khoản portal ngay trong trang Cài đặt plugin
- ✅ Gán nhiều chi nhánh cho mỗi tài khoản và tự động giới hạn truy cập
- ✅ Đăng nhập portal độc lập không cần tài khoản WordPress
- ✅ Ghi nhận trạng thái, lần đăng nhập gần nhất và khóa/mở tài khoản nhanh chóng

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
do_action('rb_booking_created', $booking_id, $booking);

// Sau khi xác nhận đặt bàn
do_action('rb_booking_confirmed', $booking_id, $booking);

// Sau khi hủy đặt bàn
do_action('rb_booking_cancelled', $booking_id, $booking);

// Sau khi hoàn tất phục vụ (đánh dấu completed)
do_action('rb_booking_completed', $booking_id, $booking);
```

**Filters:**
```php
// Tùy chỉnh email template
add_filter('rb_email_template', 'custom_email_template', 10, 2);

// Tùy chỉnh validation
add_filter('rb_booking_validation', 'custom_validation', 10, 2);
```

## 📊 Database Schema

### Bảng `wp_rb_bookings`
```sql
- id: ID đặt bàn
- customer_name: Tên khách hàng
- customer_phone: Số điện thoại (đã chuẩn hóa)
- customer_email: Email
- guest_count: Số lượng khách
- booking_date: Ngày đặt
- booking_time: Giờ đặt
- table_number: Số bàn được gán khi xác nhận
- status: Trạng thái (pending/confirmed/cancelled/completed/no-show)
- special_requests: Yêu cầu đặc biệt
- booking_source: Nguồn đặt bàn (website, hotline...)
- location_id: Chi nhánh phục vụ
- language: Ngôn ngữ khách đã chọn
- created_at: Thời gian tạo
- confirmed_at: Thời gian xác nhận
```

### Bảng `wp_rb_tables`
```sql
- id: ID bàn
- location_id: Thuộc chi nhánh nào
- table_number: Số bàn
- capacity: Sức chứa tối đa
- is_available: Bàn đang hoạt động?
- created_at: Thời gian tạo
```

### Bảng `wp_rb_customers`
```sql
- id: ID khách hàng
- name: Tên khách
- phone: Số điện thoại
- email: Email
- total_bookings: Tổng số lần đặt bàn
- total_guests: Tổng số khách đã phục vụ
- status: VIP/Black-list/Normal
- last_booking_at: Lần đặt gần nhất
```

### Bảng `wp_rb_locations`
```sql
- id: ID chi nhánh
- name: Tên chi nhánh
- slug: Định danh duy nhất
- hotline: Hotline liên hệ
- email: Email nhận thông báo
- address: Địa chỉ
- opening_time / closing_time: Giờ mở - đóng cửa
- time_slot_interval: Khoảng cách giữa các ca
- min_advance_booking / max_advance_booking: Giới hạn đặt trước
- languages: Danh sách ngôn ngữ phục vụ
```

### Bảng `wp_rb_portal_accounts`
```sql
- id: ID tài khoản portal
- username: Định danh đăng nhập duy nhất
- display_name: Tên hiển thị trong giao diện quản lý
- email: Email liên hệ (tùy chọn)
- password_hash: Mật khẩu đã băm theo chuẩn WordPress
- status: Trạng thái (active/inactive/locked)
- last_login_at: Lần đăng nhập gần nhất
- created_at: Thời gian tạo tài khoản
- updated_at: Lần cập nhật gần nhất
```

### Bảng `wp_rb_portal_account_locations`
```sql
- account_id: Liên kết tới tài khoản portal
- location_id: Chi nhánh được phép truy cập
- assigned_at: Thời điểm gán quyền
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
