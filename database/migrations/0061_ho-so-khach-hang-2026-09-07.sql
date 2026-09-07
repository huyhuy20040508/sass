-- =====================================================================
--  0061_ho-so-khach-hang-2026-09-07.sql
--  Ngày: 07/09/2026
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy (cục bộ / thử / thật đều khác tên).
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  công cụ giữ vân tay và sẽ báo lệch. Cần thêm gì thì viết tệp mới.
-- =====================================================================
--
--  HỒ SƠ KHÁCH HÀNG — phần còn thiếu của màn Thống kê → Khách hàng.
--
--  Màn đã dựng xong giao diện theo bản v2, nhưng bảy ô trong đó chưa có chỗ
--  để ghi: mã khách, loại khách (cá nhân / doanh nghiệp), nhóm khách, mã số
--  thuế, CCCD, người đại diện và ghi chú. Tệp này mở chỗ cho chúng.
--
--  BA ĐIỀU CẦN BIẾT TRƯỚC KHI ĐỌC TIẾP:
--
--   1. Khách hàng bên mình KHÔNG có bảng riêng như `3rd_customers` của v2 —
--      họ là dòng trong `users` mang vai `customer` (roles.id = 4). Nên các
--      cột dưới đây gắn thẳng vào `users`, và mang tiền tố `customer_` để
--      người đọc bảng `users` biết ngay chúng chỉ có nghĩa với khách. Ngoại
--      lệ: `tax_code`, `citizen_id`, `representative_*` giữ tên trần vì
--      chúng là khái niệm chung, ai cũng có thể có.
--
--   2. `customer_code` sinh theo dải `cus-00001` — đúng dạng bản v2 đang
--      chạy. Sinh ở tầng ứng dụng (service) chứ không phải cột tính sẵn:
--      cửa hàng sau này có thể đổi quy tắc đánh số như đã làm với nhà cung
--      cấp, mà cột sinh sẵn thì không sửa lại được.
--
--   3. KHÔNG có cột `points` / `rank_id`. Ô lọc Điểm và Cấp độ thành viên
--      trên màn vẫn để đó theo khuôn v2 nhưng chưa nối — điểm tích luỹ là
--      cả một module (quy tắc tích, quy tắc đổi, lịch sử dùng điểm), thêm
--      mỗi cái cột rỗng thì chỉ tổ có chỗ chứa số không ai sinh ra.
--
--  CÒN NỢ CỦA KHÁCH thì KHÔNG cần cột nào: gộp thẳng từ `orders` theo
--  `payment_status` (xem AggregateCustomerOrders bên Go). Đẻ thêm một sổ nợ
--  song song với sổ đơn là hai nguồn sự thật cho cùng một con số.
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
--  1. NHÓM KHÁCH HÀNG
--
--  Cửa hàng tự khai "Khách vãng lai", "Khách sỉ", "Khách thân thiết"… rồi
--  gán cho từng khách. Port từ `3rd_group_customers` của v2.
--
--  VÌ SAO KHÔNG CÓ UNIQUE KEY TRÊN TÊN: bảng xoá mềm. Đưa `deleted_at` vào
--  khoá duy nhất thì khoá không chặn được gì (MySQL coi mỗi NULL là một giá
--  trị riêng, mà dòng đang sống thì deleted_at luôn NULL); còn đặt khoá trần
--  trên (tenant, name) thì tên của dòng ĐÃ XOÁ vẫn giữ chỗ — xoá "Khách sỉ"
--  rồi khai lại đúng tên ấy là bị chặn, trong khi đó mới là việc bình
--  thường nhất. Chặn trùng đặt ở tầng ứng dụng và chỉ xét dòng chưa xoá,
--  đúng như `income_expense_types` và `product_units` đang làm.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customer_groups (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,

  name VARCHAR(150) NOT NULL COMMENT 'ten nhom, vi du Khach vang lai',
  note VARCHAR(255) NULL,

  status TINYINT(1) NOT NULL DEFAULT 1
    COMMENT '1 = dang dung, 0 = ngung: nhom ngung khong hien trong o chon khi khai khach moi',

  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,
  deleted_at DATETIME(3) NULL,

  PRIMARY KEY (id),
  KEY idx_cg_tenant (tenant_id, name(100)),
  KEY idx_cg_deleted (deleted_at),
  CONSTRAINT fk_cg_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Gieo nhóm khởi điểm cho MỌI cửa hàng đang có. "Khách vãng lai" là nhóm
-- bản v2 gán sẵn cho khách chưa xếp nhóm, nên khách cũ có chỗ đứng ngay.
-- LEFT JOIN ... IS NULL để chạy lại tệp không đẻ thêm dòng trùng.
INSERT INTO customer_groups (tenant_id, name, status, created_at, updated_at)
SELECT t.id, m.name, 1, NOW(3), NOW(3)
FROM tenants t
CROSS JOIN (
            SELECT 'Khách vãng lai' AS name
  UNION ALL SELECT 'Khách thân thiết'
  UNION ALL SELECT 'Khách sỉ'
) AS m
LEFT JOIN customer_groups x
       ON x.tenant_id = t.id
      AND x.name      = m.name
WHERE x.id IS NULL;

-- ---------------------------------------------------------------------
--  2. CÁC CỘT HỒ SƠ TRÊN `users`
--
--  Viết bằng nhánh IF để chạy lại tệp không gãy: MySQL không có
--  "ADD COLUMN IF NOT EXISTS", mà tệp migration thì phải chịu được lượt
--  chạy thứ hai (môi trường thử đã chạy dở rồi chạy lại).
-- ---------------------------------------------------------------------
SET @db := DATABASE();

SET @co := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'customer_code');
SET @sql := IF(@co = 0,
  'ALTER TABLE users ADD COLUMN customer_code VARCHAR(30) NULL COMMENT "ma khach hang, dai cus-00001; NULL voi tai khoan noi bo" AFTER phone',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @co := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'customer_type');
