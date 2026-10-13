-- =====================================================================
--  0082_phieu-chi-phi-the-2026-10-12.sql
--  Ngày: 12/10/2026
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy.
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  viết tệp mới.
-- =====================================================================
--
--  GHI BÙ PHIẾU CHI PHÍ QUẸT THẺ cho đơn quầy đã bán trước khi có phiếu tự
--  sinh (nguồn `card_fee`, source_id = id đơn). Từ nay PaymentMethodRepository
--  .GhiDonDung ghi phiếu cùng lúc với orders.card_fee.
--
--  Cùng khuôn với 0060: mã `PC-PHI-<mã đơn>` (không cấp theo quy tắc đánh số
--  được trong SQL), shift_id / created_by NULL, chuyển khoản vì ngân hàng trừ
--  thẳng — không qua két. NOT EXISTS theo nguồn để chạy lại không ghi trùng.
-- =====================================================================

INSERT INTO income_expenses
  (tenant_id, shop_id, code, type, category_id, amount, payment_method,
   note, source, source_id, created_at, updated_at)
SELECT
  o.tenant_id,
  o.shop_id,
  CONCAT('PC-PHI-', o.order_code),
  1,
  (SELECT MIN(t.id) FROM income_expense_types t
    WHERE t.tenant_id = o.tenant_id AND t.type = 1 AND t.deleted_at IS NULL
      AND t.name = 'Phí thanh toán, phí ngân hàng, phí cổng thanh toán'),
  o.card_fee,
  'transfer',
  CONCAT('Phí quẹt thẻ ', o.order_code, IF(pd.name IS NULL, '', CONCAT(' · ', pd.name))),
  'card_fee',
  o.id,
  o.created_at,
  NOW(3)
FROM orders o
LEFT JOIN payment_method_details d ON d.id = o.payment_method_detail_id
LEFT JOIN payment_devices pd ON pd.id = d.device_id
WHERE o.deleted_at IS NULL
  AND o.card_fee > 0
  AND NOT EXISTS (
    SELECT 1 FROM income_expenses ie
    WHERE ie.source = 'card_fee' AND ie.source_id = o.id AND ie.deleted_at IS NULL
  );
