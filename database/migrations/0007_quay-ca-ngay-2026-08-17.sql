-- =====================================================================
--  0007_quay-ca-ngay-2026-08-17.sql
--  Ngày: 17/08/2026
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
--  QUẦY DÙNG ĐƯỢC CẢ NGÀY (GIAI ĐOẠN 2)
--
--  0006 làm cho quầy BÁN ĐƯỢC. Tệp này làm cho quầy dùng được suốt một ca:
--  quét mã vạch thay vì gõ tên hàng, và bớt giá từng món trong tầm quyền
--  của người đang đứng bán.
-- =====================================================================

-- ---------------------------------------------------------------------
--  1. `barcode` — mã vạch in trên chính món hàng.
--
--  VÌ SAO KHÔNG DÙNG LUÔN CỘT `sku`: hai thứ này khác nhau về nguồn gốc và
--  về ai đặt ra chúng. SKU là mã NỘI BỘ do cửa hàng tự đặt để phân biệt
--  size/màu ("ao-real-2024-m-trang") — nó phải dễ đọc cho người. Mã vạch
--  là con số do NHÀ SẢN XUẤT in lên bao bì (EAN-13, UPC), cửa hàng không
--  chọn được và cũng không sửa được. Hàng nhập về đã có sẵn mã vạch trên
--  tem; ép nó thành SKU là vứt đi cái tên dễ đọc, mà giữ SKU rồi bắt người
--  bán gõ tay thì cái máy quét nằm không.
--
--  Đường quét vẫn tra CẢ HAI (xem orderRepository.TimBienTheTheoMa): tiệm
--  nhỏ thường tự in tem SKU dán lên hàng lẻ, và họ phải quét được thứ họ
--  vừa in ra.
--
--  UNIQUE ghép với deleted_mark, cùng khuôn với uq_variants_sku sẵn có:
--  MySQL coi mỗi NULL là một giá trị riêng nên nhiều biến thể cùng để
--  trống mã vạch vẫn chèn được — đúng ý, vì phần lớn hàng chưa dán mã.
--  Nhưng hai biến thể ĐANG SỐNG thì không được trùng mã: quét một cái ra
--  hai kết quả là lúc máy phải hỏi người, và ở quầy thì không có thời gian
--  cho câu hỏi đó.
-- ---------------------------------------------------------------------
ALTER TABLE product_variants
  ADD COLUMN barcode VARCHAR(64) NULL
    COMMENT 'mã vạch in trên hàng (EAN/UPC); NULL = chưa dán mã'
    AFTER sku,
  ADD UNIQUE KEY uq_variants_barcode (barcode, deleted_mark);

-- ---------------------------------------------------------------------
--  2. Giảm giá theo TỪNG DÒNG hàng.
--
--  Tới giờ đơn chỉ có MỘT ô giảm giá cho cả đơn (orders.discount_amount,
--  do voucher trừ). Ở quầy thì việc bớt tiền gần như luôn gắn với một món
--  cụ thể: cái áo này lỗi đường chỉ nên bớt 20%, đôi giày kia trưng bày
--  lâu ngày. Dồn hết vào ô của cả đơn thì hôm sau không ai trả lời được
--  "bớt cho món nào, vì sao" — và đó chính là câu hỏi lúc đối soát.
--
--  LƯU CẢ HAI CON SỐ, không phải một:
--    discount_percent = mức người bán BẤM, cũng là mức bị hạn quyền chặn.
--    discount_amount  = số tiền THẬT đã trừ khỏi dòng đó.
--  Giữ mỗi phần trăm thì tiền phải tính lại từ đơn giá, mà đơn giá sẽ đổi
--  sau mỗi đợt khuyến mãi; giữ mỗi số tiền thì mất dấu vết "ai được phép
--  duyệt mức này". Cần cả hai mới dựng lại được một dòng hàng cũ.
--
--  NOT NULL DEFAULT 0: không giảm là 0 đồng, không có ca "chưa biết".
--  Khác hẳn amount_tendered của 0006 — ở đó NULL mang nghĩa "không áp
--  dụng", còn ở đây mọi dòng hàng đều có một mức giảm, kể cả mức không.
-- ---------------------------------------------------------------------
ALTER TABLE order_items
  ADD COLUMN discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0
    COMMENT 'mức giảm người bán bấm, %; 0 = không giảm'
    AFTER unit_price,
  ADD COLUMN discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0
    COMMENT 'số tiền đã trừ khỏi dòng này'
    AFTER discount_percent;
