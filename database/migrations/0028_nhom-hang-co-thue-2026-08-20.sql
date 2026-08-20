-- =====================================================================
--  0028_nhom-hang-co-thue-2026-08-20.sql
--  Ngày: 20/08/2026
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
--  NHÓM HÀNG HOÁ MANG SẴN MỨC THUẾ
--
--  Đây là quy tắc của bản cũ v2 (odr_menu_groups.id_tax): chọn nhóm hàng
--  lúc khai mặt hàng thì ô thuế TỰ ĐIỀN theo nhóm. Cửa hàng bán vài trăm
--  mặt hàng nhưng chỉ có dăm nhóm, mà thuế thì đi theo nhóm chứ không đi
--  theo từng món — gõ tay từng mặt hàng là vừa mất công vừa dễ lệch.
--
--  Vẫn sửa đè được ở từng mặt hàng: nhóm chỉ đặt giá trị ban đầu.
--
--  Giá trị giống hệt products.vat (0028 và 0027 dùng chung quy ước):
--  số dương là phần trăm, -1 = KCT, -2 = KKKNT.
-- ---------------------------------------------------------------------
ALTER TABLE categories
  ADD COLUMN vat INT NOT NULL DEFAULT 0
    COMMENT '% thuế GTGT mặc định của nhóm; -1 = KCT, -2 = KKKNT'
    AFTER description;
