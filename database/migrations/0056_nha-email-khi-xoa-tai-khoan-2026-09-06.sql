-- XOÁ TÀI KHOẢN THÌ NHẢ LẠI EMAIL VÀ TÊN ĐĂNG NHẬP.
--
-- Bốn UNIQUE của `users` không xét `deleted_at`, nên một tài khoản đã xoá mềm
-- giữ email/tên đăng nhập của nó VĨNH VIỄN. Hậu quả thấy ngay ở màn Nhân sự: xoá
-- hồ sơ NV2 xong, khai lại đúng người đó (hoặc sửa lại cái vừa gõ nhầm) thì nhận
-- 422 "Email đã được sử dụng" — mà nhìn danh sách thì chẳng còn ai đang dùng
-- email ấy cả. Tuyển lại người cũ là kẹt, và không có đường nào gỡ trừ vào thẳng
-- database.
--
-- Cách chữa dùng đúng khuôn đã có sẵn ở `products` / `product_variants` từ
-- migration 0001: thêm cột phụ `deleted_mark` quy NULL về một mốc cố định, rồi
-- đưa nó vào UNIQUE. MySQL coi mỗi NULL là một giá trị khác nhau nên `deleted_at`
-- thô không dùng được — ràng buộc sẽ mất tác dụng với chính các dòng đang sống.
--
-- Sau bản này ràng buộc chỉ còn hiệu lực GIỮA CÁC DÒNG ĐANG SỐNG:
--   - hai tài khoản đang dùng vẫn không được trùng email / tên đăng nhập;
--   - tài khoản đã xoá không chắn ai nữa; nhiều tài khoản đã xoá trùng email
--     cũng không sao (chúng khác nhau ở thời điểm xoá).
--
-- Luật mới LỎNG HƠN luật cũ ở mọi mặt, nên không dòng nào đang có bị vướng.
--
-- ---------------------------------------------------------------------
-- VÌ SAO KHÔNG DÙNG `IF NOT EXISTS` / `IF EXISTS` Ở ĐÂY
--
-- `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`, `ADD KEY IF NOT EXISTS` và
-- `DROP INDEX IF EXISTS` là phần MỞ RỘNG CỦA MARIADB. MySQL 8 không có, và trả
-- về lỗi 1064 (lỗi cú pháp) ngay từ tệp đầu tiên gặp phải.
--
-- Máy phát triển chạy MariaDB (bản đi kèm XAMPP) nên nhận hết; máy chủ thật và
-- CI chạy MySQL 8 thì chết. Đây đúng loại lệch nguy hiểm nhất: bản viết sai vẫn
-- xanh ở chỗ mình viết ra nó.
--
-- Nên hỏi `information_schema` rồi dựng câu lệnh bằng PREPARE — cùng khuôn với
-- migration 0021. Dài hơn, nhưng chạy được ở cả hai hệ và VẪN chạy lại được sau
-- một lượt hỏng giữa chừng (MySQL không cho DDL nằm trong transaction, nên tệp
-- chạy dở là dở thật).
-- ---------------------------------------------------------------------

