-- =====================================================================
--  0086_don-vi-tinh-tren-don-2026-10-14.sql
--  Ngày: 14/10/2026
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy.
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  viết tệp mới.
-- =====================================================================
--
--  ĐƠN VỊ TÍNH IN TRÊN PHIẾU ("2 Cái", "1 Sợi").
--
--  Mẫu in có dòng "Đơn vị tính" nhưng dòng đơn hàng chưa có chỗ ghi. Lưu TÊN
--  (không phải unit_id) lúc lập đơn, như product_name / variant_name: đổi tên
--  hay đổi ĐVT của mặt hàng sau này thì phiếu in lại của đơn cũ vẫn đúng như
--  lúc bán.
--
--  Đơn cũ để rỗng — phiếu bỏ qua ĐVT thay vì đoán theo mặt hàng hiện tại.
-- =====================================================================

ALTER TABLE `order_items`
    ADD COLUMN `unit_name` VARCHAR(50) NOT NULL DEFAULT '' AFTER `variant_name`;
