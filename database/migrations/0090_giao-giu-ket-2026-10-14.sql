-- =====================================================================
--  0090_giao-giu-ket-2026-10-14.sql
--  Ngày: 14/10/2026
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy.
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  viết tệp mới.
-- =====================================================================
--
--  BÀN GIAO KÉT lúc đóng ca: tiền đếm được = giao về chủ + giữ lại cho ca sau
--  (ordertable v2 có end_shift_cash / cash_delivery). Số giữ lại là gợi ý tiền
--  đầu ca cho ca kế tiếp — ca sau vẫn tự đếm, không lấy số này thay cho đếm.
--
--  NULL = ca đóng trước tệp này (không biết đã giao bao nhiêu): màn hình lùi
--  về counted_cash như trước.
-- =====================================================================

ALTER TABLE work_shifts
  ADD COLUMN delivered_cash DECIMAL(15,2) NULL COMMENT 'giao về chủ lúc đóng ca' AFTER counted_cash,
  ADD COLUMN kept_cash      DECIMAL(15,2) NULL COMMENT 'giữ lại trong két cho ca sau' AFTER delivered_cash;
