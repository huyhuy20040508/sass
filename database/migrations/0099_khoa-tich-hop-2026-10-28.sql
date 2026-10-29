-- =====================================================================
--  0099_khoa-tich-hop-2026-10-28.sql
--  Ngày: 28/10/2026
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy.
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  viết tệp mới.
-- =====================================================================
--
--  KHOÁ TÍCH HỢP: danh tính của một HỆ THỐNG khác (hôm nay là web bán hàng
--  ecommerce_web) gọi API thay cho một cửa hàng, không cần ai đăng nhập — web
--  phải đọc hàng và gửi đơn cả lúc chủ tiệm không mở máy.
--
--  Chỉ lưu BĂM sha256 của khoá: lộ bản sao database cũng không dùng được để
--  gọi API thay cửa hàng. key_prefix (vài ký tự đầu) để chủ tiệm nhận ra khoá
--  nào đang chạy mà không phải hiện cả khoá.
--
--  Mỗi cửa hàng một khoá ĐANG DÙNG cho mỗi `app`: cấp khoá mới là thu hồi khoá
--  cũ cùng app (revoked_at), dòng cũ giữ lại để biết ai cấp / thu hồi lúc nào.
-- =====================================================================

CREATE TABLE IF NOT EXISTS integration_keys (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id    BIGINT UNSIGNED NOT NULL,
  app          VARCHAR(30)     NOT NULL COMMENT 'hệ thống dùng khoá, vd web_ban_hang',
  key_hash     CHAR(64)        NOT NULL COMMENT 'sha256 hex của khoá',
  key_prefix   VARCHAR(16)     NOT NULL COMMENT 'phần đầu của khoá để nhận diện',
  created_by   BIGINT UNSIGNED NULL     COMMENT 'users.id người cấp',
  last_used_at DATETIME(3)     NULL,
  revoked_at   DATETIME(3)     NULL,
  created_at   DATETIME(3)     NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_integration_keys_hash (key_hash),
  KEY idx_integration_keys_tenant_app (tenant_id, app, revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
