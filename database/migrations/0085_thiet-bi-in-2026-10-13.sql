-- =====================================================================
--  0085_thiet-bi-in-2026-10-13.sql
--  Ngày: 13/10/2026
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy.
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  viết tệp mới.
-- =====================================================================
--
--  CÀI ĐẶT THIẾT BỊ (Cài đặt → Thông số chung), theo system/parameter/
--  setup-store của ordertable v2. Mọi bảng theo TỪNG chi nhánh.
--
--  printers        — máy in của chi nhánh.
--  printer_routes  — loại phiếu nào in ra máy nào (tem: theo nhóm hàng).
--  print_buttons   — bấm nút nào ở quầy thì in những phiếu gì. Chưa có dòng
--                    = mặc định khai trong domain.DanhMucNutIn.
--  print_templates — mẫu in đã chỉnh. Chưa có dòng = mặc định của
--                    domain.DanhMucMauIn; "về mặc định" là xoá dòng.
-- =====================================================================

CREATE TABLE IF NOT EXISTS printers (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  shop_id       BIGINT UNSIGNED NOT NULL,
  name          VARCHAR(100) NOT NULL,
  conn_type     VARCHAR(10)  NOT NULL COMMENT 'lan | usb | bt | urovo',
  -- IP với máy LAN, MAC với máy Bluetooth, rỗng với USB / Urovo. Trùng địa
  -- chỉ kiểm ở service (rỗng thì không tính là trùng — khoá duy nhất làm hỏng).
  address       VARCHAR(50)  NOT NULL DEFAULT '',
  paper         VARCHAR(4)   NOT NULL DEFAULT '80' COMMENT '58 | 80 | a5 | a4',
  dpi           SMALLINT UNSIGNED NOT NULL DEFAULT 203,
  printed_count INT UNSIGNED NOT NULL DEFAULT 0,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME(3) NULL,
  updated_at    DATETIME(3) NULL,

  PRIMARY KEY (id),
  KEY idx_printers_shop (tenant_id, shop_id),
  CONSTRAINT fk_printers_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_printers_shop FOREIGN KEY (shop_id) REFERENCES shops (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS printer_routes (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id      BIGINT UNSIGNED NOT NULL,
  shop_id        BIGINT UNSIGNED NOT NULL,
  printer_id     BIGINT UNSIGNED NOT NULL,
  slip_type      VARCHAR(20) NOT NULL COMMENT 'hoa-don | tem | ket-ca | tra-hang | hddt',
  -- Tem in theo nhóm hàng gốc. all_categories = mọi nhóm, kể cả nhóm thêm sau
  -- này (bản cũ bung "Tất cả" ra danh sách id nên nhóm mới không máy nào in).
  all_categories TINYINT(1) NOT NULL DEFAULT 0,
  category_ids   JSON NULL,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  created_at     DATETIME(3) NULL,
  updated_at     DATETIME(3) NULL,

  PRIMARY KEY (id),
  KEY idx_printer_routes_shop (tenant_id, shop_id),
  CONSTRAINT fk_printer_routes_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  -- KHÔNG cascade: máy còn được gán phiếu thì không xoá được (service báo câu
  -- dễ hiểu trước, khoá ngoại là chốt cuối).
  CONSTRAINT fk_printer_routes_printer FOREIGN KEY (printer_id) REFERENCES printers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS print_buttons (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  BIGINT UNSIGNED NOT NULL,
  shop_id    BIGINT UNSIGNED NOT NULL,
  button     VARCHAR(20) NOT NULL COMMENT 'thanh-toan | tam-tinh',
  slips      JSON NOT NULL COMMENT 'mảng mã phiếu',
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_print_buttons (tenant_id, shop_id, button),
  CONSTRAINT fk_print_buttons_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_print_buttons_shop FOREIGN KEY (shop_id) REFERENCES shops (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS print_templates (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  BIGINT UNSIGNED NOT NULL,
  shop_id    BIGINT UNSIGNED NOT NULL,
  kind       VARCHAR(10) NOT NULL COMMENT 'hd | a5 | a4 | tem | tra | ca',
  options    JSON NOT NULL COMMENT '{mã dòng: bật/tắt}',
  settings   JSON NOT NULL COMMENT 'khổ, số bản, chân trang…',
  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_print_templates (tenant_id, shop_id, kind),
  CONSTRAINT fk_print_templates_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_print_templates_shop FOREIGN KEY (shop_id) REFERENCES shops (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quyền mới. Ai đang được sửa cấu hình cửa hàng thì nhận luôn quyền xem +
-- sửa thiết bị — như 0077 làm với Phương thức thanh toán.
INSERT IGNORE INTO user_permissions (tenant_id, user_id, permission, created_at, updated_at)
SELECT p.tenant_id, p.user_id, q.permission, NOW(3), NOW(3)
FROM user_permissions p
CROSS JOIN (SELECT 'thiet-bi.xem' AS permission UNION ALL SELECT 'thiet-bi.sua') q
WHERE p.permission = 'cau-hinh.sua';
