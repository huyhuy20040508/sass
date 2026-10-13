-- =====================================================================
--  0078_webhook-rieng-tung-cong-2026-10-12.sql
--  Ngày: 12/10/2026
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy.
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  viết tệp mới.
-- =====================================================================
--
--  URL WEBHOOK RIÊNG CHO TỪNG CỔNG QR — để chủ tiệm tự kết nối SePay/PayOS
--  như ordertable v2 (mpos/{store_id}/{code}/payment-webhook).
--
--  Webhook của cổng không mang token, không đi qua tên miền của tiệm, nên
--  URL phải tự nói "tiền này của ai". Mã là chuỗi ngẫu nhiên chứ không phải
--  id: id đoán được, và biết URL của tiệm khác là gửi được gói giả tới đó
--  (dù vẫn bị chữ ký / khoá chặn, không cần mở thêm cửa ấy).
--
--  NULL với tài khoản ngân hàng, máy quẹt thẻ và cổng chưa có webhook.
-- =====================================================================

ALTER TABLE payment_method_details
  ADD COLUMN webhook_code VARCHAR(40) NULL COMMENT 'mã ngẫu nhiên trong URL webhook riêng của cổng' AFTER device_id,
  ADD UNIQUE KEY uq_payment_method_details_webhook (webhook_code);
