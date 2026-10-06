-- =====================================================================
--  0074_chup-hoa-hong-vao-don-2026-10-05.sql
--  Ngày: 05/10/2026
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy.
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  viết tệp mới.
-- =====================================================================
--
--  Báo cáo hoa hồng trước đây đọc tỉ lệ HIỆN TẠI trên hồ sơ nhân sự: sửa tỉ
--  lệ là hoa hồng của các kỳ đã trả lương đổi theo, xoá hồ sơ là hoa hồng về
--  0. Nay chụp hồ sơ của NGƯỜI LẬP vào đơn lúc tạo đơn:
--
--  staff_code / staff_name / staff_commission_rate — NULL = lúc lập đơn người
--      đó không có hồ sơ nhân sự (khách tự đặt, tài khoản quản trị không hồ
--      sơ), báo cáo lùi về hồ sơ hiện tại như cũ.
--
--  Đơn cũ: chụp theo hồ sơ ĐANG CÓ lúc chạy tệp này — mốc đúng nhất còn lại.
--  Hồ sơ đã xoá trước đó thì không tra ngược được, để NULL.
-- =====================================================================

ALTER TABLE orders
    ADD COLUMN staff_code VARCHAR(30) NULL COMMENT 'mã nhân sự người lập, chụp lúc tạo đơn' AFTER created_by,
    ADD COLUMN staff_name VARCHAR(150) NULL COMMENT 'tên nhân sự người lập, chụp lúc tạo đơn' AFTER staff_code,
    ADD COLUMN staff_commission_rate DECIMAL(5,2) NULL COMMENT 'tỉ lệ hoa hồng % của người lập, chụp lúc tạo đơn' AFTER staff_name;

UPDATE orders o
JOIN employees e ON e.user_id = o.created_by AND e.tenant_id = o.tenant_id AND e.deleted_at IS NULL
SET o.staff_code = e.code,
    o.staff_name = e.full_name,
    o.staff_commission_rate = e.commission_rate
WHERE o.staff_commission_rate IS NULL;
