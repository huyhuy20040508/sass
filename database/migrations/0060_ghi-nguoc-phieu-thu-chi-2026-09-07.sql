-- =====================================================================
--  0060_ghi-nguoc-phieu-thu-chi-2026-09-07.sql
--  Ngày: 07/09/2026
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy (cục bộ / thử / thật đều khác tên).
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  công cụ giữ vân tay và sẽ báo lệch. Cần thêm gì thì viết tệp mới.
-- =====================================================================
--
--  GHI NGƯỢC PHIẾU THU CHI CHO CHỨNG TỪ CŨ.
--
--  Từ migration 0058, mỗi đơn bán đã thu tiền / mỗi lượt trả tiền nhà
--  cung cấp / mỗi lượt hoàn tiền trả hàng đều tự đẻ một phiếu trong
--  `income_expenses`. Nhưng chứng từ lập TRƯỚC đó thì không có phiếu nào,
--  nên sổ thu chi mở ra thấy trống trong khi tiền đã ra vào thật — và
--  "Tổng thu" / "Tổng chi" in ra nói thiếu đúng phần lịch sử ấy.
--
--  Tệp này dựng lại phần thiếu đó, MỘT LẦN, cho mọi cửa hàng đang có.
--
--  BỐN ĐIỀU ĐÃ CÂN NHẮC:
--
--   1. `created_at` lấy NGÀY CỦA CHỨNG TỪ GỐC, không phải NOW(). Lấy giờ
--      chạy migration thì cả lịch sử dồn vào đúng một ngày, và mọi bảng
--      cộng theo tháng — kể cả quỹ đầu kỳ của chính màn thu chi — sai hết.
--
--   2. MÃ PHIẾU suy từ mã chứng từ gốc (`PT-DH000001`,
--      `PC-PMH20260823001-7`) chứ không lấy từ bộ đếm `code_counters`.
--      Hai lẽ: bộ đếm cấp số theo thứ tự CHẠY, nên phiếu của tháng 8 sẽ
--      mang số lớn hơn phiếu của tháng 9 vừa lập — đọc sổ thấy ngay là
--      sai; và tiêu số của bộ đếm cho dữ liệu cũ làm dải mã đang chạy
--      nhảy cóc. Mã ở đây tự nói ra nó dựng từ chứng từ nào.
--
--   3. CHẠY LẠI ĐƯỢC. Mỗi lệnh đều `NOT EXISTS` theo `code` — mà mã thì
--      suy một-một từ chứng từ gốc, nên chạy hai lần không đẻ bản sao.
--      Cần vậy vì MySQL không cho DDL/DML dài nằm trong transaction, tệp
--      phải chịu được một lượt hỏng giữa chừng.
--
--   4. KHÔNG đụng `cash_entries`. Két của một ca cộng từ bảng ấy, và các
--      luồng bán hàng đã tự ghi nó từ lâu. Ghi thêm ở đây là đếm hai lần
--      số tiền của những ca đã chốt sổ từ nhiều tháng trước.
--
--  `shift_id` để NULL: không dựng lại được ca trực của một chứng từ cũ, và
--  đoán bừa thì phiếu bị khoá/mở nhầm theo một ca không liên quan.
--  `created_by` cũng NULL với phiếu từ đơn hàng — đơn không lưu người bán.
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
--  1. ĐƠN BÁN ĐÃ THU TIỀN → phiếu THU.
--
--  Chỉ đơn `payment_status = 'paid'`: đơn còn nợ thì chưa có đồng nào vào.
--  Đơn đã xoá mềm bỏ qua — sổ thu chi không dựng lại tiền của một đơn mà
--  màn hình nào cũng coi như không tồn tại.
-- ---------------------------------------------------------------------
INSERT INTO income_expenses
  (tenant_id, shop_id, code, type, amount, payment_method,
   note, source, source_id, created_at, updated_at)
SELECT
  o.tenant_id,
  o.shop_id,
  CONCAT('PT-', o.order_code),
  0,
  o.total_amount,
  CASE WHEN o.payment_method = 'cash' THEN 'cash' ELSE 'transfer' END,
  CONCAT('Bán hàng ', o.order_code),
  'order',
  o.id,
  o.created_at,
  NOW(3)
