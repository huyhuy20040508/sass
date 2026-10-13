-- =====================================================================
--  0080_don-ghi-chi-tiet-thanh-toan-2026-10-12.sql
--  Ngày: 12/10/2026
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy.
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  viết tệp mới.
-- =====================================================================
--
--  ĐƠN QUẦY GHI LẠI THU TIỀN BẰNG GÌ — như odr_orders.payment_method_detail_id
--  và odr_orders.card_fee của ordertable v2.
--
--  payment_method_detail_id: tài khoản nhận chuyển khoản / máy quẹt thẻ / cổng
--  QR đã dùng. Cũng là thứ chặn xoá một dòng chi tiết đã có đơn (v2:
--  cannot_delete_payment_method_detail_used) — xoá đi thì đơn cũ không còn
--  biết tiền đã vào tài khoản nào.
--
--  card_fee: phí quẹt thẻ CỬA HÀNG chịu (không cộng vào tiền khách trả), tính
--  theo % đã khai cho máy — để đối soát với sao kê ngân hàng.
-- =====================================================================

ALTER TABLE orders
  ADD COLUMN payment_method_detail_id BIGINT UNSIGNED NULL COMMENT 'payment_method_details.id đã dùng để thu' AFTER payment_method,
  ADD COLUMN card_fee DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'phí quẹt thẻ cửa hàng chịu' AFTER payment_method_detail_id,
  ADD KEY idx_orders_payment_detail (payment_method_detail_id);
