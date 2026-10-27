-- =====================================================================
--  0097_ghi-chu-khach-2026-10-22.sql
--  Ngày: 22/10/2026
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy.
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  viết tệp mới.
-- =====================================================================
--
--  SỔ GHI CHÚ KHÁCH: nhiều dòng, mỗi dòng biết ai ghi, lúc nào. Ô
--  users.customer_note giữ nguyên làm "ghi chú cố định" (dị ứng, yêu cầu riêng);
--  ô ấy bị ghi đè mỗi lần sửa hồ sơ nên không chứa được lời hẹn.
--
--  type: cham-soc | hen | canh-bao. due_date chỉ có ở loại "hen" — nút lọc
--  "Có hẹn hôm nay" của quầy đọc cột này.
--  Chỉ người ghi xoá được dòng của mình (xoá mềm), không có sửa.
-- =====================================================================

CREATE TABLE IF NOT EXISTS customer_notes (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id   BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL COMMENT 'users.id của khách',
  shop_id     BIGINT UNSIGNED NULL COMMENT 'chi nhánh nơi ghi',
  type        VARCHAR(20) NOT NULL DEFAULT 'cham-soc' COMMENT 'cham-soc | hen | canh-bao',
  content     VARCHAR(500) NOT NULL,
  due_date    DATE NULL COMMENT 'ngày hẹn — chỉ loại hen',
  created_by  BIGINT UNSIGNED NULL COMMENT 'users.id người ghi',
  created_at  DATETIME(3) NULL,
  updated_at  DATETIME(3) NULL,
  deleted_at  DATETIME(3) NULL,

  PRIMARY KEY (id),
  KEY idx_customer_notes_customer (tenant_id, customer_id, created_at),
  KEY idx_customer_notes_due (tenant_id, type, due_date),
  KEY idx_customer_notes_deleted (deleted_at),
  CONSTRAINT fk_customer_notes_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_customer_notes_customer FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_customer_notes_shop FOREIGN KEY (shop_id) REFERENCES shops (id) ON DELETE SET NULL,
  CONSTRAINT fk_customer_notes_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
