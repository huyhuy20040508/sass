-- =====================================================================
--  0005_ton-kho-theo-chi-nhanh-2026-08-16.sql
--  Ngày: 16/08/2026
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
--  TỒN KHO TÁCH RA THEO TỪNG CHI NHÁNH
--
--  Bảng `variant_stocks` (tồn của một biến thể TẠI một chi nhánh) dựng từ
--  0002, nạp dữ liệu một lần rồi nằm im từ đó tới nay: KHÔNG dòng code Go
--  nào đọc hay ghi nó. Suốt thời gian ấy tồn kho vẫn chạy qua
--  `product_variants.stock_quantity` — một con số DUY NHẤT cho cả cửa
--  hàng. Nghĩa là mở được chi nhánh thứ hai thì hai chi nhánh vẫn dùng
--  chung một rổ hàng: bán ở Quận 1 thì kho Quận 7 cũng tụt theo.
--
--  Tệp này là BƯỚC 1 của đợt chuyển đã ghi sẵn trong 0002: nạp lại
--  variant_stocks từ số tồn đang chạy. Bước 2 (code đọc/ghi bảng này) đi
--  cùng lượt triển khai ngay sau đây.
--
--  VÌ SAO NẠP LẠI CHỨ KHÔNG TIN DỮ LIỆU CŨ: bản nạp của 0002 chụp tồn kho
--  của ngày 11/08. Từ hôm đó tới nay hàng vào hàng ra đều chỉ ghi vào
--  stock_quantity, nên dòng trong variant_stocks giờ là ảnh chụp cũ. Tin nó
--  nghĩa là ngày triển khai, tồn kho của mọi khách lùi về mốc đó — sai theo
--  kiểu không ai nhận ra ngay, và mỗi ngày trôi qua càng khó dựng lại.
--
--  VÌ SAO KHÔNG BỎ CỘT stock_quantity Ở ĐÂY (bước 3 của 0002): từ nay nó
--  không còn là nguồn sự thật nữa mà là BẢN CỘNG SẴN của mọi chi nhánh, do
--  tầng repository ghi lại sau mỗi lần đụng kho. Giữ nó lại vì hàng chục
--  đường đọc đang dựa vào: trang bán hàng cho khách vãng lai, danh sách sản
--  phẩm, ô "còn mấy cái" lúc lập phiếu, báo cáo giá trị kho. Đổi hết chúng
--  sang phép cộng theo chi nhánh trong cùng một đợt là đổi cả phần bán hàng
--  chỉ để phục vụ phần quản lý kho — nhiều rủi ro mà không thêm giá trị nào
--  cho khách một chi nhánh (tức là gần như mọi khách hôm nay).
--
--  DỒN VỀ CHI NHÁNH NÀO: chi nhánh CÒN SỐNG có id nhỏ nhất của mỗi cửa hàng
--  — chính là dòng 'mac-dinh' dựng cùng lúc mở tài khoản. Toàn bộ số hàng
--  đang có được coi là nằm ở đó, vì tới giờ phút này nó là chi nhánh duy
--  nhất từng bán được cái gì. Ai vừa mở thêm chi nhánh trước lượt triển
--  khai này thì chi nhánh đó bắt đầu từ 0 và phải nhập hàng vào — đúng với
--  thực tế: chưa có đợt nhập nào ghi hàng về đó cả.
-- =====================================================================

-- ---------------------------------------------------------------------
--  1. Xoá sạch bản nạp cũ của 0002.
--
--  Xoá chứ không UPDATE: dòng cũ có thể trỏ vào chi nhánh mà cửa hàng đã
--  đóng, và một dòng tồn treo ở chi nhánh đã đóng thì không có màn hình nào
--  hiển thị nó nữa — hàng biến mất khỏi mọi phép cộng mà sổ vẫn ghi là có.
-- ---------------------------------------------------------------------
DELETE FROM variant_stocks;

