-- =====================================================================
--  0072_the-thanh-vien-2026-09-28.sql
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
--  THẺ THÀNH VIÊN — khuôn pmt_setting_ranks / pmt_setting_point_converts /
--  3rd_customers.total_score, remaining_score của bản v2.
--
--  membership_ranks — hạng: đạt `point` điểm TÍCH LUỸ thì lên hạng, được giảm
--      `discount_value` (money = tiền, percent = %) trên tiền hàng của đơn ở
--      quầy; apply_all_order_values = 0 thì chỉ đơn trong [min, max] mới được.
--
--  point_conversions — MỘT dòng mỗi cửa hàng:
--      earn_money / earn_point   : "10.000đ = 100 điểm" (tích khi thanh toán)
--      redeem_point / redeem_money: "1 điểm = 100đ"     (đổi điểm ở quầy)
--
--  users.total_points — điểm tích luỹ TRỌN ĐỜI, quyết định hạng (không giảm khi
--      đổi điểm — như v2 web: đổi điểm không làm khách tụt hạng).
--  users.points       — điểm CÒN DÙNG được (đổi ra tiền thì trừ ở đây).
--  users.rank_id      — hạng hiện tại, tính lại khi điểm hay bảng hạng đổi.
--
--  point_histories — sổ điểm: earn (tích), redeem (đổi), revert (huỷ / trả hàng
--      hoàn lại). `points` có dấu; `balance` = users.points sau dòng này.
--
--  orders.rank_discount — tiền giảm theo hạng (đã nằm trong discount_amount).
--  orders.points_used / points_amount — điểm đổi và số tiền đổi được (cũng đã
--      nằm trong discount_amount).
--  orders.points_earned — điểm khách được cộng từ đơn này (0 = chưa cộng); trả
--      hàng thì trừ lại theo tỉ lệ tiền trả.
-- =====================================================================

CREATE TABLE IF NOT EXISTS membership_ranks (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id              BIGINT UNSIGNED NOT NULL,
  name                   VARCHAR(100) NOT NULL,
  point                  INT UNSIGNED NOT NULL,
  discount_type          ENUM('money','percent') NOT NULL DEFAULT 'money',
  discount_value         DECIMAL(12,2) NOT NULL DEFAULT 0,
  status                 TINYINT(1) NOT NULL DEFAULT 1,
  apply_all_order_values TINYINT(1) NOT NULL DEFAULT 1,
  min_order_value        DECIMAL(12,2) NULL,
  max_order_value        DECIMAL(12,2) NULL,
  created_at             DATETIME(3) NULL,
  updated_at             DATETIME(3) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_membership_ranks_point (tenant_id, point),
  CONSTRAINT fk_membership_ranks_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS point_conversions (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id      BIGINT UNSIGNED NOT NULL,
  earn_money     DECIMAL(12,2) NOT NULL DEFAULT 0,
  earn_point     INT UNSIGNED NOT NULL DEFAULT 0,
  earn_enabled   TINYINT(1) NOT NULL DEFAULT 0,
  redeem_point   INT UNSIGNED NOT NULL DEFAULT 0,
  redeem_money   DECIMAL(12,2) NOT NULL DEFAULT 0,
  redeem_enabled TINYINT(1) NOT NULL DEFAULT 0,
  created_at     DATETIME(3) NULL,
  updated_at     DATETIME(3) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_point_conversions_tenant (tenant_id),
  CONSTRAINT fk_point_conversions_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS point_histories (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  BIGINT UNSIGNED NOT NULL,
  user_id    BIGINT UNSIGNED NOT NULL,
  order_id   BIGINT UNSIGNED NULL,
  kind       ENUM('earn','redeem','revert') NOT NULL,
  points     INT NOT NULL,
  amount     DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'tiền của đơn (earn) hoặc tiền đổi được (redeem)',
  balance    INT NOT NULL,
  created_at DATETIME(3) NULL,
  PRIMARY KEY (id),
  KEY idx_point_histories_user (user_id),
  KEY idx_point_histories_order (order_id),
  CONSTRAINT fk_point_histories_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_point_histories_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
    ADD COLUMN total_points INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN points       INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN rank_id      BIGINT UNSIGNED NULL,
    ADD KEY idx_users_rank (rank_id),
    ADD CONSTRAINT fk_users_rank FOREIGN KEY (rank_id) REFERENCES membership_ranks (id) ON DELETE SET NULL;

ALTER TABLE orders
    ADD COLUMN rank_discount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER promotion_detail_ids,
    ADD COLUMN points_used   INT UNSIGNED NOT NULL DEFAULT 0 AFTER rank_discount,
    ADD COLUMN points_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER points_used,
    ADD COLUMN points_earned INT UNSIGNED NOT NULL DEFAULT 0 AFTER points_amount;
