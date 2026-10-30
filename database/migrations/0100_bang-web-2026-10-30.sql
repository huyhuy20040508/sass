-- =====================================================================
--  0100_bang-web-2026-10-30.sql
--  Ngày: 30/10/2026
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy.
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  viết tệp mới.
-- =====================================================================
--
--  DỮ LIỆU RIÊNG CỦA WEB BÁN HÀNG (ecommerce_web) nằm ngay trong DB POS, như
--  bảng `shoptable` của kimguest: mẫu giao diện, khối trang chủ, cài đặt hiển
--  thị… Mọi bảng của web mang tiền tố `web_` và cột tenant_id.
--
--  Web đọc thẳng DB POS bằng một tài khoản MySQL riêng: SELECT toàn DB, còn
--  INSERT / UPDATE / DELETE CHỈ trên các bảng `web_` (MySQL không cấp quyền
--  theo tiền tố tên bảng — mỗi bảng web_ mới phải GRANT thêm, xem
--  ecommerce_web/backend/database/quyen-db.sql). Hàng, giá, tồn, khách, đơn
--  web không ghi được: đơn đi qua API POS.
--
--  web_cai_dat: kho khoá–giá trị JSON theo cửa hàng. `loai` gom nhóm (vd
--  giao_dien, hien_thi), `khoa` phân biệt trong nhóm.
-- =====================================================================

CREATE TABLE IF NOT EXISTS web_cai_dat (
  tenant_id  BIGINT UNSIGNED NOT NULL,
  loai       VARCHAR(40)     NOT NULL,
  khoa       VARCHAR(80)     NOT NULL DEFAULT '',
  du_lieu    JSON            NOT NULL,
  sua_boi    BIGINT UNSIGNED NULL     COMMENT 'users.id người sửa gần nhất',
  created_at DATETIME(3)     NULL,
  updated_at DATETIME(3)     NULL,

  PRIMARY KEY (tenant_id, loai, khoa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
