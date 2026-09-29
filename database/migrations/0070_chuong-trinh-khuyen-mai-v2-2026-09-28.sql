-- =====================================================================
--  0070_chuong-trinh-khuyen-mai-v2-2026-09-28.sql
--  Ngày: 28/09/2026
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy (cục bộ / thử / thật đều khác tên).
--
--  MySQL không cho DDL nằm trong transaction, nên tệp chạy dở là dở thật.
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  công cụ giữ vân tay và sẽ báo lệch. Cần thêm gì thì viết tệp mới.
-- =====================================================================
--
--  CHƯƠNG TRÌNH KHUYẾN MẠI — khuôn pmt_promotion_programs của bản v2.
--
--  Khác bảng `promotions` (giảm giá tự áp lên giá từng sản phẩm, chạy cả
--  website): chương trình ở đây do THU NGÂN CHỌN ở quầy (nút "Khuyến mãi"),
--  mỗi chương trình có nhiều BẬC, đơn đạt bậc nào cao nhất thì giảm theo bậc đó
--  và nhận hàng tặng của bậc đó.
--
--  promotion_programs.type
--      0 = Phiếu bán hàng — bậc theo TỔNG TIỀN HÀNG của đơn (total_apply)
--      2 = Nhóm hàng      — bậc theo số lượng của một danh mục (kèm con)
--      3 = Danh sách hàng — bậc theo số lượng của một sản phẩm
--      (1 "Số lượng khách hàng" của v2 không dùng: quầy shop không nhập số khách.)
--  approved: 0 Lưu tạm, 1 Đã duyệt. Quầy chỉ thấy chương trình đã duyệt + bật.
--  used: số đơn đã dùng chương trình — có đơn dùng rồi thì không xoá được.
--
--  promotion_program_details — một bậc: total_apply (type 0) hoặc object_id +
--      quantity (type 2/3); formality 0 = %, 1 = tiền; value; max_value (trần
--      của %, với "tiền" thì bằng value như form v2).
--  promotion_program_gifts   — hàng tặng của một bậc: biến thể + số lượng.
--
--  orders.promotion_discount / promotion_detail_ids — tổng tiền chương trình đã
--  giảm (đã nằm trong discount_amount) và các bậc đã áp, để báo cáo đọc lại.
--  order_items.promotion_detail_id — dòng hàng tặng thuộc bậc nào.
-- =====================================================================

CREATE TABLE IF NOT EXISTS promotion_programs (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  code          VARCHAR(50)  NOT NULL,
  name          VARCHAR(100) NOT NULL,
  description   VARCHAR(255) NOT NULL DEFAULT '',
  type          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  status        TINYINT(1) NOT NULL DEFAULT 1,
  approved      TINYINT(1) NOT NULL DEFAULT 0,
  no_time_limit TINYINT(1) NOT NULL DEFAULT 0,
  start_date    DATE NULL,
  end_date      DATE NULL,
  days_of_week  VARCHAR(20) NOT NULL DEFAULT '',
  all_shops     TINYINT(1) NOT NULL DEFAULT 1,
  used          INT UNSIGNED NOT NULL DEFAULT 0,
  created_at    DATETIME(3) NULL,
  updated_at    DATETIME(3) NULL,
  PRIMARY KEY (id),
  KEY idx_promotion_programs_tenant_code (tenant_id, code),
  CONSTRAINT fk_promotion_programs_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS promotion_program_shops (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  BIGINT UNSIGNED NOT NULL,
  program_id BIGINT UNSIGNED NOT NULL,
  shop_id    BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_promotion_program_shops (program_id, shop_id),
  CONSTRAINT fk_pp_shops_program FOREIGN KEY (program_id) REFERENCES promotion_programs (id) ON DELETE CASCADE,
  CONSTRAINT fk_pp_shops_shop FOREIGN KEY (shop_id) REFERENCES shops (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS promotion_program_details (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id   BIGINT UNSIGNED NOT NULL,
  program_id  BIGINT UNSIGNED NOT NULL,
  total_apply DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'type 0: tổng tiền hàng tối thiểu',
  object_id   BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'type 2: categories.id, type 3: products.id',
  quantity    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'type 2/3: số lượng tối thiểu',
  formality   TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 %, 1 tiền',
  value       DECIMAL(15,2) NOT NULL DEFAULT 0,
  max_value   DECIMAL(15,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_pp_details_program (program_id),
  CONSTRAINT fk_pp_details_program FOREIGN KEY (program_id) REFERENCES promotion_programs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS promotion_program_gifts (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id          BIGINT UNSIGNED NOT NULL,
  detail_id          BIGINT UNSIGNED NOT NULL,
  product_variant_id BIGINT UNSIGNED NOT NULL,
  quantity           INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_pp_gifts_detail (detail_id),
  CONSTRAINT fk_pp_gifts_detail FOREIGN KEY (detail_id) REFERENCES promotion_program_details (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE orders
    ADD COLUMN promotion_discount   DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER discount_amount,
    ADD COLUMN promotion_detail_ids VARCHAR(255)  NOT NULL DEFAULT '' AFTER promotion_discount;

ALTER TABLE order_items
    ADD COLUMN promotion_detail_id BIGINT UNSIGNED NULL AFTER fixed_price_detail_id;
