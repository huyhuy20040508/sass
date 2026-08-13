-- =====================================================================
--  0007_khu-dieu-hanh-co-tai-khoan-rieng-2026-08-12.sql
--  Ngày: 12/08/2026
--  LƯỢC ĐỒ: selliotech_platform (KHÔNG phải selliotech)
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy (cục bộ / thử / thật đều khác tên).
--
--  MySQL không cho DDL nằm trong transaction, nên tệp chạy dở là dở thật.
--  Viết sao cho chạy lại được nếu có thể: IF NOT EXISTS, IF EXISTS...
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  công cụ giữ vân tay và sẽ báo lệch. Cần thêm gì thì viết tệp mới.
-- =====================================================================
--
--  THÁO CẦU TẠM: KHU ĐIỀU HÀNH TỰ ĐĂNG NHẬP BẰNG SỔ CỦA CHÍNH NÓ
--
--  0006 nối `platform_users` sang một TÀI KHOẢN CỬA HÀNG bằng cặp
--  (tenant_id, user_id), và tự nó ghi rõ đó là cầu tạm "viết ra để tháo".
--  Tệp này tháo, sớm hơn dự tính đúng một ngày, vì cây cầu ấy chỉ che được
--  một nửa vấn đề:
--
--    · Nó chặn được người NGOÀI danh sách. Đúng phần này thì nó làm tốt.
--    · Nó KHÔNG bỏ được sự thật là người điều hành vẫn đăng nhập bằng tài
--      khoản super_admin của MỘT cửa hàng. Nghĩa là ai đổi được mật khẩu
--      trong cửa hàng đó — chính chủ shop, hoặc bất kỳ ai chiếm được một
--      phiên quản trị của tiệm ấy — cũng đổi được chìa vào khu điều hành.
--      Ranh giới giữa "khách hàng" và "nhà cung cấp phần mềm" đi qua đúng
--      bảng `users` của một khách hàng.
--
--  Từ tệp này, `/auth/platform-login` xác thực THẲNG vào bảng dưới đây:
--  email + mật khẩu của chính nó, không mượn tài khoản của ai. Token phát
--  ra mang cờ riêng và `tenant_id = 0`, nên:
--
--    · token nền tảng KHÔNG mở được đường nào của khu cửa hàng (JWTAuth
--      vốn đã từ chối mọi token không nói được nó thuộc tiệm nào);
--    · token cửa hàng KHÔNG mở được đường nào của khu điều hành.
--
--  Hai chiều loại trừ nhau bằng cấu trúc, không bằng một dãy điều kiện ai
--  đó phải nhớ viết đúng ở từng nhóm route.
--
--  ĐỌC KỸ TRƯỚC KHI CHẠY TRÊN MÁY THẬT — TỆP NÀY CẮT ĐƯỜNG VÀO:
--
--    1. Sau khi chạy, KHÔNG tài khoản cửa hàng nào vào được khu điều hành
--       nữa, kể cả tài khoản bạn đang dùng hôm nay. Đó là mục đích.
--    2. Dòng nào trong `platform_users` có `password_hash` NULL thì CHƯA
--       đăng nhập được. Đặt mật khẩu trước hoặc ngay sau khi chạy:
--           go run ./cmd/nguoi-dieu-hanh dat-mat-khau --email <email>
--    3. Bảng rỗng + không đặt mật khẩu = không ai vào được khu điều hành.
--       Cửa đóng vẫn an toàn hơn cửa mở nhầm, nhưng phải biết trước.
--
--  `password_hash` GIỮ NGUYÊN cho phép NULL (0006 nới ra). Nay nó mang một
--  nghĩa khác và vẫn cần thiết: "tài khoản đã có trong sổ nhưng chưa đặt
--  mật khẩu, nên chưa đăng nhập được". Ép NOT NULL trở lại thì mọi dòng
--  tạo ra phải kèm một chuỗi băm ngay lập tức, và người ta sẽ điền một mật
--  khẩu tạm rồi quên mất là nó tạm.
-- =====================================================================

SET NAMES utf8mb4;

-- =====================================================================
--  1. Bỏ khoá duy nhất của cầu tạm
-- =====================================================================
--  Phải bỏ TRƯỚC hai cột, vì khoá đang dựng trên chính chúng.
ALTER TABLE platform_users
  DROP INDEX uq_platform_users_tai_khoan;

-- =====================================================================
--  2. Bỏ hai cột nối sang data plane
-- =====================================================================
--  Không giữ lại "cho có dấu vết": một cột không còn ai đọc là một cột
--  không còn ai cập nhật, và sáu tháng nữa nó vẫn trỏ vào một tài khoản
--  cửa hàng có thể đã bị xoá — người đọc sau này sẽ tin nó.
--
--  Ai cần biết một người điều hành ứng với tài khoản cửa hàng nào thì tra
--  bằng email, và đó là việc tra cứu để LIÊN LẠC, không phải để xét quyền.
--
--  KHÔNG dùng IF EXISTS: MariaDB (máy cục bộ) cho phép, MySQL 8 (máy thật)
--  thì không — câu chạy được ở nhà mà gãy trên máy thật là kiểu lệch tệ
--  nhất. Cùng lý do 0005 không dùng IF EXISTS lúc bỏ cột.
ALTER TABLE platform_users
  DROP COLUMN tenant_id,
  DROP COLUMN user_id;

-- =====================================================================
--  KẾT THÚC — 2 cột bị bỏ, 1 khoá bị bỏ. Từ đây khu điều hành có danh
--  tính của riêng nó, và ranh giới khách hàng · nhà cung cấp không còn đi
--  qua bảng `users` của một khách hàng nào.
-- =====================================================================
