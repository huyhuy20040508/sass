-- =====================================================================
--  0077_phuong-thuc-thanh-toan-2026-10-12.sql
--  Ngày: 12/10/2026
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy.
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  viết tệp mới.
-- =====================================================================
--
--  PHƯƠNG THỨC THANH TOÁN THEO CHI NHÁNH — chép mô hình của ordertable v2
--  (sys_payment_methods + sys_payment_method_details).
--
--  Mỗi chi nhánh có đúng 4 phương thức cố định: Tiền mặt (1), Chuyển khoản
--  (4), Quẹt thẻ (5), QR tự động (6). Người dùng không thêm/xoá phương
--  thức, chỉ bật tắt và chọn một cái làm mặc định ở quầy. Thứ thêm/xoá được
--  là CHI TIẾT: tài khoản ngân hàng, cấu hình máy quẹt thẻ, cổng QR.
--
--  Không có câu INSERT dữ liệu ở đây: 4 phương thức của một chi nhánh được
--  API dựng ở lần đầu có người đọc (PaymentMethodService.dungSan), kèm chuyển
--  tài khoản ngân hàng / công tắc PayOS, SePay từ trang Cài đặt cũ sang. Làm
--  bên Go vì phải dò mã BIN từ tên ngân hàng gõ tự do — SQL làm không nổi —
--  và vì chi nhánh mở SAU migration này cũng cần đúng lượt dựng ấy.
-- =====================================================================

CREATE TABLE IF NOT EXISTS payment_methods (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  BIGINT UNSIGNED NOT NULL,
  shop_id    BIGINT UNSIGNED NOT NULL COMMENT 'chi nhánh',
  type       TINYINT UNSIGNED NOT NULL COMMENT '1 tiền mặt | 4 chuyển khoản | 5 quẹt thẻ | 6 QR tự động',
  status     TINYINT(1) NOT NULL DEFAULT 0,
  is_default TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'mở sẵn khi thanh toán ở quầy',
  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,

  PRIMARY KEY (id),
  -- Đúng một dòng mỗi loại mỗi chi nhánh: hai lượt dựng sẵn chạy cùng lúc
  -- thì lượt sau đụng khoá này và bỏ qua, không đẻ ra phương thức thứ hai.
  UNIQUE KEY uq_payment_methods_shop_type (tenant_id, shop_id, type),
  CONSTRAINT fk_payment_methods_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_payment_methods_shop FOREIGN KEY (shop_id) REFERENCES shops (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_devices (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id   BIGINT UNSIGNED NOT NULL,
  shop_id     BIGINT UNSIGNED NOT NULL,
  code        VARCHAR(50)  NOT NULL COMMENT 'POS ID / số serial',
  name        VARCHAR(100) NOT NULL,
  description VARCHAR(255) NULL,
  is_physical TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0 máy mPOS | 1 máy quẹt thẻ độc lập',
  created_at  DATETIME(3) NULL,
  updated_at  DATETIME(3) NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_payment_devices_code (tenant_id, shop_id, code),
  CONSTRAINT fk_payment_devices_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_payment_devices_shop FOREIGN KEY (shop_id) REFERENCES shops (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_method_details (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id         BIGINT UNSIGNED NOT NULL,
  shop_id           BIGINT UNSIGNED NOT NULL,
  payment_method_id BIGINT UNSIGNED NOT NULL,
  detail_type       VARCHAR(20) NOT NULL COMMENT 'bank_transfer | mpos_device | physical_device | sepay | payos | mpos_qr | momo',
  status            TINYINT(1) NOT NULL DEFAULT 1,
  -- v2 cất cờ mặc định TRONG JSON (bank_default / swipe_card_default). Tách
  -- thành cột để luật "đúng một mặc định trong các dòng đang bật" kiểm bằng
  -- một câu SQL, không phải giải JSON từng dòng.
  is_default        TINYINT(1) NOT NULL DEFAULT 0,
  -- Máy quẹt thẻ gắn với cấu hình này. Cột riêng (không nằm trong JSON) để
  -- khoá "mỗi máy một cấu hình" và để xoá máy thì biết máy đang được dùng.
  device_id         BIGINT UNSIGNED NULL,
  -- Mọi ô còn lại theo từng loại: ngân hàng, merchant, phí thẻ… Khoá bí mật
  -- (secret key, API key, checksum) đã mã hoá bằng pkg/bimat trước khi vào đây.
  data              JSON NULL,
  created_at        DATETIME(3) NULL,
  updated_at        DATETIME(3) NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_payment_method_details_device (device_id),
  KEY idx_payment_method_details_method (payment_method_id, status),
  CONSTRAINT fk_payment_method_details_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_payment_method_details_method FOREIGN KEY (payment_method_id) REFERENCES payment_methods (id) ON DELETE CASCADE,
  CONSTRAINT fk_payment_method_details_device FOREIGN KEY (device_id) REFERENCES payment_devices (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quyền mới. Ai đang được sửa cấu hình cửa hàng thì nhận luôn quyền xem +
-- sửa phương thức thanh toán — trước migration này, việc khai tài khoản
-- ngân hàng nằm trong chính trang Cài đặt mà họ đang sửa được.
INSERT IGNORE INTO user_permissions (tenant_id, user_id, permission, created_at, updated_at)
SELECT p.tenant_id, p.user_id, q.permission, NOW(3), NOW(3)
FROM user_permissions p
CROSS JOIN (SELECT 'phuong-thuc-tt.xem' AS permission UNION ALL SELECT 'phuong-thuc-tt.sua') q
WHERE p.permission = 'cau-hinh.sua';
