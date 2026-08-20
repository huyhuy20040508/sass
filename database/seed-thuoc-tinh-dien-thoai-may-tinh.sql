-- Bộ thuộc tính sẵn cho tiệm ĐIỆN THOẠI – MÁY TÍNH.
--
-- Đây là DỮ LIỆU của một cửa hàng, không phải migration: mỗi ngành hàng khai
-- một bộ khác nhau (tiệm cà phê cần "Mức đá", tiệm này cần "RAM"), nên không
-- gieo cho mọi tenant.
--
-- Cách chạy (đổi @tenant thành id cửa hàng cần gieo):
--   mysql -u root --default-character-set=utf8mb4 selliotech < database/seed-thuoc-tinh-dien-thoai-may-tinh.sql
--
-- Chạy lại bao nhiêu lần cũng được: thuộc tính hay giá trị đã có thì bỏ qua,
-- không tạo bản thứ hai và không đụng tới thứ người dùng đã sửa.
--
-- Mã đặt theo đúng cách tầng Go đặt hộ: mã thuộc tính viết hoa không dấu, mã
-- giá trị là <mã thuộc tính> + số thứ tự hai chữ số (RAM01, RAM02…).

SET @tenant := 1;

-- them_tt: thêm một thuộc tính nếu cửa hàng chưa có mã ấy.
DROP PROCEDURE IF EXISTS them_tt;
DROP PROCEDURE IF EXISTS them_gt;

DELIMITER //

-- COLLATE khai tay: tham số của procedure mặc định mang utf8mb4_general_ci, còn
-- cột thì utf8mb4_unicode_ci — so hai bên là MySQL báo 'Illegal mix of collations'.
CREATE PROCEDURE them_tt(
  p_tenant BIGINT UNSIGNED,
  p_code VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  p_name VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
)
BEGIN
  INSERT INTO product_attributes (tenant_id, code, name, is_active, raw_material, created_at, updated_at)
  SELECT p_tenant, p_code, p_name, 1, 0, NOW(3), NOW(3)
  FROM DUAL
  WHERE NOT EXISTS (
    SELECT 1 FROM product_attributes WHERE tenant_id = p_tenant AND code = p_code
  );
END //

-- them_gt: thêm một giá trị vào thuộc tính, mã tự đánh theo số thứ tự đang có.
CREATE PROCEDURE them_gt(
  p_tenant BIGINT UNSIGNED,
  p_ma_tt VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  p_name VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
)
BEGIN
  DECLARE v_attr BIGINT UNSIGNED;
  DECLARE v_stt INT;

  SELECT id INTO v_attr
  FROM product_attributes
  WHERE tenant_id = p_tenant AND code = p_ma_tt AND deleted_at IS NULL
  LIMIT 1;

  IF v_attr IS NOT NULL THEN
    SELECT COUNT(*) + 1 INTO v_stt FROM product_attribute_values WHERE attribute_id = v_attr;

    INSERT INTO product_attribute_values (tenant_id, attribute_id, code, name, created_at, updated_at)
    SELECT p_tenant, v_attr, CONCAT(p_ma_tt, LPAD(v_stt, 2, '0')), p_name, NOW(3), NOW(3)
    FROM DUAL
    WHERE NOT EXISTS (
      SELECT 1 FROM product_attribute_values WHERE attribute_id = v_attr AND name = p_name
    );
  END IF;
END //

DELIMITER ;

-- ---------- Dùng chung cho cả điện thoại lẫn máy tính ----------

CALL them_tt(@tenant, 'MAUSAC', 'Màu sắc');
CALL them_gt(@tenant, 'MAUSAC', 'Đen');
CALL them_gt(@tenant, 'MAUSAC', 'Trắng');
CALL them_gt(@tenant, 'MAUSAC', 'Bạc');
CALL them_gt(@tenant, 'MAUSAC', 'Xám');
CALL them_gt(@tenant, 'MAUSAC', 'Xanh dương');
CALL them_gt(@tenant, 'MAUSAC', 'Xanh lá');
CALL them_gt(@tenant, 'MAUSAC', 'Vàng');
CALL them_gt(@tenant, 'MAUSAC', 'Hồng');
CALL them_gt(@tenant, 'MAUSAC', 'Tím');
CALL them_gt(@tenant, 'MAUSAC', 'Đỏ');

CALL them_tt(@tenant, 'DUNGLUONG', 'Dung lượng lưu trữ');
CALL them_gt(@tenant, 'DUNGLUONG', '64GB');
CALL them_gt(@tenant, 'DUNGLUONG', '128GB');
CALL them_gt(@tenant, 'DUNGLUONG', '256GB');
CALL them_gt(@tenant, 'DUNGLUONG', '512GB');
CALL them_gt(@tenant, 'DUNGLUONG', '1TB');
CALL them_gt(@tenant, 'DUNGLUONG', '2TB');

CALL them_tt(@tenant, 'RAM', 'RAM');
CALL them_gt(@tenant, 'RAM', '4GB');
CALL them_gt(@tenant, 'RAM', '6GB');
CALL them_gt(@tenant, 'RAM', '8GB');
CALL them_gt(@tenant, 'RAM', '12GB');
CALL them_gt(@tenant, 'RAM', '16GB');
CALL them_gt(@tenant, 'RAM', '32GB');
CALL them_gt(@tenant, 'RAM', '64GB');

