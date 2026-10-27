-- =====================================================================
--  0094_phieu-dieu-chinh-2026-10-22.sql
--  Ngày: 22/10/2026
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy.
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  viết tệp mới.
-- =====================================================================
--
--  PHIẾU ĐIỀU CHỈNH. Phiếu của ca đã đóng bị khoá (ca đã chốt số); gõ sai thì
--  lập một phiếu MỚI vào ca đang mở — vế đảo / cùng vế, số chênh — và trỏ
--  adjusts_id về phiếu gốc. Ca cũ giữ nguyên mọi con số, tiền thật đổi hôm nay
--  thì ghi vào hôm nay; hai phiếu nối nhau để đối soát.
--
--  ON DELETE SET NULL: phiếu gốc chỉ xoá mềm nên thực tế không chạm tới; khai
--  để khoá ngoại không chặn dọn dữ liệu.
-- =====================================================================

ALTER TABLE income_expenses
  ADD COLUMN adjusts_id BIGINT UNSIGNED NULL COMMENT 'income_expenses.id — phiếu gốc mà phiếu này điều chỉnh' AFTER source_id,
  ADD KEY idx_income_expenses_adjusts (adjusts_id),
  ADD CONSTRAINT fk_income_expenses_adjusts FOREIGN KEY (adjusts_id) REFERENCES income_expenses (id) ON DELETE SET NULL;
