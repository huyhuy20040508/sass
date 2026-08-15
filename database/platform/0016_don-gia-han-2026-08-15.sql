-- =====================================================================
--  0016_don-gia-han-2026-08-15.sql
--  Ngày: 15/08/2026
--  LƯỢC ĐỒ: selliotech_platform (KHÔNG phải selliotech)
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
--  RENEWAL_ORDERS — KHÁCH TỰ BẤM GIA HẠN
--
--  Tới hôm nay, gia hạn là việc của NGƯỜI BÁN: khách gọi điện, mình bấm
--  nút trong khu điều hành. Bảng này là mảnh còn thiếu để khách tự làm —
--  bấm gói, trả tiền qua cổng, và hợp đồng dài thêm mà không ai phải có
--  mặt.
--
--  VÌ SAO PHẢI CÓ MỘT BẢNG RIÊNG, không ghi thẳng vào `invoices`:
--
--    · `invoices` là SỔ TIỀN ĐÃ VÀO. Một dòng ở đó nghĩa là tiền có thật.
--      Đơn gia hạn thì phần lớn KHÔNG bao giờ thành tiền — khách bấm rồi
--      đóng tab, hết hạn link, đổi ý. Nhét chúng vào sổ thu là làm hỏng
--      con số duy nhất mà cả khu điều hành tin được.
--    · Cổng thanh toán báo về bằng MÃ ĐƠN, không bằng id hợp đồng. Phải
--      có chỗ tra ngược "mã này là của ai, gói nào, mấy tháng" — và phải
--      là chỗ ghi TRƯỚC khi khách trả tiền, vì lúc webhook về thì không
--      còn ai để hỏi.
--    · Số tiền phải CHỐT lúc tạo đơn. Bảng giá đổi giữa lúc khách đang
--      mở trang thanh toán là chuyện có thật, và khách phải trả đúng số
--      đã thấy.
--
--  MỘT DÒNG = MỘT LẦN KHÁCH BẤM GIA HẠN, không phải một lần trả tiền
--  thành công. Trạng thái nói phần còn lại.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS renewal_orders (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- Khách và phần mềm. Chép ra đây (thay vì chỉ trỏ vào subscription) vì
  -- webhook tra theo mã đơn và cần biết ngay đang nói về cửa hàng nào —
  -- kể cả khi hợp đồng đã bị đổi hay huỷ giữa chừng.
  tenant_id       BIGINT UNSIGNED NOT NULL COMMENT 'khách hàng — 0001.tenants',
  app_id          BIGINT UNSIGNED NOT NULL COMMENT 'phần mềm — 0002.apps',
  subscription_id BIGINT UNSIGNED NOT NULL COMMENT 'hợp đồng sẽ được đẩy hạn — 0001.subscriptions',
  plan_id         BIGINT UNSIGNED NULL     COMMENT 'dòng bảng giá khách chọn — 0003.plans; NULL nếu gói đã bị xoá',

  so_thang      SMALLINT UNSIGNED NOT NULL COMMENT 'số tháng gia hạn',
  -- Số tiền CHỐT lúc tạo đơn, không tra lại bảng giá lúc webhook về: bảng
  -- giá được phép đổi bất cứ lúc nào, còn khách thì trả đúng số họ đã
  -- nhìn thấy trên màn hình.
  so_tien       DECIMAL(12,2) NOT NULL COMMENT 'tổng tiền của đơn (VND)',

  -- ma_don là orderCode gửi sang cổng thanh toán. PayOS đòi một SỐ NGUYÊN
  -- chưa từng dùng trên kênh thanh toán đó — kể cả link đã huỷ cũng không
  -- dùng lại được. Dùng chính id tự tăng của bảng này: duy nhất theo
  -- database, và tra ngược từ webhook về đơn chỉ là một lượt tra khoá.
  ma_don        BIGINT UNSIGNED NOT NULL COMMENT 'orderCode gửi sang cổng thanh toán',

  -- cho_thanh_toan | da_thanh_toan | huy | het_han
  --
  -- KHÔNG có 'that_bai': tiền không vào thì đơn vẫn đang chờ cho tới lúc
  -- link hết hạn. Thêm một trạng thái mà không có gì đưa đơn vào đó là
  -- thêm một nhánh code không ai chạy.
  trang_thai    VARCHAR(20) NOT NULL DEFAULT 'cho_thanh_toan',

  cong          VARCHAR(20)  NOT NULL DEFAULT 'payos' COMMENT 'cổng thanh toán đã dùng',
  link_id       VARCHAR(100) NULL COMMENT 'paymentLinkId của cổng, để tra cứu về sau',
  checkout_url  VARCHAR(500) NULL COMMENT 'địa chỉ trang trả tiền của cổng',

  -- invoice_id nối sang SỔ THU: đơn đã thu tiền thì phải chỉ ra được dòng
  -- tiền tương ứng, nếu không thì hai bảng nói hai chuyện và không ai đối
  -- chiếu được. NULL khi chưa trả xong.
  invoice_id    BIGINT UNSIGNED NULL COMMENT 'dòng sổ thu sinh ra khi đơn hoàn tất — 0014.invoices',

  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  paid_at       DATETIME(3) NULL COMMENT 'lúc cổng báo tiền đã vào',
  het_han_luc   DATETIME(3) NULL COMMENT 'hạn của link thanh toán',

  PRIMARY KEY (id),
  -- Khoá duy nhất trên ma_don là chốt chặn CHỐNG TRẢ TIỀN HAI LẦN cho một
  -- mã: webhook của cổng có thể tới nhiều lần cho cùng một giao dịch (đó
  -- là thiết kế của họ, không phải lỗi), và lượt xử lý phải nhận ra ngay
  -- là đã xử lý rồi.
  UNIQUE KEY uq_renewal_ma_don (ma_don),
  KEY idx_renewal_tenant (tenant_id, trang_thai),
  KEY idx_renewal_sub (subscription_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Đơn gia hạn do khách tự đặt (control plane)';