CALL them_tt(@tenant, 'MANHINH', 'Kích thước màn hình');
CALL them_gt(@tenant, 'MANHINH', '6.1 inch');
CALL them_gt(@tenant, 'MANHINH', '6.5 inch');
CALL them_gt(@tenant, 'MANHINH', '6.7 inch');
CALL them_gt(@tenant, 'MANHINH', '13.3 inch');
CALL them_gt(@tenant, 'MANHINH', '14 inch');
CALL them_gt(@tenant, 'MANHINH', '15.6 inch');
CALL them_gt(@tenant, 'MANHINH', '16 inch');

CALL them_tt(@tenant, 'HEDIEUHANH', 'Hệ điều hành');
CALL them_gt(@tenant, 'HEDIEUHANH', 'Android');
CALL them_gt(@tenant, 'HEDIEUHANH', 'iOS');
CALL them_gt(@tenant, 'HEDIEUHANH', 'iPadOS');
CALL them_gt(@tenant, 'HEDIEUHANH', 'Windows 11 Home');
CALL them_gt(@tenant, 'HEDIEUHANH', 'Windows 11 Pro');
CALL them_gt(@tenant, 'HEDIEUHANH', 'macOS');
CALL them_gt(@tenant, 'HEDIEUHANH', 'Không kèm hệ điều hành');

CALL them_tt(@tenant, 'TINHTRANG', 'Tình trạng máy');
CALL them_gt(@tenant, 'TINHTRANG', 'Mới 100% (nguyên seal)');
CALL them_gt(@tenant, 'TINHTRANG', 'Like New 99%');
CALL them_gt(@tenant, 'TINHTRANG', 'Đã qua sử dụng');
CALL them_gt(@tenant, 'TINHTRANG', 'Máy trưng bày');
CALL them_gt(@tenant, 'TINHTRANG', 'Hàng đổi bảo hành');

CALL them_tt(@tenant, 'LOAIHANG', 'Loại hàng');
CALL them_gt(@tenant, 'LOAIHANG', 'Chính hãng VN/A');
CALL them_gt(@tenant, 'LOAIHANG', 'Hàng quốc tế');
CALL them_gt(@tenant, 'LOAIHANG', 'Xách tay');

CALL them_tt(@tenant, 'BAOHANH', 'Thời gian bảo hành');
CALL them_gt(@tenant, 'BAOHANH', '1 tháng');
CALL them_gt(@tenant, 'BAOHANH', '3 tháng');
CALL them_gt(@tenant, 'BAOHANH', '6 tháng');
CALL them_gt(@tenant, 'BAOHANH', '12 tháng');
CALL them_gt(@tenant, 'BAOHANH', '24 tháng');
CALL them_gt(@tenant, 'BAOHANH', '36 tháng');

-- ---------- Riêng điện thoại ----------

CALL them_tt(@tenant, 'SIM', 'Số SIM');
CALL them_gt(@tenant, 'SIM', '1 SIM');
CALL them_gt(@tenant, 'SIM', '2 SIM');
CALL them_gt(@tenant, 'SIM', '1 SIM + eSIM');
CALL them_gt(@tenant, 'SIM', '2 eSIM');

-- ---------- Riêng máy tính ----------

CALL them_tt(@tenant, 'CPU', 'Bộ vi xử lý');
CALL them_gt(@tenant, 'CPU', 'Intel Core i3');
CALL them_gt(@tenant, 'CPU', 'Intel Core i5');
CALL them_gt(@tenant, 'CPU', 'Intel Core i7');
CALL them_gt(@tenant, 'CPU', 'Intel Core i9');
CALL them_gt(@tenant, 'CPU', 'AMD Ryzen 5');
CALL them_gt(@tenant, 'CPU', 'AMD Ryzen 7');
CALL them_gt(@tenant, 'CPU', 'AMD Ryzen 9');
CALL them_gt(@tenant, 'CPU', 'Apple M2');
CALL them_gt(@tenant, 'CPU', 'Apple M3');
CALL them_gt(@tenant, 'CPU', 'Apple M4');

CALL them_tt(@tenant, 'OCUNG', 'Ổ cứng');
CALL them_gt(@tenant, 'OCUNG', 'SSD 256GB');
CALL them_gt(@tenant, 'OCUNG', 'SSD 512GB');
CALL them_gt(@tenant, 'OCUNG', 'SSD 1TB');
CALL them_gt(@tenant, 'OCUNG', 'SSD 2TB');
CALL them_gt(@tenant, 'OCUNG', 'HDD 1TB');
CALL them_gt(@tenant, 'OCUNG', 'HDD 2TB');

CALL them_tt(@tenant, 'VGA', 'Card đồ họa');
CALL them_gt(@tenant, 'VGA', 'Card tích hợp');
CALL them_gt(@tenant, 'VGA', 'GTX 1650');
CALL them_gt(@tenant, 'VGA', 'RTX 3050');
CALL them_gt(@tenant, 'VGA', 'RTX 4050');
CALL them_gt(@tenant, 'VGA', 'RTX 4060');
CALL them_gt(@tenant, 'VGA', 'RTX 4070');

DROP PROCEDURE IF EXISTS them_tt;
DROP PROCEDURE IF EXISTS them_gt;
