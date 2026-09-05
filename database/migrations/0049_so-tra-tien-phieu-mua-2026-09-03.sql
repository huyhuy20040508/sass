-- =====================================================================
--  0049_so-tra-tien-phieu-mua-2026-09-03.sql
--  Ngày: 03/09/2026
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
--  SỔ TỪNG LƯỢT TRẢ TIỀN NHÀ CUNG CẤP
--
--  Migration 0048 nói rõ chỗ nó còn thiếu: `purchase_orders.paid_amount`
--  là con số LUỸ KẾ, mỗi lượt sửa chỉ để lại một dòng chữ trong
--  `purchase_order_history` kiểu "từ 0 → 400000". Đọc được bằng mắt,
--  nhưng không cộng được: không trả lời nổi "tháng này chi bao nhiêu tiền
--  mua hàng", "trả bằng tiền mặt hay chuyển khoản", "ai là người ghi".
--
--  Tệp này dựng đúng cái sổ ấy. Bên v2 nó là `cab_debt_detail`.
--
--  QUAN HỆ VỚI `paid_amount` — đọc kỹ, đây là quyết định gốc:
--
--      purchase_orders.paid_amount = SUM(purchase_payments.amount)
--
--  `paid_amount` GIỮ NGUYÊN vai trò nguồn sự thật của số luỹ kế: mọi màn
--  danh sách đều đọc nó, và bắt chúng SUM lại một bảng con cho mỗi dòng
--  là đổi một phép đọc cột thành một phép gộp. Bảng dưới đây là bản GIẢI
--  THÍCH con số ấy — nó tới từ mấy lượt, mỗi lượt bao nhiêu, hình thức gì.
--
--  VÌ SAO `amount` LÀ SỐ CHÊNH CHỨ KHÔNG PHẢI SỐ LUỸ KẾ:
--
--  Đường API nhận số luỹ kế (màn hình bày số đã trả, người dùng sửa đúng
--  con số đó). Server tự lấy hiệu với số đang lưu rồi ghi phần chênh vào
--  đây. Nhờ vậy `amount` cộng lại đúng bằng `paid_amount`, và một lượt
--  SỬA LẠI cho đúng sẽ ghi thành số ÂM — nhìn vào sổ là biết ngay đây là
--  lượt chữa chứ không phải lượt trả.
--
--  CHỈ ghi dòng khi tiền THỰC SỰ đổi. Sửa mỗi hạn nợ hay số điện thoại
--  thì không đẻ ra một dòng "trả 0 đồng" — chuyện ấy đã có
--  `purchase_order_history` ghi lại.
--
--  CHƯA có ở tệp này: sổ thu chi toàn cửa hàng (`cab_income_expense` của
--  v2). Đó là chuyện của module Thu chi, và khi làm thì bảng này là nguồn
--  để đổ sang — mỗi dòng ở đây là đúng một bút toán chi.
-- =====================================================================

CREATE TABLE IF NOT EXISTS purchase_payments (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  --  Chi nhánh chi tiền — chép từ phiếu lúc ghi, để báo cáo theo chi
  --  nhánh không phải nối bảng.
  shop_id   BIGINT UNSIGNED NOT NULL,

  purchase_order_id BIGINT UNSIGNED NOT NULL,

  --  Số tiền của RIÊNG lượt này. Âm = lượt chữa lại con số đã ghi sai.
  amount DECIMAL(18,2) NOT NULL DEFAULT 0,
  --  Số luỹ kế SAU lượt này. Thừa về mặt dữ liệu (cộng dồn là ra), nhưng
  --  đọc sổ mà phải tự cộng từ đầu mới biết lúc ấy đã trả tới đâu thì
  --  không ai đọc.
  paid_after DECIMAL(18,2) NOT NULL DEFAULT 0,

  payment_method VARCHAR(20)  NOT NULL DEFAULT '' COMMENT 'cash | transfer',
  note           VARCHAR(500) NOT NULL DEFAULT '',
  attachment     VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'ảnh uỷ nhiệm chi / biên lai',

  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME(3) NULL,

  PRIMARY KEY (id),
  --  Đọc sổ của một phiếu theo thứ tự thời gian — truy vấn duy nhất của
  --  bảng này lúc này.
  KEY idx_pp_order (purchase_order_id, id),
  CONSTRAINT fk_pp_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_pp_order  FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Dựng lại sổ cho những phiếu ĐÃ trả tiền trước khi có bảng này
-- ---------------------------------------------------------------------
--  Không có bảng thì cũng không có cách nào biết họ đã trả mấy lượt —
--  chỉ biết TỔNG. Ghi đúng một dòng gộp cho mỗi phiếu, mang chính con số
--  luỹ kế ấy, để bất biến `SUM(amount) = paid_amount` đúng ngay từ đầu.
--  Không có dòng nào thì mọi báo cáo dựng trên bảng này sẽ báo cửa hàng
--  chưa chi đồng nào.
INSERT INTO purchase_payments
  (tenant_id, shop_id, purchase_order_id, amount, paid_after, payment_method, note, created_by, created_at)
SELECT
  po.tenant_id, po.shop_id, po.id, po.paid_amount, po.paid_amount,
  po.payment_method, 'Số đã trả ghi nhận trước khi có sổ trả tiền',
  po.handled_by, COALESCE(po.updated_at, po.created_at)
FROM purchase_orders po
WHERE po.paid_amount > 0
  AND po.deleted_at IS NULL;
