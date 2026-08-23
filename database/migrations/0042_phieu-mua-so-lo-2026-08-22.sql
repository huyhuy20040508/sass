-- =====================================================================
--  0042_phieu-mua-so-lo-2026-08-22.sql
--  Ngày: 22/08/2026
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy (cục bộ / thử / thật đều khác tên).
--
--  MySQL không cho DDL nằm trong transaction, nên tệp chạy dở là dở thật.
--  Viết sao cho chạy lại được nếu có thể: IF NOT EXISTS, IF EXISTS...
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  công cụ giữ vân tay và sẽ báo lệch. Cần thêm gì thì viết tệp mới.
-- =====================================================================
--
--  SỐ LÔ VÀ HẠN DÙNG TRÊN DÒNG PHIẾU MUA
--
--  Màn "Phiếu mua hàng" của bản order v2 có hai cột này trên lưới hàng và
--  bắt buộc điền số lô. Dựng lại màn ấy thì phải có chỗ ghi chúng, không
--  thì hai ô trên màn hình gõ xong là bay mất.
--
--  PHẠM VI — đọc kỹ chỗ này trước khi ai đó định dựng FIFO theo lô:
--
--  Đây là bản CHỤP trên chứng từ, KHÔNG phải một chiều của tồn kho. Tồn
--  kho của hệ thống này đếm theo (chi nhánh × biến thể) — xem
--  `variant_stocks` và repository/ton_kho_chi_nhanh.go — và migration này
--  KHÔNG đổi điều đó. Nhập hai lô của cùng một mặt hàng vẫn cộng vào một
--  dòng tồn duy nhất.
--
--  Vậy hai cột này dùng được việc gì: tra ngược "lô X về theo phiếu nào,
--  ngày nào, giá bao nhiêu" khi nhà cung cấp báo thu hồi, và in ra phiếu
--  đúng như chứng từ bên bán. Muốn trừ kho theo lô thì đó là việc của
--  module Tồn kho, và phải thêm chiều lô vào `variant_stocks` trước.
-- ---------------------------------------------------------------------

ALTER TABLE purchase_order_items
  ADD COLUMN lot_number VARCHAR(50) NOT NULL DEFAULT ''
    COMMENT 'số lô bên bán ghi; rỗng = chưa xác định'
    AFTER unit_ratio,
  ADD COLUMN expire_date DATE NULL
    COMMENT 'hạn dùng của lô; NULL = hàng không có hạn'
    AFTER lot_number,
  ADD KEY idx_poi_lot (lot_number);
