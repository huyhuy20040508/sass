-- =====================================================================
--  0062_email-khach-khong-bat-buoc-2026-09-07.sql
--  Ngày: 07/09/2026
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy (cục bộ / thử / thật đều khác tên).
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  công cụ giữ vân tay và sẽ báo lệch. Cần thêm gì thì viết tệp mới.
-- =====================================================================
--
--  EMAIL KHÔNG CÒN BẮT BUỘC VỚI KHÁCH HÀNG.
--
--  Khách hàng bỏ chức năng đăng nhập storefront, nên email thôi là tên đăng
--  nhập và trở thành một ô liên lạc bình thường — khai cũng được, bỏ trống
--  cũng được. Nhưng khách lại nằm chung bảng `users` với tài khoản nội bộ,
--  mà bảng ấy có `uq_users_email (tenant_id, email, deleted_mark)`: hai
--  khách cùng để trống email là hai dòng cùng mang chuỗi rỗng, và khoá duy
--  nhất chặn ngay dòng thứ hai.
--
--  CÁCH CHỮA: thêm cột sinh `email_key = NULLIF(email, '')` rồi chuyển khoá
--  duy nhất sang cột đó. MySQL coi mỗi NULL là một giá trị RIÊNG, nên bao
--  nhiêu dòng email rỗng cũng lọt; email khai thật thì vẫn duy nhất trong
--  cửa hàng như cũ.
--
--  VÌ SAO KHÔNG ĐỔI CỘT `email` THÀNH NULL-ABLE: `domain.User.Email` là
--  `string` và có 51 chỗ đọc nó trong mã Go. Đổi sang kiểu cho phép NULL là
--  sờ vào cả luồng đăng nhập, quên mật khẩu, gửi thư — đắt hơn nhiều so với
--  cái đang cần. Cột sinh giải quyết đúng chỗ vướng mà không đụng tầng Go.
--
--  KHÔNG áp cho `username`: tài khoản nội bộ vẫn bắt buộc có tên đăng nhập,
--  và cột đó đã NULL-able sẵn cho khách (xem domain.User.Username).
-- ---------------------------------------------------------------------

SET @db := DATABASE();

-- 1) Cột sinh: rỗng -> NULL, còn lại giữ nguyên email.
SET @co := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_key');
SET @sql := IF(@co = 0,
  'ALTER TABLE users
     ADD COLUMN email_key VARCHAR(191)
       GENERATED ALWAYS AS (NULLIF(email, "")) STORED
       COMMENT "cot phu cho UNIQUE: email rong quy ve NULL de nhieu khach cung bo trong"',
  'DO 0');
PREPARE lenh FROM @sql; EXECUTE lenh; DEALLOCATE PREPARE lenh;

-- 2) Chuyển khoá duy nhất sang cột sinh.
--
-- Soi theo TÊN CỘT trong index (`email_key`) chứ không chỉ hỏi "index có
-- chưa": bản 0056 cũng tên `uq_users_email` nhưng dựng trên cột `email`, hỏi
-- trống không thì lượt chạy lại bỏ qua đúng việc cần làm.
SET @cu := (SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users'
              AND INDEX_NAME = 'uq_users_email' AND COLUMN_NAME = 'email_key');
SET @sql := IF(@cu = 0, 'ALTER TABLE users DROP INDEX uq_users_email', 'DO 0');
PREPARE lenh FROM @sql; EXECUTE lenh; DEALLOCATE PREPARE lenh;
SET @sql := IF(@cu = 0,
  'ALTER TABLE users ADD UNIQUE KEY uq_users_email (tenant_id, email_key, deleted_mark)', 'DO 0');
PREPARE lenh FROM @sql; EXECUTE lenh; DEALLOCATE PREPARE lenh;
