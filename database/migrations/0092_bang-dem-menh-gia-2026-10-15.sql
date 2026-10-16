-- =====================================================================
--  0092_bang-dem-menh-gia-2026-10-15.sql
--  Ngày: 15/10/2026
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy.
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  viết tệp mới.
-- =====================================================================
--
--  BẢNG ĐẾM THEO MỆNH GIÁ của ca — lúc mở (két đầu ca) và lúc đóng (tiền đếm
--  được). Trước đây hộp đếm chỉ cộng ra tổng rồi bỏ; lệch két thì không dò lại
--  được nhầm ở tờ nào (hay gặp: 500k ↔ 50k).
--
--  Mảng JSON [{"menh_gia":500000,"so_to":3}, …], mệnh giá giảm dần, bỏ dòng 0
--  tờ. Mảng chứ không phải object: MySQL sắp lại khoá của object JSON, mất thứ
--  tự mệnh giá. Tổng các dòng luôn bằng opening_cash / counted_cash — API chặn
--  lúc ghi. NULL = không đếm theo tờ (gõ thẳng tổng) hoặc ca trước migration.
-- =====================================================================

ALTER TABLE work_shifts
  ADD COLUMN opening_count JSON NULL COMMENT 'bảng đếm mệnh giá lúc mở ca' AFTER opening_cash,
  ADD COLUMN counted_count JSON NULL COMMENT 'bảng đếm mệnh giá lúc đóng ca' AFTER counted_cash;
