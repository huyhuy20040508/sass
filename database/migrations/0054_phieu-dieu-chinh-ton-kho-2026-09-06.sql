-- =====================================================================
--  0054_phieu-dieu-chinh-ton-kho-2026-09-06.sql
--  Ngày: 06/09/2026
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
--  PHIẾU ĐIỀU CHỈNH TỒN KHO — nắn lại số tồn của MỘT kho theo chứng từ.
--
--  Vòng đời bốn nước, đúng như war_warehouse_adjusts của v2:
--
--      lưu tạm ──gửi duyệt──> chờ duyệt ──duyệt──> đã duyệt (kho đổi số)
--                                       └──từ chối──> từ chối
--
--  Hai loại phiếu: `adjust` (điều chỉnh thường, người dùng gõ số lệch từng
--  dòng) và `balance` (cân đối hàng âm: đưa lô đang âm về 0, sinh từ nút
--  "Cân đối hàng âm").
--
--  KHÁC v2 ba chỗ, cùng lý do đã ghi ở 0043 và 0050:
--
--   1. Phiếu ĐÃ DUYỆT khoá lại — không sửa, không xoá. v2 cho gọi update
--      với status=2 lần nữa là kho cộng thêm một lần nữa.
--   2. Số lượng là số NGUYÊN, cùng đơn vị với `variant_stocks.quantity`.
--   3. Duyệt là MỘT bước: duyệt xong kho đổi ngay. v2 có công tắc "2 bước"
--      (duyệt phiếu rồi duyệt thẻ kho) nhưng đã ẩn khỏi giao diện, và hai
--      ma trận trạng thái của nó viết ngược nhau — không port.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS stock_adjustments (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,

  adjustment_code VARCHAR(30) NOT NULL COMMENT 'ma phieu: DCT20260906001 hoac do quy tac danh so sinh',

  -- Kho bị nắn số. Chốt lúc lập phiếu, lượt duyệt ghi kho theo con số này.
  shop_id BIGINT UNSIGNED NOT NULL,

  type   VARCHAR(20) NOT NULL DEFAULT 'adjust' COMMENT 'adjust | balance',
  status VARCHAR(20) NOT NULL DEFAULT 'draft'  COMMENT 'draft | pending | approved | rejected',

  note          VARCHAR(500) NOT NULL DEFAULT '',
  reject_reason VARCHAR(500) NOT NULL DEFAULT '',

  created_by BIGINT UNSIGNED NULL,
  handled_by BIGINT UNSIGNED NULL COMMENT 'nguoi duyet / tu choi',

  approved_at DATETIME(3) NULL COMMENT 'moc kho doi so',

  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,
  deleted_at DATETIME(3) NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_sa_code (tenant_id, adjustment_code),
  KEY idx_sa_shop (tenant_id, shop_id, status),
  KEY idx_sa_created (tenant_id, created_at),
  KEY idx_sa_deleted (deleted_at),
  CONSTRAINT fk_sa_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_sa_shop   FOREIGN KEY (shop_id)   REFERENCES shops (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Dòng hàng.
--
--  `quantity` là tồn CỦA LÔ ĐÓ lúc lập phiếu (bản chụp, để in "tồn trước /
--  tồn sau"), `adjust_quantity` là số lệch có dấu: dương = cộng vào kho,
--  âm = bớt đi. Lượt duyệt chỉ dùng `adjust_quantity`.
--
--  `lot_number` rỗng = lô "Không xác định" (cùng quy ước với stock_lots).
--  Cụm product_name / variant_sku / variant_name / unit_name là bản CHỤP.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS stock_adjustment_items (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,

  stock_adjustment_id BIGINT UNSIGNED NOT NULL,

  product_id         BIGINT UNSIGNED NULL,
  product_variant_id BIGINT UNSIGNED NULL,

  product_name VARCHAR(255) NOT NULL DEFAULT '',
  variant_sku  VARCHAR(100) NOT NULL DEFAULT '',
  variant_name VARCHAR(255) NOT NULL DEFAULT '',
  unit_name    VARCHAR(50)  NOT NULL DEFAULT '',

  lot_number  VARCHAR(50) NOT NULL DEFAULT '' COMMENT 'rong = lo khong xac dinh',
  expire_date DATE NULL,

  quantity        INT NOT NULL DEFAULT 0 COMMENT 'ton cua lo luc lap phieu',
  adjust_quantity INT NOT NULL DEFAULT 0 COMMENT 'so lech co dau: duong = cong, am = bot',
  unit_cost       DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'gia von mot don vi, chup luc lap phieu',

  attachment VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'duong dan anh chung tu cua dong',

  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,

  PRIMARY KEY (id),
  KEY idx_sai_adjust (stock_adjustment_id),
  KEY idx_sai_variant (product_variant_id),
  CONSTRAINT fk_sai_tenant FOREIGN KEY (tenant_id)           REFERENCES tenants (id),
  CONSTRAINT fk_sai_adjust FOREIGN KEY (stock_adjustment_id) REFERENCES stock_adjustments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Lịch sử thao tác. Tên bảng số ít, cùng quy ước với stock_transfer_history.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS stock_adjustment_history (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,

  stock_adjustment_id BIGINT UNSIGNED NOT NULL,
  from_status VARCHAR(20)  NOT NULL DEFAULT '',
  to_status   VARCHAR(20)  NOT NULL DEFAULT '',
  note        VARCHAR(500) NOT NULL DEFAULT '',
  changed_by  BIGINT UNSIGNED NULL,

  created_at DATETIME(3) NULL,

  PRIMARY KEY (id),
  KEY idx_sah_adjust (stock_adjustment_id),
  CONSTRAINT fk_sah_tenant FOREIGN KEY (tenant_id)           REFERENCES tenants (id),
  CONSTRAINT fk_sah_adjust FOREIGN KEY (stock_adjustment_id) REFERENCES stock_adjustments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Quyền và quy tắc đánh số KHÔNG khai ở đây — cả hai nằm trong code Go
--  (`domain.DanhMucQuyen`, `domain.DanhMucLoaiMa`). Chưa bật quy tắc thì
--  mã chạy theo dải sẵn có DCT + ngày + số thứ tự.
-- ---------------------------------------------------------------------