SET @sql := IF(@co = 0,
  'ALTER TABLE users ADD COLUMN customer_type TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT "0 = ca nhan, 1 = doanh nghiep" AFTER customer_code',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @co := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'customer_group_id');
SET @sql := IF(@co = 0,
  'ALTER TABLE users ADD COLUMN customer_group_id BIGINT UNSIGNED NULL COMMENT "tro toi customer_groups; NULL = chua xep nhom" AFTER customer_type',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @co := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'tax_code');
SET @sql := IF(@co = 0,
  'ALTER TABLE users ADD COLUMN tax_code VARCHAR(20) NULL COMMENT "ma so thue, chi dung voi khach doanh nghiep" AFTER customer_group_id',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @co := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'citizen_id');
SET @sql := IF(@co = 0,
  'ALTER TABLE users ADD COLUMN citizen_id VARCHAR(12) NULL COMMENT "CCCD, chi dung voi khach ca nhan" AFTER tax_code',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @co := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'representative_name');
SET @sql := IF(@co = 0,
  'ALTER TABLE users ADD COLUMN representative_name VARCHAR(150) NULL COMMENT "nguoi dai dien cua khach doanh nghiep" AFTER citizen_id',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @co := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'representative_phone');
SET @sql := IF(@co = 0,
  'ALTER TABLE users ADD COLUMN representative_phone VARCHAR(20) NULL AFTER representative_name',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @co := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'customer_note');
SET @sql := IF(@co = 0,
  'ALTER TABLE users ADD COLUMN customer_note VARCHAR(500) NULL COMMENT "ghi chu ve khach" AFTER representative_phone',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Tra khách theo mã, và tra nhóm khi lọc danh sách.
SET @co := (SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_customer_code');
SET @sql := IF(@co = 0,
  'ALTER TABLE users ADD KEY idx_users_customer_code (tenant_id, customer_code)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @co := (SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_customer_group');
SET @sql := IF(@co = 0,
  'ALTER TABLE users ADD KEY idx_users_customer_group (customer_group_id)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
--  3. LẤP DỮ LIỆU CHO KHÁCH ĐÃ CÓ
--
--  Mã sinh từ id nên khớp đúng cái màn đang bày ra hôm nay (PHP đang tự ghép
--  `cus-` + id lúc đọc) — chuyển sang cột thật mà mã không đổi, chứng từ cũ
--  in ra vẫn đúng.
--
--  Nhóm mặc định là "Khách vãng lai" của chính cửa hàng đó.
-- ---------------------------------------------------------------------
UPDATE users
   SET customer_code = CONCAT('cus-', LPAD(id, 5, '0'))
 WHERE role_id = 4
   AND customer_code IS NULL;

UPDATE users u
  JOIN customer_groups g
    ON g.tenant_id  = u.tenant_id
   AND g.name       = 'Khách vãng lai'
   AND g.deleted_at IS NULL
   SET u.customer_group_id = g.id
 WHERE u.role_id = 4
   AND u.customer_group_id IS NULL;
