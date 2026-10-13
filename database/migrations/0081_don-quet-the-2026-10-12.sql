-- =====================================================================
--  0081_don-quet-the-2026-10-12.sql
--  Ngày: 12/10/2026
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy.
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  viết tệp mới.
-- =====================================================================
--
--  QUẸT THẺ TẠI QUẦY — thêm 'card' vào hình thức thanh toán của đơn.
--
--  Báo cáo bán hàng đã có sẵn ô "Quẹt thẻ" gom theo mã 'card' (xem
--  sales_report_service.oHinhThuc), sổ chứng từ xếp nó vào cột Thẻ/Ví.
--
--  MODIFY giữ nguyên thứ tự các giá trị cũ và chỉ thêm vào CUỐI: ENUM lưu
--  theo chỉ số, đảo thứ tự là mọi dòng cũ đọc ra sai giá trị (xem 0006).
-- =====================================================================

ALTER TABLE orders
  MODIFY COLUMN payment_method
    ENUM('cod','vnpay','momo','bank_transfer','payos','sepay','cash','card')
    NOT NULL DEFAULT 'cod';
