-- =====================================================================
--  0004_ten-mien-theo-goi-2026-08-12.sql
--  Ngày: 12/08/2026
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
--  TÊN MIỀN RIÊNG LÀ TÍNH NĂNG CỦA GÓI, KHÔNG PHẢI CỦA MỌI KHÁCH
--
--  Chính sách bán hàng vừa chốt:
--
--    · Dùng thử và các gói thường (Khởi đầu, Cửa hàng) — dùng CHUNG
--      order.selliotech.store, gõ mã cửa hàng ở ô 1 lúc đăng nhập. Không
--      cấp tên miền nào cả.
--    · Gói Chuỗi ĐÃ TRẢ TIỀN — được cấp tên miền riêng, đúng như bảng giá
--      đang công khai trên landing ("Tên miền riêng và logo của bạn").
--
--  VÌ SAO GHI VÀO BẢNG GIÁ chứ không viết thẳng `if plan == "chuoi"` trong
--  Go: đây là điều khoản BÁN HÀNG, không phải luật kỹ thuật. Ngày mai bán
--  kèm tên miền cho gói Cửa hàng, hoặc mở một gói thứ tư, thì đổi một ô
--  trong bảng giá là xong — không phải sửa code, dựng lại và triển khai.
--  Bảng giá cũng chính là chỗ người ta đọc để trả lời "gói này gồm những
--  gì", nên điều khoản nằm ở đó là nằm đúng chỗ.
--
--  CỜ NÀY KHÔNG PHÂN BIỆT subdomain VỚI custom. Nó trả lời câu "gói này có
--  được một địa chỉ riêng không". Địa chỉ đó là quochuy.selliotech.store
--  hay shopquochuy.com thì cột `tenant_domains.kind` đã nói rồi, và cả hai
--  đều tốn đúng ngần ấy việc: một bản ghi DNS, một server block, một
--  chứng chỉ.
--
--  NƠI ÉP LUẬT là `cmd/ten-mien them` — đường DUY NHẤT ghi vào sổ tên
--  miền (không có đường nào cho khách tự khai, xem đầu tệp lệnh đó). Điều
--  kiện đầy đủ mà lệnh đó xét:
--
--      thuê bao còn hiệu lực  +  status = 'active'  +  gói có own_domain = 1
--
--  `status` phải là 'active' chứ không nhận 'trial': đúng câu "khách dùng
--  thử thì dùng chung tên miền". 'past_due' cũng không nhận — khách đang
--  nợ tiền thì không cấp THÊM tên miền mới, còn tên miền đã cấp vẫn chạy
--  (thu hồi là chuyện của việc khoá cửa hàng, không phải của lệnh này).
--
--  HỆ QUẢ NGAY HÔM NAY, nói trước cho khỏi tưởng là hỏng: bảng
--  `subscriptions` đang RỖNG, nên kể từ tệp này mọi lượt `ten-mien them`
--  đều bị từ chối. Đó là kết quả ĐÚNG — sổ nền tảng trống thì không cửa
--  hàng nào chứng minh được mình đã mua gói gì. Muốn cấp trước khi có
--  thuê bao thì dùng `--dac-cach "<lý do>"`, và lý do đó được GHI LẠI
--  (xem phần 3 bên dưới) chứ không chỉ in ra màn hình rồi bay mất.
-- =====================================================================

SET NAMES utf8mb4;

-- =====================================================================
--  1. plans.own_domain — gói này có được một tên miền riêng không
-- =====================================================================
--  Mặc định 0: gói mới thêm vào bảng giá KHÔNG tự nhiên kèm tên miền. Mỗi
--  tên miền cấp ra là một bản ghi DNS, một server block nginx và một
--  chứng chỉ phải gia hạn — thứ tốn việc thật, không phải một dòng dữ
--  liệu. Bật nhầm thì tốn tiền và tốn người; quên bật thì khách gọi điện
--  hỏi ngay hôm sau. Cái hỏng nhìn thấy được luôn là cái nên chọn.
--
--  KHÔNG dùng IF NOT EXISTS: MariaDB (máy cục bộ) cho phép, MySQL 8 (máy
--  thật) thì không, nên câu có IF NOT EXISTS chạy được ở đây mà gãy trên
--  máy thật — kiểu lệch tệ nhất, vì nó qua được mọi lần thử ở nhà.
ALTER TABLE plans
  ADD COLUMN own_domain TINYINT(1) NOT NULL DEFAULT 0
  COMMENT 'gói này có được cấp tên miền riêng không; 0 = dùng chung order.selliotech.store'
  AFTER max_shops;

-- =====================================================================
--  2. Bật cho gói Chuỗi
-- =====================================================================
--  Chỉ 'chuoi', và chỉ trong bảng giá của app 'order' — app khác sau này
--  có gói trùng tên thì tự quyết định chính sách của nó.
UPDATE plans p
  JOIN apps a ON a.id = p.app_id
   SET p.own_domain = 1,
       p.updated_at = NOW(3)
 WHERE a.code = 'order'
   AND p.code = 'chuoi';

-- =====================================================================
--  3. tenant_domains.note — vì sao dòng này có mặt
-- =====================================================================
--  Chỗ ghi lý do cho những tên miền cấp NGOÀI luật: demo cho khách đang
--  xem hàng, tên miền nội bộ để thử, cửa hàng đã thoả thuận riêng.
--
--  Lý do bắt buộc phải NẰM LẠI TRONG SỔ chứ không chỉ in ra lúc chạy
--  lệnh: sáu tháng sau nhìn vào một tên miền không khớp gói nào, người
--  đọc phải trả lời được "cái này là ngoại lệ có chủ đích hay là một chỗ
--  hở", mà cửa sổ terminal hôm cấp thì không còn.
ALTER TABLE tenant_domains
  ADD COLUMN note VARCHAR(255) NULL
  COMMENT 'lý do, bắt buộc khi cấp bằng --dac-cach (ngoài điều kiện gói)'
  AFTER verified_at;

-- =====================================================================
--  KẾT THÚC — 2 cột thêm, 1 dòng bảng giá được bật cờ.
-- =====================================================================
