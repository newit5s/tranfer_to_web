# Đối chiếu Location Manager với Admin

## Phạm vi truy cập & phân quyền
- Màn hình portal chỉ tải sau khi xác định tài khoản portal hiện tại, lọc danh sách chi nhánh theo danh sách được gán và buộc người dùng hoạt động trong chi nhánh đang chọn. Nếu không có chi nhánh hợp lệ, giao diện hiển thị cảnh báo và dừng render. 【F:public/class-frontend-manager.php†L204-L343】
- Tất cả API AJAX của location manager (cập nhật booking, tạo booking, quản lý bàn, khách hàng, cài đặt) đều mở đầu bằng bước đọc phiên làm việc, kiểm tra nonce và xác thực chi nhánh trước khi xử lý. Điều này đảm bảo manager không thể thao tác lên chi nhánh ngoài phạm vi được giao. 【F:public/class-frontend-manager.php†L2106-L2335】【F:public/class-frontend-manager.php†L2516-L2990】

## Giao diện & luồng nghiệp vụ
- Thanh điều hướng của portal hiển thị đầy đủ các tab Dashboard, Timeline, Create Booking, Manage Tables, Customers và Location Settings giống admin, mỗi tab đều tự động mang theo `location_id` đang chọn. 【F:public/class-frontend-manager.php†L285-L342】
- Danh sách booking dựng dạng thẻ với ID, thời gian, khách, trạng thái và ghi chú; thẻ lưu cả `data-checkout-time` để giao diện hiển thị khung giờ từ giờ bắt đầu đến giờ kết thúc tương tự màn hình admin. 【F:public/class-frontend-manager.php†L326-L525】
- Javascript frontend đọc các thuộc tính dữ liệu đó để render panel chi tiết, hiển thị khoảng thời gian bắt đầu/kết thúc, nguồn, bàn, ghi chú và các nút hành động giống dashboard admin. 【F:assets/js/frontend.js†L780-L900】【F:assets/js/frontend.js†L1027-L1138】

## Dữ liệu & đồng bộ booking
- Modal chỉnh sửa booking điền sẵn thông tin khách, ngày giờ, checkout, bàn và ghi chú, đồng thời gửi `booking_id` ẩn giúp manager sửa giống admin. Lưu thành công sẽ cập nhật lại thẻ trong danh sách, bao gồm khung giờ dạng “start – end”. 【F:public/class-frontend-manager.php†L943-L1020】【F:assets/js/frontend.js†L1488-L1569】
- Form tạo booking yêu cầu giờ checkout, tự động gợi ý giờ kết thúc sau 2 giờ kể từ giờ bắt đầu (tôn trọng giờ đóng cửa) và khóa độ dài đặt bàn trong phạm vi 1–6 giờ như backend admin. 【F:assets/js/frontend.js†L1622-L1726】【F:public/class-frontend-manager.php†L2214-L2335】
- Khi tạo hoặc cập nhật, backend kiểm tra trùng slot, đảm bảo giờ checkout sau giờ checkin, xác minh bàn tồn tại và còn trống trước khi gán – tương đồng luật ở khu vực admin. 【F:public/class-frontend-manager.php†L2280-L2468】

## Quản lý bàn, khách hàng & thiết lập
- Các thao tác thêm/xoá/đổi trạng thái bàn đều kiểm tra chi nhánh, cập nhật qua bảng `rb_tables` như admin, và trả về thông báo thành công/thất bại rõ ràng. 【F:public/class-frontend-manager.php†L2516-L2679】
- Manager có thể đánh dấu VIP/blacklist, lưu ghi chú khách hàng và xem lịch sử đặt bàn; mỗi API đều giới hạn theo chi nhánh đang chọn giống logic ở backend. 【F:public/class-frontend-manager.php†L2681-L2884】
- Tab Location Settings cho phép chỉnh hotline, email, khung giờ mở cửa, buffer, đặt cọc, ngôn ngữ…, với các kiểm tra email hợp lệ và whitelist ngôn ngữ giống admin. 【F:public/class-frontend-manager.php†L2886-L2990】

## Quyền dành riêng cho super admin
- Các menu và hành động trong WordPress admin (Booking Hub, bảng điều khiển, cấu hình portal account) đều yêu cầu capability `manage_options`, vì vậy chỉ super admin mới truy cập được phần cài đặt hệ thống và quản lý tài khoản portal. 【F:admin/class-admin.php†L58-L150】

## Kết luận
Giao diện, dữ liệu và khả năng quản lý của location manager đã phản chiếu gần như đầy đủ chức năng từ phía admin cho từng chi nhánh, trong khi các quyền cấu hình cấp hệ thống vẫn được giới hạn cho super admin như yêu cầu.
