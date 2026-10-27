-- =====================================================================
--  0098_qua-sinh-nhat-2026-10-27.sql
--  Ngày: 27/10/2026
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy.
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  viết tệp mới.
-- =====================================================================
--
--  MỐC CHÚC SINH NHẬT của khách, bấm ở màn Khách hàng của quầy: "Tạo mã sinh
--  nhật" (voucher_id = mã vừa sinh) hoặc "Đã chúc" (voucher_id NULL). Có mốc
--  trong mùa sinh nhật này thì khách rời nút lọc "Sinh nhật 7 ngày" tới năm sau.
--
--  voucher_id cũng là luật "mã chỉ của khách này": lúc áp mã, voucher có dòng ở
--  đây thì đơn phải gắn đúng customer_id. Giữ ở bảng riêng thay vì thêm cột vào
--  vouchers — một năm một khách một dòng, đếm "đã tặng / đã dùng" bằng JOIN.
-- =====================================================================

CREATE TABLE IF NOT EXISTS customer_birthday_gifts (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id   BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL COMMENT 'users.id của khách',
  voucher_id  BIGINT UNSIGNED NULL COMMENT 'mã đã tặng; NULL = chỉ đánh dấu đã chúc',
  shop_id     BIGINT UNSIGNED NULL COMMENT 'chi nhánh nơi bấm',
  created_by  BIGINT UNSIGNED NULL COMMENT 'users.id thu ngân',
  created_at  DATETIME(3) NULL,

  PRIMARY KEY (id),
  KEY idx_birthday_gifts_customer (tenant_id, customer_id, created_at),
  UNIQUE KEY uq_birthday_gifts_voucher (voucher_id),
  CONSTRAINT fk_birthday_gifts_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_birthday_gifts_customer FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_birthday_gifts_voucher FOREIGN KEY (voucher_id) REFERENCES vouchers (id) ON DELETE SET NULL,
  CONSTRAINT fk_birthday_gifts_shop FOREIGN KEY (shop_id) REFERENCES shops (id) ON DELETE SET NULL,
  CONSTRAINT fk_birthday_gifts_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
