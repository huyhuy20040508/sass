-- =====================================================================
--  0069_khuyen-mai-dong-gia-2026-09-28.sql
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
--  KHUYẾN MẠI ĐỒNG GIÁ — khuôn pmt_fixed_prices của bản v2 (ordertable).
--
--  "Giỏ có từ Q cái của nhóm hàng / sản phẩm X thì MỌI cái của X bán đúng giá
--  P, kèm hàng tặng." Thu ngân chọn chương trình ở quầy (nút "Đồng giá"), máy
--  chủ tính lại giá lúc chốt đơn.
--
--  fixed_prices          — đầu chương trình.
--      type             1 = theo NHÓM HÀNG (danh mục, kèm danh mục con)
--                       2 = theo SẢN PHẨM
--      approved         0 = Lưu tạm, 1 = Đã duyệt. Quầy chỉ thấy chương trình
--                       đã duyệt VÀ đang bật (status).
--      no_time_limit    1 = không giới hạn thời gian, start/end_date bỏ trống.
--      days_of_week     "1,2,…" theo ISO (1 = Thứ Hai … 7 = CN), RỖNG = mọi ngày.
--      all_shops        1 = toàn hệ thống; 0 = chỉ các chi nhánh trong
--                       fixed_price_shops.
--  fixed_price_details   — từng dòng: object_id (danh mục hoặc sản phẩm tuỳ
--                          type), quantity (SL tối thiểu), price (giá đồng giá).
--  fixed_price_gifts     — hàng tặng của một dòng: biến thể + số lượng.
--
--  order_items.is_gift / fixed_price_detail_id — dòng đơn là hàng tặng, hay
--  được tính giá theo dòng đồng giá nào. Giá đồng giá nằm thẳng ở unit_price
--  (không phải một khoản giảm), vì trả hàng và đổi hàng hoàn tiền theo
--  unit_price × số lượng.
--
--  Xoá chương trình là xoá HẲN (như v2), dòng và hàng tặng đi theo khoá ngoại.
-- =====================================================================

CREATE TABLE IF NOT EXISTS fixed_prices (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  code          VARCHAR(50)  NOT NULL,
  name          VARCHAR(255) NOT NULL,
  description   VARCHAR(255) NOT NULL DEFAULT '',
  type          TINYINT UNSIGNED NOT NULL DEFAULT 2 COMMENT '1 nhóm hàng | 2 sản phẩm',
  status        TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'bật / tắt',
  approved      TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0 lưu tạm | 1 đã duyệt',
  no_time_limit TINYINT(1) NOT NULL DEFAULT 0,
  start_date    DATE NULL,
  end_date      DATE NULL,
  days_of_week  VARCHAR(20) NOT NULL DEFAULT '',
  all_shops     TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME(3) NULL,
  updated_at    DATETIME(3) NULL,
  PRIMARY KEY (id),
  KEY idx_fixed_prices_tenant_code (tenant_id, code),
  CONSTRAINT fk_fixed_prices_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fixed_price_shops (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id      BIGINT UNSIGNED NOT NULL,
  fixed_price_id BIGINT UNSIGNED NOT NULL,
  shop_id        BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fixed_price_shops (fixed_price_id, shop_id),
  CONSTRAINT fk_fixed_price_shops_fp FOREIGN KEY (fixed_price_id) REFERENCES fixed_prices (id) ON DELETE CASCADE,
  CONSTRAINT fk_fixed_price_shops_shop FOREIGN KEY (shop_id) REFERENCES shops (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fixed_price_details (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id      BIGINT UNSIGNED NOT NULL,
  fixed_price_id BIGINT UNSIGNED NOT NULL,
  object_id      BIGINT UNSIGNED NOT NULL COMMENT 'categories.id hoặc products.id theo type',
  quantity       INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'số lượng tối thiểu',
  price          DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'giá đồng giá mỗi đơn vị',
  PRIMARY KEY (id),
  KEY idx_fixed_price_details_fp (fixed_price_id),
  CONSTRAINT fk_fixed_price_details_fp FOREIGN KEY (fixed_price_id) REFERENCES fixed_prices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fixed_price_gifts (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id             BIGINT UNSIGNED NOT NULL,
  fixed_price_detail_id BIGINT UNSIGNED NOT NULL,
  product_variant_id    BIGINT UNSIGNED NOT NULL,
  quantity              INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_fixed_price_gifts_detail (fixed_price_detail_id),
  CONSTRAINT fk_fixed_price_gifts_detail FOREIGN KEY (fixed_price_detail_id) REFERENCES fixed_price_details (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE order_items
    ADD COLUMN is_gift               TINYINT(1)      NOT NULL DEFAULT 0 AFTER total_price,
    ADD COLUMN fixed_price_detail_id BIGINT UNSIGNED NULL AFTER is_gift;
