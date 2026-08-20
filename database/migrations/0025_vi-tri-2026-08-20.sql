-- =====================================================================
--  0025_vi-tri-2026-08-20.sql
--  Ngày: 20/08/2026
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
--  VỊ TRÍ (Hàng hóa → Vị trí)
--
--  Bảng tra nhỏ: cửa hàng tự khai chỗ để hàng — "Kệ A - Tầng 1", "Kho
--  lạnh", "Quầy trước"… rồi gắn cho mặt hàng. Người soạn hàng đọc mã vị
--  trí là biết đi thẳng tới đâu, thay vì đi dò cả kho.
--
--  Bản cũ v2 KHÔNG có màn này (Menu QR chỉ tới Hoa hồng là hết), nên đây
--  là bảng mới hoàn toàn. Dựng theo đúng khuôn product_units — cùng là
--  bảng tra mã + tên của một cửa hàng — để hai màn hành xử giống nhau:
--
--  1. Mã duy nhất TRONG MỘT cửa hàng, không phải toàn hệ thống. Tiệm này
--     đặt "VT001" không được cản tiệm khác đặt cùng mã.
--
--  2. Xoá mềm (deleted_at). Mã của dòng đã xoá vẫn giữ chỗ trong UNIQUE
--     index, và tầng Go báo trùng trước khi MySQL kịp ném lỗi.
--
--  3. Không seed dòng nào. Chỗ để hàng là chuyện riêng của từng mặt bằng,
--     đoán hộ vài dòng mẫu thì cửa hàng nào cũng phải xoá đi khai lại.
-- =====================================================================

CREATE TABLE IF NOT EXISTS product_locations (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  BIGINT UNSIGNED NOT NULL,

  code       VARCHAR(20)  NOT NULL COMMENT 'mã ngắn, luôn viết hoa: VT001, KEA1, KHOLANH',
  name       VARCHAR(100) NOT NULL,

  -- Tắt = thôi bày ở ô chọn vị trí lúc khai mặt hàng. Dòng vẫn còn nên
  -- mặt hàng cũ vẫn tra ra được tên vị trí của nó.
  is_active  TINYINT(1) NOT NULL DEFAULT 1,

  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,
  deleted_at DATETIME(3) NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_product_locations_code (tenant_id, code),
  KEY idx_product_locations_deleted (deleted_at),
  CONSTRAINT fk_product_locations_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
