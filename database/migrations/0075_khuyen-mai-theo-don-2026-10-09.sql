-- =====================================================================
--  0075_khuyen-mai-theo-don-2026-10-09.sql
--  Ngày: 09/10/2026
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy.
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  viết tệp mới.
-- =====================================================================
--
--  KHUYẾN MẠI THEO ĐƠN — nguồn của "Báo cáo chương trình khuyến mại".
--
--  Trước đây đơn chỉ giữ TỔNG tiền chương trình đã giảm (promotion_discount)
--  và id các bậc (promotion_detail_ids). Hai chỗ hỏng cho báo cáo:
--    - đơn áp hai chương trình thì không tách được mỗi chương trình giảm bao
--      nhiêu;
--    - sửa chương trình là xoá rồi tạo lại các bậc / dòng đồng giá với id mới,
--      id cũ trên đơn không còn trỏ về đâu. Xoá chương trình thì mất hẳn.
--  Nay mỗi chương trình áp vào đơn là MỘT DÒNG ở đây, chụp mã / tên / loại lúc
--  bán. Không có khoá ngoại sang bảng chương trình — chương trình bị xoá thì
--  báo cáo vẫn đọc được bản chụp.
--
--  kind            program     = chương trình khuyến mại (promotion_programs)
--                  fixed_price = khuyến mại đồng giá (fixed_prices)
--  program_type    loại của chương trình lúc bán (promotion_programs.type
--                  0/2/3, fixed_prices.type 1/2).
--  detail_id       bậc đã áp (kind = program); 0 với đồng giá — một chương
--                  trình đồng giá áp nhiều dòng cùng lúc.
--  quantity        số hàng BÁN được tính theo chương trình (loại 2/3, đồng
--                  giá); 0 với loại 0 (giảm trên cả đơn).
--  gift_quantity   số hàng TẶNG của chương trình trên đơn.
--  base_amount     đồng giá: tiền các dòng ấy theo giá bán gốc.
--  discount_amount tiền chương trình giảm trên đơn. Với chương trình khuyến
--                  mại, tổng các dòng của một đơn = orders.promotion_discount;
--                  với đồng giá = base_amount − tiền theo giá đồng giá.
--
--  Đơn cũ: chương trình khuyến mại mà đơn chỉ áp MỘT chương trình thì chép bù
--  được đúng (cả tiền giảm là của nó) — miễn bậc còn tồn tại. Đơn áp nhiều
--  chương trình, và đơn đồng giá (không còn giá gốc lúc bán), thì không chép.
-- =====================================================================

CREATE TABLE IF NOT EXISTS order_promotions (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id       BIGINT UNSIGNED NOT NULL,
  order_id        BIGINT UNSIGNED NOT NULL,
  kind            ENUM('program','fixed_price') NOT NULL,
  program_id      BIGINT UNSIGNED NOT NULL,
  detail_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  program_code    VARCHAR(50)  NOT NULL DEFAULT '',
  program_name    VARCHAR(255) NOT NULL DEFAULT '',
  program_type    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  quantity        INT UNSIGNED NOT NULL DEFAULT 0,
  gift_quantity   INT UNSIGNED NOT NULL DEFAULT 0,
  base_amount     DECIMAL(12,2) NOT NULL DEFAULT 0,
  discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at      DATETIME(3) NULL,
  PRIMARY KEY (id),
  KEY idx_order_promotions_order (order_id),
  KEY idx_order_promotions_program (tenant_id, kind, program_id),
  CONSTRAINT fk_order_promotions_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
  CONSTRAINT fk_order_promotions_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO order_promotions (tenant_id, order_id, kind, program_id, detail_id, program_code, program_name, program_type, discount_amount, created_at)
SELECT o.tenant_id, o.id, 'program', MIN(p.id), MIN(d.id), MIN(p.code), MIN(p.name), MIN(p.type), o.promotion_discount, o.created_at
FROM orders o
JOIN promotion_program_details d ON d.tenant_id = o.tenant_id AND FIND_IN_SET(d.id, o.promotion_detail_ids) > 0
JOIN promotion_programs p ON p.id = d.program_id
WHERE o.promotion_detail_ids <> ''
  AND NOT EXISTS (SELECT 1 FROM order_promotions x WHERE x.order_id = o.id)
GROUP BY o.id, o.tenant_id, o.promotion_discount, o.created_at
HAVING COUNT(DISTINCT p.id) = 1;