-- ---------------------------------------------------------------------
--  2. Nạp lại từ số tồn ĐANG CHẠY, dồn vào chi nhánh gốc của từng cửa hàng.
--
--  Tính cả biến thể đã xoá mềm: hàng của nó vẫn nằm trong kho thật, và
--  luồng huỷ đơn vẫn phải trả hàng về được (xem order_repository — nó đọc
--  Unscoped đúng vì lý do này). Bỏ chúng ra là tạo ra một lượt trả hàng ghi
--  vào chỗ không tồn tại.
--
--  Tồn ÂM (dữ liệu cũ lỡ tay) chép nguyên chứ không kẹp về 0: sửa số kho
--  của khách trong một migration là thay đổi không ai yêu cầu và không ai
--  thấy. Để nguyên thì nó hiện ra ở trang tồn kho, đúng chỗ có người nhìn.
-- ---------------------------------------------------------------------
INSERT INTO variant_stocks (tenant_id, shop_id, product_variant_id, quantity, created_at, updated_at)
SELECT v.tenant_id, g.shop_id, v.id, v.stock_quantity, NOW(3), NOW(3)
FROM product_variants v
JOIN (
    SELECT tenant_id, MIN(id) AS shop_id
    FROM shops
    WHERE deleted_at IS NULL
    GROUP BY tenant_id
) g ON g.tenant_id = v.tenant_id
ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), updated_at = NOW(3);

-- ---------------------------------------------------------------------
--  3. Bút toán kho ghi luôn chi nhánh phát sinh.
--
--  Cột `shop_id` đã có từ 0002 (mặc định 1) nhưng chưa dòng nào ghi đúng.
--  Dồn nốt lịch sử về chi nhánh gốc để trang "lịch sử kho" của một chi
--  nhánh không bị lẫn bút toán của chi nhánh khác — mặc định 1 là SAI với
--  mọi cửa hàng có id chi nhánh khác 1, tức là mọi khách trừ người đầu tiên.
-- ---------------------------------------------------------------------
UPDATE inventory_transactions i
JOIN (
    SELECT tenant_id, MIN(id) AS shop_id
    FROM shops
    WHERE deleted_at IS NULL
    GROUP BY tenant_id
) g ON g.tenant_id = i.tenant_id
SET i.shop_id = g.shop_id
WHERE i.shop_id <> g.shop_id;

-- ---------------------------------------------------------------------
--  4. Đơn hàng và chứng từ kho: cùng lý do, cùng cách dồn.
--
--  Bốn bảng này mang shop_id từ 0002 và cũng chưa ai ghi. Từ lượt triển
--  khai sau, dòng MỚI mang đúng chi nhánh đang làm việc; dòng CŨ thì gán về
--  chi nhánh gốc vì đó là nơi chúng thật sự phát sinh.
-- ---------------------------------------------------------------------
UPDATE orders o
JOIN (SELECT tenant_id, MIN(id) AS shop_id FROM shops WHERE deleted_at IS NULL GROUP BY tenant_id) g
  ON g.tenant_id = o.tenant_id
SET o.shop_id = g.shop_id WHERE o.shop_id <> g.shop_id;

UPDATE purchase_orders po
JOIN (SELECT tenant_id, MIN(id) AS shop_id FROM shops WHERE deleted_at IS NULL GROUP BY tenant_id) g
  ON g.tenant_id = po.tenant_id
SET po.shop_id = g.shop_id WHERE po.shop_id <> g.shop_id;

UPDATE order_returns r
JOIN (SELECT tenant_id, MIN(id) AS shop_id FROM shops WHERE deleted_at IS NULL GROUP BY tenant_id) g
  ON g.tenant_id = r.tenant_id
SET r.shop_id = g.shop_id WHERE r.shop_id <> g.shop_id;

UPDATE purchase_returns pr
JOIN (SELECT tenant_id, MIN(id) AS shop_id FROM shops WHERE deleted_at IS NULL GROUP BY tenant_id) g
  ON g.tenant_id = pr.tenant_id
SET pr.shop_id = g.shop_id WHERE pr.shop_id <> g.shop_id;
