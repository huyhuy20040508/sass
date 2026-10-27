-- =====================================================================
--  0096_nop-ve-cong-ty-2026-10-22.sql
--  Ngày: 22/10/2026
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy.
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  viết tệp mới.
-- =====================================================================
--
--  NỘP TIỀN VỀ CÔNG TY giữa ca. Ghi như phiếu chi tiền mặt (két giảm, có dòng
--  sổ quỹ của ca) nhưng là một lần CHUYỂN KÉT chứ không phải chi phí: báo cáo
--  không cộng vào "Tổng chi". kind = 'deposit' đánh dấu loại phiếu này; '' =
--  phiếu thu / chi thường. Vẫn là phiếu lập tay (source manual) nên sửa / xoá,
--  khoá theo ca, nhật ký… đi đúng đường cũ.
-- =====================================================================

ALTER TABLE income_expenses
  ADD COLUMN kind VARCHAR(20) NOT NULL DEFAULT '' COMMENT '\'\' = thu / chi thường; deposit = nộp tiền về công ty' AFTER source,
  ADD KEY idx_income_expenses_kind (shift_id, kind);
