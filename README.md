# Restaurant Booking Manager

Plugin WordPress giúp nhà hàng quản lý đặt bàn với giao diện đặt chỗ hiện đại cho khách, portal riêng cho nhân viên và bộ công cụ quản trị đầy đủ. Phiên bản này đã được tinh gọn lại, loại bỏ mã thử nghiệm cũ và cập nhật tài liệu cho đúng với cấu trúc hiện tại.

## 📦 Cấu trúc thư mục

```
restaurant-booking-manager/
├── restaurant-booking-manager.php      # Bootstrap plugin
├── admin/
│   └── class-admin.php                 # Màn hình quản trị & settings
├── includes/
│   ├── class-ajax.php                  # Endpoint AJAX cho frontend & portal
│   ├── class-assets-manager.php        # Enqueue giao diện đặt bàn mới
│   ├── class-booking.php               # Business logic đặt bàn
│   ├── class-customer.php              # Quản lý khách hàng & lịch sử
│   ├── class-database.php              # Tạo & migrate bảng dữ liệu
│   ├── class-email.php                 # Gửi email xác nhận & thông báo
│   ├── class-i18n.php                  # Đa ngôn ngữ & bản dịch
│   ├── class-language-switcher.php     # Shortcode + widget đổi ngôn ngữ
│   ├── class-location.php              # Quản lý chi nhánh & lịch làm việc
│   └── class-portal-account.php        # Tài khoản portal & session
├── public/
│   ├── class-frontend-base.php         # Logic chia sẻ giữa frontend/portal
│   ├── class-frontend-public.php       # Widget đặt bàn mới (customer facing)
│   ├── class-frontend-manager.php      # Portal quản lý cho nhân viên
│   └── class-frontend.php              # Facade nạp các bề mặt frontend
├── assets/
│   ├── css/
│   │   ├── admin.css                   # Phong cách trang quản trị plugin
│   │   ├── frontend.css                # Portal quản lý (layout kế thừa)
│   │   └── new-frontend.css            # Giao diện đặt bàn mới dạng modal
│   └── js/
│       ├── admin.js                    # Tương tác CRUD trong trang admin
│       ├── frontend.js                 # Portal quản lý & bảng điều khiển
│       └── new-booking.js              # Logic luồng đặt bàn mới 3 bước
├── languages/                          # File bản dịch (vi, en, ja)
└── public assets khác...
```

## ✅ Yêu cầu

- WordPress 5.8+ và PHP 7.0 trở lên
- Bật wp-cron để xử lý email và nhắc hẹn
- Quyền tạo bảng trong cơ sở dữ liệu MySQL

## 🚀 Cài đặt & kích hoạt

1. Upload toàn bộ thư mục `restaurant-booking-manager` vào `wp-content/plugins/`.
2. Đăng nhập trang quản trị WordPress, vào **Plugins → Installed Plugins**.
3. Kích hoạt **Restaurant Booking Manager**.
4. Vào **Đặt bàn → Cài đặt** để cấu hình:
   - Số bàn tối đa, giờ mở/đóng cửa và khoảng cách ca làm việc.
   - Giờ nghỉ trưa, ca sáng/chiều (nếu dùng chế độ nâng cao).
   - Bật/tắt email tự động và cập nhật email nhận thông báo.
5. Sang tab **Portal Accounts** để tạo tài khoản nội bộ, gán chi nhánh và mật khẩu cho từng quản lý.

## 🧭 Shortcode & Widget

| Mục đích | Shortcode | Mô tả |
| --- | --- | --- |
| Form đặt bàn cho khách | `[restaurant_booking]` | Giao diện modal 3 bước, tự nạp CSS/JS mới (`new-frontend.css`, `new-booking.js`). |
| Portal dành cho nhân viên | `[restaurant_booking_manager]` | Dashboard quản lý đặt bàn, sử dụng assets kế thừa (`frontend.css`, `frontend.js`). |
| Bộ chọn ngôn ngữ | `[rb_language_switcher style="flags"]` | Hiển thị dropdown hoặc biểu tượng cờ, đồng bộ với hệ thống RB_I18n. |

> **Mẹo:** Có thể đặt shortcode đặt bàn vào Gutenberg block, widget, hoặc template PHP (`echo do_shortcode('[restaurant_booking]');`).

## ✨ Tính năng nổi bật

### Giao diện khách hàng

- Modal responsive với 3 bước: chọn lịch, nhập thông tin, xác nhận.
- Kiểm tra bàn trống theo chi nhánh, gợi ý giờ lân cận khi full slot.
- Tự động chuyển ngôn ngữ và bản dịch theo lựa chọn của khách.
- Xác nhận qua email kèm token bảo mật.

### Portal quản lý

- Đăng nhập bằng tài khoản nội bộ, gán được nhiều chi nhánh.
- Quản lý trạng thái đặt bàn (pending/confirmed/cancelled/completed).
- Cập nhật bàn, khách hàng VIP/Blacklist, ghi chú nội bộ.
- CRUD bàn ăn, cấu hình giờ mở cửa theo từng chi nhánh.

### Trang quản trị WordPress

- Tabs cấu hình chung, Portal Accounts, Quản lý chi nhánh.
- Assets admin riêng (`admin.css`, `admin.js`) với AJAX nonce bảo vệ.
- Thống kê đơn đặt bàn, thiết lập email, buffer time giữa các ca.

## 🌐 Đa ngôn ngữ

- File dịch `.po/.mo` nằm trong thư mục `languages/` (vi_VN, en_US, ja_JP).
- Lớp `RB_I18n` xử lý việc lưu ngôn ngữ lựa chọn trong session/cookie.
- `RB_Language_Switcher` cung cấp shortcode, widget và AJAX chuyển ngôn ngữ.
- JS frontend nhận chuỗi dịch qua `wp_localize_script` để đồng bộ trải nghiệm.

## 🗄️ Cấu trúc dữ liệu chính

- `wp_rb_bookings`: lưu chi tiết đặt bàn, trạng thái, token xác nhận.
- `wp_rb_tables`: cấu hình bàn theo chi nhánh và sức chứa.
- `wp_rb_customers`: lịch sử khách hàng, trạng thái VIP/Blacklist.
- `wp_rb_locations`: thông tin chi nhánh, giờ hoạt động, ngôn ngữ hỗ trợ.
- `wp_rb_portal_accounts` & `wp_rb_portal_account_locations`: tài khoản nội bộ và quyền truy cập chi nhánh.

`includes/class-database.php` sẽ tự tạo/migrate bảng khi kích hoạt plugin.

## 🔐 Bảo mật & Hiệu năng

- Nonce cho mọi AJAX (`rb_admin_nonce`, `rb_frontend_nonce`, `rb_language_nonce`).
- Sanitization & validation đầu vào trước khi lưu database.
- Prepared statements và `$wpdb->prepare()` để chống SQL Injection.
- Chặn nạp assets frontend khi không cần thiết để tối ưu hiệu năng.

## 📄 Giấy phép

Phát hành theo GPL v2 hoặc mới hơn. Bạn có thể tự do chỉnh sửa và phân phối lại theo điều khoản GPL.

---

**Made with ❤️ for Vietnamese Restaurants**
