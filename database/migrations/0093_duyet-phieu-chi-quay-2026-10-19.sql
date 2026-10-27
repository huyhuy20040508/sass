-- =====================================================================
--  0093_duyet-phieu-chi-quay-2026-10-19.sql
--  Ngày: 19/10/2026
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy.
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  viết tệp mới.
-- =====================================================================
--
--  NGƯỜI DUYỆT PHIẾU CHI Ở QUẦY. Phiếu chi lập / sửa ở quầy vượt "Hạn mức phiếu
--  chi ở quầy" (Cài đặt → Quầy bán hàng) phải có chủ hoặc quản trị viên nhập tài
--  khoản để duyệt; chủ / quản trị tự lập thì ghi chính họ. NULL = không cần duyệt
--  (dưới hạn mức, phiếu thu, phiếu lập ở màn quản trị, phiếu trước migration).
--
--  ON DELETE SET NULL: xoá tài khoản người duyệt không được xoá theo phiếu.
-- =====================================================================

ALTER TABLE income_expenses
  ADD COLUMN approved_by BIGINT UNSIGNED NULL COMMENT 'users.id — người duyệt phiếu chi vượt hạn mức ở quầy' AFTER created_by,
  ADD COLUMN approved_at DATETIME(3) NULL COMMENT 'lúc duyệt' AFTER approved_by,
  ADD CONSTRAINT fk_income_expenses_approved_by FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL;
