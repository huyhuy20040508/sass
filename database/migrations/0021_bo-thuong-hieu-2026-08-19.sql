-- =====================================================================
--  0021_bo-thuong-hieu-2026-08-19.sql
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
--  BỎ HẲN MODULE THƯƠNG HIỆU
--
--  Bán lẻ ở đây không dùng tới thương hiệu: hàng phân loại bằng nhóm
--  hàng hoá là đủ. Giữ lại một bảng rỗng cùng một cột luôn NULL chỉ tổ
--  bày thêm một ô lọc không lọc được gì.
--
--  Gỡ theo đúng thứ tự phụ thuộc: mục tiêu khuyến mãi -> khoá ngoại +
--  chỉ mục trên products -> cột brand_id -> bảng brands. Đảo thứ tự là
--  MySQL chặn ngay ở bước bỏ bảng.
--
--  MẤT DỮ LIỆU: có. Mọi thương hiệu đã khai, mọi liên kết sản phẩm ->
--  thương hiệu, và mọi phạm vi khuyến mãi khai theo thương hiệu đều mất
--  hẳn, không lấy lại được. Cần thì sao lưu trước khi chạy.
-- =====================================================================

-- 1) Phạm vi khuyến mãi khai theo thương hiệu — bỏ trước, nếu không nó
--    thành mấy dòng trỏ vào một bảng không còn tồn tại.
DELETE FROM promotion_targets WHERE target_type = 'brand';

-- 2) Khoá ngoại và chỉ mục của cột brand_id. MySQL không có
--    "DROP FOREIGN KEY IF EXISTS", nên hỏi information_schema rồi dựng
--    câu lệnh — chạy lại lần hai không báo lỗi.
SET @co_fk := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'products'
    AND CONSTRAINT_NAME = 'fk_products_brand'
);
SET @sql := IF(@co_fk > 0, 'ALTER TABLE products DROP FOREIGN KEY fk_products_brand', 'DO 0');
PREPARE lenh FROM @sql; EXECUTE lenh; DEALLOCATE PREPARE lenh;

SET @co_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'products'
    AND INDEX_NAME = 'idx_products_brand'
);
SET @sql := IF(@co_idx > 0, 'ALTER TABLE products DROP INDEX idx_products_brand', 'DO 0');
PREPARE lenh FROM @sql; EXECUTE lenh; DEALLOCATE PREPARE lenh;

-- 3) Cột brand_id.
SET @co_cot := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'products'
    AND COLUMN_NAME = 'brand_id'
);
SET @sql := IF(@co_cot > 0, 'ALTER TABLE products DROP COLUMN brand_id', 'DO 0');
PREPARE lenh FROM @sql; EXECUTE lenh; DEALLOCATE PREPARE lenh;

-- 4) Và bảng brands.
DROP TABLE IF EXISTS brands;
