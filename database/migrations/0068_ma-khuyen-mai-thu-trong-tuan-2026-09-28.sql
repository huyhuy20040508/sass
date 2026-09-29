-- =====================================================================
--  0068_ma-khuyen-mai-thu-trong-tuan-2026-09-28.sql
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
--  CRM → CHƯƠNG TRÌNH KHUYẾN MÃI (khuôn crm/promotion-program của bản v2)
--
--  1. `code` — MÃ CHƯƠNG TRÌNH, chỉ chữ và số, không trùng trong một cửa hàng.
--     Chương trình có sẵn được cấp mã KM + id (KM00012) để cột không rỗng.
--
--     Không đặt UNIQUE ở database: chương trình xoá MỀM (deleted_at) vẫn giữ
--     mã, UNIQUE sẽ chặn dùng lại mã của một chương trình đã xoá. Chống trùng
--     nằm ở tầng service, chỉ xét chương trình còn sống.
--
--  2. `days_of_week` — THỨ TRONG TUẦN chương trình chạy, chuỗi số ISO ngăn
--     bởi dấu phẩy: 1 = Thứ Hai … 7 = Chủ Nhật ("1,2,3,4,5" = ngày thường).
--     RỖNG = mọi ngày, nên mọi chương trình có sẵn giữ nguyên cách chạy.
-- =====================================================================

ALTER TABLE promotions
    ADD COLUMN code         VARCHAR(30) NOT NULL DEFAULT '' AFTER tenant_id,
    ADD COLUMN days_of_week VARCHAR(20) NOT NULL DEFAULT '' AFTER end_at;

UPDATE promotions SET code = CONCAT('KM', LPAD(id, 5, '0')) WHERE code = '';

CREATE INDEX idx_promotions_code ON promotions (tenant_id, code);
