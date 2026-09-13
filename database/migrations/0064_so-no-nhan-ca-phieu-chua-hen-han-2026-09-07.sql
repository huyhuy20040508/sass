-- =====================================================================
--  0064_so-no-nhan-ca-phieu-chua-hen-han-2026-09-07.sql
--  Ngày: 07/09/2026
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy (cục bộ / thử / thật đều khác tên).
--
--  MySQL không cho DDL nằm trong transaction, nên tệp chạy dở là dở thật.
--  Vì thế mọi lệnh dưới đây hỏi `information_schema` trước rồi mới dựng
--  câu bằng PREPARE — cùng khuôn migration 0021 và 0056, chạy được ở cả
--  MariaDB (máy phát triển) lẫn MySQL 8 (CI và máy chủ thật), và chạy lại
--  được sau một lượt hỏng giữa chừng.
-- =====================================================================
--
--  SỔ NỢ BỎ SÓT PHIẾU DUYỆT XONG CHƯA TRẢ ĐỒNG NÀO
--
--  PMH202609050001: đã duyệt, tổng 40.000.000, trả 0, payment_status
--  'unpaid', is_debt 0 — hàng đã nhận, tiền chưa trả, mà màn Công nợ ghi
--  "Chưa có khoản nợ nào".
--
--  Gốc rễ: màn Công nợ lấy `is_debt = 1` làm điều kiện VÀO SỔ. Cờ ấy chỉ
--  đặt được qua hộp Thanh toán của màn Phiếu mua hàng; lượt DUYỆT phiếu
--  không đặt. Ai duyệt xong đóng luôn, không mở hộp Thanh toán, thì phiếu
--  đó không bao giờ vào sổ nợ. Nợ là tiền còn thiếu, không phải một ô tick.
--
--  Điều kiện mới ở congNoRepository.nen:
--
--      status = 'approved'
--      AND (is_debt = 1 OR paid_amount < total_amount - 0.005)
--
--  Vế `is_debt = 1` vẫn giữ để khoản ĐÃ thoả thuận không rời sổ ngay khi
--  trả xong. `is_debt` KHÔNG đổi nghĩa — nó vẫn chỉ nói hai bên có hẹn
--  hạn hay không; phiếu vào sổ mà chưa hẹn thì hai cột Hạn còn / Ngày đáo
--  hạn để TRỐNG chứ không bịa ra ngày.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1) Nói lại cho đúng chú thích cột.
--
-- Chú thích cũ ('hai bên có thoả thuận cho nợ hay không', migration 0048)
-- không sai về NGHĨA, nhưng đọc xong dễ tưởng đây là điều kiện vào sổ nợ —
-- đúng là chỗ vừa hiểu nhầm. Nói thẳng ra nó KHÔNG phải điều kiện ấy.
--
-- MODIFY giữ nguyên kiểu, NOT NULL và DEFAULT: chỉ chú thích đổi, không
-- dòng dữ liệu nào bị đụng tới.
-- ---------------------------------------------------------------------
SET @chu_thich := (
  SELECT COLUMN_COMMENT FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'purchase_orders'
    AND COLUMN_NAME = 'is_debt'
);
SET @sql := IF(@chu_thich IS NOT NULL AND @chu_thich <> 'hai bên có hẹn hạn trả hay không; KHÔNG phải điều kiện vào sổ nợ',
  'ALTER TABLE purchase_orders
     MODIFY COLUMN is_debt TINYINT(1) NOT NULL DEFAULT 0
       COMMENT ''hai bên có hẹn hạn trả hay không; KHÔNG phải điều kiện vào sổ nợ''',
  'DO 0');
PREPARE lenh FROM @sql; EXECUTE lenh; DEALLOCATE PREPARE lenh;

-- ---------------------------------------------------------------------
-- 2) Đổi chỉ mục cho khớp câu truy vấn mới.
--
-- 0059 dựng idx_po_cong_no (status, is_debt, shop_id, debt_due_date), hợp
-- lý với câu cũ vì `is_debt` khi ấy là điều kiện BẰNG. Nay nó nằm trong
-- một vế OR, không còn là điều kiện bằng nữa — mà một cột không-bằng đặt
-- giữa chừng thì MỌI cột sau nó trong chỉ mục hết dùng được. Chỉ mục cũ
-- vì thế tụt xuống chỉ còn tiền tố `status`, tức gần như quét cả bảng
-- phiếu đã duyệt rồi mới lọc và sắp lại.
--
-- Chỉ mục mới bỏ `is_debt` ra: (status, shop_id, debt_due_date). `status`
-- là điều kiện bằng và không bộ lọc nào tắt được; `shop_id` cũng bằng
-- nhưng có thể vắng (xem hết mọi chi nhánh); `debt_due_date` đứng cuối vì
-- nó là điều kiện KHOẢNG, và nhờ đứng cuối thì `ORDER BY debt_due_date`
-- đọc thẳng theo chỉ mục thay vì gom cả tập rồi sắp lại.
--
-- Vế `paid_amount < total_amount` so hai cột với nhau nên không chỉ mục
-- nào giúp được — để nó lọc sau, trên tập đã hẹp lại theo ba cột trên.
-- ---------------------------------------------------------------------
SET @co_moi := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'purchase_orders'
    AND INDEX_NAME = 'idx_po_cong_no_han'
);
SET @sql := IF(@co_moi = 0,
  'ALTER TABLE purchase_orders ADD KEY idx_po_cong_no_han (status, shop_id, debt_due_date)',
  'DO 0');
PREPARE lenh FROM @sql; EXECUTE lenh; DEALLOCATE PREPARE lenh;

-- Dỡ chỉ mục cũ SAU khi chỉ mục mới đã đứng: giữa hai lệnh mà có lượt đọc
-- nào của màn Công nợ thì nó vẫn còn đường đi.
SET @co_cu := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'purchase_orders'
    AND INDEX_NAME = 'idx_po_cong_no'
);
SET @sql := IF(@co_cu > 0, 'ALTER TABLE purchase_orders DROP INDEX idx_po_cong_no', 'DO 0');
PREPARE lenh FROM @sql; EXECUTE lenh; DEALLOCATE PREPARE lenh;
