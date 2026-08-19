-- =====================================================================
--  0023_don-vi-tinh-2026-08-19.sql
--  Ngày: 19/08/2026
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
--  ĐƠN VỊ TÍNH (Hàng hóa → Đơn vị)
--
--  Bảng tra nhỏ: cửa hàng tự khai "Cái", "Hộp", "Kg", "Thùng"… Dựng theo
--  màn Đơn vị của bản cũ (odr_menu_units), khác ba chỗ:
--
--  1. Mã duy nhất TRONG MỘT cửa hàng. Bản cũ ràng buộc duy nhất trên cả
--     bảng dùng chung, nên tiệm này đặt "KG" rồi là tiệm khác hết đặt.
--
--  2. Không có cột is_default. Hai dòng KG/G khoá cứng bên bản cũ là móc
--     của tính năng bán theo cân (số lượng lưu bằng gram); ở đây chưa có
--     tính năng ấy nên không seed dòng nào — bảng rỗng, cửa hàng tự khai.
--
--  3. Xoá mềm (deleted_at). Mã của dòng đã xoá vẫn giữ chỗ trong UNIQUE
--     index, và tầng Go báo trùng trước khi MySQL kịp ném lỗi.
-- =====================================================================

CREATE TABLE IF NOT EXISTS product_units (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  BIGINT UNSIGNED NOT NULL,

  code       VARCHAR(20)  NOT NULL COMMENT 'mã ngắn, luôn viết hoa: KG, CAI, THUNG',
  name       VARCHAR(100) NOT NULL,

  -- Tắt = thôi bày ở ô chọn đơn vị lúc khai mặt hàng. Dòng vẫn còn nên
  -- mặt hàng cũ vẫn tra ra được tên đơn vị của nó.
  is_active  TINYINT(1) NOT NULL DEFAULT 1,

  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,
  deleted_at DATETIME(3) NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_product_units_code (tenant_id, code),
  KEY idx_product_units_deleted (deleted_at),
  CONSTRAINT fk_product_units_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