FROM orders o
WHERE o.deleted_at IS NULL
  AND o.payment_status = 'paid'
  AND o.total_amount > 0
  AND NOT EXISTS (
    SELECT 1 FROM income_expenses ie
    WHERE ie.tenant_id = o.tenant_id AND ie.code = CONCAT('PT-', o.order_code)
  );

-- ---------------------------------------------------------------------
--  2. LƯỢT TRẢ TIỀN NHÀ CUNG CẤP → phiếu CHI.
--
--  Một phiếu mua có thể trả nhiều lượt, nên mã phiếu kèm id của LƯỢT TRẢ
--  chứ không chỉ mã phiếu mua — hai lượt trả cùng một phiếu phải ra hai
--  dòng riêng.
--
--  LƯỢT CHỮA (`amount` âm — sửa lại con số đã ghi sai) ĐỔI VẾ thành phiếu
--  THU với trị tuyệt đối. Giữ nguyên số âm thì tổng vẫn ra đúng nhưng cột
--  "Số tiền" in ra "-500,000", và không ai đọc sổ theo kiểu đó.
-- ---------------------------------------------------------------------
INSERT INTO income_expenses
  (tenant_id, shop_id, code, type, amount, payment_method,
   note, source, source_id, created_by, created_at, updated_at)
SELECT
  pp.tenant_id,
  pp.shop_id,
  CONCAT(IF(pp.amount < 0, 'PT-', 'PC-'), po.po_code, '-', pp.id),
  IF(pp.amount < 0, 0, 1),
  ABS(pp.amount),
  CASE WHEN pp.payment_method = 'cash' THEN 'cash' ELSE 'transfer' END,
  CONCAT(
    IF(pp.amount < 0, 'Chữa lại lượt trả tiền phiếu mua ', 'Trả tiền phiếu mua '),
    po.po_code
  ),
  'purchase',
  pp.purchase_order_id,
  pp.created_by,
  pp.created_at,
  NOW(3)
FROM purchase_payments pp
JOIN purchase_orders po ON po.id = pp.purchase_order_id
WHERE pp.amount <> 0
  AND NOT EXISTS (
    SELECT 1 FROM income_expenses ie
    WHERE ie.tenant_id = pp.tenant_id
      AND ie.code = CONCAT(IF(pp.amount < 0, 'PT-', 'PC-'), po.po_code, '-', pp.id)
  );

-- ---------------------------------------------------------------------
--  3. TRẢ HÀNG ĐÃ HOÀN TIỀN → phiếu CHI.
--
--  Chỉ phiếu đã tới trạng thái `refunded` — điểm cuối của vòng đời trả
--  hàng, và cũng là lúc DUY NHẤT tiền thật sự rời két.
--
--  `created_at` ở đây lấy `updated_at` chứ không phải `created_at`: phiếu
--  trả được lập lúc khách gửi yêu cầu, còn tiền thì ra lúc chốt hoàn —
--  hai mốc có thể cách nhau nhiều ngày, và sổ tiền phải ghi theo mốc sau.
-- ---------------------------------------------------------------------
INSERT INTO income_expenses
  (tenant_id, shop_id, code, type, amount, payment_method,
   note, source, source_id, created_by, created_at, updated_at)
SELECT
  rt.tenant_id,
  rt.shop_id,
  CONCAT('PC-', rt.return_code),
  1,
  rt.refund_amount,
  CASE WHEN rt.refund_method = 'bank_transfer' THEN 'transfer' ELSE 'cash' END,
  CONCAT('Hoàn tiền trả hàng ', rt.return_code),
  'order_return',
  rt.id,
  rt.handled_by,
  rt.updated_at,
  NOW(3)
FROM order_returns rt
WHERE rt.deleted_at IS NULL
  AND rt.status = 'refunded'
  AND rt.refund_amount > 0
  AND NOT EXISTS (
    SELECT 1 FROM income_expenses ie
    WHERE ie.tenant_id = rt.tenant_id AND ie.code = CONCAT('PC-', rt.return_code)
  );
