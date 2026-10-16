-- =====================================================================
--  0087_hoan-tien-mat-2026-10-14.sql
--  Ngày: 14/10/2026
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy.
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  viết tệp mới.
-- =====================================================================
--
--  HOÀN TIỀN TRẢ HÀNG BẰNG TIỀN MẶT / VÍ (KB #74).
--
--  Lượt hoàn ghi một dòng payments với provider = cách tiền thật sự ra khỏi
--  cửa hàng. ENUM chưa có 'cash' và 'ewallet' nên hoàn tiền mặt (cảnh hay gặp
--  nhất ở quầy) bị MySQL từ chối, cả lượt hoàn rollback và báo lỗi 500.
--
--  MODIFY chỉ thêm vào CUỐI danh sách: ENUM lưu theo chỉ số (xem 0006).
-- =====================================================================

ALTER TABLE payments
  MODIFY COLUMN provider
    ENUM('cod','vnpay','momo','bank_transfer','payos','sepay','mpos_qr','card','cash','ewallet')
    NOT NULL;
