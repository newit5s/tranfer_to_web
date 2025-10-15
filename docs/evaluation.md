# Plugin Capability Evaluation

## Yêu cầu: Tách 2 trang (frontend & backend location)
- Plugin cung cấp shortcode `[restaurant_booking_portal]` với flow 3 bước: chọn chi nhánh & ngôn ngữ, kiểm tra chỗ trống, rồi nhập chi tiết đặt bàn cho khách. 【F:public/class-frontend.php†L382-L566】
- Shortcode `[restaurant_booking_manager]` hiển thị portal đăng nhập và bảng điều khiển riêng cho từng location, chỉ dành cho tài khoản có quyền `rb_manage_location`. 【F:public/class-frontend.php†L574-L727】【F:restaurant-booking-manager.php†L44-L55】

## Yêu cầu: 3 location (HCM, HN, JP)
- Khi kích hoạt plugin, bảng `rb_locations` được tạo kèm dữ liệu mẫu cho 3 chi nhánh HCM, HN, JP, đồng thời tạo bàn mặc định cho từng chi nhánh. 【F:includes/class-database.php†L203-L305】
- Lớp `RB_Location` hỗ trợ lấy danh sách và cấu hình chi nhánh để frontend/backend sử dụng. 【F:includes/class-location.php†L19-L106】

## Yêu cầu: Frontend kiểm tra bàn trống và gợi ý thời gian khác
- Bước kiểm tra gọi AJAX `rb_check_availability`; nếu hết chỗ sẽ trả về danh sách khung giờ đề xuất trong ±30 phút để khách chọn. 【F:public/class-frontend.php†L1094-L1137】【F:includes/class-booking.php†L342-L384】

## Yêu cầu: Email xác nhận với link auto confirm và hotline nếu không có email
- Sau khi khách gửi form, hệ thống tạo booking ở trạng thái pending, gửi email xác nhận và email cho admin; nội dung message trên frontend nhắc khách bấm link, hoặc gọi hotline nếu không có email. 【F:public/class-frontend.php†L1019-L1089】【F:includes/class-email.php†L30-L83】
- Email pending chứa link `rb_confirm_token` để khách tự động xác nhận. 【F:includes/class-email.php†L49-L66】

## Yêu cầu: Backend theo location và admin tổng
- Người dùng có vai trò `rb_location_manager` (được tạo khi kích hoạt) chỉ xem/duyệt đơn trong chi nhánh của họ; admin mặc định có thêm quyền này nên xem toàn bộ. 【F:restaurant-booking-manager.php†L44-L55】【F:public/class-frontend.php†L897-L953】

## Yêu cầu: Đa ngôn ngữ
- Lớp `RB_I18n` quản lý ngôn ngữ (Vi/En/Ja), lưu vào session/cookie/user meta và cung cấp switcher cho frontend/backend. 【F:includes/class-i18n.php†L15-L131】【F:includes/class-language-switcher.php†L12-L103】
- Mỗi ngôn ngữ có gói dịch riêng trong thư mục `languages` (ví dụ `languages/vi_VN/translations.php`, `languages/en_US/translations.php`, `languages/ja_JP/translations.php`) bao phủ toàn bộ nhãn ở cả frontend lẫn backend. 【F:languages/vi_VN/translations.php†L7-L90】【F:languages/en_US/translations.php†L7-L80】【F:languages/ja_JP/translations.php†L7-L82】
- Frontend portal tích hợp bộ chọn ngôn ngữ ngay trong bước đầu và lưu lựa chọn để bước backend cũng hiển thị đúng ngôn ngữ, đồng thời mọi thông báo JS lấy từ gói dịch. 【F:public/class-frontend.php†L421-L506】【F:includes/class-i18n.php†L33-L112】【F:assets/js/frontend.js†L147-L257】【F:restaurant-booking-manager.php†L179-L199】

## Kết luận
Plugin đáp ứng đầy đủ các tiêu chí mô tả (flow đặt bàn, gợi ý giờ khác, email xác nhận, quản lý đa chi nhánh với quyền riêng, và đa ngôn ngữ). Người dùng chỉ cần cấu hình thêm nội dung thực tế (giờ mở cửa, hotline, dịch thuật) trong admin.
