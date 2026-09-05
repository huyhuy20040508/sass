-- =====================================================================
--  0052_vi-tri-theo-chi-nhanh-2026-09-04.sql
--  Ngày: 04/09/2026
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
--  VỊ TRÍ ĐỂ HÀNG LÀ CHUYỆN CỦA TỪNG CHI NHÁNH
--
--  Hai cái sai đang sửa, và chúng là hai cái khác nhau:
--
--   1. `product_locations` không có chủ. "Kệ A" của Quận 1 và "Kệ A" của
--      Quận 7 là HAI CHỖ VẬT LÝ KHÁC NHAU, nhưng bảng để chung nên ô
--      chọn vị trí trộn kệ của mọi chi nhánh vào một danh sách. Người
--      soạn hàng ở Quận 7 đọc thấy "Kho lạnh" mà kho ấy nằm ở quận khác.
--
--   2. `products.location_id` là MỘT giá trị cho cả chuỗi. Một mặt hàng
--      chỉ khai được đúng một vị trí, trong khi ở mỗi kho nó nằm một kệ
--      khác. Đây mới là cái sai nặng: nó khiến tính năng "đi thẳng tới
--      kệ" — toàn bộ lý do bảng vị trí tồn tại — vô dụng với chuỗi.
--
--  Chưa gãy ngoài đời vì gần như mọi khách hôm nay còn một chi nhánh.
--  Sửa BÂY GIỜ vì càng nhiều dữ liệu thì lượt dời càng phải đoán nhiều.
--
--  BỎ HẲN `products.location_id`, không giữ lại làm "vị trí mặc định".
--  Hai chỗ cùng giữ một sự thật thì sớm muộn lệch nhau, và cái lệch chỉ
--  lộ ra lúc có người đi tìm hàng theo kệ — đúng lý do migration 0036 đã
--  bỏ cột tồn gộp `product_variants.stock_quantity`.
--
--  DỜI DỮ LIỆU CŨ VỀ CHI NHÁNH ĐANG MỞ CÓ ID NHỎ NHẤT. Với tiệm một chi
--  nhánh (gần như mọi khách) đó là câu trả lời ĐÚNG, không phải đoán.
--  Với tiệm nhiều chi nhánh thì đây là phỏng đoán — nhưng dữ liệu cũ vốn
--  không mang thông tin chi nhánh nào cả, nên không có câu nào đúng hơn,
--  và để trống thì mất luôn phần khai tay của họ.
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
--  1. Kệ thuộc về MỘT chi nhánh
-- ---------------------------------------------------------------------
ALTER TABLE product_locations
  ADD COLUMN shop_id BIGINT UNSIGNED NULL COMMENT 'chi nhanh so huu ke nay' AFTER tenant_id;

-- Dời kệ cũ về chi nhánh đang mở có id nhỏ nhất của chính cửa hàng ấy.
UPDATE product_locations pl
SET pl.shop_id = (
  SELECT MIN(s.id) FROM shops s
  WHERE s.tenant_id = pl.tenant_id AND s.is_active = 1
)
WHERE pl.shop_id IS NULL;

-- Cửa hàng không còn chi nhánh nào đang mở (không xảy ra qua đường bình
-- thường): lấy chi nhánh bất kỳ, miễn là đúng cửa hàng. Thà gán vào một
-- chi nhánh đã đóng còn hơn để NULL rồi câu ALTER dưới đây gãy.
UPDATE product_locations pl
SET pl.shop_id = (SELECT MIN(s.id) FROM shops s WHERE s.tenant_id = pl.tenant_id)
WHERE pl.shop_id IS NULL;

-- Kệ mồ côi (cửa hàng không có chi nhánh nào — dữ liệu rác): xoá mềm rồi
-- mới siết NOT NULL, chứ không xoá cứng: có thể còn mặt hàng trỏ tới.
DELETE FROM product_locations WHERE shop_id IS NULL;

ALTER TABLE product_locations
  MODIFY COLUMN shop_id BIGINT UNSIGNED NOT NULL COMMENT 'chi nhanh so huu ke nay';

-- Mã kệ duy nhất TRONG MỘT CHI NHÁNH, không phải trong cả cửa hàng: hai
-- chi nhánh cùng đặt "KEA1" cho kệ A của mình là chuyện bình thường, và
-- bắt họ nghĩ ra mã khác nhau chỉ vì database là bắt sai người.
ALTER TABLE product_locations
  DROP INDEX uq_product_locations_code,
  ADD UNIQUE KEY uq_product_locations_code (tenant_id, shop_id, code),
  ADD CONSTRAINT fk_product_locations_shop FOREIGN KEY (shop_id) REFERENCES shops (id) ON DELETE CASCADE;

-- ---------------------------------------------------------------------
--  2. Mặt hàng nằm ở kệ nào, TẠI TỪNG CHI NHÁNH
--
--  Bảng nối chứ không phải cột: số vị trí của một mặt hàng bằng số chi
--  nhánh, và con số ấy đổi mỗi lần chủ tiệm mở thêm điểm bán.
--
--  THIẾU DÒNG = CHƯA GÁN KỆ ở chi nhánh đó — cùng quy ước với
--  `product_shops` (rỗng = mọi chi nhánh) và `variant_shop_prices`
--  (thiếu dòng = giá gốc). Không gieo sẵn đủ mọi cặp: tiệm 500 mặt hàng
--  × 3 chi nhánh là 1.500 dòng, mà phần lớn chưa ai xếp kệ.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_shop_locations (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,

  shop_id     BIGINT UNSIGNED NOT NULL,
  product_id  BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,

  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,

  PRIMARY KEY (id),
  -- Một mặt hàng nằm ở ĐÚNG MỘT kệ trong một chi nhánh. Muốn hai chỗ thì
  -- đó là hai kệ, và cách nói ra là tách mặt hàng — không phải nhét hai
  -- dòng vào đây rồi để người soạn hàng đoán đi chỗ nào.
  UNIQUE KEY uq_psl_shop_product (shop_id, product_id),
  KEY idx_psl_location (location_id),
  KEY idx_psl_tenant (tenant_id),
  CONSTRAINT fk_psl_tenant   FOREIGN KEY (tenant_id)   REFERENCES tenants (id),
  CONSTRAINT fk_psl_shop     FOREIGN KEY (shop_id)     REFERENCES shops (id) ON DELETE CASCADE,
  CONSTRAINT fk_psl_product  FOREIGN KEY (product_id)  REFERENCES products (id) ON DELETE CASCADE,
  CONSTRAINT fk_psl_location FOREIGN KEY (location_id) REFERENCES product_locations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dời phần khai tay cũ sang: mặt hàng nào đang có `location_id` thì gán
-- vào ĐÚNG chi nhánh sở hữu cái kệ ấy — kệ đã được dời ở bước 1 nên chỗ
-- này không phải đoán lại lần nữa.
INSERT INTO product_shop_locations (tenant_id, shop_id, product_id, location_id, created_at, updated_at)
SELECT p.tenant_id, pl.shop_id, p.id, p.location_id, NOW(3), NOW(3)
FROM products p
JOIN product_locations pl ON pl.id = p.location_id
WHERE p.location_id IS NOT NULL
ON DUPLICATE KEY UPDATE location_id = VALUES(location_id);

-- ---------------------------------------------------------------------
--  3. Bỏ cột cũ — xem lý do ở đầu tệp
-- ---------------------------------------------------------------------
ALTER TABLE products
  DROP FOREIGN KEY fk_products_location;

ALTER TABLE products
  DROP COLUMN location_id;
