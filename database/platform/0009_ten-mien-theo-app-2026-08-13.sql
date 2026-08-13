-- =====================================================================
--  0009_ten-mien-theo-app-2026-08-13.sql
--  Ngày: 13/08/2026
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
--  MỘT TÊN MIỀN TRỎ VÀO MỘT PHẦN MỀM CỦA MỘT KHÁCH
--
--  0008 cho một khách mua nhiều phần mềm. Ngay sau đó, sổ tên miền hết
--  trả lời được câu của chính nó: `quochuy.selliotech.store` là cửa hàng
--  của Quốc Huy — nhưng là phần mềm BÁN HÀNG hay phần mềm BIDA của anh ấy?
--  Hôm nay chỉ có một app chạy nên không ai để ý; ngày có app thứ hai thì
--  middleware phân giải tên miền sẽ tra ra đúng cửa hàng và phục vụ SAI
--  phần mềm, mà không có gì trong dữ liệu nói rằng nó sai.
--
--  Làm bây giờ vì sổ đang TRỐNG (0 dòng). Đây đúng là lý do 0008 được viết
--  sớm, và bài học lặp lại y nguyên: sửa một bảng rỗng là sửa một bảng
--  rỗng; sửa cùng bảng đó khi mỗi dòng là một địa chỉ khách đang gõ vào
--  trình duyệt thì phải vừa đổi khoá, vừa điền ngược, vừa không được để
--  đứt phút nào.
--
--  HAI KHOÁ, HAI Ý NGHĨA KHÁC HẲN NHAU:
--
--    · `uq_tenant_domains_host` GIỮ NGUYÊN phạm vi toàn bảng. Một tên miền
--      trên đời chỉ trỏ được vào một chỗ — thêm app_id vào khoá này là cho
--      phép hai dòng cùng host khác app, tức là hai phần mềm cùng nhận
--      chung một địa chỉ và request rơi vào cái nào là do thứ tự đọc đĩa.
--    · `uq_tenant_domains_primary` thì PHẢI mở theo app: "tên miền chính"
--      là địa chỉ dùng dựng link trong email và hoá đơn, mà email của phần
--      mềm bán hàng không được trỏ vào phần mềm bida. Mỗi khách mỗi phần
--      mềm một tên miền chính.
-- =====================================================================

SET NAMES utf8mb4;

-- =====================================================================
--  1. Thêm cột, cho phép NULL trước
-- =====================================================================
--  Ba bước (thêm NULL → điền → siết NOT NULL) chứ không thêm thẳng NOT
--  NULL: thêm thẳng chỉ chạy được trên bảng rỗng, mà tệp migration phải
--  chạy đúng trên mọi môi trường — kể cả môi trường ai đó vừa cấp tay vài
--  tên miền để thử.
ALTER TABLE tenant_domains
  ADD COLUMN app_id BIGINT UNSIGNED NULL
  COMMENT 'tên miền này phục vụ phần mềm nào — 0002.apps'
  AFTER tenant_id;

-- =====================================================================
--  2. Điền cho các dòng cũ: tất cả đều là tên miền của 'order'
-- =====================================================================
--  Trước tệp này chỉ có một app chạy, nên mọi tên miền đã cấp đều trỏ vào
--  nó. Tra id qua `apps.code` chứ không gõ số 1: id là số tự sinh của từng
--  database.
UPDATE tenant_domains d
  JOIN apps a ON a.code = 'order'
   SET d.app_id = a.id,
       d.updated_at = NOW(3)
 WHERE d.app_id IS NULL;

-- =====================================================================
--  3. Siết NOT NULL
-- =====================================================================
--  Một tên miền không nói được nó phục vụ phần mềm nào thì middleware
--  phải đoán, và đoán sai nghĩa là khách gõ đúng địa chỉ của mình rồi
--  nhìn thấy một sản phẩm khác.
ALTER TABLE tenant_domains
  MODIFY COLUMN app_id BIGINT UNSIGNED NOT NULL
  COMMENT 'tên miền này phục vụ phần mềm nào — 0002.apps';

-- =====================================================================
--  4. Khoá "tên miền chính" mở theo app + khoá ngoại sang apps
-- =====================================================================
--  Một câu ALTER cho cả ba việc: tách ra thì tệp có thể chết ở giữa và để
--  lại một bảng KHÔNG còn khoá "một tên miền chính", tức lúc đó cấp được
--  hai tên miền chính cho cùng một khách và link trong email tuỳ hứng.
--
--  KHÔNG đụng `uq_tenant_domains_host` — xem đầu tệp.
--
--  Khoá ngoại tự sinh chỉ mục trên app_id nên không khai thêm KEY: sổ này
--  nhỏ và không có màn hình nào lọc tên miền theo app.
ALTER TABLE tenant_domains
  DROP INDEX uq_tenant_domains_primary,
  ADD UNIQUE KEY uq_tenant_domains_primary (tenant_id, app_id, primary_mark),
  ADD CONSTRAINT fk_tenant_domains_app FOREIGN KEY (app_id) REFERENCES apps (id);

-- =====================================================================
--  KẾT THÚC — 1 cột thêm, 1 khoá mở theo app.
--
--  Đi kèm ở tầng mã nguồn (cùng đợt triển khai, không tách ra được):
--    · `cmd/ten-mien` ghi app_id cho mỗi dòng cấp ra;
--    · middleware phân giải tên miền chỉ nhận tên miền CỦA CHÍNH app mà
--      tiến trình API đó phục vụ (APP_CODE trong .env) — vào nhầm địa chỉ
--      của phần mềm khác thì phải là "không tìm thấy", không phải phục vụ
--      bằng dữ liệu của phần mềm này.
-- =====================================================================