-- 1) Cột phụ cho UNIQUE.
SET @co_cot := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'deleted_mark'
);
SET @sql := IF(@co_cot = 0,
  'ALTER TABLE users
     ADD COLUMN deleted_mark DATETIME(3)
       GENERATED ALWAYS AS (IFNULL(deleted_at, ''1970-01-01 00:00:00.000'')) STORED
       COMMENT ''cột phụ cho UNIQUE: quy deleted_at NULL về mốc cố định — xem 0001 (products)''',
  'DO 0');
PREPARE lenh FROM @sql; EXECUTE lenh; DEALLOCATE PREPARE lenh;

-- 2) Index riêng cho khoá ngoại, DỰNG TRƯỚC khi dỡ mấy UNIQUE bên dưới.
--
-- `fk_users_tenant` đang MƯỢN uq_users_email làm index của nó (khoá ấy mở đầu
-- bằng tenant_id). Dỡ UNIQUE khi chưa có index thay thế thì MySQL từ chối bằng
-- lỗi 1553. Giữ luôn index này: khoá ngoại đáng có index của chính mình thay vì
-- sống nhờ một UNIQUE có thể đổi.
SET @co_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND INDEX_NAME = 'idx_users_tenant'
);
SET @sql := IF(@co_idx = 0, 'ALTER TABLE users ADD KEY idx_users_tenant (tenant_id)', 'DO 0');
PREPARE lenh FROM @sql; EXECUTE lenh; DEALLOCATE PREPARE lenh;

-- 3) Dỡ bốn UNIQUE cũ, rồi dựng lại kèm deleted_mark.
--
-- Cùng một lượt cho cả bốn: facebook_id / google_id vướng y hệt, chỉ kín hơn —
-- khách xoá tài khoản rồi thì không bao giờ nối lại được tài khoản Google cũ.
--
-- Mỗi khoá soi riêng SỐ CỘT của index đang có (COUNT trong STATISTICS): bản cũ
-- có 2 cột, bản mới có 3. Chỉ hỏi "index này có chưa" là không phân biệt được
-- hai bản, và lượt chạy lại sẽ bỏ qua đúng việc cần làm.
SET @cu := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
    AND INDEX_NAME = 'uq_users_email' AND COLUMN_NAME = 'deleted_mark'
);
SET @sql := IF(@cu = 0, 'ALTER TABLE users DROP INDEX uq_users_email', 'DO 0');
PREPARE lenh FROM @sql; EXECUTE lenh; DEALLOCATE PREPARE lenh;
SET @sql := IF(@cu = 0,
  'ALTER TABLE users ADD UNIQUE KEY uq_users_email (tenant_id, email, deleted_mark)', 'DO 0');
PREPARE lenh FROM @sql; EXECUTE lenh; DEALLOCATE PREPARE lenh;

SET @cu := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
    AND INDEX_NAME = 'uq_users_username' AND COLUMN_NAME = 'deleted_mark'
);
SET @sql := IF(@cu = 0, 'ALTER TABLE users DROP INDEX uq_users_username', 'DO 0');
PREPARE lenh FROM @sql; EXECUTE lenh; DEALLOCATE PREPARE lenh;
SET @sql := IF(@cu = 0,
  'ALTER TABLE users ADD UNIQUE KEY uq_users_username (tenant_id, username, deleted_mark)', 'DO 0');
PREPARE lenh FROM @sql; EXECUTE lenh; DEALLOCATE PREPARE lenh;

SET @cu := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
    AND INDEX_NAME = 'uq_users_facebook_id' AND COLUMN_NAME = 'deleted_mark'
);
SET @sql := IF(@cu = 0, 'ALTER TABLE users DROP INDEX uq_users_facebook_id', 'DO 0');
PREPARE lenh FROM @sql; EXECUTE lenh; DEALLOCATE PREPARE lenh;
SET @sql := IF(@cu = 0,
  'ALTER TABLE users ADD UNIQUE KEY uq_users_facebook_id (tenant_id, facebook_id, deleted_mark)', 'DO 0');
PREPARE lenh FROM @sql; EXECUTE lenh; DEALLOCATE PREPARE lenh;

SET @cu := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
    AND INDEX_NAME = 'uq_users_google_id' AND COLUMN_NAME = 'deleted_mark'
);
SET @sql := IF(@cu = 0, 'ALTER TABLE users DROP INDEX uq_users_google_id', 'DO 0');
PREPARE lenh FROM @sql; EXECUTE lenh; DEALLOCATE PREPARE lenh;
SET @sql := IF(@cu = 0,
  'ALTER TABLE users ADD UNIQUE KEY uq_users_google_id (tenant_id, google_id, deleted_mark)', 'DO 0');
PREPARE lenh FROM @sql; EXECUTE lenh; DEALLOCATE PREPARE lenh;
