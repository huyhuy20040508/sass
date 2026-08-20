-- =====================================================================
--  0027_hang-hoa-ban-le-2026-08-20.sql
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
--  HÀNG HOÁ BÁN LẺ — bỏ khuôn áo bóng đá, dựng khuôn bán lẻ chung
--
--  Bảng products sinh ra cho một cửa hàng áo bóng đá: đội bóng, mùa
--  giải, loại áo là ba cột nướng cứng, và biến thể chỉ biết đúng hai
--  chiều size + màu. Cửa hàng bán điện thoại, mỹ phẩm hay tạp hoá không
--  dùng được cột nào trong đó, mà "dung lượng", "dòng máy", "hương" thì
--  không có chỗ để khai.
--
--  Bốn màn danh mục đã dựng trước (Thuế 0020, Đơn vị tính 0023, Thuộc
--  tính 0024, Vị trí 0025-0026) tới giờ vẫn đứng một mình, chưa cái nào
--  được mặt hàng trỏ tới. Tệp này là chỗ chúng gắn vào:
--
--    - Đơn vị tính  -> products.unit_id
--    - Thuế         -> products.vat (mức %, hoặc mã KCT/KKKNT)
--    - Thuộc tính   -> product_variant_attributes (tổ hợp của biến thể)
--    - Vị trí       -> products.location_id (đã có từ 0026)
--
--  MẤT DỮ LIỆU: có, và không lấy lại được.
--    1. products.team / season / kit_type mất hẳn.
--    2. product_variants.size / color mất hẳn — nhưng được gộp trước vào
--       cột `name` mới ("M · Đỏ"), nên tên biến thể vẫn đọc ra được.
--    3. Bốn bảng dòng chứng từ (đơn hàng, trả hàng, đặt mua, trả nhà
--       cung cấp) cũng gộp size/color vào `variant_name` rồi mới bỏ hai
--       cột cũ. Phiếu cũ giữ nguyên chữ, chỉ đổi chỗ đứng.
--  Cần giữ nguyên trạng thì sao lưu trước khi chạy.
-- ---------------------------------------------------------------------


-- ---------------------------------------------------------------------
--  1) BIẾN THỂ: size/màu -> tên biến thể + tổ hợp thuộc tính
--
--  Làm TRƯỚC phần products vì bước gộp tên phải đọc size/color khi hai
--  cột còn nguyên, và bước suy `is_multi_variant` ở dưới lại đọc chính
--  cột `name` vừa dựng.
-- ---------------------------------------------------------------------

-- Tên biến thể — thứ người bán đọc trên phiếu và trên màn thu ngân.
-- Rỗng = mặt hàng không có biến thể (chỉ một dòng mặc định).
ALTER TABLE product_variants
  ADD COLUMN name VARCHAR(255) NOT NULL DEFAULT ''
    COMMENT 'tên biến thể dựng từ tổ hợp thuộc tính, vd "128GB · Đen"; rỗng = hàng không biến thể'
    AFTER sku;

-- Dòng MẶC ĐỊNH của mặt hàng không biến thể. Bất biến của khuôn mới:
-- mọi mặt hàng luôn có ít nhất một dòng ở product_variants, vì tồn kho,
-- phiếu nhập, đơn bán và báo cáo đều khoá theo biến thể — mặt hàng không
-- có dòng nào là mặt hàng không nhập được, không bán được.
ALTER TABLE product_variants
  ADD COLUMN is_default TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = dòng mặc định của hàng không biến thể (mã/giá đồng bộ từ chính mặt hàng)'
    AFTER name;

-- Thứ tự bày biến thể trong bảng nhập và ở ô chọn ngoài quầy.
ALTER TABLE product_variants
  ADD COLUMN pos INT NOT NULL DEFAULT 0
    COMMENT 'thứ tự sắp xếp trong nhóm biến thể của mặt hàng'
    AFTER is_default;

-- Gộp hai cột cũ thành một chuỗi. NULLIF để size rỗng không đẻ ra tên
-- kiểu " · Đỏ"; TRIM cho trường hợp cả hai cùng rỗng.
UPDATE product_variants
SET name = TRIM(CONCAT_WS(' · ', NULLIF(TRIM(size), ''), NULLIF(TRIM(color), '')));

