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
-- Mọi lệnh đều IF EXISTS / IF NOT EXISTS: MySQL không cho DDL nằm trong
-- transaction, nên tệp này phải chạy lại được sau một lượt hỏng giữa chừng.
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS deleted_mark DATETIME(3)
    GENERATED ALWAYS AS (IFNULL(deleted_at, '1970-01-01 00:00:00.000')) STORED
    COMMENT 'cột phụ cho UNIQUE: quy deleted_at NULL về mốc cố định — xem 0001 (products)';

-- Khoá ngoại `fk_users_tenant` đang MƯỢN uq_users_email làm index của nó (khoá ấy
-- mở đầu bằng tenant_id). Dỡ khoá trước khi có index thay thế thì MySQL từ chối
-- bằng lỗi 1553 — nên dựng index riêng cho khoá ngoại trước, và giữ nó luôn:
-- khoá ngoại đáng có index của chính mình thay vì sống nhờ một UNIQUE có thể đổi.
ALTER TABLE users
  ADD KEY IF NOT EXISTS idx_users_tenant (tenant_id);

ALTER TABLE users
  DROP INDEX IF EXISTS uq_users_email,
  DROP INDEX IF EXISTS uq_users_username,
  DROP INDEX IF EXISTS uq_users_facebook_id,
  DROP INDEX IF EXISTS uq_users_google_id;

-- Cùng một lượt cho cả bốn: facebook_id / google_id vướng y hệt, chỉ kín hơn —
-- khách xoá tài khoản rồi thì không bao giờ nối lại được tài khoản Google cũ.
ALTER TABLE users
  ADD UNIQUE KEY IF NOT EXISTS uq_users_email (tenant_id, email, deleted_mark),
  ADD UNIQUE KEY IF NOT EXISTS uq_users_username (tenant_id, username, deleted_mark),
  ADD UNIQUE KEY IF NOT EXISTS uq_users_facebook_id (tenant_id, facebook_id, deleted_mark),
  ADD UNIQUE KEY IF NOT EXISTS uq_users_google_id (tenant_id, google_id, deleted_mark);
