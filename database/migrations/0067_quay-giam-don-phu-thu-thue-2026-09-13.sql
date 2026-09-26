-- =====================================================================
--  0067_quay-giam-don-phu-thu-thue-2026-09-13.sql
--  Ngày: 13/09/2026
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy (cục bộ / thử / thật đều khác tên).
--
--  MySQL không cho DDL nằm trong transaction, nên tệp chạy dở là dở thật.
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  công cụ giữ vân tay và sẽ báo lệch. Cần thêm gì thì viết tệp mới.
-- =====================================================================
--
--  QUẦY BÁN HÀNG: GIẢM CẢ ĐƠN, PHỤ THU, THUẾ SẢN PHẨM, NGƯỜI MUA LẤY HOÁ ĐƠN
--
--  1. GIẢM TAY TRÊN CẢ ĐƠN — `order_discount_percent` / `order_discount_amount`.
--
--     Số tiền giảm tay ĐÃ CỘNG vào `discount_amount` (cùng phần mã giảm giá).
--     Hai cột mới chỉ để TÁCH NGUỒN: báo cáo, sổ đơn và hoá đơn điện tử vẫn
--     đọc đúng một cột `discount_amount` như trước, nên không luồng nào phải
--     học thêm một khoản giảm thứ hai. `order_discount_percent` = 0 nghĩa là
--     người bán gõ SỐ TIỀN chứ không gõ phần trăm.
--
--  2. PHỤ THU — `surcharge_amount` + `surcharge_note` (lý do in lên phiếu).
--     Cộng thẳng vào `total_amount`, không chịu thuế (giống phí giao hàng).
--
--  3. THUẾ SẢN PHẨM — `orders.vat_amount`, `order_items.vat` + `vat_amount`.
--
--     Giá bán là giá CHƯA thuế, thuế cộng thêm vào tổng — đúng cách hoá đơn
--     điện tử đã tính từ trước tới nay (TotalPrice của dòng là "tiền chưa
--     thuế"). Trước bản này đơn quầy KHÔNG cộng thuế nên hoá đơn xuất ra lớn
--     hơn số khách đã trả đúng bằng tiền thuế.
--
--     `order_items.vat` CHỤP thuế suất lúc bán (quy ước của products.vat: số
--     dương là %, -1 KCT, -2 KKKNT). NULL = dòng bán trước migration này —
--     hoá đơn phát hành bù cho những dòng ấy lùi về thuế suất hiện tại của
--     mặt hàng, đúng như trước.
--
--  4. NGƯỜI MUA LẤY HOÁ ĐƠN — `buyer_tax_code`, `buyer_company`, `buyer_address`.
--     Đơn quầy không có địa chỉ giao, mà hoá đơn cho doanh nghiệp bắt buộc có
--     mã số thuế, tên đơn vị và địa chỉ đăng ký.
--
--  Mọi cột đều NOT NULL DEFAULT 0 / '' nên đơn cũ đọc ra đúng nghĩa "không có".
-- =====================================================================

ALTER TABLE orders
    ADD COLUMN order_discount_percent DECIMAL(5,2)  NOT NULL DEFAULT 0.00 AFTER discount_amount,
    ADD COLUMN order_discount_amount  DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER order_discount_percent,
    ADD COLUMN surcharge_amount       DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER shipping_fee,
    ADD COLUMN surcharge_note         VARCHAR(255)  NOT NULL DEFAULT ''   AFTER surcharge_amount,
    ADD COLUMN vat_amount             DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER surcharge_note,
    ADD COLUMN buyer_tax_code         VARCHAR(20)   NOT NULL DEFAULT ''   AFTER recipient_email,
    ADD COLUMN buyer_company          VARCHAR(255)  NOT NULL DEFAULT ''   AFTER buyer_tax_code,
    ADD COLUMN buyer_address          VARCHAR(255)  NOT NULL DEFAULT ''   AFTER buyer_company;

ALTER TABLE order_items
    ADD COLUMN vat        INT           NULL                  AFTER total_price,
    ADD COLUMN vat_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER vat;
