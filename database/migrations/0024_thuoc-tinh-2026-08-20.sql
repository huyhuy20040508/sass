-- =====================================================================
--  0024_thuoc-tinh-2026-08-20.sql
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
--  THUỘC TÍNH (Hàng hóa → Thuộc tính)
--
--  Bảng tra hai tầng: thuộc tính ("Kích cỡ", "Mức đá") và các GIÁ TRỊ của
--  nó ("Nhỏ/Vừa/Lớn", "Ít đá/Nhiều đá"). Dựng theo màn Quản lý thuộc tính
--  của bản cũ (odr_menu_attributes + odr_menu_attribute_details), khác
--  bốn chỗ:
--
--  1. Mã duy nhất TRONG MỘT cửa hàng. Bản cũ ràng buộc duy nhất trên cả
--     bảng dùng chung, nên tiệm này đặt "SIZE" rồi là tiệm khác hết đặt.
--
--  2. Bảng giá trị cũng mang tenant_id. Bản cũ để bảng con không có cột
--     chi nhánh nào cả, chỉ dựa vào khoá ngoại trỏ lên cha — nên mọi
--     đường đọc thẳng bảng con (và bản cũ có vài đường như thế) là đọc
--     của cả thiên hạ.
--
--  3. Giá trị có mã, và mã duy nhất TRONG MỘT thuộc tính. Bản cũ thêm cột
--     code sau bằng ALTER, không ràng buộc gì, nên hai giá trị cùng mã
--     nằm chung một thuộc tính là chuyện bình thường.
--
--  4. Thuộc tính xoá MỀM, giá trị xoá HẲN.
--
--     Thuộc tính giữ chỗ mã như đơn vị tính (mã đã in ra ngoài thì không
--     đem đặt lại cho thứ khác). Còn giá trị thì bảng nhập cho phép bỏ
--     một dòng rồi khai lại đúng mã ấy ngay trong cùng lượt sửa — xoá
--     mềm ở tầng này nghĩa là dòng cũ còn nằm trong UNIQUE index và
--     người dùng ăn lỗi "mã đã tồn tại" cho một bảng đang trống trơn.
--
--     Hôm nay chưa bảng nào trỏ tới product_attribute_values nên xoá hẳn
--     là an toàn. Khi dựng biến thể mặt hàng thì thêm lượt đếm ở tầng Go
--     để chặn xoá giá trị đang được dùng, đừng đổi sang xoá mềm.
--
--  Không seed dòng nào: thuộc tính là thứ mỗi ngành hàng khai một kiểu.
-- =====================================================================

CREATE TABLE IF NOT EXISTS product_attributes (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  BIGINT UNSIGNED NOT NULL,

  code       VARCHAR(20)  NOT NULL COMMENT 'mã ngắn, luôn viết hoa: SIZE, DA, TOPPING',
  name       VARCHAR(100) NOT NULL,

  -- Tắt = thôi bày ở ô chọn thuộc tính lúc khai mặt hàng. Dòng vẫn còn
  -- nên mặt hàng cũ vẫn tra ra được tên thuộc tính của nó.
  is_active  TINYINT(1) NOT NULL DEFAULT 1,

  -- Cờ "dùng để định lượng nguyên vật liệu": chỉ thuộc tính bật cờ này
  -- mới được đem ra khai định lượng nguyên liệu cho món. Giữ đúng nghĩa
  -- cột raw_material_quantification của bản cũ.
  raw_material TINYINT(1) NOT NULL DEFAULT 0,

  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,
  deleted_at DATETIME(3) NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_product_attributes_code (tenant_id, code),
  KEY idx_product_attributes_deleted (deleted_at),
  CONSTRAINT fk_product_attributes_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_attribute_values (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id    BIGINT UNSIGNED NOT NULL,
  attribute_id BIGINT UNSIGNED NOT NULL,

  -- Bỏ trống lúc khai thì tầng Go đặt hộ theo dạng <mã thuộc tính><số>:
  -- SIZE01, SIZE02… Mã chỉ cần khác nhau TRONG một thuộc tính — "S" của
  -- Kích cỡ và "S" của Mức đá là hai thứ không liên quan.
  code         VARCHAR(32)  NOT NULL COMMENT 'mã ngắn, luôn viết hoa: S, M, L',
  name         VARCHAR(100) NOT NULL,

  created_at   DATETIME(3) NULL,
  updated_at   DATETIME(3) NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_product_attribute_values_code (tenant_id, attribute_id, code),
  KEY idx_product_attribute_values_attr (attribute_id),
  CONSTRAINT fk_product_attribute_values_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_product_attribute_values_attr FOREIGN KEY (attribute_id) REFERENCES product_attributes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
