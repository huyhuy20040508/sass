-- =====================================================================
--  0088_nguoi-truc-ket-2026-10-14.sql
--  Ngày: 14/10/2026
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy.
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  viết tệp mới.
-- =====================================================================
--
--  NGƯỜI TRỰC KÉT tách khỏi NGƯỜI MỞ MÁY.
--
--  opened_by vẫn là tài khoản đã bấm Mở ca (ai chịu trách nhiệm thao tác).
--  employee_id là nhân sự nhận két trong ca — có thể là người khác (quản lý mở
--  máy hộ đầu giờ). NULL = ca cũ, hoặc người bấm chưa có hồ sơ nhân sự: phiếu
--  kết ca lùi về tên người mở.
--
--  ON DELETE SET NULL: xoá hồ sơ nhân sự không được xoá theo lịch sử ca.
-- =====================================================================

ALTER TABLE work_shifts
  ADD COLUMN employee_id BIGINT UNSIGNED NULL COMMENT 'employees.id — người trực két trong ca' AFTER opened_by,
  ADD KEY idx_work_shifts_employee (employee_id),
  ADD CONSTRAINT fk_work_shifts_employee FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE SET NULL;
