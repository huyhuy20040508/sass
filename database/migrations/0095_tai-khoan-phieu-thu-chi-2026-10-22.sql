-- =====================================================================
--  0095_tai-khoan-phieu-thu-chi-2026-10-22.sql
--  Ngày: 22/10/2026
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy.
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  viết tệp mới.
-- =====================================================================
--
--  TÀI KHOẢN của phiếu thu chi CHUYỂN KHOẢN: tiền vào / ra tài khoản nào trong
--  Cài đặt → Phương thức thanh toán (payment_method_details loại bank_transfer
--  của chi nhánh). Không có thì đối sao kê không ghép được phiếu với tài khoản.
--  NULL = phiếu tiền mặt, phiếu cũ, hoặc chi nhánh chưa khai tài khoản nào.
--
--  ON DELETE SET NULL: API đã chặn xoá tài khoản có phiếu dùng; khai để khoá
--  ngoại không chặn dọn dữ liệu.
-- =====================================================================

ALTER TABLE income_expenses
  ADD COLUMN bank_account_id BIGINT UNSIGNED NULL COMMENT 'payment_method_details.id — tài khoản nhận / chuyển của phiếu chuyển khoản' AFTER payment_method,
  ADD KEY idx_income_expenses_bank_account (bank_account_id),
  ADD CONSTRAINT fk_income_expenses_bank_account FOREIGN KEY (bank_account_id) REFERENCES payment_method_details (id) ON DELETE SET NULL;
