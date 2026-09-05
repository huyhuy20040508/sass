-- =====================================================================
--  0047_ton-kho-theo-lo-2026-09-01.sql
--  Ngày: 01/09/2026
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
--  SỐ LÔ TRỞ THÀNH MỘT CHIỀU CỦA TỒN KHO
--
--  Migration 0042 nói rõ giới hạn của nó: số lô lúc ấy chỉ là bản CHỤP
--  trên dòng phiếu mua, tồn kho vẫn đếm theo (chi nhánh × biến thể). Tệp
--  này gỡ đúng giới hạn đó, dựng lại mô hình của bản order v2 — bên đó
--  bảng `war_warehouse_stock` khoá theo (branch_id, menu_id, lot_number).
--
--  CÁCH GHÉP VỚI `variant_stocks` — đọc kỹ, đây là quyết định gốc:
--
--  `variant_stocks` GIỮ NGUYÊN vai trò nguồn sự thật của TỔNG tồn mỗi
--  (chi nhánh × biến thể). Bảng mới dưới đây là BẢN CHIA NHỎ của đúng con
--  số ấy theo lô, và bất biến phải giữ là:
--
--      variant_stocks.quantity = SUM(stock_lots.quantity) cùng (shop, variant)
--
--  Vì sao không chuyển hẳn tồn sang bảng lô rồi bỏ variant_stocks: hơn
--  chục đường đọc đang cộng thẳng từ nó — trang bán hàng, danh sách sản
--  phẩm, báo cáo giá trị kho, ô tồn trong phiếu mua. Đổi nguồn sự thật là
--  phải sửa và kiểm lại toàn bộ số đó trong cùng một đợt, mà mỗi chỗ sai
--  là một con số tồn sai không ai nhận ra. Giữ tổng ở chỗ cũ thì mọi
--  đường đọc hiện có đúng y như hôm qua, còn lô là thứ cộng thêm.
--
--  Cả hai bảng chỉ được ghi trong CÙNG một giao dịch, qua đúng một cửa
--  `ghiTonChiNhanh` bên repository — grep tên hàm đó ra là thấy hết chỗ
--  hàng hoá đổi số.
--
--  LÔ RỖNG = "KHÔNG XÁC ĐỊNH". Bản v2 để nguyên chuỗi tiếng Việt có dấu
--  'Không xác định' làm sentinel trong dữ liệu thật. Ở đây dùng chuỗi RỖNG
--  cho cùng ý nghĩa, vì ba lẽ: cột `purchase_order_items.lot_number` của
--  migration 0042 đã mang sẵn quy ước "rỗng = chưa xác định" và trong
--  database đang có dữ liệu như vậy; khoá unique không cho NULL lặp lại
--  nên NULL không dùng được; và một chuỗi tiếng Việt có dấu nằm trong khoá
--  là thứ sớm muộn ai đó gõ sai một dấu. Nhãn "Không xác định" do tầng
--  hiển thị đặt, không nằm dưới database.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS stock_lots (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id          BIGINT UNSIGNED NOT NULL DEFAULT 1,
  shop_id            BIGINT UNSIGNED NOT NULL,
  product_variant_id BIGINT UNSIGNED NOT NULL,

  lot_number  VARCHAR(50) NOT NULL DEFAULT ''
    COMMENT 'số lô bên bán ghi; rỗng = Không xác định',
  -- Hạn dùng của LÔ, không phải của mặt hàng. NULL = hàng không có hạn, và
  -- FEFO xếp những dòng ấy xuống cuối (hàng không hạn thì không vội bán).
  expire_date DATE NULL COMMENT 'hạn dùng của lô; NULL = hàng không có hạn',

  -- Cho phép ÂM. Bán vượt tồn vẫn phải bán được (v2 đẻ ra lô "Không xác
  -- định" âm rồi để phiếu cân đối dọn sau), và chặn ở đây thì quầy đứng
  -- hình giữa lúc có khách. Luật "không cho âm" nằm ở tầng gọi, theo từng
  -- nghiệp vụ, chứ không phải ở cột này.
  quantity INT NOT NULL DEFAULT 0 COMMENT 'số hàng của lô này TẠI chi nhánh này',

  -- Giá vốn của lô, chỉ để TRA CỨU và in phiếu. Giá vốn dùng cho báo cáo
  -- lãi gộp vẫn là bình quân gia quyền trên product_variants.cost_price —
  -- tệp này KHÔNG đổi cách tính giá vốn, chỉ thêm chiều lô cho số lượng.
  unit_cost DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'giá nhập một đơn vị chính của lô',

  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,

  PRIMARY KEY (id),
  -- Một lô của một biến thể có ĐÚNG MỘT dòng ở mỗi chi nhánh — cùng khoá
  -- với war_warehouse_stock của v2. shop_id đứng đầu vì câu hỏi thường gặp
  -- là "chi nhánh này còn lô nào", không phải ngược lại.
  UNIQUE KEY uq_stock_lots_shop_variant_lot (shop_id, product_variant_id, lot_number),
  KEY idx_stock_lots_tenant (tenant_id),
  KEY idx_stock_lots_variant (product_variant_id),
  -- FEFO quét theo hạn dùng trong phạm vi một (chi nhánh, biến thể).
  KEY idx_stock_lots_expire (shop_id, product_variant_id, expire_date),
  CONSTRAINT fk_stock_lots_tenant  FOREIGN KEY (tenant_id)          REFERENCES tenants (id),
  CONSTRAINT fk_stock_lots_shop    FOREIGN KEY (shop_id)            REFERENCES shops (id),
  CONSTRAINT fk_stock_lots_variant FOREIGN KEY (product_variant_id) REFERENCES product_variants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  NẠP TỒN ĐANG CÓ VÀO LÔ "KHÔNG XÁC ĐỊNH"
--
--  Hàng đang nằm trong kho hôm nay không ai biết thuộc lô nào — nó vào kho
--  từ trước khi có bảng này. Dồn hết vào lô rỗng để bất biến
--  SUM(stock_lots) = variant_stocks đúng ngay từ dòng đầu tiên.
--
--  KHÔNG cố suy ngược lô từ purchase_order_items: dòng phiếu mua chỉ nói
--  ĐÃ NHẬP bao nhiêu, không nói còn lại bao nhiêu sau bao nhiêu lượt bán.
--  Suy ra là dựng một con số trông có vẻ chính xác mà không ai kiểm được.
--
--  Cả dòng tồn 0 cũng chép: "có dòng, quantity = 0" mang nghĩa khác hẳn
--  "không có dòng", và bên repository đọc phân biệt hai cái đó.
-- ---------------------------------------------------------------------
INSERT INTO stock_lots (tenant_id, shop_id, product_variant_id, lot_number, expire_date, quantity, unit_cost, created_at, updated_at)
SELECT vs.tenant_id, vs.shop_id, vs.product_variant_id, '', NULL, vs.quantity, 0, NOW(3), NOW(3)
FROM variant_stocks vs
ON DUPLICATE KEY UPDATE stock_lots.quantity = stock_lots.quantity;

-- ---------------------------------------------------------------------
--  SỔ TIÊU THỤ THEO LÔ
--
--  Bán hàng KHÔNG chọn lô — người đứng quầy bấm món, hệ thống tự rút lô
--  theo FIFO/FEFO. Nên tới lúc huỷ đơn hay khách trả hàng, không có cách
--  nào biết phải hoàn về lô nào NẾU không ghi lại lúc rút.
--
--  Hoàn đại vào lô "Không xác định" thì mỗi vòng bán–trả lại bào mòn một
--  ít khỏi các lô có thật, và sau vài tháng bảng lô chỉ còn một cục vô
--  danh — đúng thứ mà cả tính năng này sinh ra để tránh.
--
--  Bản v2 giải cùng bài toán này bằng bảng `odr_minus_warehouses`: mỗi
--  lượt trừ kho ghi một dòng trỏ về NGUỒN nhập đã bị rút. Bảng dưới đây là
--  cùng ý đó, nhưng trỏ về (lô × chứng từ) thay vì về dòng chứng từ nhập —
--  hợp với mô hình ở đây, nơi tồn theo lô nằm ở stock_lots chứ không suy
--  ra từ từng dòng phiếu nhập.
--
--  Dấu của `quantity` theo đúng chiều hàng đi: âm = ra khỏi kho, dương =
--  vào kho. Cộng hết mọi dòng của một (shop, variant, lot) phải ra đúng
--  stock_lots.quantity của lô đó — trừ phần tồn đầu do migration này nạp.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS stock_lot_moves (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id          BIGINT UNSIGNED NOT NULL DEFAULT 1,
  shop_id            BIGINT UNSIGNED NOT NULL,
  product_variant_id BIGINT UNSIGNED NOT NULL,
  lot_number         VARCHAR(50) NOT NULL DEFAULT '',

  quantity INT NOT NULL COMMENT 'âm = ra khỏi kho, dương = vào kho',

  -- Chứng từ gây ra lượt đi này: 'order' | 'order_return' | 'purchase' |
  -- 'supplier_return' | 'stocktake'. Cặp (type, id) là thứ lượt hoàn kho
  -- tra ngược để biết đảo lại đúng những lô nào.
  reference_type VARCHAR(30) NOT NULL DEFAULT '',
  reference_id   BIGINT UNSIGNED NULL,

  created_at DATETIME(3) NULL,

  PRIMARY KEY (id),
  KEY idx_slm_tenant (tenant_id),
  -- Câu hỏi nóng nhất: "đơn này đã rút những lô nào của món này".
  KEY idx_slm_ref (shop_id, reference_type, reference_id, product_variant_id),
  KEY idx_slm_lot (shop_id, product_variant_id, lot_number),
  CONSTRAINT fk_slm_tenant  FOREIGN KEY (tenant_id)          REFERENCES tenants (id),
  CONSTRAINT fk_slm_shop    FOREIGN KEY (shop_id)            REFERENCES shops (id),
  CONSTRAINT fk_slm_variant FOREIGN KEY (product_variant_id) REFERENCES product_variants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