-- Hàng chỉ có ĐÚNG một biến thể và biến thể ấy không mang tên gì thì đó
-- là hàng không biến thể — dòng duy nhất của nó là dòng mặc định.
UPDATE product_variants v
  JOIN (
    SELECT product_id, COUNT(*) AS so_dong, MAX(TRIM(name)) AS ten
    FROM product_variants
    WHERE deleted_at IS NULL
    GROUP BY product_id
  ) g ON g.product_id = v.product_id
SET v.is_default = 1
WHERE v.deleted_at IS NULL AND g.so_dong = 1 AND g.ten = '';

-- Chống trùng trước khi dựng khoá mới: dữ liệu cũ có thể có hai biến thể
-- gộp ra cùng một tên (vd size='M',color='' và size='',color='M'). Gắn
-- thêm id vào dòng thứ hai trở đi — thà tên hơi xấu còn hơn cả tệp
-- migration gãy ở lệnh ADD UNIQUE trên database thật.
UPDATE product_variants v
  JOIN (
    SELECT product_id, name, MIN(id) AS giu_lai
    FROM product_variants
    WHERE deleted_at IS NULL
    GROUP BY product_id, name
    HAVING COUNT(*) > 1
  ) d ON d.product_id = v.product_id AND d.name = v.name
SET v.name = CONCAT(v.name, ' #', v.id)
WHERE v.deleted_at IS NULL AND v.id <> d.giu_lai;

-- Khoá cũ dựng trên (product_id, size, color) — phải bỏ trước khi bỏ cột.
SET @co_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'product_variants'
    AND INDEX_NAME = 'uq_variants_product_size_color'
);
SET @sql := IF(@co_idx > 0, 'ALTER TABLE product_variants DROP INDEX uq_variants_product_size_color', 'DO 0');
PREPARE lenh FROM @sql; EXECUTE lenh; DEALLOCATE PREPARE lenh;

-- Khoá mới: trong một mặt hàng, không có hai biến thể ĐANG SỐNG cùng
-- tên. deleted_mark giữ nguyên vai trò cũ — xoá một biến thể rồi khai
-- lại đúng tên ấy phải làm được.
ALTER TABLE product_variants
  ADD UNIQUE KEY uq_variants_product_name (product_id, name, deleted_mark);

SET @co_cot := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_variants' AND COLUMN_NAME = 'size'
);
SET @sql := IF(@co_cot > 0, 'ALTER TABLE product_variants DROP COLUMN size', 'DO 0');
PREPARE lenh FROM @sql; EXECUTE lenh; DEALLOCATE PREPARE lenh;

SET @co_cot := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_variants' AND COLUMN_NAME = 'color'
);
SET @sql := IF(@co_cot > 0, 'ALTER TABLE product_variants DROP COLUMN color', 'DO 0');
PREPARE lenh FROM @sql; EXECUTE lenh; DEALLOCATE PREPARE lenh;


