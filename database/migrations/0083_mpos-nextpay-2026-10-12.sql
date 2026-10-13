-- =====================================================================
--  0083_mpos-nextpay-2026-10-12.sql
--  Ngày: 12/10/2026
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy.
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  viết tệp mới.
-- =====================================================================
--
--  NỐI NEXTPAY mPOS ở quầy (QR mPOS + đẩy số tiền xuống máy quẹt thẻ).
--
--  orders.payment_method thêm 'mpos_qr' — đơn trả bằng QR của NextPay.
--  payments.provider thêm 'mpos_qr' và 'card': lượt chờ khách quét QR mPOS
--  / quẹt thẻ trên máy mPOS cũng là một lượt thử thanh toán — có dòng ở đây
--  thì huỷ được (chốt trước khi tiền tới muộn) và webhook tìm ra đúng đơn.
--
--  MODIFY chỉ thêm vào CUỐI danh sách: ENUM lưu theo chỉ số (xem 0006).
-- =====================================================================

ALTER TABLE orders
  MODIFY COLUMN payment_method
    ENUM('cod','vnpay','momo','bank_transfer','payos','sepay','cash','card','mpos_qr')
    NOT NULL DEFAULT 'cod';

ALTER TABLE payments
  MODIFY COLUMN provider
    ENUM('cod','vnpay','momo','bank_transfer','payos','sepay','mpos_qr','card')
    NOT NULL;
