-- =====================================================================
--  0071_voucher-coupon-2026-09-28.sql
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
--  CHƯƠNG TRÌNH VOUCHER / COUPON — khuôn pmt_voucher_coupon của bản v2.
--
--  Một chương trình phát ra NHIỀU MÃ (tiền tố + 5 ký tự ngẫu nhiên + hậu tố),
--  mỗi mã là MỘT DÒNG của bảng `vouchers` sẵn có. Nhờ vậy quầy và website dùng
--  mã đúng như mọi mã giảm giá khác (ô "Mã giảm giá", hạn mức lượt, chi nhánh,
--  lịch sử voucher_usages) — không đẻ thêm một đường tính tiền thứ hai.
--
--  voucher_programs.status
--      1 = Chưa phát hành — còn sửa / xoá được, CHƯA có mã nào.
--      2 = Phát hành      — đã sinh đủ `quantity` mã; chỉ xem, bật / tắt từng mã.
--  discount_type 'percentage' (Coupon, %) | 'fixed' (Voucher, tiền) — cùng từ
--  với vouchers.discount_type để chép thẳng xuống từng mã.
--  usage_limit: số lần MỖI MÃ được dùng ("Số lần áp dụng" của v2).
--  all_categories = 0 thì chỉ phần tiền hàng thuộc các danh mục ở
--  voucher_program_categories được giảm (đơn tối thiểu vẫn tính trên cả đơn).
--
--  vouchers.program_id  — mã thuộc chương trình nào; NULL = mã lẻ tạo ở màn
--                          Mã giảm giá như trước.
--  vouchers.category_ids — "3,7,12" chép từ chương trình lúc phát hành; RỖNG =
--                          mọi danh mục. Chép xuống mã để lượt áp mã ở quầy
--                          không phải đọc thêm bảng chương trình giữa giao dịch.
-- =====================================================================

CREATE TABLE IF NOT EXISTS voucher_programs (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id           BIGINT UNSIGNED NOT NULL,
  code                VARCHAR(50)  NOT NULL,
  name                VARCHAR(255) NOT NULL,
  description         VARCHAR(255) NOT NULL DEFAULT '',
  discount_type       ENUM('percentage','fixed') NOT NULL,
  discount_value      DECIMAL(12,2) NOT NULL,
  max_discount_amount DECIMAL(12,2) NULL COMMENT 'trần giảm khi discount_type=percentage',
  min_order_amount    DECIMAL(12,2) NOT NULL DEFAULT 0,
  all_shops           TINYINT(1) NOT NULL DEFAULT 1,
  all_categories      TINYINT(1) NOT NULL DEFAULT 1,
  no_time_limit       TINYINT(1) NOT NULL DEFAULT 0,
  start_date          DATE NULL,
  end_date            DATE NULL,
  prefix              VARCHAR(20) NOT NULL DEFAULT '',
  suffix              VARCHAR(20) NOT NULL DEFAULT '',
  quantity            INT UNSIGNED NOT NULL,
  usage_limit         INT UNSIGNED NOT NULL DEFAULT 1,
  status              TINYINT UNSIGNED NOT NULL DEFAULT 1,
  created_by          BIGINT UNSIGNED NULL,
  released_at         DATETIME(3) NULL,
  created_at          DATETIME(3) NULL,
  updated_at          DATETIME(3) NULL,
  PRIMARY KEY (id),
  KEY idx_voucher_programs_tenant_code (tenant_id, code),
  CONSTRAINT fk_voucher_programs_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS voucher_program_shops (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  BIGINT UNSIGNED NOT NULL,
  program_id BIGINT UNSIGNED NOT NULL,
  shop_id    BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_voucher_program_shops (program_id, shop_id),
  CONSTRAINT fk_vp_shops_program FOREIGN KEY (program_id) REFERENCES voucher_programs (id) ON DELETE CASCADE,
  CONSTRAINT fk_vp_shops_shop FOREIGN KEY (shop_id) REFERENCES shops (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS voucher_program_categories (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id   BIGINT UNSIGNED NOT NULL,
  program_id  BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_voucher_program_categories (program_id, category_id),
  CONSTRAINT fk_vp_categories_program FOREIGN KEY (program_id) REFERENCES voucher_programs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE vouchers
    ADD COLUMN program_id   BIGINT UNSIGNED NULL AFTER tenant_id,
    ADD COLUMN category_ids VARCHAR(500) NOT NULL DEFAULT '' AFTER min_order_amount,
    ADD KEY idx_vouchers_program (program_id),
    ADD CONSTRAINT fk_vouchers_program FOREIGN KEY (program_id) REFERENCES voucher_programs (id) ON DELETE SET NULL;