-- ---------------------------------------------------------------------
--  2) TỔ HỢP THUỘC TÍNH CỦA BIẾN THỂ
--
--  Một dòng = một chiều của biến thể ("Dung lượng" = "128GB"). Biến thể
--  hai chiều thì có hai dòng.
--
--  Vì sao là bảng nối chứ không phải mấy cột attr1/attr2/attr3: số chiều
--  do cửa hàng quyết, tiệm quần áo dùng 2, tiệm điện thoại dùng 3-4, và
--  cột cố định thì thêm chiều là lại một lượt ALTER trên bảng thật.
--
--  UNIQUE (variant_id, attribute_id): trong một biến thể, mỗi thuộc tính
--  chỉ được mang đúng một giá trị — "128GB" và "256GB" cùng lúc là dữ
--  liệu hỏng, không phải một biến thể.
--
--  KHÔNG có deleted_at: bảng này luôn được ghi lại NGUYÊN cụm theo biến
--  thể (xoá hết rồi chèn lại), xoá mềm ở đây chỉ tổ đẻ ra dòng rác mà
--  không ai đọc.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_variant_attributes (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id    BIGINT UNSIGNED NOT NULL,

  variant_id   BIGINT UNSIGNED NOT NULL,
  attribute_id BIGINT UNSIGNED NOT NULL,
  value_id     BIGINT UNSIGNED NOT NULL,

  created_at   DATETIME(3) NULL,
  updated_at   DATETIME(3) NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_variant_attr (variant_id, attribute_id),
  KEY idx_variant_attr_variant (variant_id),
  KEY idx_variant_attr_value (value_id),
  CONSTRAINT fk_variant_attr_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  -- Xoá cứng biến thể thì tổ hợp của nó đi theo; xoá mềm thì dòng ở đây
  -- vẫn nằm yên, đúng ý — khôi phục biến thể là có lại tổ hợp cũ.
  CONSTRAINT fk_variant_attr_variant FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE CASCADE,
  CONSTRAINT fk_variant_attr_attribute FOREIGN KEY (attribute_id) REFERENCES product_attributes (id),
  CONSTRAINT fk_variant_attr_value FOREIGN KEY (value_id) REFERENCES product_attribute_values (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  3) MẶT HÀNG: bỏ ba cột áo bóng đá, thêm phần bán lẻ cần
-- ---------------------------------------------------------------------

-- Đơn vị tính (Hàng hoá → Đơn vị). NULL = chưa khai, và đó là trạng thái
-- hợp lệ: mọi mặt hàng đang có đều rơi vào đây sau lượt chạy này.
--
-- KHÔNG ON DELETE CASCADE: xoá một đơn vị tính không được phép kéo theo
-- mặt hàng. Tầng Go chặn xoá đơn vị còn hàng trỏ tới, khoá ngoại là lớp
-- chắn cuối.
ALTER TABLE products
  ADD COLUMN unit_id BIGINT UNSIGNED NULL
    COMMENT 'đơn vị tính, trỏ product_units; NULL = chưa khai'
    AFTER location_id;

ALTER TABLE products
  ADD KEY idx_products_unit (unit_id);

ALTER TABLE products
  ADD CONSTRAINT fk_products_unit
    FOREIGN KEY (unit_id) REFERENCES product_units (id);

-- Thuế suất của mặt hàng. Lưu THẲNG con số chứ không phải id một dòng
-- thuế: bảng thuế bên này là bộ mức được phép chọn (Thuế 0020), không
-- phải danh sách bản ghi có id để trỏ vào.
--
-- Hai giá trị âm là mã của hoá đơn điện tử, không phải phần trăm:
--   -1 = KCT   (không chịu thuế)
--   -2 = KKKNT (không kê khai, không nộp thuế)
-- Quy chúng về 0 là mất phân biệt với mức "0%" — xem domain.MucKhongChiuThue.
ALTER TABLE products
  ADD COLUMN vat INT NOT NULL DEFAULT 0
    COMMENT '% thuế GTGT; -1 = KCT, -2 = KKKNT'
    AFTER cost_price;

-- Giá bán sỉ. NULL = chưa khai riêng, lúc bán lấy bằng giá bán lẻ —
-- KHÔNG phải 0đ. Để 0 thì nút "giá sỉ" ngoài quầy bán mất tiền.
ALTER TABLE products
  ADD COLUMN wholesale_price DECIMAL(12,2) NULL
    COMMENT 'giá bán sỉ; NULL = chưa khai, dùng bằng giá bán lẻ'
    AFTER sale_price;

-- Định mức tồn: dưới min thì cần nhập thêm, trên max là ứ hàng. NULL =
-- không theo dõi mức nào cả.
ALTER TABLE products
  ADD COLUMN min_stock INT NULL COMMENT 'định mức tồn tối thiểu; NULL = không theo dõi' AFTER wholesale_price;

ALTER TABLE products
  ADD COLUMN max_stock INT NULL COMMENT 'định mức tồn tối đa; NULL = không theo dõi' AFTER min_stock;

-- Mặt hàng có nhiều biến thể hay không.
--
-- Vì sao cần cờ này khi đã đếm được số dòng biến thể: hàng nhiều thuộc
-- tính đang trong lúc khai có thể mới có một tổ hợp, mà đếm thì ra 1 —
-- không phân biệt được với hàng vốn dĩ không có biến thể. Cờ nói ý định
-- của người khai, số dòng chỉ nói hiện trạng.
ALTER TABLE products
  ADD COLUMN is_multi_variant TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = hàng nhiều biến thể (tổ hợp thuộc tính); 0 = hàng thường, chỉ có dòng mặc định'
    AFTER is_featured;

-- Hàng nào đang có biến thể mang tên thì đánh dấu là hàng nhiều biến thể.
UPDATE products p
SET p.is_multi_variant = 1
WHERE EXISTS (
  SELECT 1 FROM product_variants v
  WHERE v.product_id = p.id AND v.deleted_at IS NULL AND TRIM(v.name) <> ''
);

-- Ba cột của khuôn áo bóng đá.
SET @co_cot := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'team'
);
SET @sql := IF(@co_cot > 0, 'ALTER TABLE products DROP COLUMN team', 'DO 0');
PREPARE lenh FROM @sql; EXECUTE lenh; DEALLOCATE PREPARE lenh;

SET @co_cot := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'season'
);
SET @sql := IF(@co_cot > 0, 'ALTER TABLE products DROP COLUMN season', 'DO 0');
PREPARE lenh FROM @sql; EXECUTE lenh; DEALLOCATE PREPARE lenh;

SET @co_cot := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'kit_type'
);
SET @sql := IF(@co_cot > 0, 'ALTER TABLE products DROP COLUMN kit_type', 'DO 0');
PREPARE lenh FROM @sql; EXECUTE lenh; DEALLOCATE PREPARE lenh;


-- ---------------------------------------------------------------------
--  4) DÒNG CHỨNG TỪ: size + màu -> tên biến thể
--
--  Bốn bảng này CHỤP LẠI tên hàng lúc lập phiếu, để phiếu cũ đọc được
--  nguyên trạng kể cả khi mặt hàng đã bị sửa hay xoá. Chúng đổi theo
--  biến thể chứ không phải vì nghiệp vụ đổi: chỗ nào trước ghi "M"/"Đỏ"
--  thì nay ghi "M · Đỏ".
-- ---------------------------------------------------------------------

ALTER TABLE order_items
  ADD COLUMN variant_name VARCHAR(255) NOT NULL DEFAULT ''
    COMMENT 'tên biến thể chụp lúc bán; rỗng = hàng không biến thể'
    AFTER variant_sku;
UPDATE order_items
SET variant_name = TRIM(CONCAT_WS(' · ', NULLIF(TRIM(size), ''), NULLIF(TRIM(color), '')));
ALTER TABLE order_items DROP COLUMN size, DROP COLUMN color;

ALTER TABLE order_return_items
  ADD COLUMN variant_name VARCHAR(255) NOT NULL DEFAULT ''
    COMMENT 'tên biến thể chụp lúc lập phiếu trả'
    AFTER variant_sku;
UPDATE order_return_items
SET variant_name = TRIM(CONCAT_WS(' · ', NULLIF(TRIM(size), ''), NULLIF(TRIM(color), '')));
ALTER TABLE order_return_items DROP COLUMN size, DROP COLUMN color;

ALTER TABLE purchase_order_items
  ADD COLUMN variant_name VARCHAR(255) NOT NULL DEFAULT ''
    COMMENT 'tên biến thể chụp lúc đặt mua'
    AFTER variant_sku;
UPDATE purchase_order_items
SET variant_name = TRIM(CONCAT_WS(' · ', NULLIF(TRIM(size), ''), NULLIF(TRIM(color), '')));
ALTER TABLE purchase_order_items DROP COLUMN size, DROP COLUMN color;

ALTER TABLE purchase_return_items
  ADD COLUMN variant_name VARCHAR(255) NOT NULL DEFAULT ''
    COMMENT 'tên biến thể chụp lúc trả nhà cung cấp'
    AFTER variant_sku;
UPDATE purchase_return_items
SET variant_name = TRIM(CONCAT_WS(' · ', NULLIF(TRIM(size), ''), NULLIF(TRIM(color), '')));
ALTER TABLE purchase_return_items DROP COLUMN size, DROP COLUMN color;
